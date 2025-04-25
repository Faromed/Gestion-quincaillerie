<?php
// Démarre la session PHP
session_start();

// Inclure le fichier de connexion à la base de données
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Si l'utilisateur n'est PAS connecté, le rediriger vers la page de connexion
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Stockiste)
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA GESTION DES MOUVEMENTS DE STOCK MANUELS ---

$success_message = '';
$error_message = '';

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// --- Gérer l'enregistrement d'un nouveau mouvement manuel ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_movement'])) {
    $product_id = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
    $movement_type_base = $_POST['movement_type_base'] ?? ''; // 'Entree' ou 'Sortie'
    $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); // Quantité doit être > 0
    $notes = trim($_POST['notes'] ?? '');

    // Déterminer le type de mouvement complet basé sur l'option sélectionnée
    $valid_manual_types = [
        'Entree - Ajustement' => 'Entree',
        'Sortie - Casse' => 'Sortie',
        'Sortie - Usage Interne' => 'Sortie'
    ];
    $movement_type = '';
    if ($movement_type_base === 'Entree') {
         $movement_type = 'Entree - Ajustement'; // Seul type d'entrée manuelle pour l'instant
    } elseif ($movement_type_base === 'Sortie') {
        // Pour les sorties manuelles, on pourrait avoir un select pour Casse ou Usage Interne.
        // Pour simplifier ici, on va juste prendre "Sortie - Usage Interne" ou vous pouvez adapter.
        // OU mieux : on peut avoir un seul select avec tous les types ENUM possibles.
        // Changeons le formulaire pour utiliser directement les valeurs ENUM manuelles.
         $valid_manual_enum_types = ['Entree - Ajustement', 'Sortie - Casse', 'Sortie - Usage Interne'];
         $selected_enum_type = $_POST['manual_movement_enum_type'] ?? ''; // Nouveau nom de champ
         if (in_array($selected_enum_type, $valid_manual_enum_types)) {
             $movement_type = $selected_enum_type;
         }
    }


    if ($product_id === false || empty($movement_type) || $quantity === false || $quantity < 1) {
        $error_message = "Veuillez sélectionner un produit, un type de mouvement, et saisir une quantité valide (> 0).";
         // Si le type de mouvement n'est pas valide
         if (empty($movement_type)) $error_message = "Veuillez sélectionner un type de mouvement valide.";

    } else {
        try {
            // Déterminer la quantité réelle à enregistrer (+ ou -)
            $recorded_quantity = $quantity;
            if (strpos($movement_type, 'Sortie - ') === 0) { // Si c'est un type de sortie
                $recorded_quantity = -$quantity; // La quantité enregistrée sera négative
            }

            // --- Démarre une transaction ---
            $pdo->beginTransaction();

            // 1. Insérer le mouvement de stock
            $sql_insert_movement = "INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id)
                                    VALUES (:product_id, :movement_type, :quantity, :notes, :user_id)";
            $stmt_insert_movement = $pdo->prepare($sql_insert_movement);
            $stmt_insert_movement->execute([
                ':product_id' => $product_id,
                ':movement_type' => $movement_type,
                ':quantity' => $recorded_quantity, // Utilise la quantité enregistrée (+ ou -)
                ':notes' => !empty($notes) ? $notes : null,
                ':user_id' => get_current_user_id() // Enregistre l'utilisateur connecté
            ]);

            // 2. Mettre à jour le stock actuel du produit
            // On utilise quantity ici car elle a déjà le signe +/-
            $sql_update_stock = "UPDATE products SET current_stock = current_stock + :quantity WHERE id = :product_id";
            $stmt_update_stock = $pdo->prepare($sql_update_stock);
            $stmt_update_stock->execute([
                ':quantity' => $recorded_quantity,
                ':product_id' => $product_id
            ]);

            // --- Commit la transaction si tout s'est bien passé ---
            $pdo->commit();

            // Récupérer le nom du produit pour le message de succès
            $sql_product_name = "SELECT name FROM products WHERE id = :product_id";
            $stmt_product_name = $pdo->prepare($sql_product_name);
            $stmt_product_name->execute([':product_id' => $product_id]);
            $product_name = $stmt_product_name->fetchColumn();

            header('Location: stock_movements.php?success=' . urlencode("Mouvement de stock enregistré pour '" . htmlspecialchars($product_name) . "' (Quantité : " . $recorded_quantity . ")."));
            exit;

        } catch (\PDOException $e) {
             // --- En cas d'erreur, rollback la transaction ---
             if ($pdo->inTransaction()) {
                 $pdo->rollBack();
             }
             $error_message = "Erreur lors de l'enregistrement du mouvement : " . $e->getMessage();
        }
    }
}


// --- Récupérer l'historique des mouvements de stock ---
$movements = [];
try {
    // Jointure avec products et users pour afficher les noms
    $sql_select = "SELECT sm.*, p.name as product_name, u.username
                   FROM stock_movements sm
                   JOIN products p ON sm.product_id = p.id
                   LEFT JOIN users u ON sm.user_id = u.id -- LEFT JOIN car user_id peut être NULL
                   ORDER BY sm.movement_date DESC
                   LIMIT 50"; // Limiter à 50 derniers mouvements pour l'affichage initial
    $stmt_select = $pdo->query($sql_select);
    $movements = $stmt_select->fetchAll();
} catch (\PDOException $e) {
     if (empty($error_message)) {
         $error_message = "Erreur lors du chargement de l'historique des mouvements : " . $e->getMessage();
     }
}

