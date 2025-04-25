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

// Optionnel mais recommandé : Vérifier si l'utilisateur a le rôle Administrateur (ID 1)
if (get_current_user_role_id() != 1) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA GESTION DES CATÉGORIES (Suite) ---

$success_message = '';
$error_message = '';
$edit_category = null; // Variable pour stocker les données de la catégorie en cours de modification

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


// --- Gérer l'ajout d'une nouvelle catégorie (déjà présent, juste s'assurer qu'il est avant le traitement de modification) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['name'] ?? '');
    $category_description = trim($_POST['description'] ?? '');

    if (empty($category_name)) {
        $error_message = "Le nom de la catégorie est obligatoire.";
    } else {
        try {
            $sql_check = "SELECT COUNT(*) FROM categories WHERE name = :name";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':name' => $category_name]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Une catégorie avec ce nom existe déjà.";
            } else {
                $sql_insert = "INSERT INTO categories (name, description) VALUES (:name, :description)";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    ':name' => $category_name,
                    ':description' => !empty($category_description) ? $category_description : null
                ]);
                // Redirection post-POST pour éviter la resoumission du formulaire
                header('Location: categories.php?success=' . urlencode("Catégorie '" . htmlspecialchars($category_name) . "' ajoutée avec succès !"));
                exit;
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de l'ajout de la catégorie : " . $e->getMessage();
        }
    }
}

// --- Gérer la suppression d'une catégorie ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $category_id = filter_var($_GET['id'], FILTER_VALIDATE_INT); // Valider que l'ID est un entier

    if ($category_id === false) {
        // ID invalide
        header('Location: categories.php?error=' . urlencode("ID de catégorie invalide."));
        exit;
    }

    try {
        // TODO: IMPORTANT ! Avant de supprimer une catégorie, vérifier si elle est utilisée par des produits.
        // Pour l'instant, on supprime directement, mais dans un vrai système, il faudrait empêcher la suppression
        // si la catégorie a des produits associés, ou proposer de réaffecter les produits.
        // La contrainte ON DELETE RESTRICT dans la table `products` (quand on l'ajoutera) gérera cela au niveau DB.

        $sql_delete = "DELETE FROM categories WHERE id = :id";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':id' => $category_id]);

        if ($stmt_delete->rowCount() > 0) {
            header('Location: categories.php?success=' . urlencode("Catégorie supprimée avec succès !"));
            exit;
        } else {
            header('Location: categories.php?error=' . urlencode("Catégorie non trouvée ou déjà supprimée."));
            exit;
        }

    } catch (\PDOException $e) {
         // Gérer l'erreur si la catégorie est liée à des produits (si la contrainte ON DELETE RESTRICT est active)
         if ($e->getCode() === '23000') { // Code d'erreur pour violation de contrainte d'intégrité
             header('Location: categories.php?error=' . urlencode("Impossible de supprimer la catégorie car elle est utilisée par des produits."));
         } else {
             header('Location: categories.php?error=' . urlencode("Erreur lors de la suppression de la catégorie : " . $e->getMessage()));
         }
        exit;
    }
}

// --- Gérer l l'affichage du formulaire de modification ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
     $category_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

     if ($category_id === false) {
         $error_message = "ID de catégorie invalide pour modification.";
     } else {
         try {
             $sql_select_one = "SELECT id, name, description FROM categories WHERE id = :id LIMIT 1";
             $stmt_select_one = $pdo->prepare($sql_select_one);
             $stmt_select_one->execute([':id' => $category_id]);
             $edit_category = $stmt_select_one->fetch(); // Récupère les données de la catégorie

             if (!$edit_category) {
                 $error_message = "Catégorie non trouvée pour modification.";
             }
         } catch (\PDOException $e) {
             $error_message = "Erreur lors du chargement de la catégorie pour modification : " . $e->getMessage();
         }
     }
}

// --- Gérer la soumission du formulaire de modification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $category_id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $category_name = trim($_POST['name'] ?? '');
    $category_description = trim($_POST['description'] ?? '');

     if ($category_id === false || empty($category_name)) {
        $error_message = "Données de modification invalides.";
    } else {
        try {
            // Vérifier si une autre catégorie avec le même nom existe (sauf celle qu'on modifie)
            $sql_check = "SELECT COUNT(*) FROM categories WHERE name = :name AND id != :id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':name' => $category_name, ':id' => $category_id]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Une autre catégorie avec ce nom existe déjà.";
            } else {
                // Mettre à jour la catégorie dans la base de données
                $sql_update = "UPDATE categories SET name = :name, description = :description WHERE id = :id";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    ':name' => $category_name,
                    ':description' => !empty($category_description) ? $category_description : null,
                    ':id' => $category_id
                ]);

                // Redirection post-POST
                header('Location: categories.php?success=' . urlencode("Catégorie '" . htmlspecialchars($category_name) . "' mise à jour avec succès !"));
                exit;
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de la mise à jour de la catégorie : " . $e->getMessage();
        }
    }
}


