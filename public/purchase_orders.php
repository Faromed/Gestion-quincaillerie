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

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Stockiste) pour gérer les commandes fournisseurs
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA GESTION DES COMMANDES FOURNISSEURS ---

$success_message = '';
$error_message = '';

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


// --- Nouveau bloc pour gérer le succès de la commande et le lien facture ---
// Ce bloc vient APRÈS les vérifications précédentes pour ne pas être écrasé
if (isset($_GET['success_purchase_order_id'])) {
    $purchase_order_id_for_link = filter_var($_GET['success_purchase_order_id'], FILTER_VALIDATE_INT);
    if ($purchase_order_id_for_link !== false) {
        $success_message = "Commande n°" . htmlspecialchars($purchase_order_id_for_link) . " finalisée avec succès ! "
                         . '<a href="generate_po_pdf.php?purchase_order_id=' . htmlspecialchars($purchase_order_id_for_link) . '" target="_blank" class="alert-link btn btn-success text-light">Générer le bon de commande PDF</a>';
    } else {
         $error_message = "Commande finalisée, mais ID de Commande invalide pour générer la facture.";
    }
}

// --- Gérer l'ajout d'un produit à la commande (Panier Session) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_po_cart'])) {
    $product_id = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
    $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($product_id === false || $quantity === false) {
        $error_message = "Sélection de produit ou quantité invalide.";
    } else {
        try {
            // Récupérer les informations du produit (nom et prix d'achat)
            $sql = "SELECT id, name, purchase_price FROM products WHERE id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $product_id]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Produit introuvable.";
            } else {
                // Initialiser le panier de commande si ce n'est pas déjà fait
                if (!isset($_SESSION['po_cart'])) {
                    $_SESSION['po_cart'] = [];
                }

                // Clé unique pour chaque produit dans le panier (basée sur l'ID produit)
                $cart_item_key = $product['id'];

                // Si le produit est déjà dans le panier, augmenter la quantité
                if (isset($_SESSION['po_cart'][$cart_item_key])) {
                    $_SESSION['po_cart'][$cart_item_key]['quantity'] += $quantity;
                    // Recalculer line_total basé sur le prix d'achat unitaire stocké au premier ajout si vous voulez le conserver,
                    // ou recalculer basé sur le prix D'ACHAT ACTUEL du produit si vous voulez que le prix se mette à jour.
                    // On va conserver le prix UNITAIRE D'ACHAT du panier pour que le total ne change pas si le prix du produit est modifié
                    // après l'ajout à la commande.
                    $_SESSION['po_cart'][$cart_item_key]['line_total'] = $_SESSION['po_cart'][$cart_item_key]['quantity'] * $_SESSION['po_cart'][$cart_item_key]['unit_price']; // Utilise unit_price du panier PO
                    $success_message = "Quantité pour '" . htmlspecialchars($product['name']) . "' mise à jour dans la commande.";

                } else {
                    // Ajouter le produit à la commande
                    $_SESSION['po_cart'][$cart_item_key] = [
                        'product_id' => $product['id'],
                        'name' => $product['name'],
                        'quantity' => $quantity,
                        'unit_price' => $product['purchase_price'], // Prix d'achat unitaire au moment de l'ajout
                        'line_total' => $quantity * $product['purchase_price'],
                    ];
                     $success_message = "'" . htmlspecialchars($product['name']) . "' ajouté à la commande.";
                }
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de l'ajout à la commande : " . $e->getMessage();
        }
    }
    // Redirection post-POST pour éviter la resoumission
     header('Location: purchase_orders.php' . (!empty($success_message) ? '?success=' . urlencode($success_message) : '') . (!empty($error_message) ? '?error=' . urlencode($error_message) : ''));
     exit;
}

// --- Gérer la suppression d'un article de la commande (Panier Session) ---
if (isset($_GET['action']) && $_GET['action'] === 'remove_po_item' && isset($_GET['product_id'])) {
     $product_id_to_remove = filter_var($_GET['product_id'], FILTER_VALIDATE_INT);

     if ($product_id_to_remove !== false && isset($_SESSION['po_cart'][$product_id_to_remove])) {
         $product_name = $_SESSION['po_cart'][$product_id_to_remove]['name'];
         unset($_SESSION['po_cart'][$product_id_to_remove]); // Supprimer l'élément du tableau de session

         $success_message = "'" . htmlspecialchars($product_name) . "' retiré de la commande.";
     } else {
         $error_message = "Article non trouvé dans la commande.";
     }
     // Redirection post-GET
      header('Location: purchase_orders.php' . (!empty($success_message) ? '?success=' . urlencode($success_message) : '') . (!empty($error_message) ? '?error=' . urlencode($error_message) : ''));
     exit;
}

