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

// Vérifier si l'utilisateur a un rôle autorisé pour gérer les produits (Admin ou Stockiste)
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    header('Location: index.php?error=unauthorized');
    exit;
}

// --- LOGIQUE PHP POUR LA GESTION DES PRODUITS (Continuation avec Import) ---

$success_message = '';
$error_message = '';
$edit_product = null; // Variable pour stocker les données du produit en cours de modification

// Gérer les messages de succès/erreur passés via l'URL (après redirection)
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


// --- Gérer l'export de la liste actuelle de produits (CSV) ---
if (isset($_GET['action']) && $_GET['action'] === 'export_list_csv') {
    // Vérifier si l'utilisateur a les permissions (Admin ou Stockiste)
    if (!in_array(get_current_user_role_id(), $allowed_roles)) {
        die("Vous n'avez pas les permissions pour exporter la liste des produits.");
    }

   try {
       // Récupérer TOUTES les données des produits (ou filtrées si des filtres étaient appliqués sur la liste - non implémenté ici)
       $sql_select_export = "SELECT p.id, p.name, p.barcode, p.reference, p.category_id, c.name as category_name,
                            p.purchase_price, p.sale_price, p.current_stock, p.alert_threshold, p.location, p.image
                            FROM products p
                            JOIN categories c ON p.category_id = c.id
                            ORDER BY p.name ASC";
       $stmt_select_export = $pdo->query($sql_select_export);
       $products_export_data = $stmt_select_export->fetchAll();

       if (empty($products_export_data)) {
           die("Aucune donnée produit à exporter.");
       }

       // Nom du fichier CSV
       $filename = 'liste_produits_' . date('Ymd_His') . '.csv';

       // Entêtes HTTP pour forcer le téléchargement et définir le type MINE
       header('Content-Type: text/csv; charset=utf-8');
       header('Content-Disposition: attachment; filename="' . $filename . '"');

       // Ouvrir le flux de sortie
       $output = fopen('php://output', 'w');

       // Écrire les en-têtes de colonne (vous pouvez adapter les noms ici)
        $headers = array_keys($products_export_data[0]); // Utiliser les clés de la première ligne comme en-têtes
         // Adapter les noms d'en-têtes si nécessaire pour le CSV (similaire aux rapports)
          $display_headers = [];
          foreach($headers as $header) {
               $display_name = ucwords(str_replace(['_', 'id'], [' ', ' ID'], $header));
               $display_name = str_replace('Category Name', 'Catégorie', $display_name);
               $display_name = str_replace('Purchase Price', 'Prix Achat', $display_name);
               $display_name = str_replace('Sale Price', 'Prix Vente', $display_name);
               $display_name = str_replace('Current Stock', 'Stock Actuel', $display_name);
               $display_name = str_replace('Alert Threshold', 'Seuil Alerte', $display_name);
               $display_name = str_replace('Contact Person', 'Contact', $display_name); // Si on ajoute fournisseur lié au produit plus tard

               $display_headers[] = $display_name;
          }

       fputcsv($output, $display_headers, ';'); // Utiliser le point-virgule comme séparateur


       // Écrire les données des produits
       foreach ($products_export_data as $row) {
            $csv_row = [];
            foreach($row as $key => $value) {
                 // Formatage spécifique des valeurs pour le CSV si nécessaire
                 if (is_numeric($value) && (strpos($key, '_price') !== false)) {
                      $csv_row[] = number_format($value, 2, ',', ''); // Format prix CSV
                 } elseif ($key === 'current_stock' || $key === 'alert_threshold') {
                      $csv_row[] = $value; // Valeur brute
                 } else {
                       $csv_row[] = $value; // Valeur brute ou htmlspecialchars
                 }
            }
           fputcsv($output, $csv_row, ';'); // Écrire la ligne dans le CSV
       }

       // Fermer le flux
       fclose($output);

       exit(0); // Terminer le script après l'export
   } catch (\PDOException $e) {
       die("Erreur lors de l'export de la liste de produits : " . $e->getMessage());
   }
}