// --- Récupérer toutes les catégories pour l'affichage (Assurez-vous que cette section est TOUJOURS exécutée pour afficher la liste) ---
// On réinitialise le message d'erreur après les traitements POST/GET si une redirection a eu lieu et qu'on gère les messages via GET
// Sinon, le message d'erreur d'une tentative POST ratée pourrait persister.
$categories = [];
try {
    $sql_select = "SELECT id, name, description FROM categories ORDER BY name ASC";
    $stmt_select = $pdo->query($sql_select);
    $categories = $stmt_select->fetchAll();
} catch (\PDOException $e) {
    // Si une erreur de chargement survient APRES une tentative POST/GET qui a déjà défini $error_message,
    // on pourrait écraser le message le plus pertinent. Une gestion plus fine serait de stocker
    // les messages d'erreurs/succès dans un tableau. Pour l'instant, on laisse tel quel, mais attention.
    // $error_message = "Erreur lors du chargement des catégories : " . $e->getMessage(); // Commenté pour éviter d'écraser d'autres erreurs
}

// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Catégories - Système Quincaillerie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
     <style>
        body {
            display: flex; /* Utiliser flexbox pour la mise en page principale */
            min-height: 100vh; /* Hauteur minimale de la page */
        }
        .sidebar {
            width: 250px; /* Largeur de la barre latérale */
            background-color: #343a40; /* Couleur de fond sombre */
            color: white;
            padding-top: 20px;
            flex-shrink: 0; /* Empêche la barre latérale de rétrécir */
        }
        .sidebar a {
            color: #adb5bd; /* Couleur du texte des liens */
            text-decoration: none;
            padding: 10px 15px;
            display: block;
        }
        .sidebar a:hover {
            background-color: #495057; /* Couleur de fond au survol */
            color: white;
        }
        .content {
            flex-grow: 1; /* Permet au contenu de prendre l'espace restant */
            padding: 20px;
        }
        .navbar-top {
            background-color: #e9ecef; /* Couleur de fond de la barre supérieure */
            padding: 10px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between; /* Aligne les éléments aux extrémités */
            align-items: center;
        }
        /* Style pour la section active dans le menu */
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #007bff; /* Couleur bleue de Bootstrap */
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
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a> </li>
             <li class="nav-item">
                <a class="nav-link" href="#"><i class="fas fa-file-alt me-2"></i> Rapports</a> </li>
             <?php if (get_current_user_role_id() == 1): // Si l'utilisateur est Admin (ID 1) ?>
             <li class="nav-item">
                <a class="nav-link" href="users.php"><i class="fas fa-users me-2"></i> Utilisateurs</a> </li>
            <li class="nav-item">
                <a class="nav-link active" href="categories.php"><i class="fas fa-tags me-2"></i> Catégories</a> </li>
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

        <h1>Gestion des Catégories</h1>

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
                <?php if ($edit_category): // Afficher le formulaire de modification si $edit_category est défini ?>
                     <div class="card mb-4">
                        <div class="card-header">
                            Modifier la catégorie "<?php echo htmlspecialchars($edit_category['name']); ?>"
                        </div>
                        <div class="card-body">
                            <form action="categories.php" method="POST">
                                <input type="hidden" name="update_category" value="1">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_category['id']); ?>">
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label">Nom de la catégorie</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_description" class="form-label">Description (Optionnel)</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="3"><?php echo htmlspecialchars($edit_category['description'] ?? ''); ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-success me-2"><i class="fas fa-save me-2"></i> Enregistrer les modifications</button>
                                <a href="categories.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i> Annuler</a>
                            </form>
                        </div>
                    </div>
                <?php else: // Sinon, afficher le formulaire d'ajout ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            Ajouter une nouvelle catégorie
                        </div>
                        <div class="card-body">
                            <form action="categories.php" method="POST">
                                <input type="hidden" name="add_category" value="1">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Nom de la catégorie</label>
                                    <input type="text" class="form-control" id="name" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description (Optionnel)</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-2"></i> Ajouter</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                 <div class="card">
                    <div class="card-header">
                        Liste des catégories
                    </div>
                    <div class="card-body">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($categories) > 0): ?>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($category['id']); ?></td>
                                        <td><?php echo htmlspecialchars($category['name']); ?></td>
                                        <td><?php echo htmlspecialchars($category['description'] ?? 'N/A'); ?></td>
                                        <td>
                                            <a href="categories.php?action=edit&id=<?php echo $category['id']; ?>" class="btn btn-sm btn-warning me-1" title="Modifier"><i class="fas fa-edit"></i></a>
                                            <a href="categories.php?action=delete&id=<?php echo $category['id']; ?>" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer la catégorie \'<?php echo htmlspecialchars(addslashes($category['name'])); ?>\' ?');"><i class="fas fa-trash-alt"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">Aucune catégorie trouvée.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>