// --- Gérer l'annulation de la commande (vider le panier Session) ---
if (isset($_GET['action']) && $_GET['action'] === 'clear_po_cart') {
    unset($_SESSION['po_cart']); // Supprimer la variable de session du panier commande
    header('Location: purchase_orders.php?success=' . urlencode("Commande annulée. La liste a été vidée."));
    exit;
}


// --- Gérer la soumission de la commande (Sauvegarde en Base de Données) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_purchase_order'])) {
    $supplier_id = filter_var($_POST['supplier_id'] ?? '', FILTER_VALIDATE_INT);
    $notes = trim($_POST['notes'] ?? '');

    // Vérifier si un fournisseur est sélectionné
     if ($supplier_id === false) {
         $error_message = "Veuillez sélectionner un fournisseur.";
     }
    // Vérifier si le panier de commande n'est pas vide
    elseif (!isset($_SESSION['po_cart']) || empty($_SESSION['po_cart'])) {
        $error_message = "La commande est vide. Impossible de sauvegarder.";
    } else {
        try {
            // Vérifier si le supplier_id existe réellement
            $sql_check_supplier = "SELECT COUNT(*) FROM suppliers WHERE id = :supplier_id";
            $stmt_check_supplier = $pdo->prepare($sql_check_supplier);
            $stmt_check_supplier->execute([':supplier_id' => $supplier_id]);
            if ($stmt_check_supplier->fetchColumn() == 0) {
                 $error_message = "Le fournisseur sélectionné n'est pas valide.";
            } else {

                 // --- Calculer le total final de la commande ---
                $order_total_amount = 0;
                foreach ($_SESSION['po_cart'] as $item) {
                    $order_total_amount += $item['line_total'];
                }

                // --- Démarrer une transaction ---
                $pdo->beginTransaction();

                // 1. Insérer la commande fournisseur principale
                $sql_insert_order = "INSERT INTO purchase_orders (supplier_id, total_amount, status, user_id, notes)
                                    VALUES (:supplier_id, :total_amount, 'Pending', :user_id, :notes)";
                $stmt_insert_order = $pdo->prepare($sql_insert_order);
                $stmt_insert_order->execute([
                    ':supplier_id' => $supplier_id,
                    ':total_amount' => $order_total_amount,
                    ':user_id' => get_current_user_id(),
                    ':notes' => !empty($notes) ? $notes : null
                ]);

                $purchase_order_id = $pdo->lastInsertId(); // Récupérer l'ID de la commande

                // 2. Insérer les articles de la commande
                foreach ($_SESSION['po_cart'] as $item) {
                    $sql_insert_item = "INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, unit_price, line_total)
                                        VALUES (:purchase_order_id, :product_id, :quantity_ordered, :unit_price, :line_total)";
                    $stmt_insert_item = $pdo->prepare($sql_insert_item);
                    $stmt_insert_item->execute([
                        ':purchase_order_id' => $purchase_order_id,
                        ':product_id' => $item['product_id'],
                        ':quantity_ordered' => $item['quantity'],
                        ':unit_price' => $item['unit_price'],
                        ':line_total' => $item['line_total']
                    ]);
                }

                // --- Commit la transaction ---
                $pdo->commit();

                // --- Vider le panier et rediriger ---
                unset($_SESSION['po_cart']);
                // Redirection post-POST avec message de succès et ID de vente pour la facture
                header('Location: purchase_orders?success_purchase_order_id=' . $purchase_order_id);
                exit;
            }


        } catch (\PDOException $e) {
             // --- En cas d'erreur, rollback la transaction ---
             if ($pdo->inTransaction()) {
                 $pdo->rollBack();
             }
            $error_message = "Erreur lors de la sauvegarde de la commande : " . $e->getMessage();
        }
    }
     // Si une erreur s'est produite (fournisseur invalide, panier vide, erreur DB),
     // on ne redirige pas, et la page s'affiche avec le message d'erreur et le panier conservé.
}