// --- Récupérer la liste des produits pour le select du formulaire ---
$products = [];
try {
    $sql_products = "SELECT id, name, current_stock FROM products ORDER BY name ASC";
    $stmt_products = $pdo->query($sql_products);
    $products = $stmt_products->fetchAll();
} catch (\PDOException $e) {
      // Gérer l'erreur de chargement des produits
}

// --- Définir les types de mouvements manuels pour le formulaire ---
$manual_movement_types = [
    'Entree - Ajustement'       => 'Entrée - Ajustement',
    'Sortie - Casse'            => 'Sortie - Casse',
    'Sortie - Usage Interne'    => 'Sortie - Usage Interne',
    // Ajoutez ici d'autres types manuels si nécessaire
];


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Mouvements de Stock - Système Quincaillerie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
         /* Styles spécifiques aux mouvements */
        .movement-entry { color: green; font-weight: bold; }
        .movement-exit { color: red; font-weight: bold; }
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
                <a class="nav-link" href="products.php"><i class="fas fa-box me-2"></i> Produits</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a> </li>
             <li class="nav-item">
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a> </li>
            <li class="nav-item">
                <a class="nav-link active" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a> </li>
             <li class="nav-item">
                <a class="nav-link" href="reports.php"><i class="fas fa-file-alt me-2"></i> Rapports</a> </li>
             <?php if (get_current_user_role_id() == 1): // Si l'utilisateur est Admin (ID 1) ?>
             <li class="nav-item">
                <a class="nav-link" href="users.php"><i class="fas fa-users me-2"></i> Utilisateurs</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="categories.php"><i class="fas fa-tags me-2"></i> Catégories</a> </li>
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

        <h1>Gestion des Mouvements de Stock Manuels</h1>

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
            <div class="col-md-5">
                 <div class="card mb-4">
                    <div class="card-header">
                        Enregistrer un mouvement manuel
                    </div>
                    <div class="card-body">
                        <form action="stock_movements.php" method="POST">
                            <input type="hidden" name="add_manual_movement" value="1">
                             <div class="mb-3">
                                <label for="product_id" class="form-label">Produit</label>
                                <select class="form-select" id="product_id" name="product_id" required>
                                    <option value="">-- Sélectionner un produit --</option>
                                     <?php foreach ($products as $product): ?>
                                        <option value="<?php echo htmlspecialchars($product['id']); ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> (Stock: <?php echo htmlspecialchars($product['current_stock']); ?>)
                                        </option>
                                     <?php endforeach; ?>
                                </select>
                                <?php if (empty($products)): ?>
                                    <small class="form-text text-muted">Aucun produit disponible. Veuillez d'abord ajouter des produits.</small>
                                <?php endif; ?>
                            </div>
                             <div class="mb-3">
                                <label for="manual_movement_enum_type" class="form-label">Type de mouvement</label>
                                <select class="form-select" id="manual_movement_enum_type" name="manual_movement_enum_type" required>
                                     <option value="">-- Sélectionner un type --</option>
                                     <?php foreach ($manual_movement_types as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                                     <?php endforeach; ?>
                                </select>
                            </div>
                             <div class="mb-3">
                                <label for="quantity" class="form-label">Quantité</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" required min="1">
                                <small class="form-text text-muted">Saisir une quantité positive (Ex: 5 pour ajouter 5, ou pour retirer 5 selon le type de mouvement).</small>
                            </div>
                             <div class="mb-3">
                                <label for="notes" class="form-label">Notes / Raison (Optionnel)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" <?php echo empty($products) ? 'disabled' : ''; ?>><i class="fas fa-plus-circle me-2"></i> Enregistrer le mouvement</button>
                             <?php if (empty($products)): ?>
                                <span class="text-danger"> (Ajoutez des produits pour activer le bouton)</span>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                 <div class="card">
                    <div class="card-header">
                        Historique des 50 derniers mouvements
                    </div>
                    <div class="card-body">
                        <table class="table table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ID Mouvement</th>
                                    <th>Produit</th>
                                    <th>Type</th>
                                    <th>Quantité</th>
                                    <th>Date</th>
                                    <th>Utilisateur</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($movements) > 0): ?>
                                    <?php foreach ($movements as $movement): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($movement['id']); ?></td>
                                        <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                        <td>
                                            <?php
                                                // Appliquer une classe CSS selon le type de mouvement
                                                $movement_class = (strpos($movement['movement_type'], 'Entree - ') === 0) ? 'movement-entry' : 'movement-exit';
                                            ?>
                                            <span class="<?php echo $movement_class; ?>">
                                                <?php echo htmlspecialchars($movement['movement_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($movement['quantity']); ?></td>
                                        <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($movement['movement_date']))); ?></td>
                                        <td><?php echo htmlspecialchars($movement['username'] ?? 'Système'); ?></td> <td><?php echo nl2br(htmlspecialchars($movement['notes'] ?? 'N/A')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">Aucun mouvement de stock enregistré.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                         <small class="form-text text-muted">Affichage limité aux 50 derniers mouvements.</small>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>