// --- Gérer l'ajout d'un nouveau produit (Code précédent ici) ---
// ... (Conservez le bloc de code pour l'ajout tel quel) ...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    // ... (votre code de gestion de l'ajout de produit) ...
     $name = trim($_POST['name'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
    $purchase_price = filter_var($_POST['purchase_price'] ?? '', FILTER_VALIDATE_FLOAT);
    $sale_price = filter_var($_POST['sale_price'] ?? '', FILTER_VALIDATE_FLOAT);
    $current_stock = filter_var($_POST['current_stock'] ?? '', FILTER_VALIDATE_INT);
    $alert_threshold = filter_var($_POST['alert_threshold'] ?? '', FILTER_VALIDATE_INT);
    $location = trim($_POST['location'] ?? '');

    $image_path = null;
    $upload_dir = __DIR__ . '/assets/img/products/';

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_buffer($file_info, file_get_contents($_FILES['image']['tmp_name']));
        finfo_close($file_info);

        $extension = array_search($mime_type, $allowed_types, true);

        if (false === $extension) {
            $error_message = "Type de fichier image invalide. Types autorisés : JPG, PNG, GIF.";
        } elseif ($_FILES['image']['size'] > $max_size) {
             $error_message = "L'image est trop grande (max 5MB).";
        } else {
            $file_name = uniqid() . "." . $extension;
            $destination = $upload_dir . $file_name;

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_path = 'assets/img/products/' . $file_name;
            } else {
                $error_message = "Erreur lors de l'upload de l'image.";
            }
        }
    }


    if (empty($name) || $category_id === false || $purchase_price === false || $sale_price === false || $current_stock === false || $alert_threshold === false) {
        $error_message = $error_message ?: "Veuillez remplir tous les champs obligatoires et vérifier les formats numériques.";
    } else {
        try {
            $sql_check = "SELECT COUNT(*) FROM products WHERE ";
            $conditions = [];
            $params = [];
            if (!empty($barcode)) { $conditions[] = "barcode = :barcode"; $params[':barcode'] = $barcode; }
            if (!empty($reference)) { $conditions[] = "reference = :reference"; $params[':reference'] = $reference; }

            if (!empty($conditions)) {
                 $sql_check .= implode(" OR ", $conditions);
                 $stmt_check = $pdo->prepare($sql_check);
                 $stmt_check->execute($params);
                 if ($stmt_check->fetchColumn() > 0) {
                     $error_message = "Le code-barres et/ou la référence existent déjà.";
                 }
            }

            if (empty($error_message)) {
                 $sql_check_cat = "SELECT COUNT(*) FROM categories WHERE id = :category_id";
                 $stmt_check_cat = $pdo->prepare($sql_check_cat);
                 $stmt_check_cat->execute([':category_id' => $category_id]);

                 if ($stmt_check_cat->fetchColumn() == 0) {
                     $error_message = "La catégorie sélectionnée n'est pas valide.";
                 } else {
                    $sql_insert = "INSERT INTO products (name, barcode, reference, category_id, purchase_price, sale_price, current_stock, alert_threshold, location, image)
                                   VALUES (:name, :barcode, :reference, :category_id, :purchase_price, :sale_price, :current_stock, :alert_threshold, :location, :image)";
                    $stmt_insert = $pdo->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':name' => $name,
                        ':barcode' => !empty($barcode) ? $barcode : null,
                        ':reference' => !empty($reference) ? $reference : null,
                        ':category_id' => $category_id,
                        ':purchase_price' => $purchase_price,
                        ':sale_price' => $sale_price,
                        ':current_stock' => $current_stock,
                        ':alert_threshold' => $alert_threshold,
                        ':location' => !empty($location) ? $location : null,
                        ':image' => $image_path
                    ]);
                    header('Location: products.php?success=' . urlencode("Produit '" . htmlspecialchars($name) . "' ajouté avec succès !"));
                    exit;
                 }
            }

        } catch (\PDOException $e) {
             if ($e->getCode() === '23000') {
                 $error_message = "Erreur : Le code-barres ou la référence existe déjà.";
             } else {
                $error_message = "Erreur lors de l'ajout du produit : " . $e->getMessage();
             }
        }
    }
}


// --- Gérer la suppression d'un produit (Code précédent ici) ---
// ... (Conservez le bloc de code pour la suppression tel quel) ...
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
     $product_id_to_delete = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if ($product_id_to_delete === false) {
        header('Location: products.php?error=' . urlencode("ID produit invalide pour suppression."));
        exit;
    }

    try {
        $sql_get_image = "SELECT image FROM products WHERE id = :id";
        $stmt_get_image = $pdo->prepare($sql_get_image);
        $stmt_get_image->execute([':id' => $product_id_to_delete]);
        $product_image = $stmt_get_image->fetchColumn();

        $sql_delete = "DELETE FROM products WHERE id = :id";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([':id' => $product_id_to_delete]);

        if ($stmt_delete->rowCount() > 0) {
            if ($product_image && file_exists(__DIR__ . '/assets/img/products/' . basename($product_image))) {
                 unlink(__DIR__ . '/assets/img/products/' . basename($product_image));
            }
            header('Location: products.php?success=' . urlencode("Produit supprimé avec succès !"));
            exit;
        } else {
            header('Location: products.php?error=' . urlencode("Produit non trouvé ou déjà supprimé."));
            exit;
        }

    } catch (\PDOException $e) {
         if ($e->getCode() === '23000') {
             header('Location: products.php?error=' . urlencode("Impossible de supprimer le produit car il est lié à d'autres données (stock, ventes, etc.)."));
         } else {
            header('Location: products.php?error=' . urlencode("Erreur lors de la suppression du produit : " . $e->getMessage()));
         }
        exit;
    }
}


// --- Gérer l'affichage du formulaire de modification (Code précédent ici) ---
// ... (Conservez le bloc de code pour l'affichage du formulaire de modification tel quel) ...
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
     $product_id_to_edit = filter_var($_GET['id'], FILTER_VALIDATE_INT);

     if ($product_id_to_edit === false) {
         $error_message = "ID produit invalide pour modification.";
     } else {
         try {
             $sql_select_one = "SELECT * FROM products WHERE id = :id LIMIT 1";
             $stmt_select_one = $pdo->prepare($sql_select_one);
             $stmt_select_one->execute([':id' => $product_id_to_edit]);
             $edit_product = $stmt_select_one->fetch();

             if (!$edit_product) {
                 $error_message = "Produit non trouvé pour modification.";
             }
         } catch (\PDOException $e) {
             $error_message = "Erreur lors du chargement du produit pour modification : " . $e->getMessage();
         }
     }
}


