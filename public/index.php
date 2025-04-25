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

// --- LOGIQUE PHP POUR LE TABLEAU DE BORD ET LES ALERTES ---

$error_message = ''; // Pour gérer d'éventuelles erreurs de chargement

// --- Récupérer les statistiques clés ---
$total_products = 0;
$low_stock_products_count = 0;
$sales_today_count = 0;
$sales_today_amount = 0;

try {
    // Total nombre de produits
    $sql_total_products = "SELECT COUNT(*) FROM products";
    $stmt_total_products = $pdo->query($sql_total_products);
    $total_products = $stmt_total_products->fetchColumn();

    // Nombre de produits sous le seuil d'alerte
    $sql_low_stock = "SELECT COUNT(*) FROM products WHERE current_stock <= alert_threshold AND alert_threshold > 0";
    $stmt_low_stock = $pdo->query($sql_low_stock);
    $low_stock_products_count = $stmt_low_stock->fetchColumn();

    // Ventes d'aujourd'hui (nombre et montant total)
    // Assurez-vous que la colonne sale_date est bien un TIMESTAMP
    $sql_sales_today = "SELECT COUNT(*), SUM(final_amount) FROM sales WHERE DATE(sale_date) = CURDATE()";
    $stmt_sales_today = $pdo->query($sql_sales_today);
    $today_sales_data = $stmt_sales_today->fetch(PDO::FETCH_NUM); // Récupère les résultats sous forme de tableau indexé
    $sales_today_count = $today_sales_data[0] ?? 0;
    $sales_today_amount = $today_sales_data[1] ?? 0.00;


} catch (\PDOException $e) {
    $error_message = "Erreur lors du chargement des statistiques : " . $e->getMessage();
}


// --- Récupérer la liste des produits sous le seuil d'alerte ---
$low_stock_products = [];
if ($low_stock_products_count > 0) { // Charger les détails seulement s'il y en a
    try {
         $sql_low_stock_details = "SELECT id, name, current_stock, alert_threshold, location
                                   FROM products
                                   WHERE current_stock <= alert_threshold AND alert_threshold > 0
                                   ORDER BY name ASC";
         $stmt_low_stock_details = $pdo->query($sql_low_stock_details);
         $low_stock_products = $stmt_low_stock_details->fetchAll();
    } catch (\PDOException $e) {
         // Gérer l'erreur de chargement des détails des produits en alerte
         $error_message = $error_message ?: "Erreur lors du chargement des produits en alerte : " . $e->getMessage();
    }
}


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Système Quincaillerie</title>
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
         .card-stats .card-body {
             text-align: center;
         }
         .card-stats .card-body i {
             font-size: 2em;
             margin-bottom: 10px;
         }
         .card-alert {
             border-color: #ffc107; /* Couleur jaune d'alerte de Bootstrap */
         }
         .card-alert .card-header {
             background-color: #ffc107;
             color: #343a40; /* Couleur sombre pour le texte sur fond jaune */
             font-weight: bold;
         }
         .low-stock-item {
             border-bottom: 1px solid #eee;
             padding: 5px 0;
         }
         .low-stock-item:last-child {
             border-bottom: none;
         }
    </style>
