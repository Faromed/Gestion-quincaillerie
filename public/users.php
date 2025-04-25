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

// Vérifier si l'utilisateur a le rôle Administrateur (ID 1) - Indispensable pour gérer les utilisateurs
if (get_current_user_role_id() != 1) {
    header('Location: index.php?error=unauthorized');
    exit;
}


// --- LOGIQUE PHP POUR LA GESTION DES UTILISATEURS (Suite) ---

$success_message = '';
$error_message = '';
$edit_user = null; // Variable pour stocker les données de l'utilisateur en cours de modification

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


// --- Gérer l'ajout d'un nouvel utilisateur (Le code précédent ici) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);

    if (empty($username) || empty($password) || $role_id === false) {
        $error_message = "Veuillez remplir tous les champs obligatoires (Nom d'utilisateur, Mot de passe, Rôle).";
    } else {
        try {
            // Vérifier si un utilisateur avec le même nom existe déjà
            $sql_check = "SELECT COUNT(*) FROM users WHERE username = :username";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':username' => $username]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Un utilisateur avec ce nom existe déjà.";
            } else {
                 // Vérifier si le role_id existe réellement dans la table roles
                 $sql_check_role = "SELECT COUNT(*) FROM roles WHERE id = :role_id";
                 $stmt_check_role = $pdo->prepare($sql_check_role);
                 $stmt_check_role->execute([':role_id' => $role_id]);
                 if ($stmt_check_role->fetchColumn() == 0) {
                     $error_message = "Le rôle sélectionné n'est pas valide.";
                 } else {
                    // Hacher le mot de passe
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insérer le nouvel utilisateur dans la base de données
                    $sql_insert = "INSERT INTO users (username, password, role_id) VALUES (:username, :password, :role_id)";
                    $stmt_insert = $pdo->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':username' => $username,
                        ':password' => $hashed_password,
                        ':role_id' => $role_id
                    ]);
                    // Redirection post-POST
                    header('Location: users.php?success=' . urlencode("Utilisateur '" . htmlspecialchars($username) . "' ajouté avec succès !"));
                    exit;
                 }
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de l'ajout de l'utilisateur : " . $e->getMessage();
        }
    }
     // Si une erreur s'est produite lors de l'ajout, assurez-vous que $edit_user reste null
     // pour ne pas afficher le formulaire de modification par erreur après une tentative d'ajout échouée.
    $edit_user = null;
}


// --- Gérer la suppression d'un utilisateur ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $user_id_to_delete = filter_var($_GET['id'], FILTER_VALIDATE_INT); // Valider que l'ID est un entier

    if ($user_id_to_delete === false) {
        // ID invalide
        header('Location: users.php?error=' . urlencode("ID utilisateur invalide."));
        exit;
    }

    // --- Vérification CRUCIALE : Empêcher la suppression de l'utilisateur actuellement connecté ---
    if ($user_id_to_delete == get_current_user_id()) {
         header('Location: users.php?error=' . urlencode("Vous ne pouvez pas supprimer votre propre compte."));
         exit;
    }

    try {
        // TODO: IMPORTANT ! Avant de supprimer un utilisateur, gérer ce qui se passe avec les données associées (ventes, mouvements de stock, etc.)
        // Pour l'instant, on supprime directement, ce qui peut causer des problèmes si des clés étrangères existent.
        // Dans un vrai système, il faudrait soit empêcher la suppression si des enregistrements y font référence,
        // soit rendre les relations NULL, soit réaffecter les données à un autre utilisateur (par exemple, un utilisateur "Supprimé").
        // Pour cette phase, nous allons simplement supprimer. Les contraintes FOREIGN KEY futures gèreront l'intégrité.

        $sql_delete = "DELETE FROM users WHERE id = :id";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':id' => $user_id_to_delete]);

        if ($stmt_delete->rowCount() > 0) {
            header('Location: users.php?success=' . urlencode("Utilisateur supprimé avec succès !"));
            exit;
        } else {
            header('Location: users.php?error=' . urlencode("Utilisateur non trouvé ou déjà supprimé."));
            exit;
        }

    } catch (\PDOException $e) {
         // Gérer les erreurs PDO, y compris les violations de contraintes futures
         if ($e->getCode() === '23000') { // Code d'erreur pour violation de contrainte d'intégrité
             header('Location: users.php?error=' . urlencode("Impossible de supprimer l'utilisateur car il est lié à d'autres données du système (ventes, etc.)."));
         } else {
            header('Location: users.php?error=' . urlencode("Erreur lors de la suppression de l'utilisateur : " . $e->getMessage()));
         }
        exit;
    }
}


