<?php
// Démarre la session PHP
session_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Si l'utilisateur n'est PAS connecté, le rediriger vers la page de connexion
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Stockiste) pour recevoir du stock
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA RÉCEPTION DE STOCK ---

$error_message = '';
$success_message = '';
$purchase_order_to_receive = null;
$po_items_to_receive = [];

$po_id = filter_var($_REQUEST['po_id'] ?? '', FILTER_VALIDATE_INT); // Use REQUEST for GET/POST

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


// --- Gérer la soumission du formulaire de réception ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_reception'])) {
    $po_id_for_reception = filter_var($_POST['po_id'] ?? '', FILTER_VALIDATE_INT);
    $reception_notes = trim($_POST['reception_notes'] ?? '');
    $received_quantities = $_POST['received_quantity'] ?? []; // Tableau associatif item_id => quantity

     if ($po_id_for_reception === false) {
         $error_message = "ID de commande fournisseur invalide pour la réception.";
     } elseif (empty($received_quantities) || !is_array($received_quantities)) {
        $error_message = "Aucune quantité de réception soumise.";
     } else {

         try {
             // Vérifier l'existence et le statut de la commande
             $sql_check_po = "SELECT id, status FROM purchase_orders WHERE id = :id LIMIT 1";
             $stmt_check_po = $pdo->prepare($sql_check_po);
             $stmt_check_po->execute([':id' => $po_id_for_reception]);
             $po_status = $stmt_check_po->fetchColumn();

             if (!$po_status) {
                 $error_message = "Commande fournisseur introuvable.";
             } elseif ($po_status === 'Received' || $po_status === 'Cancelled') {
                  $error_message = "Impossible de recevoir du stock pour une commande au statut '" . htmlspecialchars($po_status) . "'.";
             } else {
                 // Récupérer les articles de la commande pour validation et traitement
                 $sql_po_items = "SELECT poi.id as item_id, poi.product_id, poi.quantity_ordered, poi.quantity_received
                                  FROM purchase_order_items poi
                                  WHERE poi.purchase_order_id = :po_id";
                 $stmt_po_items = $pdo->prepare($sql_po_items);
                 $stmt_po_items->execute([':po_id' => $po_id_for_reception]);
                 $po_items_db = $stmt_po_items->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC); // Index par item_id

                 $pdo->beginTransaction(); // Démarrer la transaction

                 $total_items_processed = 0;
                 $stock_updated_count = 0;

                 foreach ($received_quantities as $item_id => $received_qty_str) {
                     $item_id = filter_var($item_id, FILTER_VALIDATE_INT);
                     $received_qty = filter_var($received_qty_str, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]); // Quantité reçue peut être 0

                     // Vérifier si l'item_id est valide et appartient à cette commande
                     if ($item_id !== false && isset($po_items_db[$item_id])) {
                         $total_items_processed++;

                         // Récupérer les infos de l'article depuis les données DB chargées
                         $item_db = $po_items_db[$item_id];
                         $product_id = $item_db['product_id'];
                         $already_received = $item_db['quantity_received'];
                         $ordered_qty = $item_db['quantity_ordered'];

                         // Valider la quantité reçue soumise
                         if ($received_qty === false) {
                             // Ignorer cet article mais continuer les autres, ou tout annuler ?
                             // On va ignorer cet item invalide mais enregistrer une erreur spécifique si possible.
                             // Pour l'instant, on continue mais c'est une simplification.
                              error_log("Quantité reçue invalide pour l'item_id: " . $item_id . " (PO ID: " . $po_id_for_reception . ")"); // Log l'erreur
                             continue; // Passer à l'item suivant
                         }

                         // Calculer la quantité à ajouter au stock et à quantity_received
                         $quantity_to_add = $received_qty; // Simplement la quantité entrée par l'utilisateur pour cette réception


                         if ($quantity_to_add > 0) {
                              // Mettre à jour la quantité reçue dans purchase_order_items
                             $sql_update_item = "UPDATE purchase_order_items SET quantity_received = quantity_received + :qty WHERE id = :item_id";
                             $stmt_update_item = $pdo->prepare($sql_update_item);
                             $stmt_update_item->execute([
                                 ':qty' => $quantity_to_add,
                                 ':item_id' => $item_id
                             ]);

                             // Mettre à jour le stock actuel du produit
                             $sql_update_stock = "UPDATE products SET current_stock = current_stock + :qty WHERE id = :product_id";
                             $stmt_update_stock = $pdo->prepare($sql_update_stock);
                             $stmt_update_stock->execute([
                                 ':qty' => $quantity_to_add,
                                 ':product_id' => $product_id
                             ]);

                             // Enregistrer le mouvement de stock (Entrée - Approvisionnement)
                             $sql_insert_movement = "INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id)
                                                     VALUES (:product_id, 'Entree - Approvisionnement', :quantity, :notes, :user_id)";
                             $stmt_insert_movement = $pdo->prepare($sql_insert_movement);
                             $movement_notes = "Réception PO #" . $po_id_for_reception . " (Item #" . $item_id . ", Quantité : " . $quantity_to_add . ")"; // Note descriptive
                             $stmt_insert_movement->execute([
                                 ':product_id' => $product_id,
                                 ':quantity' => $quantity_to_add, // Quantité positive pour une entrée
                                 ':notes' => $movement_notes,
                                 ':user_id' => get_current_user_id()
                             ]);

                             $stock_updated_count++;
                         }
                         // Si $quantity_to_add est 0, on ne fait rien pour cet item, ce qui est correct pour une réception partielle/zéro
                     }
                 }

                 // Après avoir traité tous les articles soumis :
                 // Marquer la commande comme Reçue (indique que le processus de réception est terminé pour cette PO)
                 $sql_update_po_status = "UPDATE purchase_orders SET status = 'Received' WHERE id = :po_id";
                 $stmt_update_po_status = $pdo->prepare($sql_update_po_status);
                 $stmt_update_po_status->execute([':po_id' => $po_id_for_reception]);


                 $pdo->commit(); // Commit la transaction

                 $success_message = "Réception pour la commande n°" . htmlspecialchars($po_id_for_reception) . " finalisée avec succès. Stock mis à jour pour " . $stock_updated_count . " article(s).";
                 header('Location: purchase_orders.php?success=' . urlencode($success_message));
                 exit;


             } // Fin du check statut PO
         } catch (\PDOException $e) {
             // --- En cas d'erreur, rollback la transaction ---
             if ($pdo->inTransaction()) {
                 $pdo->rollBack();
             }
             $error_message = "Erreur lors de la finalisation de la réception : " . $e->getMessage();
         }
     }
    // Si une erreur POST survient, la page s'affiche avec le message d'erreur et les données postées peuvent potentiellement être perdues
    // Une meilleure approche serait de rediriger avec un paramètre POST dans la session, mais c'est plus complexe.
    // Pour l'instant, l'utilisateur devra peut-être resaisir les quantités si la page s'affiche avec une erreur.
}


