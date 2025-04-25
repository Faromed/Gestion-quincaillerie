<?php
// Démarre la session PHP
session_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../app/config/db.php'; // Inclut la connexion DB et la constante TAX_RATE
require_once __DIR__ . '/../app/includes/functions.php';

// Si l'utilisateur n'est PAS connecté, le rediriger vers la page de connexion
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Vendeur)
$allowed_roles = [1, 2];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA PAGE DE VENTES ---
// Initialiser les messages une seule fois
$success_message = '';
$error_message = '';

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// --- Nouveau bloc pour gérer le succès de la vente et le lien facture ---
// Ce bloc vient APRÈS les vérifications précédentes pour ne pas être écrasé
if (isset($_GET['success_sale_id'])) {
    $sale_id_for_link = filter_var($_GET['success_sale_id'], FILTER_VALIDATE_INT);
    if ($sale_id_for_link !== false) {
        $success_message = "Vente n°" . htmlspecialchars($sale_id_for_link) . " finalisée avec succès ! "
                         . '<a href="generate_invoice.php?sale_id=' . htmlspecialchars($sale_id_for_link) . '" target="_blank" class="alert-link btn btn-success text-light">Générer la facture PDF</a>';
    } else {
         $error_message = "Vente finalisée, mais ID de vente invalide pour générer la facture.";
    }
}