// --- Gérer l l'affichage du formulaire de modification ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
     $user_id_to_edit = filter_var($_GET['id'], FILTER_VALIDATE_INT);

     if ($user_id_to_edit === false) {
         $error_message = "ID utilisateur invalide pour modification.";
     } else {
         try {
             // Récupérer les données de l'utilisateur à modifier
             $sql_select_one = "SELECT id, username, role_id FROM users WHERE id = :id LIMIT 1";
             $stmt_select_one = $pdo->prepare($sql_select_one);
             $stmt_select_one->execute([':id' => $user_id_to_edit]);
             $edit_user = $stmt_select_one->fetch(); // Stocke les données

             if (!$edit_user) {
                 $error_message = "Utilisateur non trouvé pour modification.";
             }
         } catch (\PDOException $e) {
             $error_message = "Erreur lors du chargement de l'utilisateur pour modification : " . $e->getMessage();
         }
     }
}


// --- Gérer la soumission du formulaire de modification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id_to_update = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $username = trim($_POST['username'] ?? ''); // On permet de modifier le nom d'utilisateur (avec vérif unique)
    $role_id = filter_var($_POST['role_id'] ?? '', FILTER_VALIDATE_INT);

     if ($user_id_to_update === false || empty($username) || $role_id === false) {
        $error_message = "Données de modification invalides.";
    } else {
        // --- Vérification CRUCIALE : Empêcher la modification du rôle de l'utilisateur actuellement connecté si cela pose problème ---
        // Dans ce cas précis (changer de rôle), ce n'est pas un gros risque pour l'admin, mais bonne pratique.
        // Si on permettait de désactiver un compte, cette vérification serait vitale.
         if ($user_id_to_update == get_current_user_id() && $role_id != 1) {
             // Exemple: si un admin tente de changer son propre rôle pour devenir non-admin
             // header('Location: users.php?error=' . urlencode("Vous ne pouvez pas changer votre propre rôle d'administrateur."));
             // exit;
             // Pour l'instant, on permet à l'admin de changer son propre rôle s'il le souhaite.
         }


        try {
            // Vérifier si un AUTRE utilisateur avec le même nom existe déjà
            $sql_check = "SELECT COUNT(*) FROM users WHERE username = :username AND id != :id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':username' => $username, ':id' => $user_id_to_update]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Un autre utilisateur avec ce nom existe déjà.";
            } else {
                 // Vérifier si le nouveau role_id existe réellement dans la table roles
                 $sql_check_role = "SELECT COUNT(*) FROM roles WHERE id = :role_id";
                 $stmt_check_role = $pdo->prepare($sql_check_role);
                 $stmt_check_role->execute([':role_id' => $role_id]);
                 if ($stmt_check_role->fetchColumn() == 0) {
                     $error_message = "Le rôle sélectionné n'est pas valide.";
                 } else {
                    // Mettre à jour l'utilisateur dans la base de données (username et role_id)
                    $sql_update = "UPDATE users SET username = :username, role_id = :role_id WHERE id = :id";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([
                        ':username' => $username,
                        ':role_id' => $role_id,
                        ':id' => $user_id_to_update
                    ]);

                    // Si l'utilisateur connecté a modifié SON PROPRE RÔLE, il faut mettre à jour la variable de session
                    if ($user_id_to_update == get_current_user_id()) {
                         $_SESSION['role_id'] = $role_id; // Important pour que la sidebar et les permissions se mettent à jour
                    }


                    // Redirection post-POST
                    header('Location: users.php?success=' . urlencode("Utilisateur '" . htmlspecialchars($username) . "' mis à jour avec succès !"));
                    exit;
                 }
            }
        } catch (\PDOException $e) {
            $error_message = "Erreur lors de la mise à jour de l'utilisateur : " . $e->getMessage();
        }
    }
     // Si une erreur s'est produite lors de la modification, réaffiche le formulaire de modification avec les données POST
     // Cela permet à l'utilisateur de corriger sans tout perdre.
     // On crée un objet temporaire pour pré-remplir le formulaire
     $edit_user = [
         'id' => $user_id_to_update,
         'username' => $username,
         'role_id' => $role_id
         // Note : Le mot de passe n'est pas inclus ici car on ne le modifie pas via ce formulaire
     ];
}