// --- Charger les données de la commande si un ID est présent dans l'URL pour affichage (GET ou POST échoué) ---
if ($po_id !== false && empty($error_message) && empty($success_message)) { // Ne charger que si l'ID est valide et pas d'erreur/succès en cours
    try {
        // Récupérer les informations de la commande principale, joindre le fournisseur
        $sql_po = "SELECT po.*, s.name as supplier_name, s.contact_person, s.phone, u.username as creator_name
                  FROM purchase_orders po
                  JOIN suppliers s ON po.supplier_id = s.id
                  LEFT JOIN users u ON po.user_id = u.id
                  WHERE po.id = :po_id LIMIT 1";
        $stmt_po = $pdo->prepare($sql_po);
        $stmt_po->execute([':po_id' => $po_id]);
        $purchase_order_to_receive = $stmt_po->fetch();

        if (!$purchase_order_to_receive) {
            // Rediriger si la commande n'existe pas
            header('Location: purchase_orders.php?error=' . urlencode("Commande fournisseur introuvable pour réception."));
            exit;
        }

        // Vérifier le statut pour la réception
        if ($purchase_order_to_receive['status'] === 'Received' || $purchase_order_to_receive['status'] === 'Cancelled') {
             header('Location: purchase_orders.php?error=' . urlencode("Impossible de recevoir du stock pour une commande déjà '" . htmlspecialchars($purchase_order_to_receive['status']) . "'."));
            exit;
        }


        // Récupérer les articles de cette commande pour affichage dans le formulaire de réception
        $sql_po_items = "SELECT poi.id as item_id, poi.product_id, poi.quantity_ordered, poi.quantity_received, poi.unit_price, p.name as product_name, p.barcode, p.reference
                         FROM purchase_order_items poi
                         JOIN products p ON poi.product_id = p.id
                         WHERE poi.purchase_order_id = :po_id";
        $stmt_po_items = $pdo->prepare($sql_po_items);
        $stmt_po_items->execute([':po_id' => $po_id]);
        $po_items_to_receive = $stmt_po_items->fetchAll();

        if (empty($po_items_to_receive)) {
            // Rediriger si la commande est vide d'articles (ne devrait pas arriver normalement)
             header('Location: purchase_orders.php?error=' . urlencode("Aucun article trouvé pour cette commande fournisseur."));
            exit;
        }


    } catch (\PDOException $e) {
        $error_message = "Erreur lors du chargement des données de la commande : " . $e->getMessage();
    }
} elseif ($po_id === false) {
     // Si pas d'ID valide dès le départ et pas d'erreur/succès
      $error_message = "ID de commande fournisseur manquant pour la réception.";
}


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réception Commande Fournisseur <?php echo $po_id !== false ? '# ' . htmlspecialchars($po_id) : ''; ?> - Système Quincaillerie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">    <!-- Votre propre CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
     <style>
        body {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 250px;
            background-color: #343a40;
            color: white;
            padding-top: 20px;
            flex-shrink: 0;
        }
        .sidebar a {
            color: #adb5bd;
            text-decoration: none;
            padding: 10px 15px;
            display: block;
        }
        .sidebar a:hover {
            background-color: #495057;
            color: white;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
        }
        .navbar-top {
            background-color: #e9ecef;
            padding: 10px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #007bff;
        }
         .po-status-badge {
             padding: 0.3em 0.6em;
             font-size: 75%;
             font-weight: 700;
             line-height: 1;
             text-align: center;
             white-space: nowrap;
             vertical-align: baseline;
             border-radius: 0.25rem;
             color: #fff;
         }
         .status-Pending { background-color: #ffc107; color: #212529; }
         .status-Sent { background-color: #17a2b8; }
         .status-Received { background-color: #28a745; }
         .status-Cancelled { background-color: #dc3545; }
         .reception-table td {
             vertical-align: middle;
         }
    </style>
</head>
<body>

    <div class="sidebar">
        <h4 class="text-center mb-4">Quincaillerie</h4>
        <ul class="nav flex-column">
             <li class="nav-item">
                <a class="nav-link" href="index.php"><i class="fas fa-chart-line me-2"></i> Tableau de Bord</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="products.php"><i class="fas fa-box me-2"></i> Produits</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="purchase_orders.php"><i class="fas fa-truck me-2"></i> Commandes Fournisseurs</a> <!-- Lien actif -->
            </li>
             <li class="nav-item">
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="reports.php"><i class="fas fa-file-alt me-2"></i> Rapports</a>
            </li>
             <?php if (get_current_user_role_id() == 1): ?>
             <li class="nav-item">
                <a class="nav-link" href="users.php"><i class="fas fa-users me-2"></i> Utilisateurs</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="categories.php"><i class="fas fa-tags me-2"></i> Catégories</a>
            </li>
             <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Déconnexion</a>
            </li>
        </ul>
    </div>

    <div class="content">
        <div class="navbar-top">
             <h5>Bonjour, <?php echo htmlspecialchars(get_current_username() ?? 'Utilisateur'); ?> !</h5>
        </div>

        <h1>Réception de Commande Fournisseur</h1>

         <?php
        // Afficher les messages de succès ou d'erreur
        if (!empty($success_message)) {
            echo '<div class="alert alert-success" role="alert">' . $success_message . '</div>';
        }
        if (!empty($error_message)) {
            echo '<div class="alert alert-danger" role="alert">' . $error_message . '</div>';
        }
        ?>

        <?php if ($purchase_order_to_receive): // Afficher l'interface de réception si la commande est chargée ?>
            <div class="card mb-4">
                <div class="card-header">
                    Réceptionner la Commande N° <?php echo htmlspecialchars($purchase_order_to_receive['id']); ?> (Fournisseur : <?php echo htmlspecialchars($purchase_order_to_receive['supplier_name']); ?>)
                </div>
                <div class="card-body">
                    <p>Date Commande : <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($purchase_order_to_receive['order_date']))); ?></p>
                    <p>Statut : <span class="po-status-badge status-<?php echo htmlspecialchars($purchase_order_to_receive['status']); ?>"><?php echo htmlspecialchars($purchase_order_to_receive['status']); ?></span></p>
                     <?php if (!empty($purchase_order_to_receive['notes'])): ?>
                         <p>Notes : <?php echo nl2br(htmlspecialchars($purchase_order_to_receive['notes'])); ?></p>
                     <?php endif; ?>

                    <hr>

                    <h5>Articles de la commande</h5>
                    <form action="receive_po.php" method="POST">
                        <input type="hidden" name="finalize_reception" value="1">
                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($purchase_order_to_receive['id']); ?>">

                         <table class="table table-striped table-hover table-sm reception-table">
                             <thead>
                                 <tr>
                                     <th>Produit</th>
                                     <th>Référence</th>
                                     <th>Code-barres</th>
                                     <th>Qté Commandée</th>
                                     <th>Qté Déjà Reçue</th>
                                     <th>Quantité Reçue Maintenant</th>
                                 </tr>
                             </thead>
                             <tbody>
                                 <?php foreach ($po_items_to_receive as $item): ?>
                                     <tr>
                                         <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                         <td><?php echo htmlspecialchars($item['reference'] ?? 'N/A'); ?></td>
                                         <td><?php echo htmlspecialchars($item['barcode'] ?? 'N/A'); ?></td>
                                         <td><?php echo htmlspecialchars($item['quantity_ordered']); ?></td>
                                         <td><?php echo htmlspecialchars($item['quantity_received']); ?></td>
                                         <td>
                                             <?php
                                                // Quantité restante à recevoir
                                                $remaining_to_receive = $item['quantity_ordered'] - $item['quantity_received'];
                                                // Si tout est déjà reçu, désactiver l'input
                                                $is_item_fully_received = $remaining_to_receive <= 0;
                                             ?>
                                             <input type="number"
                                                    name="received_quantity[<?php echo htmlspecialchars($item['item_id']); ?>]"
                                                    class="form-control form-control-sm"
                                                    value="<?php echo $is_item_fully_received ? 0 : htmlspecialchars($remaining_to_receive); ?>"
                                                    min="0"
                                                     <?php echo $is_item_fully_received ? 'disabled' : 'required'; ?> > <!-- Required seulement si pas déjà tout reçu -->
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>

                         <div class="mb-3">
                             <label for="reception_notes" class="form-label">Notes de réception (Optionnel)</label>
                             <textarea class="form-control" id="reception_notes" name="reception_notes" rows="2"></textarea>
                         </div>

                        <button type="submit" class="btn btn-success me-2"><i class="fas fa-check-circle me-2"></i> Finaliser la réception</button>
                        <a href="purchase_orders.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i> Annuler</a>

                    </form>
                </div>
            </div>
        <?php else: // Afficher un message si pas de commande chargée (devrait être géré par redirection normalement) ?>
             <div class="alert alert-info" role="alert">
                 Veuillez sélectionner une commande fournisseur à recevoir depuis la liste des commandes.
             </div>
              <a href="purchase_orders.php" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i> Retour aux commandes fournisseurs</a>
        <?php endif; ?>


    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>