// --- Gérer la soumission du formulaire de modification (Code précédent ici) ---
// ... (Conservez le bloc de code pour la soumission du formulaire de modification tel quel) ...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $product_id_to_update = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
    $purchase_price = filter_var($_POST['purchase_price'] ?? '', FILTER_VALIDATE_FLOAT);
    $sale_price = filter_var($_POST['sale_price'] ?? '', FILTER_VALIDATE_FLOAT);
    $current_stock = filter_var($_POST['current_stock'] ?? '', FILTER_VALIDATE_INT);
    $alert_threshold = filter_var($_POST['alert_threshold'] ?? '', FILTER_VALIDATE_INT);
    $location = trim($_POST['location'] ?? '');
    $remove_image = isset($_POST['remove_image']);

    $old_image_path = null;
     if ($product_id_to_update !== false) {
        try {
             $sql_get_old_image = "SELECT image FROM products WHERE id = :id";
             $stmt_get_old_image = $pdo->prepare($sql_get_old_image);
             $stmt_get_old_image->execute([':id' => $product_id_to_update]);
             $old_image_path = $stmt_get_old_image->fetchColumn();
        } catch (\PDOException $e) {
             // silent error
        }
     }

    $image_path = $old_image_path;
    $upload_dir = __DIR__ . '/assets/img/products/';

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_buffer($file_info, file_get_contents($_FILES['image']['tmp_name']));
        finfo_close($file_info);

        $extension = array_search($mime_type, $allowed_types, true);

        if (false === $extension) {
            $error_message = "Type de fichier image invalide. Types autorisés : JPG, PNG, GIF.";
        } elseif ($_FILES['image']['size'] > $max_size) {
             $error_message = "La nouvelle image est trop grande (max 5MB).";
        } else {
            $file_name = uniqid() . "." . $extension;
            $destination = $upload_dir . $file_name;

             if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_path = 'assets/img/products/' . $file_name;
                if ($old_image_path && file_exists(__DIR__ . '/assets/img/products/' . basename($old_image_path))) {
                    unlink(__DIR__ . '/assets/img/products/' . basename($old_image_path));
                }
            } else {
                $error_message = "Erreur lors de l'upload de la nouvelle image.";
            }
        }
    } elseif ($remove_image) {
        if ($old_image_path && file_exists(__DIR__ . '/assets/img/products/' . basename($old_image_path))) {
            unlink(__DIR__ . '/assets/img/products/' . basename($old_image_path));
        }
        $image_path = null;
    }


    if ($product_id_to_update === false || empty($name) || $category_id === false || $purchase_price === false || $sale_price === false || $current_stock === false || $alert_threshold === false) {
         $error_message = $error_message ?: "Données de modification invalides. Veuillez vérifier tous les champs.";
    } else {
        try {
            $sql_check = "SELECT COUNT(*) FROM products WHERE (";
            $conditions = [];
            $params = [':id' => $product_id_to_update];
            if (!empty($barcode)) { $conditions[] = "barcode = :barcode"; $params[':barcode'] = $barcode; }
            if (!empty($reference)) { $conditions[] = "reference = :reference"; $params[':reference'] = $reference; }

            $has_unique_fields = !empty($conditions);

            if ($has_unique_fields) {
                 $sql_check .= implode(" OR ", $conditions) . ") AND id != :id";
                 $stmt_check = $pdo->prepare($sql_check);
                 $stmt_check->execute($params);
                 if ($stmt_check->fetchColumn() > 0) {
                     $error_message = "Le code-barres et/ou la référence existent déjà sur un autre produit.";
                 }
            }

            if (empty($error_message)) {
                 $sql_check_cat = "SELECT COUNT(*) FROM categories WHERE id = :category_id";
                 $stmt_check_cat = $pdo->prepare($sql_check_cat);
                 $stmt_check_cat->execute([':category_id' => $category_id]);

                 if ($stmt_check_cat->fetchColumn() == 0) {
                     $error_message = "La catégorie sélectionnée n'est pas valide.";
                 } else {
                    $sql_update = "UPDATE products
                                   SET name = :name, barcode = :barcode, reference = :reference,
                                       category_id = :category_id, purchase_price = :purchase_price,
                                       sale_price = :sale_price, current_stock = :current_stock,
                                       alert_threshold = :alert_threshold, location = :location,
                                       image = :image
                                   WHERE id = :id";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([
                        ':name' => $name,
                        ':barcode' => !empty($barcode) ? $barcode : null,
                        ':reference' => !empty($reference) ? $reference : null,
                        ':category_id' => $category_id,
                        ':purchase_price' => $purchase_price,
                        ':sale_price' => $sale_price,
                        ':current_stock' => $current_stock,
                        ':alert_threshold' => $alert_threshold,
                        ':location' => !empty($location) ? $location : null,
                        ':image' => $image_path,
                        ':id' => $product_id_to_update
                    ]);

                    header('Location: products.php?success=' . urlencode("Produit '" . htmlspecialchars($name) . "' mis à jour avec succès !"));
                    exit;
                 }
            }

        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                 $error_message = "Erreur : Le code-barres ou la référence existent déjà sur un autre produit.";
             } else {
                $error_message = "Erreur lors de la mise à jour du produit : " . $e->getMessage();
             }
        }
    }
     if ($product_id_to_update !== false) {
         $edit_product = [
             'id' => $product_id_to_update,
             'name' => $name,
             'barcode' => $barcode,
             'reference' => $reference,
             'category_id' => $category_id,
             'purchase_price' => $purchase_price,
             'sale_price' => $sale_price,
             'current_stock' => $current_stock,
             'alert_threshold' => $alert_threshold,
             'location' => $location,
             'image' => $old_image_path // IMPORTANT : on réaffiche l'ancienne image si l'upload a échoué ou n'a pas eu lieu
         ];
          if (isset($destination) && file_exists($destination) && empty($error_message)) {
               $edit_product['image'] = $image_path;
          }
     } else {
         $edit_product = null;
     }
}