// --- Gérer l'ajout d'un produit au panier ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
    $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($product_id === false || $quantity === false) {
        $error_message = "Sélection de produit ou quantité invalide.";
    } else {
        try {
            $sql = "SELECT id, name, sale_price, current_stock FROM products WHERE id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $product_id]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Produit introuvable.";
            } elseif ($product['current_stock'] < $quantity) {
                $error_message = "Stock insuffisant pour le produit '" . htmlspecialchars($product['name']) . "'. Stock disponible : " . $product['current_stock'];
            } else {
                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }

                $cart_item_key = $product['id'];

                if (isset($_SESSION['cart'][$cart_item_key])) {
                     $new_quantity = $_SESSION['cart'][$cart_item_key]['quantity'] + $quantity;
                     if ($product['current_stock'] < $new_quantity) {
                         $error_message = "L'ajout de cette quantité dépasse le stock disponible pour '" . htmlspecialchars($product['name']) . "'. Stock disponible : " . $product['current_stock'];
                     } else {
                        $_SESSION['cart'][$cart_item_key]['quantity'] = $new_quantity;
                        $_SESSION['cart'][$cart_item_key]['line_total'] = $_SESSION['cart'][$cart_item_key]['quantity'] * $_SESSION['cart'][$cart_item_key]['unit_price'];
                        $success_message = "Quantité pour '" . htmlspecialchars($product['name']) . "' mise à jour dans le panier.";
                     }
                } else {
                    $_SESSION['cart'][$cart_item_key] = [
                        'product_id' => $product['id'],
                        'name' => $product['name'],
                        'quantity' => $quantity,
                        'unit_price' => $product['sale_price'],
                        'line_total' => $quantity * $product['sale_price'],
                        'current_stock' => $product['current_stock']
                    ];
                    $success_message = "'" . htmlspecialchars($product['name']) . "' ajouté au panier.";
                }
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de l'ajout au panier : " . $e->getMessage();
        }
    }
    
    // Corriger la redirection avec les paramètres de requête
    $redirect_url = 'sales.php';
    $params = [];
    if (!empty($success_message)) {
        $params['success'] = $success_message;
    }
    if (!empty($error_message)) {
        $params['error'] = $error_message;
    }
    if (!empty($params)) {
        $redirect_url .= '?' . http_build_query($params);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// --- Gérer la suppression d'un article du panier ---
if (isset($_GET['action']) && $_GET['action'] === 'remove_item' && isset($_GET['product_id'])) {
    $product_id_to_remove = filter_var($_GET['product_id'], FILTER_VALIDATE_INT);

    if ($product_id_to_remove !== false && isset($_SESSION['cart'][$product_id_to_remove])) {
        $product_name = $_SESSION['cart'][$product_id_to_remove]['name'];
        unset($_SESSION['cart'][$product_id_to_remove]);

        $success_message = "'" . htmlspecialchars($product_name) . "' retiré du panier.";
    } else {
        $error_message = "Article non trouvé dans le panier.";
    }
    
    // Même correction de redirection
    $redirect_url = 'sales.php';
    $params = [];
    if (!empty($success_message)) {
        $params['success'] = $success_message;
    }
    if (!empty($error_message)) {
        $params['error'] = $error_message;
    }
    if (!empty($params)) {
        $redirect_url .= '?' . http_build_query($params);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// --- Gérer l'annulation de la vente (vider le panier) ---
if (isset($_GET['action']) && $_GET['action'] === 'clear_cart') {
    unset($_SESSION['cart']);
    header('Location: sales.php?success=' . urlencode("Vente annulée. Le panier a été vidé."));
    exit;
}

// --- Gérer la soumission de la vente (finalisation) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_sale'])) {
    // Vérifier si le panier n'est pas vide
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
        $error_message = "Le panier est vide. Impossible de finaliser la vente.";
    } else {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // --- Étape 1: Re-vérifier le stock juste avant de finaliser ---
        $stock_check_ok = true;
        $products_to_check = array_keys($_SESSION['cart']); // IDs des produits dans le panier
        $placeholders = rtrim(str_repeat('?,', count($products_to_check)), ',');
        $sql_current_stocks = "SELECT id, name, current_stock FROM products WHERE id IN ($placeholders)";
        $stmt_current_stocks = $pdo->prepare($sql_current_stocks);
        $stmt_current_stocks->execute($products_to_check);
        $current_stocks_db = $stmt_current_stocks->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC); // Fetch by ID

        foreach ($_SESSION['cart'] as $item_id => $item) {
            if (!isset($current_stocks_db[$item_id]) || $current_stocks_db[$item_id]['current_stock'] < $item['quantity']) {
                $error_message = "Stock insuffisant pour le produit '" . htmlspecialchars($item['name']) . "'. Stock disponible : " . ($current_stocks_db[$item_id]['current_stock'] ?? 0);
                $stock_check_ok = false;
                break; // Arrêter la vérification dès qu'un stock est insuffisant
            }
        }

        if ($stock_check_ok) {
            // --- Étape 2: Calculer les totaux finaux ---
            $sale_subtotal = 0;
            foreach ($_SESSION['cart'] as $item) {
                $sale_subtotal += $item['line_total'];
            }
            $sale_discount = 0; // Pour l'instant, pas de remise
            $sale_tax = $sale_subtotal * TAX_RATE;
            $sale_final_total = $sale_subtotal + $sale_tax - $sale_discount;

            // --- Étape 3: Démarrer la transaction et enregistrer en base de données ---
            try {
                $pdo->beginTransaction();

                // Insérer la vente principale
                $sql_insert_sale = "INSERT INTO sales (total_amount, tax_amount, discount_amount, final_amount, user_id, customer_name, notes)
                                    VALUES (:total_amount, :tax_amount, :discount_amount, :final_amount, :user_id, :customer_name, :notes)";
                $stmt_insert_sale = $pdo->prepare($sql_insert_sale);
                $stmt_insert_sale->execute([
                    ':total_amount' => $sale_subtotal,
                    ':tax_amount' => $sale_tax,
                    ':discount_amount' => $sale_discount,
                    ':final_amount' => $sale_final_total,
                    ':user_id' => get_current_user_id(),
                    ':customer_name' => !empty($customer_name) ? $customer_name : null,
                    ':notes' => !empty($notes) ? $notes : null
                ]);

                $sale_id = $pdo->lastInsertId(); // Récupérer l'ID de la vente nouvellement insérée

                // Insérer les articles de la vente, mettre à jour le stock et enregistrer les mouvements
                foreach ($_SESSION['cart'] as $item) {
                    // Insérer l'article de la vente
                    $sql_insert_item = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, line_total)
                                        VALUES (:sale_id, :product_id, :quantity, :unit_price, :line_total)";
                    $stmt_insert_item = $pdo->prepare($sql_insert_item);
                    $stmt_insert_item->execute([
                        ':sale_id' => $sale_id,
                        ':product_id' => $item['product_id'],
                        ':quantity' => $item['quantity'],
                        ':unit_price' => $item['unit_price'],
                        ':line_total' => $item['line_total']
                    ]);

                    // Mettre à jour le stock du produit
                    $sql_update_stock = "UPDATE products SET current_stock = current_stock - :quantity WHERE id = :product_id";
                    $stmt_update_stock = $pdo->prepare($sql_update_stock);
                    $stmt_update_stock->execute([
                        ':quantity' => $item['quantity'],
                        ':product_id' => $item['product_id']
                    ]);

                    // Enregistrer le mouvement de stock (Sortie - Vente)
                    $sql_insert_movement = "INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id)
                                            VALUES (:product_id, 'Sortie - Vente', :quantity, :notes, :user_id)";
                    $stmt_insert_movement = $pdo->prepare($sql_insert_movement);
                    $movement_notes = "Vente #" . $sale_id . " (" . $item['quantity'] . " x " . $item['name'] . ")"; // Note descriptive
                    $stmt_insert_movement->execute([
                        ':product_id' => $item['product_id'],
                        ':quantity' => -$item['quantity'], // Quantité négative pour une sortie
                        ':notes' => $movement_notes,
                        ':user_id' => get_current_user_id()
                    ]);
                }

                // --- Commit la transaction ---
                $pdo->commit();

                // --- Étape 4: Vider le panier et rediriger ---
                unset($_SESSION['cart']);
                
                // Redirection post-POST avec message de succès et ID de vente pour la facture
                header('Location: sales.php?success_sale_id=' . $sale_id);
                exit;

            } catch (\PDOException $e) {
                // --- Étape 5: En cas d'erreur, rollback la transaction ---
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // N'écrase pas un message d'erreur de stock si il y en a déjà un
                if (empty($error_message)) {
                    $error_message = "Erreur lors de la finalisation de la vente : " . $e->getMessage();
                }
            }
        }
    }
    // Si une erreur s'est produite, on ne redirige pas pour conserver le panier et afficher l'erreur
}