// --- Gérer les actions de mise à jour du statut de la commande ---
if (isset($_GET['action'])) {
    $po_id_for_action = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
    $action_type = $_GET['action'];
    $valid_status_actions = ['mark_as_sent', 'mark_as_received', 'mark_as_cancelled']; // Actions de changement de statut

    if ($po_id_for_action !== false && in_array($action_type, $valid_status_actions)) {
        try {
            // Récupérer la commande pour vérifier son statut actuel
            $sql_check_status = "SELECT id, status FROM purchase_orders WHERE id = :id LIMIT 1";
            $stmt_check_status = $pdo->prepare($sql_check_status);
            $stmt_check_status->execute([':id' => $po_id_for_action]);
            $po_to_update = $stmt_check_status->fetch();

            if (!$po_to_update) {
                header('Location: purchase_orders.php?error=' . urlencode("Commande fournisseur introuvable."));
                exit;
            }

            $new_status = '';
            $status_update_allowed = false;

            switch ($action_type) {
                case 'mark_as_sent':
                    if ($po_to_update['status'] === 'Pending') {
                        $new_status = 'Sent';
                        $status_update_allowed = true;
                    } else {
                         $error_message = "Impossible de marquer cette commande comme 'Sent'. Son statut actuel est '" . htmlspecialchars($po_to_update['status']) . "'.";
                    }
                    break;
                case 'mark_as_received':
                     // Une commande peut être marquée comme Reçue si elle est Sent ou Pending (si on saute l'étape Sent)
                    if ($po_to_update['status'] === 'Sent' || $po_to_update['status'] === 'Pending') {
                        // NOTE : Marquer comme "Received" ici ne met PAS à jour le stock.
                        // La réception du stock sera une étape séparée et plus détaillée.
                        // Pour l'instant, on change juste le statut.
                        $new_status = 'Received';
                        $status_update_allowed = true;
                         // TODO: Rediriger l'utilisateur vers l'interface de réception de stock plutôt que de juste changer le statut ici
                         // Pour l'instant, on change le statut et on met un message pour rappeler que le stock n'est pas mis à jour.
                         $error_message = "Statut mis à jour vers 'Received'. NOTE : Le stock des produits n'a PAS été mis à jour automatiquement. Ceci sera fait via une interface de réception dédiée.";
                    } else {
                        $error_message = "Impossible de marquer cette commande comme 'Received'. Son statut actuel est '" . htmlspecialchars($po_to_update['status']) . "'.";
                    }
                    break;
                case 'mark_as_cancelled':
                    // Une commande peut être annulée si elle est Pending ou Sent (pas si elle est déjà Reçue ou Annulée)
                    if ($po_to_update['status'] === 'Pending' || $po_to_update['status'] === 'Sent') {
                        $new_status = 'Cancelled';
                        $status_update_allowed = true;
                         // TODO: Gérer l'impact potentiel sur le stock si la commande était en cours de réception partielle (pour plus tard)
                    } else {
                        $error_message = "Impossible d'annuler cette commande. Son statut actuel est '" . htmlspecialchars($po_to_update['status']) . "'.";
                    }
                    break;
            }

            if ($status_update_allowed) {
                 $sql_update_status = "UPDATE purchase_orders SET status = :new_status WHERE id = :id";
                 $stmt_update_status = $pdo->prepare($sql_update_status);
                 $stmt_update_status->execute([':new_status' => $new_status, ':id' => $po_id_for_action]);

                 if ($stmt_update_status->rowCount() > 0) {
                     // Si le message d'erreur a été défini par l'action (ex: pour Received sans stock update), on le garde
                     $success_message = $error_message ?: "Statut de la commande n°" . htmlspecialchars($po_id_for_action) . " mis à jour vers '" . htmlspecialchars($new_status) . "' avec succès !";
                     $error_message = ''; // Vider le message d'erreur s'il n'était pas intentionnel
                 } else {
                     $error_message = "Échec de la mise à jour du statut ou statut déjà à jour.";
                 }
            }

        } catch (\PDOException $e) {
            $error_message = "Erreur lors de la mise à jour du statut : " . $e->getMessage();
        }

        // Redirection après l'action de statut
         header('Location: purchase_orders.php' . (!empty($success_message) ? '?success=' . urlencode($success_message) : '') . (!empty($error_message) ? '?error=' . urlencode($error_message) : ''));
        exit;
    }
     // Gérer d'autres actions GET si nécessaire (ex: action=view_po pour voir les détails sans recevoir)
     // ...
}