// --- Gérer l'import de produits depuis un fichier CSV ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_products'])) {
     // Vérifier si l'utilisateur est Administrateur (seul rôle autorisé pour l'import)
     if (get_current_user_role_id() != 1) {
         $error_message = "Vous n'avez pas les permissions pour importer des produits.";
     } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {

         $file_mimes = array('text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel', 'text/plain');

         if (in_array($_FILES['csv_file']['type'], $file_mimes)) {
             $csv_file_path = $_FILES['csv_file']['tmp_name'];

             if (($handle = fopen($csv_file_path, "r")) !== FALSE) {
                 // Lire la première ligne pour les en-têtes et s'assurer qu'ils sont dans le bon ordre
                 $header = fgetcsv($handle, 1000, ";"); // Assumer le point-virgule comme séparateur CSV

                 // Définir les en-têtes attendus et optionnels
                 $required_headers = ['name', 'category_id', 'purchase_price', 'sale_price', 'current_stock', 'alert_threshold'];
                 $optional_headers = ['barcode', 'reference', 'location'];
                 $expected_headers = array_merge($required_headers, $optional_headers); // Ordre attendu

                 // Mapper les noms des en-têtes du fichier aux noms des colonnes DB (ignorer la casse et les espaces)
                 $header_map = [];
                 if ($header) {
                     foreach ($header as $index => $col_name) {
                         $clean_col_name = strtolower(trim(str_replace([' ', '-'], '_', $col_name)));
                         // Trouver l'index de l'en-tête attendu correspondant
                         $expected_index = array_search($clean_col_name, $expected_headers);
                         if ($expected_index !== false) {
                             $header_map[$expected_headers[$expected_index]] = $index; // Mapper le nom DB -> index colonne fichier
                         }
                     }
                 }

                 // Vérifier si tous les en-têtes requis sont présents
                 $missing_headers = array_diff($required_headers, array_keys($header_map));
                 if (!empty($missing_headers)) {
                     $error_message = "Fichier CSV invalide. En-têtes manquants : " . implode(', ', $missing_headers);
                 } else {

                     // --- Préparer les données nécessaires (Catégories pour validation) ---
                     $categories_map = []; // id -> true (pour vérif rapide si id existe)
                      $categories_name_to_id = []; // name -> id (si on veut permettre le nom de catégorie dans le CSV)
                     try {
                         $sql_cats = "SELECT id, name FROM categories";
                         $stmt_cats = $pdo->query($sql_cats);
                         while ($row = $stmt_cats->fetch()) {
                             $categories_map[$row['id']] = true;
                             $categories_name_to_id[strtolower(trim($row['name']))] = $row['id'];
                         }
                     } catch (\PDOException $e) {
                         $error_message = "Erreur lors du chargement des catégories pour la validation.";
                     }


                     if (empty($error_message)) {
                         // --- Traiter chaque ligne du CSV ---
                         $row_count = 0;
                         $imported_count = 0;
                         $skipped_count = 0;
                         $import_errors = []; // Tableau pour stocker les erreurs par ligne

                         $pdo->beginTransaction(); // Démarrer la transaction

                         try {
                             while (($row = fgetcsv($handle, 1000, ";")) !== FALSE) {
                                 $row_count++;
                                 if ($row_count == 1) continue; // Ignorer la ligne d'en-tête

                                 // Vérifier si la ligne est vide
                                  if (count($row) == 0 || count(array_filter($row)) == 0) {
                                      $skipped_count++;
                                      $import_errors[] = ['row' => $row_count, 'message' => "Ligne vide."];
                                      continue;
                                  }


                                 // Extraire et valider les données de la ligne
                                 $product_data = [];
                                 $row_errors = [];

                                 // Récupérer les données en utilisant la map d'en-têtes pour la robustesse
                                 $product_data['name'] = trim($row[$header_map['name']] ?? '');
                                 $product_data['barcode'] = trim($row[$header_map['barcode']] ?? '');
                                 $product_data['reference'] = trim($row[$header_map['reference']] ?? '');
                                 // Gérer category_id - on s'attend à un ID numérique
                                 $category_value_from_csv = trim($row[$header_map['category_id']] ?? '');
                                 $product_data['category_id'] = filter_var($category_value_from_csv, FILTER_VALIDATE_INT); // Tente de valider comme INT

                                 // Gérer les nombres (prix, stock, seuil)
                                 // Remplacer la virgule décimale par un point si nécessaire (pour les locales qui utilisent la virgule)
                                 $purchase_price_str = trim($row[$header_map['purchase_price']] ?? '');
                                 $sale_price_str = trim($row[$header_map['sale_price']] ?? '');
                                 $current_stock_str = trim($row[$header_map['current_stock']] ?? '');
                                 $alert_threshold_str = trim($row[$header_map['alert_threshold']] ?? '');

                                  $purchase_price_str = str_replace(',', '.', $purchase_price_str);
                                  $sale_price_str = str_replace(',', '.', $sale_price_str);
                                   $current_stock_str = str_replace(',', '.', $current_stock_str); // Pour les nombres entiers aussi au cas où
                                  $alert_threshold_str = str_replace(',', '.', $alert_threshold_str); // Pour les nombres entiers aussi au cas où


                                 $product_data['purchase_price'] = filter_var($purchase_price_str, FILTER_VALIDATE_FLOAT);
                                 $product_data['sale_price'] = filter_var($sale_price_str, FILTER_VALIDATE_FLOAT);
                                 $product_data['current_stock'] = filter_var($current_stock_str, FILTER_VALIDATE_INT);
                                 $product_data['alert_threshold'] = filter_var($alert_threshold_str, FILTER_VALIDATE_INT);

                                 $product_data['location'] = trim($row[$header_map['location']] ?? '');
                                  // TODO: Gérer l'image si le CSV inclut un chemin/nom de fichier image


                                 // Valider les champs requis et les formats
                                 if (empty($product_data['name'])) { $row_errors[] = "Nom du produit manquant ou vide."; }
                                 if ($product_data['category_id'] === false || !isset($categories_map[$product_data['category_id']])) {
                                      // Si category_id n'est pas un INT ou n'existe pas dans la DB
                                      // Alternative : Tenter de trouver la catégorie par nom si $category_value_from_csv n'est pas un INT valide
                                      if (empty($category_value_from_csv)) {
                                           $row_errors[] = "Catégorie manquante ou vide.";
                                      } else {
                                           // Tenter de trouver par nom
                                           $clean_cat_name = strtolower(trim($category_value_from_csv));
                                           if (isset($categories_name_to_id[$clean_cat_name])) {
                                               $product_data['category_id'] = $categories_name_to_id[$clean_cat_name]; // Utiliser l'ID trouvé par nom
                                           } else {
                                                $row_errors[] = "Catégorie invalide ou inexistante (ID ou Nom : '" . htmlspecialchars($category_value_from_csv) . "').";
                                           }
                                      }
                                 }
                                  if ($product_data['purchase_price'] === false || $product_data['purchase_price'] < 0) { $row_errors[] = "Prix d'achat invalide."; }
                                 if ($product_data['sale_price'] === false || $product_data['sale_price'] < 0) { $row_errors[] = "Prix de vente invalide."; }
                                 // Le stock initial peut être 0
                                 if ($product_data['current_stock'] === false || $product_data['current_stock'] < 0) { $row_errors[] = "Stock initial invalide."; }
                                  // Le seuil d'alerte peut être 0
                                 if ($product_data['alert_threshold'] === false || $product_data['alert_threshold'] < 0) { $row_errors[] = "Seuil d'alerte invalide."; }

                                 // Vérifier l'unicité du code-barres et de la référence s'ils sont fournis et valides
                                  $existing_product_id = null;
                                 if (empty($row_errors)) { // Seulement si les validations de format sont passées
                                     if (!empty($product_data['barcode'])) {
                                          $sql_check_barcode = "SELECT id FROM products WHERE barcode = :barcode LIMIT 1";
                                          $stmt_check_barcode = $pdo->prepare($sql_check_barcode);
                                          $stmt_check_barcode->execute([':barcode' => $product_data['barcode']]);
                                          $existing_product_id = $stmt_check_barcode->fetchColumn();
                                     }
                                      if ($existing_product_id === false && !empty($product_data['reference'])) { // Vérifier la référence seulement si pas trouvé par code-barres
                                         $sql_check_reference = "SELECT id FROM products WHERE reference = :reference LIMIT 1";
                                          $stmt_check_reference = $pdo->prepare($sql_check_reference);
                                          $stmt_check_reference->execute([':reference' => $product_data['reference']]);
                                           $existing_product_id = $stmt_check_reference->fetchColumn();
                                      }
                                 }


                                 if (!empty($row_errors)) {
                                     // S'il y a des erreurs dans la ligne, l'ignorer et enregistrer l'erreur
                                     $skipped_count++;
                                     $import_errors[] = ['row' => $row_count, 'message' => implode(", ", $row_errors)];
                                 } else {
                                      // La ligne est valide, insérer ou mettre à jour

                                      if ($existing_product_id) {
                                          // Mettre à jour le produit existant
                                           $sql_update = "UPDATE products
                                                          SET name = :name, category_id = :category_id, purchase_price = :purchase_price,
                                                              sale_price = :sale_price, current_stock = :current_stock,
                                                              alert_threshold = :alert_threshold, location = :location
                                                          WHERE id = :id";
                                           $stmt_update = $pdo->prepare($sql_update);
                                           $stmt_update->execute([
                                               ':name' => $product_data['name'],
                                               ':category_id' => $product_data['category_id'],
                                               ':purchase_price' => $product_data['purchase_price'],
                                               ':sale_price' => $product_data['sale_price'],
                                               ':current_stock' => $product_data['current_stock'],
                                               ':alert_threshold' => $product_data['alert_threshold'],
                                               ':location' => !empty($product_data['location']) ? $product_data['location'] : null,
                                               ':id' => $existing_product_id
                                           ]);
                                           $imported_count++; // Compter comme importé/mis à jour

                                      } else {
                                           // Insérer un nouveau produit
                                          $sql_insert = "INSERT INTO products (name, barcode, reference, category_id, purchase_price, sale_price, current_stock, alert_threshold, location)
                                                         VALUES (:name, :barcode, :reference, :category_id, :purchase_price, :sale_price, :current_stock, :alert_threshold, :location)";
                                           $stmt_insert = $pdo->prepare($sql_insert);
                                            $stmt_insert->execute([
                                                ':name' => $product_data['name'],
                                                ':barcode' => !empty($product_data['barcode']) ? $product_data['barcode'] : null,
                                                ':reference' => !empty($product_data['reference']) ? $product_data['reference'] : null,
                                                ':category_id' => $product_data['category_id'],
                                                ':purchase_price' => $product_data['purchase_price'],
                                                ':sale_price' => $product_data['sale_price'],
                                                ':current_stock' => $product_data['current_stock'],
                                                ':alert_threshold' => $product_data['alert_threshold'],
                                                ':location' => !empty($product_data['location']) ? $product_data['location'] : null,
                                           ]);
                                           $imported_count++; // Compter comme importé
                                      }
                                 }
                             }

                             // --- Commit la transaction ---
                             $pdo->commit();
                             $success_message = "Import terminé. Lignes traitées : " . $row_count . ", Importées/Mises à jour : " . $imported_count . ", Ignorées : " . $skipped_count;

                             if (!empty($import_errors)) {
                                 $error_message = "Des erreurs ont été rencontrées lors de l'import :<br>";
                                 foreach($import_errors as $err) {
                                     $error_message .= "Ligne " . htmlspecialchars($err['row']) . " : " . htmlspecialchars($err['message']) . "<br>";
                                 }
                             }


                         } catch (\PDOException $e) {
                             // --- En cas d'erreur DB, rollback ---
                             if ($pdo->inTransaction()) {
                                 $pdo->rollBack();
                             }
                             $error_message = "Erreur base de données lors de l'import : " . $e->getMessage();
                         }


                     }
                 }

                 fclose($handle); // Fermer le fichier

             } else {
                 $error_message = "Erreur lors de l'ouverture du fichier CSV.";
             }

         } else {
             $error_message = "Type de fichier invalide. Veuillez uploader un fichier CSV.";
         }

     } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] !== UPLOAD_ERR_NO_FILE) {
         // Gérer les erreurs d'upload de fichier
         $error_message = "Erreur lors de l'upload du fichier : Code " . $_FILES['csv_file']['error']; // Vous pouvez mapper ces codes à des messages plus lisibles
     } else {
         // Aucune erreur de fichier, mais pas de fichier sélectionné ? (Ne devrait pas arriver avec 'required' dans le formulaire)
     }
}