</head>
<body>

    <div class="sidebar">
        <h4 class="text-center mb-4">Quincaillerie</h4>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="index.php"><i class="fas fa-chart-line me-2"></i> Tableau de Bord</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="products.php"><i class="fas fa-box me-2"></i> Produits</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a> </li>
             <li class="nav-item">
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a> </li>
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
            <!-- Futur emplacement pour les alertes, notifications rapides etc. -->
        </div>

        <h1>Tableau de Bord</h1>

        <?php
        // Afficher les messages d'erreur (si le chargement des stats a échoué)
        if (!empty($error_message)) {
            echo '<div class="alert alert-danger" role="alert">' . $error_message . '</div>';
        }
        ?>

        <!-- Section Statistiques Clés -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card card-stats text-primary">
                    <div class="card-body">
                        <i class="fas fa-box"></i>
                        <h5 class="card-title"><?php echo htmlspecialchars($total_products); ?></h5>
                        <p class="card-text">Produits Totaux</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-stats text-danger">
                    <div class="card-body">
                         <i class="fas fa-boxes"></i>
                        <h5 class="card-title"><?php echo htmlspecialchars($low_stock_products_count); ?></h5>
                        <p class="card-text">Produits Stock Bas</p>
                    </div>
                </div>
            </div>
             <div class="col-md-3">
                <div class="card card-stats text-success">
                    <div class="card-body">
                         <i class="fas fa-cash-register"></i>
                        <h5 class="card-title"><?php echo htmlspecialchars($sales_today_count); ?></h5>
                        <p class="card-text">Ventes Aujourd'hui</p>
                    </div>
                </div>
            </div>
             <div class="col-md-3">
                <div class="card card-stats text-info">
                    <div class="card-body">
                        <i class="fas fa-dollar-sign"></i>
                        <h5 class="card-title"><?php echo htmlspecialchars(number_format($sales_today_amount, 2, ',', ' ')); ?></h5>
                        <p class="card-text">Montant Ventes Aujourd'hui (FCFA)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section Alertes Stock Bas -->
        <?php if ($low_stock_products_count > 0): ?>
            <div class="card border-warning mb-4 card-alert">
                <div class="card-header">
                    <i class="fas fa-exclamation-triangle me-2"></i> Alertes Stock Bas
                </div>
                <div class="card-body">
                    <p>Les produits suivants sont sous leur seuil d'alerte :</p>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($low_stock_products as $product): ?>
                            <li class="list-group-item low-stock-item">
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong> (Stock actuel : <?php echo htmlspecialchars($product['current_stock']); ?> | Seuil : <?php echo htmlspecialchars($product['alert_threshold']); ?>)
                                 <?php if (!empty($product['location'])): ?>
                                     <small class="text-muted ms-3">Localisation : <?php echo htmlspecialchars($product['location']); ?></small>
                                 <?php endif; ?>
                                 <a href="products.php?action=edit&id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-warning float-end">Gérer le stock</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php else: ?>
             <div class="alert alert-success mb-4" role="alert">
                <i class="fas fa-check-circle me-2"></i> Aucun produit n'est actuellement sous son seuil d'alerte. Tout va bien !
             </div>
        <?php endif; ?>


        <!-- Futures sections : Produits les plus vendus, entrées/sorties récentes, graphiques -->
        <div class="row">
             <div class="col-md-6">
                 <div class="card mb-4">
                     <div class="card-header">
                         Produits les plus vendus (Aujourd'hui)
                     </div>
                     <div class="card-body">
                         <p> (Fonctionnalité à implémenter plus tard - nécessite des requêtes complexes)</p>
                         <!-- Graphique Chart.js ici -->
                     </div>
                 </div>
             </div>
              <div class="col-md-6">
                 <div class="card mb-4">
                     <div class="card-header">
                         Derniers Mouvements de Stock
                     </div>
                     <div class="card-body">
                          <p> (Fonctionnalité à implémenter plus tard - afficher les X derniers mouvements)</p>
                          <!-- Liste ou tableau des derniers mouvements -->
                     </div>
                 </div>
             </div>
        </div>
        <!-- Section Graphiques (à implémenter) -->


    </div>

    <!-- Intégration de Bootstrap JS (Bundle incluant Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Votre propre JS -->
    <script src="assets/js/script.js"></script>
    <!-- Chart.js (pour les graphiques futurs) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
     <script>
         // Script Chart.js pour les graphiques (à développer)
         // Exemple de structure pour un graphique simple
        //  const ctx = document.getElementById('myChart').getContext('2d');
        //  const myChart = new Chart(ctx, {
        //      type: 'bar',
        //      data: { ... },
        //      options: { ... }
        //  });
     </script>

</body>
</html>