// --- Récupérer la liste des commandes fournisseurs existantes pour l'affichage ---
$purchase_orders = [];
try {
    // Jointure avec suppliers et users pour afficher les noms
    $sql_select_pos = "SELECT po.*, s.name as supplier_name, u.username as creator_name
                      FROM purchase_orders po
                      JOIN suppliers s ON po.supplier_id = s.id
                      LEFT JOIN users u ON po.user_id = u.id
                      ORDER BY po.order_date DESC";
    // Pour l'instant, pas de pagination ou de filtre
    $stmt_select_pos = $pdo->query($sql_select_pos);
    $purchase_orders = $stmt_select_pos->fetchAll();
} catch (\PDOException $e) {
     // N'écrase pas un message d'erreur plus pertinent
     if (empty($error_message)) {
         $error_message = "Erreur lors du chargement des commandes fournisseurs : " . $e->getMessage();
     }
}


// --- Calculer les totaux de la commande en cours (Panier Session) ---
$po_cart_total = 0;
$po_cart_items_count = 0;

if (isset($_SESSION['po_cart']) && is_array($_SESSION['po_cart'])) {
    foreach ($_SESSION['po_cart'] as $item) {
        $po_cart_total += $item['line_total'];
        $po_cart_items_count++;
    }
}


// --- Récupérer la liste des produits pour le select du formulaire d'ajout ---
$products_for_select = [];
try {
    // On peut lister tous les produits, même sans stock, pour pouvoir les commander
    $sql_products = "SELECT id, name, purchase_price FROM products ORDER BY name ASC";
    $stmt_products = $pdo->query($sql_products);
    $products_for_select = $stmt_products->fetchAll();
} catch (\PDOException $e) {
     // Gérer l'erreur si nécessaire
}

