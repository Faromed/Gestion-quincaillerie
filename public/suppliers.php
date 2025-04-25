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

// Vérifier si l'utilisateur a un rôle autorisé pour gérer les fournisseurs (Admin ou Stockiste)
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA GESTION DES FOURNISSEURS (Suite) ---

$success_message = '';
$error_message = '';
$edit_supplier = null; // Variable pour stocker les données du fournisseur en cours de modification

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// --- Gérer l'ajout d'un nouveau fournisseur (Le code précédent ici) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($name)) {
        $error_message = "Le nom du fournisseur est obligatoire.";
    } else {
        try {
            // Vérifier si un fournisseur avec le même nom existe déjà
            $sql_check = "SELECT COUNT(*) FROM suppliers WHERE name = :name";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':name' => $name]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Un fournisseur avec ce nom existe déjà.";
            } else {
                // Insérer le nouveau fournisseur
                $sql_insert = "INSERT INTO suppliers (name, contact_person, email, phone, address)
                               VALUES (:name, :contact_person, :email, :phone, :address)";
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->execute([
                    ':name' => $name,
                    ':contact_person' => !empty($contact_person) ? $contact_person : null,
                    ':email' => !empty($email) ? $email : null,
                    ':phone' => !empty($phone) ? $phone : null,
                    ':address' => !empty($address) ? $address : null
                ]);
                // Redirection post-POST
                header('Location: suppliers.php?success=' . urlencode("Fournisseur '" . htmlspecialchars($name) . "' ajouté avec succès !"));
                exit;
            }
        } catch (\PDOException $e) {
             if ($e->getCode() === '23000') {
                 $error_message = "Erreur : Un fournisseur avec ce nom existe déjà.";
             } else {
                $error_message = "Erreur lors de l'ajout du fournisseur : " . $e->getMessage();
             }
        }
    }
     // Si une erreur POST survient lors de l'ajout, ne redirige pas.
    $edit_supplier = null; // S'assurer qu'on reste sur le formulaire d'ajout
}


// --- Gérer la suppression d'un fournisseur ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $supplier_id_to_delete = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if ($supplier_id_to_delete === false) {
        header('Location: suppliers.php?error=' . urlencode("ID fournisseur invalide pour suppression."));
        exit;
    }

    try {
        // TODO: IMPORTANT ! Vérifier si le fournisseur est lié à des produits ou des commandes avant de supprimer.
        // Si oui, empêcher la suppression ou gérer la relation. Pour l'instant, on compte sur la contrainte DB si elle est là.

        $sql_delete = "DELETE FROM suppliers WHERE id = :id";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':id' => $supplier_id_to_delete]);

        if ($stmt_delete->rowCount() > 0) {
            header('Location: suppliers.php?success=' . urlencode("Fournisseur supprimé avec succès !"));
            exit;
        } else {
            header('Location: suppliers.php?error=' . urlencode("Fournisseur non trouvé ou déjà supprimé."));
            exit;
        }

    } catch (\PDOException $e) {
         if ($e->getCode() === '23000') { // Code d'erreur pour violation de contrainte d'intégrité
             header('Location: suppliers.php?error=' . urlencode("Impossible de supprimer le fournisseur car il est lié à d'autres données (produits, commandes, etc.)."));
         } else {
            header('Location: suppliers.php?error=' . urlencode("Erreur lors de la suppression du fournisseur : " . $e->getMessage()));
         }
        exit;
    }
}


// --- Gérer l'affichage du formulaire de modification ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
     $supplier_id_to_edit = filter_var($_GET['id'], FILTER_VALIDATE_INT);

     if ($supplier_id_to_edit === false) {
         $error_message = "ID fournisseur invalide pour modification.";
     } else {
         try {
             // Récupérer toutes les données du fournisseur à modifier
             $sql_select_one = "SELECT * FROM suppliers WHERE id = :id LIMIT 1";
             $stmt_select_one = $pdo->prepare($sql_select_one);
             $stmt_select_one->execute([':id' => $supplier_id_to_edit]);
             $edit_supplier = $stmt_select_one->fetch(); // Stocke les données

             if (!$edit_supplier) {
                 $error_message = "Fournisseur non trouvé pour modification.";
             }
         } catch (\PDOException $e) {
             $error_message = "Erreur lors du chargement du fournisseur pour modification : " . $e->getMessage();
         }
     }
}


// --- Gérer la soumission du formulaire de modification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_supplier'])) {
    $supplier_id_to_update = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

     if ($supplier_id_to_update === false || empty($name)) {
        $error_message = "Données de modification invalides. Le nom est obligatoire.";
    } else {
        try {
            // Vérifier si un AUTRE fournisseur avec le même nom existe déjà
            $sql_check = "SELECT COUNT(*) FROM suppliers WHERE name = :name AND id != :id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([':name' => $name, ':id' => $supplier_id_to_update]);
            if ($stmt_check->fetchColumn() > 0) {
                $error_message = "Un autre fournisseur avec ce nom existe déjà.";
            } else {
                // Mettre à jour le fournisseur
                $sql_update = "UPDATE suppliers
                               SET name = :name, contact_person = :contact_person, email = :email,
                                   phone = :phone, address = :address
                               WHERE id = :id";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([
                    ':name' => $name,
                    ':contact_person' => !empty($contact_person) ? $contact_person : null,
                    ':email' => !empty($email) ? $email : null,
                    ':phone' => !empty($phone) ? $phone : null,
                    ':address' => !empty($address) ? $address : null,
                    ':id' => $supplier_id_to_update
                ]);

                // Redirection post-POST
                header('Location: suppliers.php?success=' . urlencode("Fournisseur '" . htmlspecialchars($name) . "' mis à jour avec succès !"));
                exit;
            }
        } catch (\PDOException $e) {
             if ($e->getCode() === '23000') {
                 $error_message = "Erreur : Un autre fournisseur avec ce nom existe déjà.";
             } else {
                $error_message = "Erreur lors de la mise à jour du fournisseur : " . $e->getMessage();
             }
        }
    }
     // Si une erreur POST survient lors de la modification, réafficher le formulaire de modification avec les données soumises.
     // Pour cela, on remplit la variable $edit_supplier avec les données reçues par POST.
     // Attention : On ne réaffiche pas l'ID si l'ID POST était invalide.
     if ($supplier_id_to_update !== false) {
         $edit_supplier = [
             'id' => $supplier_id_to_update,
             'name' => $name,
             'contact_person' => $contact_person,
             'email' => $email,
             'phone' => $phone,
             'address' => $address,
         ];
     } else {
         $edit_supplier = null; // Si l'ID était invalide, on revient au formulaire d'ajout
     }
}