// --- Calculer les totaux du panier ---
$cart_subtotal = 0;
$cart_tax = 0;
$cart_discount = 0; // Pour l'instant, pas de remise
$cart_final_total = 0;
$cart_items_count = 0;

if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_subtotal += $item['line_total'];
        $cart_items_count++;
    }
    $cart_tax = $cart_subtotal * TAX_RATE; // Calcul de la taxe
    $cart_final_total = $cart_subtotal + $cart_tax - $cart_discount;
}

// --- Récupérer la liste des produits pour le select du formulaire d'ajout ---
$products_for_select = [];
try {
    $sql_products = "SELECT id, name, sale_price, current_stock FROM products WHERE current_stock > 0 ORDER BY name ASC";
    $stmt_products = $pdo->query($sql_products);
    $products_for_select = $stmt_products->fetchAll();
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
    <title>Nouvelle Vente - Système Quincaillerie</title>
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
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 8px 0;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .cart-totals {
            margin-top: 20px;
            border-top: 2px solid #343a40;
            padding-top: 15px;
        }
        .cart-totals div {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .cart-totals .total {
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
                <a class="nav-link" href="products.php"><i class="fas fa-box me-2"></i> Produits</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a> </li>
            <li class="nav-item">
                <a class="nav-link active" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="#"><i class="fas fa-file-alt me-2"></i> Rapports</a> </li>
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

        <h1>Nouvelle Vente</h1>

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
                        Ajouter un produit à la vente
                    </div>
                    <div class="card-body">
                        <form action="sales.php" method="POST">
                            <input type="hidden" name="add_to_cart" value="1">
                            <div class="mb-3">
                                <label for="product_id" class="form-label">Produit</label>
                                <select class="form-select" id="product_id" name="product_id" required>
                                    <option value="">-- Sélectionner un produit --</option>
                                    <?php foreach ($products_for_select as $product): ?>
                                        <option value="<?php echo htmlspecialchars($product['id']); ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> (Stock: <?php echo htmlspecialchars($product['current_stock']); ?> | Prix: <?php echo htmlspecialchars(number_format($product['sale_price'], 2, ',', ' ') . ' FCFA'); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($products_for_select)): ?>
                                    <small class="form-text text-muted">Aucun produit en stock ou disponible à la vente.</small>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="quantity" class="form-label">Quantité</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" required min="1" value="1">
                            </div>
                            <button type="submit" class="btn btn-primary" <?php echo empty($products_for_select) ? 'disabled' : ''; ?>><i class="fas fa-cart-plus me-2"></i> Ajouter au panier</button>
                            <?php if (empty($products_for_select)): ?>
                                <span class="text-danger"> (Ajoutez des produits avec du stock pour vendre)</span>
                            <?php endif; ?>
                        </form>
                        <hr>
                        <h5>Scanner le code-barres</h5>
                        <p> (Fonctionnalité à implémenter plus tard)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Panier de la vente (<?php echo $cart_items_count; ?> article(s))
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                            <div class="cart-items-list">
                                <?php foreach ($_SESSION['cart'] as $item_key => $item): ?>
                                    <div class="cart-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                            <?php echo htmlspecialchars($item['quantity']); ?> x <?php echo htmlspecialchars(number_format($item['unit_price'], 2, ',', ' ') . ' FCFA'); ?>
                                        </div>
                                        <div>
                                            <?php echo htmlspecialchars(number_format($item['line_total'], 2, ',', ' ') . ' FCFA'); ?>
                                            <a href="sales.php?action=remove_item&product_id=<?php echo htmlspecialchars($item['product_id']); ?>" class="btn btn-sm btn-danger ms-2" title="Retirer"><i class="fas fa-times"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="cart-totals">
                                <div>
                                    <span>Sous-total :</span>
                                    <span><?php echo htmlspecialchars(number_format($cart_subtotal, 2, ',', ' ') . ' FCFA'); ?></span>
                                </div>
                                <div>
                                    <span>Taxe (<?php echo (TAX_RATE * 100); ?>%) :</span>
                                    <span><?php echo htmlspecialchars(number_format($cart_tax, 2, ',', ' ') . ' FCFA'); ?></span>
                                </div>
                                <?php if ($cart_discount > 0): ?>
                                <div>
                                    <span>Remise :</span>
                                    <span>- <?php echo htmlspecialchars(number_format($cart_discount, 2, ',', ' ') . ' FCFA'); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="total">
                                    <span>Total :</span>
                                    <span><?php echo htmlspecialchars(number_format($cart_final_total, 2, ',', ' ') . ' FCFA'); ?></span>
                                </div>
                            </div>

                            <div class="mt-4">
                                <form action="sales.php" method="POST">
                                    <input type="hidden" name="finalize_sale" value="1">
                                    <div class="mb-3">
                                        <label for="customer_name" class="form-label">Nom du client (Optionnel)</label>
                                        <input type="text" class="form-control" id="customer_name" name="customer_name">
                                    </div>
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notes de vente (Optionnel)</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-check-circle me-2"></i> Finaliser la Vente</button>
                                    <a href="sales.php?action=clear_cart" class="btn btn-danger w-100 mt-2" onclick="return confirm('Êtes-vous sûr de vouloir annuler cette vente ?');"><i class="fas fa-trash-alt me-2"></i> Annuler la Vente</a>
                                </form>
                            </div>

                        <?php else: ?>
                            <p class="text-center text-muted">Le panier est vide. Ajoutez des produits pour commencer une vente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>