// --- Récupérer tous les produits pour l'affichage (Code précédent ici) ---
// ... (Conservez le bloc de code pour le listage des produits tel quel) ...
// On réinitialise les messages d'erreur/succès si on vient d'une redirection pour éviter de les afficher doublement
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

$products = [];
try {
    $sql_select = "SELECT p.*, c.name as category_name
                   FROM products p
                   JOIN categories c ON p.category_id = c.id
                   ORDER BY p.name ASC";
    $stmt_select = $pdo->query($sql_select);
    $products = $stmt_select->fetchAll();
} catch (\PDOException $e) {
     if (empty($error_message)) {
         $error_message = "Erreur lors du chargement des produits : " . $e->getMessage();
     }
}

// --- Récupérer la liste des catégories pour les selects du formulaire (Code précédent ici) ---
// ... (Conservez le bloc de code pour la récupération des catégories tel quel) ...
$categories = [];
try {
    $sql_categories = "SELECT id, name FROM categories ORDER BY name ASC";
    $stmt_categories = $pdo->query($sql_categories);
    $categories = $stmt_categories->fetchAll();
} catch (\PDOException $e) {
      // silent error
}

// --- Fin de la LOGIQUE PHP ---
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Produits - Système Quincaillerie</title>
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
        .product-img-thumbnail {
            width: 50px;
            height: auto;
            object-fit: cover;
        }
        .form-img-preview {
             max-width: 150px;
             height: auto;
             margin-top: 10px;
             border: 1px solid #ddd;
             padding: 5px;
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
                <a class="nav-link active" href="products.php"><i class="fas fa-box me-2"></i> Produits</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a> </li>
             <li class="nav-item">
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a> </li>
            <li class="nav-item">
                <a class="nav-link" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a> </li>
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
            <!-- Futur emplacement pour les alertes, notifications rapides etc. -->
        </div>

        <h1>Gestion des Produits</h1>

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
                 <?php if ($edit_product): // Afficher le formulaire de modification si $edit_product est défini ?>
                     <!-- Formulaire de modification existant ici -->
                      <div class="card mb-4">
                        <div class="card-header">
                            Modifier le produit "<?php echo htmlspecialchars($edit_product['name']); ?>"
                        </div>
                        <div class="card-body">
                            <form action="products.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="update_product" value="1">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_product['id']); ?>">
                                <div class="mb-3">
                                    <label for="edit_name" class="form-label">Nom du produit</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" value="<?php echo htmlspecialchars($edit_product['name']); ?>" required>
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_barcode" class="form-label">Code-barres (Optionnel)</label>
                                    <input type="text" class="form-control" id="edit_barcode" name="barcode" value="<?php echo htmlspecialchars($edit_product['barcode'] ?? ''); ?>">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_reference" class="form-label">Référence (Optionnel)</label>
                                    <input type="text" class="form-control" id="edit_reference" name="reference" value="<?php echo htmlspecialchars($edit_product['reference'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_category_id" class="form-label">Catégorie</label>
                                    <select class="form-select" id="edit_category_id" name="category_id" required>
                                        <option value="">-- Sélectionner une catégorie --</option>
                                         <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category['id']); ?>"
                                                <?php echo ($edit_product['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                         <?php endforeach; ?>
                                    </select>
                                     <?php if (empty($categories)): ?>
                                        <small class="form-text text-muted">Aucune catégorie disponible.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <label for="edit_purchase_price" class="form-label">Prix d'achat</label>
                                    <input type="number" class="form-control" id="edit_purchase_price" name="purchase_price" value="<?php echo htmlspecialchars($edit_product['purchase_price']); ?>" step="0.01" required min="0">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_sale_price" class="form-label">Prix de vente</label>
                                    <input type="number" class="form-control" id="edit_sale_price" name="sale_price" value="<?php echo htmlspecialchars($edit_product['sale_price']); ?>" step="0.01" required min="0">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_current_stock" class="form-label">Stock actuel</label>
                                    <input type="number" class="form-control" id="edit_current_stock" name="current_stock" value="<?php echo htmlspecialchars($edit_product['current_stock']); ?>" required min="0">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_alert_threshold" class="form-label">Seuil d'alerte stock</label>
                                    <input type="number" class="form-control" id="edit_alert_threshold" name="alert_threshold" value="<?php echo htmlspecialchars($edit_product['alert_threshold']); ?>" required min="0">
                                </div>
                                <div class="mb-3">
                                    <label for="edit_location" class="form-label">Localisation (Optionnel)</label>
                                    <input type="text" class="form-control" id="edit_location" name="location" value="<?php echo htmlspecialchars($edit_product['location'] ?? ''); ?>">
                                </div>
                                 <div class="mb-3">
                                    <label for="edit_image" class="form-label">Image (Optionnel)</label>
                                    <input type="file" class="form-control" id="edit_image" name="image" accept="image/jpeg, image/png, image/gif">
                                     <?php if (!empty($edit_product['image'])): ?>
                                        <div class="form-text mt-2">Image actuelle :</div>
                                        <img src="<?php echo htmlspecialchars($edit_product['image']); ?>" alt="Image actuelle" class="img-thumbnail form-img-preview">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" value="1" id="remove_image" name="remove_image">
                                            <label class="form-check-label" for="remove_image">
                                                Supprimer l'image actuelle
                                            </label>
                                        </div>
                                     <?php endif; ?>
                                </div>

                                <button type="submit" class="btn btn-success me-2"><i class="fas fa-save me-2"></i> Enregistrer les modifications</button>
                                <a href="products.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i> Annuler</a>
                            </form>
                        </div>
                    </div>
                 <?php else: // Sinon, afficher le formulaire d'ajout et le formulaire d'import ?>
                 <div class="card mb-4">
                    <div class="card-header">
                        Ajouter un nouveau produit
                    </div>
                    <div class="card-body">
                        <!-- Formulaire d'ajout existant ici -->
                        <form action="products.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="add_product" value="1">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nom du produit</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                             <div class="mb-3">
                                <label for="barcode" class="form-label">Code-barres (Optionnel)</label>
                                <input type="text" class="form-control" id="barcode" name="barcode">
                            </div>
                             <div class="mb-3">
                                <label for="reference" class="form-label">Référence (Optionnel)</label>
                                <input type="text" class="form-control" id="reference" name="reference">
                            </div>
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Catégorie</label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">-- Sélectionner une catégorie --</option>
                                     <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category['id']); ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                     <?php endforeach; ?>
                                </select>
                                <?php if (empty($categories)): ?>
                                    <small class="form-text text-muted">Aucune catégorie disponible. Veuillez d'abord ajouter des catégories.</small>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="purchase_price" class="form-label">Prix d'achat</label>
                                <input type="number" class="form-control" id="purchase_price" name="purchase_price" step="0.01" required min="0">
                            </div>
                            <div class="mb-3">
                                <label for="sale_price" class="form-label">Prix de vente</label>
                                <input type="number" class="form-control" id="sale_price" name="sale_price" step="0.01" required min="0">
                            </div>
                            <div class="mb-3">
                                <label for="current_stock" class="form-label">Stock initial</label>
                                <input type="number" class="form-control" id="current_stock" name="current_stock" required min="0">
                            </div>
                             <div class="mb-3">
                                <label for="alert_threshold" class="form-label">Seuil d'alerte stock</label>
                                <input type="number" class="form-control" id="alert_threshold" name="alert_threshold" required min="0">
                            </div>
                            <div class="mb-3">
                                <label for="location" class="form-label">Localisation (Optionnel)</label>
                                <input type="text" class="form-control" id="location" name="location">
                            </div>
                             <div class="mb-3">
                                <label for="image" class="form-label">Image (Optionnel)</label>
                                <input type="file" class="form-control" id="image" name="image" accept="image/jpeg, image/png, image/gif">
                            </div>

                            <button type="submit" class="btn btn-primary" <?php echo empty($categories) ? 'disabled' : ''; ?>><i class="fas fa-plus-circle me-2"></i> Ajouter le produit</button>
                             <?php if (empty($categories)): ?>
                                <span class="text-danger"> (Ajoutez des catégories pour activer le bouton)</span>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                 <!-- Nouveau Formulaire d'Importation (Visible uniquement pour l'Admin) -->
                 <?php if (get_current_user_role_id() == 1): ?>
                 <div class="card mb-4">
                     <div class="card-header">
                         Importer des produits (CSV)
                     </div>
                     <div class="card-body">
                         <form action="products.php" method="POST" enctype="multipart/form-data">
                             <input type="hidden" name="import_products" value="1">
                             <div class="mb-3">
                                 <label for="csv_file" class="form-label">Fichier CSV</label>
                                 <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                                  <div class="form-text">Format attendu : CSV avec point-virgule (`;`) comme séparateur. Les colonnes obligatoires (dans n'importe quel ordre) sont : <code>name</code>, <code>category_id</code> (ou nom), <code>purchase_price</code>, <code>sale_price</code>, <code>current_stock</code>, <code>alert_threshold</code>. Les colonnes optionnelles sont : <code>barcode</code>, <code>reference</code>, <code>location</code>.</div>
                                  <div class="mt-2">
                                      <a href="path/to/your/sample_products.csv" download>Télécharger un modèle CSV</a> <!-- Créez un fichier modèle sample_products.csv -->
                                  </div>
                             </div>
                              <button type="submit" class="btn btn-success"><i class="fas fa-upload me-2"></i> Importer</button>
                         </form>
                     </div>
                 </div>
                 <?php endif; ?>

                 <?php endif; // Fin de la condition $edit_product ?>

            </div>
            <div class="col-md-7">
                 <div class="card">
                    <div class="card-header">
                        Liste des produits
                         <!-- Bouton d'export de la liste actuelle (simple CSV) -->
                         <?php if (!empty($products)): ?>
                            <a href="products.php?action=export_list_csv" class="btn btn-sm btn-secondary float-end"><i class="fas fa-download me-1"></i> Exporter la liste (CSV)</a>
                         <?php endif; ?>
                    </div>
                    <div class="card-body">
                         <!-- Code du tableau d'affichage des produits existant ici -->
                        <table class="table table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Image</th>
                                    <th>Nom</th>
                                     <th>Réf / Code</th>
                                    <th>Catégorie</th>
                                    <th>Prix Achat</th>
                                    <th>Prix Vente</th>
                                    <th>Stock</th>
                                    <th>Seuil</th>
                                     <th>Localisation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($products) > 0): ?>
                                    <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($product['id']); ?></td>
                                         <td>
                                            <?php if (!empty($product['image'])): ?>
                                                <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="img-thumbnail product-img-thumbnail">
                                            <?php else: ?>
                                                <i class="fas fa-box fa-2x text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                         <td>
                                            Réf: <?php echo htmlspecialchars($product['reference'] ?? 'N/A'); ?><br>
                                            Code: <?php echo htmlspecialchars($product['barcode'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars(number_format($product['purchase_price'], 2, ',', ' ') . ' FCFA'); ?></td>
                                        <td><?php echo htmlspecialchars(number_format($product['sale_price'], 2, ',', ' ') . ' FCFA'); ?></td>
                                        <td><?php echo htmlspecialchars($product['current_stock']); ?></td>
                                        <td><?php echo htmlspecialchars($product['alert_threshold']); ?></td>
                                        <td><?php echo htmlspecialchars($product['location'] ?? 'N/A'); ?></td>
                                        <td>
                                            <a href="products.php?action=edit&id=<?php echo $product['id']; ?>" class="btn btn-sm btn-warning me-1" title="Modifier"><i class="fas fa-edit"></i></a>
                                            <a href="products.php?action=delete&id=<?php echo $product['id']; ?>" class="btn btn-sm btn-danger" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer le produit \'<?php echo htmlspecialchars(addslashes($product['name'])); ?>\' ?');"><i class="fas fa-trash-alt"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center">Aucun produit trouvé.</td>
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