// --- Récupérer tous les fournisseurs pour l'affichage (Ce bloc doit rester à la fin) ---
// Gérer les messages après traitements GET/POST qui n'ont pas redirigé
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['success']) || isset($_GET['error']))) {
    // Les messages sont déjà gérés en haut
} else {
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error_message)) {
         // Garde le message d'erreur POST si pas de redirection
     } else {
         $success_message = '';
         $error_message = '';
     }
}

$suppliers = [];
try {
    $sql_select = "SELECT * FROM suppliers ORDER BY name ASC";
    $stmt_select = $pdo->query($sql_select);
    $suppliers = $stmt_select->fetchAll();
} catch (\PDOException $e) {
     if (empty($error_message)) {
         $error_message = "Erreur lors du chargement des fournisseurs : " . $e->getMessage();
     }
}


// --- Fin de la LOGIQUE PHP ---
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Fournisseurs - Système Quincaillerie</title>
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
                <a class="nav-link active" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a> </li>
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
            </div>

        <h1>Gestion des Fournisseurs</h1>

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
                 <?php if ($edit_supplier): // Afficher le formulaire de modification si $edit_supplier est défini ?>
                      <div class="card mb-4">
                        <div class="card-header">
                            Modifier le fournisseur "<?php echo htmlspecialchars($edit_supplier['name']); ?>"
                        </div>
                        <div class="card-body">
                            <form action="suppliers.php" method="POST">
                                <input type="hidden" name="update_supplier" value="1">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_supplier['id']); ?>">
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label">Nom du fournisseur</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" value="<?php echo htmlspecialchars($edit_supplier['name']); ?>" required>
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_contact_person" class="form-label">Contact (Optionnel)</label>
                                    <input type="text" class="form-control" id="edit_contact_person" name="contact_person" value="<?php echo htmlspecialchars($edit_supplier['contact_person'] ?? ''); ?>">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_email" class="form-label">Email (Optionnel)</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" value="<?php echo htmlspecialchars($edit_supplier['email'] ?? ''); ?>">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_phone" class="form-label">Téléphone (Optionnel)</label>
                                    <input type="text" class="form-control" id="edit_phone" name="phone" value="<?php echo htmlspecialchars($edit_supplier['phone'] ?? ''); ?>">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_address" class="form-label">Adresse (Optionnel)</label>
                                    <textarea class="form-control" id="edit_address" name="address" rows="3"><?php echo htmlspecialchars($edit_supplier['address'] ?? ''); ?></textarea>
                                </div>

                                <button type="submit" class="btn btn-success me-2"><i class="fas fa-save me-2"></i> Enregistrer les modifications</button>
                                <a href="suppliers.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i> Annuler</a>
                            </form>
                        </div>
                    </div>
                 <?php else: // Sinon, afficher le formulaire d'ajout ?>
                 <div class="card mb-4">
                    <div class="card-header">
                        Ajouter un nouveau fournisseur
                    </div>
                    <div class="card-body">
                        <form action="suppliers.php" method="POST">
                            <input type="hidden" name="add_supplier" value="1">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nom du fournisseur</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                             <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact (Optionnel)</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                            </div>
                             <div class="mb-3">
                                <label for="email" class="form-label">Email (Optionnel)</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                             <div class="mb-3">
                                <label for="phone" class="form-label">Téléphone (Optionnel)</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                             <div class="mb-3">
                                <label for="address" class="form-label">Adresse (Optionnel)</label>
                                <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-2"></i> Ajouter le fournisseur</button>
                        </form>
                    </div>
                </div>
                 <?php endif; ?>
            </div>
            <div class="col-md-7">
                 <div class="card">
                    <div class="card-header">
                        Liste des fournisseurs
                    </div>
                    <div class="card-body">
                        <table class="table table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom</th>
                                     <th>Contact</th>
                                    <th>Email</th>
                                    <th>Téléphone</th>
                                    <th>Adresse</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($suppliers) > 0): ?>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($supplier['id']); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                         <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($supplier['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo nl2br(htmlspecialchars($supplier['address'] ?? 'N/A')); ?></td>
                                        <td>
                                            <a href="suppliers.php?action=edit&id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-warning me-1" title="Modifier"><i class="fas fa-edit"></i></a>
                                            <a href="suppliers.php?action=delete&id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer le fournisseur \'<?php echo htmlspecialchars(addslashes($supplier['name'])); ?>\' ?');"><i class="fas fa-trash-alt"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center">Aucun fournisseur trouvé.</td>
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