// --- Récupérer la liste des fournisseurs pour le select ---
$suppliers_for_select = [];
try {
    $sql_suppliers = "SELECT id, name FROM suppliers ORDER BY name ASC";
    $stmt_suppliers = $pdo->query($sql_suppliers);
    $suppliers_for_select = $stmt_suppliers->fetchAll();
} catch (\PDOException $e) {
     // Gérer l'erreur si nécessaire
}


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commandes Fournisseurs - Système Quincaillerie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Votre propre CSS -->
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
         .po-cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 8px 0;
        }
        .po-cart-item:last-child {
             border-bottom: none;
        }
         .po-cart-totals {
            margin-top: 20px;
            border-top: 2px solid #343a40;
            padding-top: 15px;
        }
         .po-cart-totals div {
             display: flex;
             justify-content: space-between;
             margin-bottom: 5px;
         }
         .po-cart-totals .total {
             font-size: 1.2em;
             font-weight: bold;
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
            <!-- Futur emplacement pour les alertes, notifications rapides etc. -->
        </div>

        <h1>Gestion des Commandes Fournisseurs</h1>

         <?php
        // Afficher les messages de succès ou d'erreur
        if (!empty($success_message)) {
            echo '<div class="alert alert-success" role="alert">' . $success_message . '</div>';
        }
        if (!empty($error_message)) {
            echo '<div class="alert alert-danger" role="alert">' . $error_message . '</div>';
        }
        ?>

        <div class="row">
            <div class="col-md-6">
                 <div class="card mb-4">
                    <div class="card-header">
                        Créer une nouvelle commande
                    </div>
                    <div class="card-body">
                        <!-- Section Sélection Fournisseur et Notes -->
                         <form action="purchase_orders.php" method="POST" id="poFormHeader">
                             <input type="hidden" name="save_purchase_order" value="1">
                             <div class="mb-3">
                                 <label for="supplier_id" class="form-label">Fournisseur</label>
                                 <select class="form-select" id="supplier_id" name="supplier_id" required>
                                     <option value="">-- Sélectionner un fournisseur --</option>
                                      <?php foreach ($suppliers_for_select as $supplier): ?>
                                         <option value="<?php echo htmlspecialchars($supplier['id']); ?>">
                                             <?php echo htmlspecialchars($supplier['name']); ?>
                                         </option>
                                      <?php endforeach; ?>
                                 </select>
                                 <?php if (empty($suppliers_for_select)): ?>
                                     <small class="form-text text-muted">Aucun fournisseur disponible. Veuillez d'abord ajouter des fournisseurs.</small>
                                 <?php endif; ?>
                             </div>
                             <div class="mb-3">
                                 <label for="notes" class="form-label">Notes (Optionnel)</label>
                                 <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                             </div>
                              <!-- Les boutons de sauvegarde/annulation seront dans la colonne de droite pour être près du panier -->
                         </form>

                         <hr>

                        <!-- Formulaire pour ajouter un produit à la commande -->
                        <h5>Ajouter un produit</h5>
                        <form action="purchase_orders.php" method="POST">
                            <input type="hidden" name="add_to_po_cart" value="1">
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Produit</label>
                                <select class="form-select" id="product_id" name="product_id" required>
                                    <option value="">-- Sélectionner un produit --</option>
                                     <?php foreach ($products_for_select as $product): ?>
                                        <option value="<?php echo htmlspecialchars($product['id']); ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> (Prix Achat: <?php echo htmlspecialchars(number_format($product['purchase_price'], 2, ',', ' ') . ' FCFA'); ?>)
                                        </option>
                                     <?php endforeach; ?>
                                </select>
                                <?php if (empty($products_for_select)): ?>
                                     <small class="form-text text-muted">Aucun produit disponible. Veuillez d'abord ajouter des produits.</small>
                                <?php endif; ?>
                            </div>
                             <div class="mb-3">
                                <label for="quantity" class="form-label">Quantité commandée</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" required min="1" value="1">
                            </div>
                            <button type="submit" class="btn btn-primary" <?php echo empty($products_for_select) ? 'disabled' : ''; ?>><i class="fas fa-cart-plus me-2"></i> Ajouter à la commande</button>
                             <?php if (empty($products_for_select)): ?>
                                <span class="text-danger"> (Ajoutez des produits pour commander)</span>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                 <div class="card">
                    <div class="card-header">
                        Articles de la commande en cours (<?php echo $po_cart_items_count; ?> article(s))
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['po_cart']) && count($_SESSION['po_cart']) > 0): ?>
                             <div class="po-cart-items-list">
                                 <?php foreach ($_SESSION['po_cart'] as $item_key => $item): ?>
                                     <div class="po-cart-item">
                                         <div>
                                             <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                             <?php echo htmlspecialchars($item['quantity']); ?> x <?php echo htmlspecialchars(number_format($item['unit_price'], 2, ',', ' ') . ' FCFA'); ?>
                                         </div>
                                         <div>
                                             <?php echo htmlspecialchars(number_format($item['line_total'], 2, ',', ' ') . ' FCFA'); ?>
                                             <a href="purchase_orders.php?action=remove_po_item&product_id=<?php echo htmlspecialchars($item['product_id']); ?>" class="btn btn-sm btn-danger ms-2" title="Retirer"><i class="fas fa-times"></i></a>
                                         </div>
                                     </div>
                                 <?php endforeach; ?>
                             </div>

                             <div class="po-cart-totals">
                                 <div>
                                     <span>Total Commande :</span>
                                     <span><?php echo htmlspecialchars(number_format($po_cart_total, 2, ',', ' ') . ' FCFA'); ?></span>
                                 </div>
                             </div>

                             <div class="mt-4">
                                 <!-- Boutons de sauvegarde et annulation -->
                                  <!-- Ces boutons soumettront le formulaire principal id="poFormHeader" -->
                                  <button type="submit" form="poFormHeader" class="btn btn-success w-100" <?php echo empty($suppliers_for_select) ? 'disabled' : ''; ?>><i class="fas fa-save me-2"></i> Sauvegarder la Commande</button>
                                  <?php if (empty($suppliers_for_select)): ?>
                                     <small class="form-text text-danger text-center d-block mt-2">Ajoutez des fournisseurs pour sauvegarder la commande.</small>
                                  <?php endif; ?>

                                  <a href="purchase_orders.php?action=clear_po_cart" class="btn btn-danger w-100 mt-2" onclick="return confirm('Êtes-vous sûr de vouloir annuler la création de cette commande ?');"><i class="fas fa-trash-alt me-2"></i> Annuler la Commande</a>
                             </div>

                        <?php else: ?>
                            <p class="text-center text-muted">La commande est vide. Ajoutez des produits pour commencer.</p>
                             <div class="mt-4">
                                  <!-- Bouton Annuler désactivé si le panier est vide -->
                                  <a href="purchase_orders.php?action=clear_po_cart" class="btn btn-danger w-100" onclick="return confirm('Êtes-vous sûr de vouloir annuler la création de cette commande ?');" disabled><i class="fas fa-trash-alt me-2"></i> Annuler la Commande</a>
                             </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section pour afficher la liste des commandes existantes -->
         <div class="card mt-4">
             <div class="card-header">
                 Commandes Fournisseurs Existantes
             </div>
             <div class="card-body">
                 <?php if (count($purchase_orders) > 0): ?>
                      <table class="table table-striped table-hover table-sm">
                         <thead>
                             <tr>
                                 <th>ID</th>
                                 <th>Date</th>
                                 <th>Fournisseur</th>
                                 <th>Total</th>
                                 <th>Statut</th>
                                 <th>Créée par</th>
                                 <th>Actions</th>
                             </tr>
                         </thead>
                         <tbody>
                             <?php foreach ($purchase_orders as $po): ?>
                             <tr>
                                 <td><?php echo htmlspecialchars($po['id']); ?></td>
                                 <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($po['order_date']))); ?></td>
                                 <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                 <td><?php echo htmlspecialchars(number_format($po['total_amount'], 2, ',', ' ') . ' FCFA'); ?></td>
                                  <td><span class="po-status-badge status-<?php echo htmlspecialchars($po['status']); ?>"><?php echo htmlspecialchars($po['status']); ?></span></td>
                                 <td><?php echo htmlspecialchars($po['creator_name'] ?? 'N/A'); ?></td>
                                 <td>
                                      <a href="generate_po_pdf.php?purchase_order_id=<?php echo $po['id']; ?>" target="_blank" class="btn btn-sm btn-info me-1" title="Générer PDF"><i class="fas fa-file-pdf"></i></a>
                                      <!-- Actions de statut - affichées conditionnellement -->
                                      <?php if ($po['status'] === 'Pending'): ?>
                                           <a href="purchase_orders.php?action=mark_as_sent&id=<?php echo $po['id']; ?>" class="btn btn-sm btn-secondary me-1" title="Marquer comme Envoyée" onclick="return confirm('Marquer la commande N°<?php echo $po['id']; ?> comme Envoyée ?');"><i class="fas fa-paper-plane"></i></a>
                                            <a href="purchase_orders.php?action=mark_as_cancelled&id=<?php echo $po['id']; ?>" class="btn btn-sm btn-danger me-1" title="Annuler" onclick="return confirm('Annuler la commande N°<?php echo $po['id']; ?> ?');"><i class="fas fa-times-circle"></i></a>
                                      <?php elseif ($po['status'] === 'Sent'): ?>
                                           <!-- Lien/bouton pour INITIER la réception de stock -->
                                            <a href="receive_po.php?action=receive_stock&id=<?php echo $po['id']; ?>" class="btn btn-sm btn-success me-1" title="Recevoir le Stock"><i class="fas fa-truck-loading"></i></a>
                                            <a href="purchase_orders.php?action=mark_as_cancelled&id=<?php echo $po['id']; ?>" class="btn btn-sm btn-danger me-1" title="Annuler" onclick="return confirm('Annuler la commande N°<?php echo $po['id']; ?> ?');"><i class="fas fa-times-circle"></i></a>
                                      <?php elseif ($po['status'] === 'Received'): ?>
                                           <!-- Pas d'actions de statut supplémentaires pour 'Received' -->
                                            <!-- Optionnel : Bouton pour voir les détails de réception (pas implémenté ici) -->
                                      <?php elseif ($po['status'] === 'Cancelled'): ?>
                                           <!-- Pas d'actions pour les commandes annulées -->
                                      <?php endif; ?>
                                       <!-- Optionnel : Bouton pour supprimer (uniquement si Pending ou Cancelled ?) -->
                                      <?php if ($po['status'] === 'Pending' || $po['status'] === 'Cancelled'): // Empêcher suppression si Sent ou Received (pour garder l'historique) ?>
                                            <a href="purchase_orders.php?action=delete_po&id=<?php echo $po['id']; ?>" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer la commande N°<?php echo $po['id']; ?> ? Cette action est irréversible et supprimera les articles associés.');"><i class="fas fa-trash-alt"></i></a>
                                      <?php endif; ?>
                                 </td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                 <?php else: ?>
                     <p class="text-center text-muted">Aucune commande fournisseur enregistrée.</p>
                 <?php endif; ?>
             </div>
         </div>


    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>