// --- Récupérer tous les utilisateurs et leurs rôles pour l'affichage (Ce bloc doit rester à la fin pour garantir l'affichage de la liste) ---
// On réinitialise les messages d'erreur/succès si on vient d'une redirection pour éviter de les afficher doublement
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['success']) || isset($_GET['error']))) {
    // Les messages sont déjà gérés en haut, on ne fait rien ici.
    // Ce commentaire sert juste à clarifier que ce bloc n'écrase pas les messages GET.
} else {
    // Si la requête n'est pas une redirection POST, on vide les messages après les traitements GET/POST
     $success_message = $success_message; // Juste pour être explicite, garde le message GET
     $error_message = $error_message; // Juste pour être explicite, garde le message GET
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error_message)) {
         // Si c'est une erreur POST qui n'a PAS mené à une redirection (ex: validation échouée), on garde le message
         // Si c'est un succès POST qui a mené à une redirection, $success_message/error_message seront pris depuis GET
     } else if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
         // Si c'est un succès POST qui a mené à une redirection, on vide les messages ici car ils sont déjà dans GET
         $success_message = '';
         $error_message = '';
     }
}


$users = [];
try {
    $sql_select = "SELECT u.id, u.username, u.role_id, r.name as role_name
                   FROM users u
                   JOIN roles r ON u.role_id = r.id
                   ORDER BY u.username ASC";
    $stmt_select = $pdo->query($sql_select);
    $users = $stmt_select->fetchAll();
} catch (\PDOException $e) {
    // Cette erreur ne devrait pas écraser une erreur plus spécifique d'ajout/mod/suppr
    // if (empty($error_message)) { $error_message = "Erreur lors du chargement des utilisateurs : " . $e->getMessage(); }
}

// --- Récupérer la liste des rôles pour les selects du formulaire ---
$roles = [];
try {
    $sql_roles = "SELECT id, name FROM roles ORDER BY name ASC";
    $stmt_roles = $pdo->query($sql_roles);
    $roles = $stmt_roles->fetchAll();
} catch (\PDOException $e) {
     // Gérer l'erreur de chargement des rôles si nécessaire
}


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - Système Quincaillerie</title>
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
                <a class="nav-link active" href="users.php"><i class="fas fa-users me-2"></i> Utilisateurs</a> </li>
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

        <h1>Gestion des Utilisateurs</h1>

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
                 <?php if ($edit_user): // Afficher le formulaire de modification si $edit_user est défini ?>
                      <div class="card mb-4">
                        <div class="card-header">
                            Modifier l'utilisateur "<?php echo htmlspecialchars($edit_user['username']); ?>"
                        </div>
                        <div class="card-body">
                            <form action="users.php" method="POST">
                                <input type="hidden" name="update_user" value="1">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_user['id']); ?>">
                                <div class="mb-3">
                                    <label for="edit_username" class="form-label">Nom d'utilisateur</label>
                                    <input type="text" class="form-control" id="edit_username" name="username" value="<?php echo htmlspecialchars($edit_user['username']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_role_id" class="form-label">Rôle</label>
                                    <select class="form-select" id="edit_role_id" name="role_id" required>
                                         <option value="">-- Sélectionner un rôle --</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role['id']); ?>"
                                                <?php echo ($edit_user['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($role['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                 <button type="submit" class="btn btn-success me-2"><i class="fas fa-save me-2"></i> Enregistrer les modifications</button>
                                <a href="users.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i> Annuler</a>
                            </form>
                        </div>
                    </div>
                 <?php else: // Sinon, afficher le formulaire d'ajout ?>
                 <div class="card mb-4">
                    <div class="card-header">
                        Ajouter un nouvel utilisateur
                    </div>
                    <div class="card-body">
                        <form action="users.php" method="POST">
                            <input type="hidden" name="add_user" value="1">
                            <div class="mb-3">
                                <label for="username" class="form-label">Nom d'utilisateur</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                             <div class="mb-3">
                                <label for="password" class="form-label">Mot de passe</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <div class="mb-3">
                                <label for="role_id" class="form-label">Rôle</label>
                                <select class="form-select" id="role_id" name="role_id" required>
                                    <option value="">-- Sélectionner un rôle --</option>
                                     <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo htmlspecialchars($role['id']); ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                     <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-2"></i> Ajouter l'utilisateur</button>
                        </form>
                    </div>
                </div>
                 <?php endif; ?>
            </div>
            <div class="col-md-7">
                 <div class="card">
                    <div class="card-header">
                        Liste des utilisateurs
                    </div>
                    <div class="card-body">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom d'utilisateur</th>
                                    <th>Rôle</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($users) > 0): ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                        <td>
                                            <a href="users.php?action=edit&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning me-1" title="Modifier"><i class="fas fa-edit"></i></a>
                                             <?php if ($user['id'] != get_current_user_id()): // Empêcher la suppression de l'utilisateur actuellement connecté ?>
                                                <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer l\'utilisateur \'<?php echo htmlspecialchars(addslashes($user['username'])); ?>\' ?');"><i class="fas fa-trash-alt"></i></a>
                                             <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">Aucun utilisateur trouvé.</td>
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