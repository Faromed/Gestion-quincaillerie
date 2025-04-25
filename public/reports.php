<?php
// Démarre la session PHP
session_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Inclure l'autoload de Dompdf pour l'export PDF futur
require_once __DIR__ . '/../vendor/autoload.php';

// Références des classes Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

// Si l'utilisateur n'est PAS connecté, le rediriger vers la page de connexion
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Stockiste pour les rapports de stock initiaux)
$allowed_roles_view_stock_reports = [1, 3]; // Admin, Stockiste peuvent voir les rapports de stock
$allowed_roles_view_sales_reports = [1, 2]; // Admin, Vendeur peuvent voir les rapports de vente
$allowed_roles_view_all_reports = [1];       // Seul l'Admin peut voir TOUS les rapports

// --- LOGIQUE PHP POUR LES RAPPORTS ---

$error_message = '';
$report_data = []; // Variable pour stocker les données du rapport généré
$report_title = 'Sélectionnez un rapport'; // Titre par défaut
$report_type = $_REQUEST['report_type'] ?? ''; // Utiliser $_REQUEST pour GET ou POST (utile pour l'export)
$filters = []; // Tableau pour stocker les filtres appliqués


// Définir les types de rapports disponibles et les rôles autorisés
$available_reports = [
    'current_stock' => [
        'title' => 'État du stock actuel',
        'roles' => $allowed_roles_view_stock_reports,
        'filters' => ['category_id'] // Exemple de filtre applicable
        ],
    'low_stock' => [
        'title' => 'Produits sous le seuil',
        'roles' => $allowed_roles_view_stock_reports,
         'filters' => ['category_id'] // Exemple de filtre applicable
        ],
    'stock_movements' => [
         'title' => 'Historique des mouvements de stock',
         'roles' => $allowed_roles_view_stock_reports,
         'filters' => ['date_range', 'product_id', 'movement_type', 'user_id']
         ],
    'sales_by_period' => [
         'title' => 'Ventes par période',
         'roles' => $allowed_roles_view_sales_reports,
         'filters' => ['date_range', 'user_id']
         ],
    // 'sales_by_product' => [ ... ], // Pour plus tard
    // 'sales_by_category' => [ ... ], // Pour plus tard
    // 'sales_by_user' => [ ... ],    // Pour plus tard
    // Ajoutez d'autres rapports ici
];

// Vérifier si le type de rapport sélectionné est valide et si l'utilisateur a les permissions
$is_valid_report_type = !empty($report_type) && isset($available_reports[$report_type]);
$is_authorized_for_report = false;
if ($is_valid_report_type) {
     $allowed_roles_for_current_report = $available_reports[$report_type]['roles'];
     if (in_array(get_current_user_role_id(), $allowed_roles_for_current_report)) {
         $is_authorized_for_report = true;
         $report_title = $available_reports[$report_type]['title'];

         // Récupérer les filtres depuis la requête
         $filters['date_start'] = $_REQUEST['date_start'] ?? null;
         $filters['date_end'] = $_REQUEST['date_end'] ?? null;
         $filters['category_id'] = filter_var($_REQUEST['category_id'] ?? '', FILTER_VALIDATE_INT) ?: null; // Use null if validation fails
         $filters['product_id'] = filter_var($_REQUEST['product_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
         $filters['movement_type'] = $_REQUEST['movement_type'] ?? null; // Assurez-vous que c'est un type ENUM valide si utilisé
         $filters['user_id'] = filter_var($_REQUEST['user_id'] ?? '', FILTER_VALIDATE_INT) ?: null;


     } else {
         $error_message = "Vous n'avez pas les permissions pour consulter ce type de rapport.";
         $report_type = ''; // Réinitialiser le type pour ne pas afficher de résultats
     }
} else {
    // Si le type de rapport est vide ou invalide au chargement initial
     $error_message = !empty($report_type) ? "Type de rapport sélectionné invalide." : ''; // Message si un type invalide est soumis
}

// --- Gérer les actions (export PDF/CSV) ---
$action = $_GET['action'] ?? '';

if ($is_valid_report_type && $is_authorized_for_report && ($action === 'export_pdf' || $action === 'export_csv')) {
    // Nous allons générer les données du rapport ici, puis les exporter.
    // Le code pour générer le rapport est dupliqué ici, une meilleure approche serait une fonction.

    $report_data = []; // Vider pour regénérer

     try {
        switch ($report_type) {
            case 'current_stock':
                $sql = "SELECT p.id, p.name, c.name as category_name, p.current_stock, p.alert_threshold, p.location, p.purchase_price, p.sale_price
                        FROM products p
                        JOIN categories c ON p.category_id = c.id ";
                 // Appliquer le filtre par catégorie si présent
                 if (!empty($filters['category_id'])) {
                     $sql .= " WHERE p.category_id = :category_id ";
                 }
                 $sql .= " ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                 if (!empty($filters['category_id'])) {
                     $stmt->execute([':category_id' => $filters['category_id']]);
                 } else {
                    $stmt->execute();
                 }
                $report_data = $stmt->fetchAll();
                break;

            case 'low_stock':
                $sql = "SELECT p.id, p.name, c.name as category_name, p.current_stock, p.alert_threshold, p.location
                        FROM products p
                        JOIN categories c ON p.category_id = c.id
                        WHERE p.current_stock <= p.alert_threshold AND p.alert_threshold > 0 ";
                 // Appliquer le filtre par catégorie si présent
                 if (!empty($filters['category_id'])) {
                     $sql .= " AND p.category_id = :category_id "; // Utiliser AND car il y a déjà une clause WHERE
                 }
                 $sql .= " ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                 if (!empty($filters['category_id'])) {
                     $stmt->execute([':category_id' => $filters['category_id']]);
                 } else {
                    $stmt->execute();
                 }
                 $report_data = $stmt->fetchAll();
                break;

            case 'stock_movements':
                 $sql = "SELECT sm.id, sm.movement_type, sm.quantity, sm.movement_date, sm.notes, p.name as product_name, u.username
                        FROM stock_movements sm
                        JOIN products p ON sm.product_id = p.id
                        LEFT JOIN users u ON sm.user_id = u.id ";

                 $where_clauses = [];
                 $params = [];

                 if (!empty($filters['date_start'])) {
                      $where_clauses[] = "DATE(sm.movement_date) >= :date_start";
                      $params[':date_start'] = $filters['date_start'];
                 }
                 if (!empty($filters['date_end'])) {
                      $where_clauses[] = "DATE(sm.movement_date) <= :date_end";
                      $params[':date_end'] = $filters['date_end'];
                 }
                 if (!empty($filters['product_id'])) {
                      $where_clauses[] = "sm.product_id = :product_id";
                      $params[':product_id'] = $filters['product_id'];
                 }
                 if (!empty($filters['movement_type'])) {
                      // TODO: Valider que le movement_type est une valeur valide de l'ENUM
                      $where_clauses[] = "sm.movement_type = :movement_type";
                      $params[':movement_type'] = $filters['movement_type'];
                 }
                  if (!empty($filters['user_id'])) {
                       $where_clauses[] = "sm.user_id = :user_id";
                       $params[':user_id'] = $filters['user_id'];
                  }


                 if (!empty($where_clauses)) {
                     $sql .= " WHERE " . implode(" AND ", $where_clauses);
                 }

                 $sql .= " ORDER BY sm.movement_date DESC";

                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                 $report_data = $stmt->fetchAll();
                 break;

             case 'sales_by_period':
                 $sql = "SELECT s.id, s.sale_date, s.total_amount, s.tax_amount, s.discount_amount, s.final_amount, u.username as seller_name, s.customer_name
                        FROM sales s
                        LEFT JOIN users u ON s.user_id = u.id ";

                 $where_clauses = [];
                 $params = [];

                 if (!empty($filters['date_start'])) {
                      $where_clauses[] = "DATE(s.sale_date) >= :date_start";
                      $params[':date_start'] = $filters['date_start'];
                 }
                 if (!empty($filters['date_end'])) {
                      $where_clauses[] = "DATE(s.sale_date) <= :date_end";
                      $params[':date_end'] = $filters['date_end'];
                 }
                  if (!empty($filters['user_id'])) {
                       $where_clauses[] = "s.user_id = :user_id";
                       $params[':user_id'] = $filters['user_id'];
                  }

                 if (!empty($where_clauses)) {
                     $sql .= " WHERE " . implode(" AND ", $where_clauses);
                 }

                 $sql .= " ORDER BY s.sale_date DESC";

                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                 $report_data = $stmt->fetchAll();
                 break;


            default:
                 // Ceci ne devrait pas arriver si $is_valid_report_type est true
                break;
        }

        // --- Export en PDF avec Dompdf ---
if ($action === 'export_pdf') {
    if (empty($report_data)) {
        die("Aucune donnée à exporter en PDF.");
    }

    // Informations de l'entreprise (placeholders pour l'export)
    $company_name_exp = "Votre Quincaillerie Moderne";
    $company_address_exp = "Votre Adresse, Votre Ville";
    $company_phone_exp = "+XXX XXX XXX XXX";
    $company_email_exp = "contact@votrequincaillerie.com";
    $company_logo_path_exp = __DIR__ . '/assets/img/logo.png'; // Chemin absolu pour Dompdf

    $logo_html_exp = '';
    if (file_exists($company_logo_path_exp)) {
        $logo_type_exp = pathinfo($company_logo_path_exp, PATHINFO_EXTENSION);
        $logo_data_exp = file_get_contents($company_logo_path_exp);
        $base64_logo_exp = 'data:image/' . $logo_type_exp . ';base64,' . base64_encode($logo_data_exp);
        $logo_html_exp = '<img src="' . $base64_logo_exp . '" alt="Logo" style="height: 60px;">';
    } else {
        $logo_html_exp = '<h3>' . htmlspecialchars($company_name_exp) . '</h3>';
    }

    $html_exp = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($report_title) . '</title>
        <style>
            body { font-family: sans-serif; margin: 5mm; }
            .header { width: 100%; margin-bottom: 20mm; }
            .header td { vertical-align: top; }
            .company-info { width: 50%; }
            .report-title { width: 50%; text-align: right; }
            .report-title h1 { color: #333; }
            .report-table { width: 100%; border-collapse: collapse; margin-bottom: 20mm; font-size: 0.9em;}
            .report-table th, .report-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .report-table th { background-color: #f2f2f2; }
            .footer { width: 100%; text-align: center; margin-top: 30mm; font-size: 0.7em; color: #777; }
            .page-break { page-break-after: always; } /* Pour gestion de plusieurs pages */
        </style>
    </head>
    <body>
        <table class="header">
            <tr>
                <td class="company-info">
                    ' . $logo_html_exp . '
                    <p>
                        ' . nl2br(htmlspecialchars($company_address_exp)) . '<br>
                        Téléphone: ' . htmlspecialchars($company_phone_exp) . '<br>
                        Email: ' . htmlspecialchars($company_email_exp) . '
                    </p>
                </td>
                <td class="report-title">
                    <h1>Rapport</h1>
                    <p>' . htmlspecialchars($report_title) . '</p>
                    <p>Généré le: ' . htmlspecialchars(date('d/m/Y H:i')) . '</p>
                </td>
            </tr>
        </table>

        <table class="report-table">
            <thead>
                <tr>';
    
    // En-têtes basés sur les clés des données
    if (!empty($report_data)) {
        $first_row = $report_data[0];
        foreach (array_keys($first_row) as $column_name) {
            $display_name = ucwords(str_replace(['_', 'id'], [' ', 'ID'], $column_name));
            
            // Mapper les noms de colonnes spécifiques
            $display_name = str_replace('Current Stock', 'Stock Actuel', $display_name);
            $display_name = str_replace('Alert Threshold', 'Seuil Alerte', $display_name);
            $display_name = str_replace('Purchase Price', 'Prix Achat', $display_name);
            $display_name = str_replace('Sale Price', 'Prix Vente', $display_name);
            $display_name = str_replace('Category Name', 'Catégorie', $display_name);
            $display_name = str_replace('Movement Type', 'Type Mouvement', $display_name);
            $display_name = str_replace('Movement Date', 'Date Mouvement', $display_name);
            $display_name = str_replace('Product Name', 'Produit', $display_name);
            $display_name = str_replace('Seller Name', 'Vendeur', $display_name);
            $display_name = str_replace('Total Amount', 'Montant Total', $display_name);
            $display_name = str_replace('Tax Amount', 'Montant Taxe', $display_name);
            $display_name = str_replace('Discount Amount', 'Montant Remise', $display_name);
            $display_name = str_replace('Final Amount', 'Montant Final', $display_name);
            $display_name = str_replace('Sale Date', 'Date Vente', $display_name);
            
            $html_exp .= '<th>' . htmlspecialchars($display_name) . '</th>';
        }
    }
    
    $html_exp .= '
                </tr>
            </thead>
            <tbody>';
    
    // Données du tableau
    foreach ($report_data as $row) {
        $html_exp .= '<tr>';
        foreach ($row as $key => $value) {
            // Formatage spécifique selon la colonne
            if (is_numeric($value) && (strpos($key, '_price') !== false || strpos($key, '_amount') !== false)) {
                $html_exp .= '<td>' . htmlspecialchars(number_format($value, 2, ',', ' ') . ' FCFA') . '</td>';
            } elseif (strpos($key, '_date') !== false && strtotime($value)) {
                $html_exp .= '<td>' . htmlspecialchars(date('d/m/Y H:i', strtotime($value))) . '</td>';
            } elseif (strpos($value, '-') !== false && strlen($value) == 10 && strtotime($value)) {
                $html_exp .= '<td>' . htmlspecialchars(date('d/m/Y', strtotime($value))) . '</td>';
            } else {
                $html_exp .= '<td>' . htmlspecialchars($value) . '</td>';
            }
        }
        $html_exp .= '</tr>';
    }
    
    $html_exp .= '
            </tbody>
        </table>

        <div class="footer">
            <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($company_name_exp) . '. Tous droits réservés.</p>
        </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    // $options->set('defaultFont', 'Arial');

    $dompdf = new Dompdf($options);

    $dompdf->loadHtml($html_exp);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Nom du fichier PDF
    $filename = str_replace(' ', '_', $report_title) . '_' . date('Ymd_His') . '.pdf';

    // Envoyer le PDF au navigateur
    $dompdf->stream($filename, ['Attachment' => false]); // false pour afficher inline, true pour télécharger

    exit(0); // Terminer le script après l'export PDF

        } elseif ($action === 'export_csv') {
            // --- Export en CSV ---

            if (empty($report_data)) {
                 die("Aucune donnée à exporter en CSV.");
            }

            // Nom du fichier CSV
             $filename = str_replace(' ', '_', $report_title) . '_' . date('Ymd_His') . '.csv';

             // Entêtes HTTP pour forcer le téléchargement et définir le type MINE
             header('Content-Type: text/csv; charset=utf-8');
             header('Content-Disposition: attachment; filename="' . $filename . '"');

             // Ouvrir le flux de sortie
             $output = fopen('php://output', 'w');

             // Écrire les en-têtes de colonne
             if (!empty($report_data)) {
                $first_row = $report_data[0];
                 $headers = [];
                 foreach (array_keys($first_row) as $column_name) {
                      $display_name = ucwords(str_replace(['_', 'id'], [' ', 'ID'], $column_name));
                      // Mapper les noms de colonnes spécifiques si nécessaire (comme pour PDF)
                       $display_name = str_replace('Current Stock', 'Stock Actuel', $display_name);
                       $display_name = str_replace('Alert Threshold', 'Seuil Alerte', $display_name);
                       $display_name = str_replace('Purchase Price', 'Prix Achat', $display_name);
                       $display_name = str_replace('Sale Price', 'Prix Vente', $display_name);
                       $display_name = str_replace('Category Name', 'Catégorie', $display_name);
                       $display_name = str_replace('Movement Type', 'Type Mouvement', $display_name);
                       $display_name = str_replace('Movement Date', 'Date Mouvement', $display_name);
                       $display_name = str_replace('Product Name', 'Produit', $display_name);
                       $display_name = str_replace('Seller Name', 'Vendeur', $display_name);
                       $display_name = str_replace('Total Amount', 'Montant Total', $display_name);
                       $display_name = str_replace('Tax Amount', 'Montant Taxe', $display_name);
                       $display_name = str_replace('Discount Amount', 'Montant Remise', $display_name);
                       $display_name = str_replace('Final Amount', 'Montant Final', $display_name);
                       $display_name = str_replace('Sale Date', 'Date Vente', $display_name);


                      $headers[] = $display_name;
                 }
                 fputcsv($output, $headers, ';'); // Utiliser le point-virgule comme séparateur pour le CSV
             }

             // Écrire les données du rapport
             foreach ($report_data as $row) {
                  $csv_row = [];
                  foreach ($row as $value) {
                       // Formater les valeurs pour le CSV si nécessaire (dates, nombres)
                       if (is_numeric($value) && (strpos($value, '.') !== false || $value == (int)$value) && ($display_name === 'Prix Achat' || $display_name === 'Prix Vente' || strpos($display_name, 'Montant') !== false)) {
                           $csv_row[] = number_format($value, 2, ',', ''); // Format numérique CSV (virgule décimale, pas de séparateur de milliers)
                       } elseif (strtotime($value)) { // Date ou datetime
                            $csv_row[] = date('d/m/Y H:i', strtotime($value));
                       }
                       else {
                           $csv_row[] = $value; // Valeur brute ou htmlspecialchars si vous le préférez, mais CSV est plus brut
                       }
                  }
                 fputcsv($output, $csv_row, ';'); // Écrire la ligne dans le CSV
             }

             // Fermer le flux
             fclose($output);

             exit(0); // Terminer le script après l'export CSV
        }


    } catch (\PDOException $e) {
        // En cas d'erreur lors de la génération des données pour l'export
        die("Erreur lors de la préparation des données pour l'export : " . $e->getMessage());
    }
}


// --- Gérer la génération du rapport sélectionné (affichage HTML) ---
// Ce bloc s'exécute si $_GET['report_type'] est défini et valide, ET qu'il n'y a PAS d'action d'export
if ($is_valid_report_type && $is_authorized_for_report && $action === '') {

    // Récupérer les filtres appliqués pour les réafficher dans le formulaire
     $filters_applied = [
         'date_start' => $_REQUEST['date_start'] ?? '',
         'date_end' => $_REQUEST['date_end'] ?? '',
         'category_id' => $_REQUEST['category_id'] ?? '',
         'product_id' => $_REQUEST['product_id'] ?? '',
         'movement_type' => $_REQUEST['movement_type'] ?? '',
         'user_id' => $_REQUEST['user_id'] ?? '',
     ];


    try {
        // Les requêtes sont les mêmes que celles utilisées pour l'export, avec l'ajout de filtres.
        // Il serait préférable d'avoir une fonction get_report_data($pdo, $report_type, $filters)
        // pour éviter la duplication, mais pour l'instant, on garde le switch ici.

         switch ($report_type) {
            case 'current_stock':
                $sql = "SELECT p.id, p.name, c.name as category_name, p.current_stock, p.alert_threshold, p.location, p.purchase_price, p.sale_price
                        FROM products p
                        JOIN categories c ON p.category_id = c.id ";
                 $params = [];
                 if (!empty($filters_applied['category_id'])) {
                     $sql .= " WHERE p.category_id = :category_id ";
                     $params[':category_id'] = $filters_applied['category_id'];
                 }
                 $sql .= " ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                $report_data = $stmt->fetchAll();
                break;

            case 'low_stock':
                $sql = "SELECT p.id, p.name, c.name as category_name, p.current_stock, p.alert_threshold, p.location
                        FROM products p
                        JOIN categories c ON p.category_id = c.id
                        WHERE p.current_stock <= p.alert_threshold AND p.alert_threshold > 0 ";
                 $params = [];
                 if (!empty($filters_applied['category_id'])) {
                     $sql .= " AND p.category_id = :category_id ";
                     $params[':category_id'] = $filters_applied['category_id'];
                 }
                 $sql .= " ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                 $report_data = $stmt->fetchAll();
                break;

            case 'stock_movements':
                 $sql = "SELECT sm.id, sm.movement_type, sm.quantity, sm.movement_date, sm.notes, p.name as product_name, u.username
                        FROM stock_movements sm
                        JOIN products p ON sm.product_id = p.id
                        LEFT JOIN users u ON sm.user_id = u.id ";

                 $where_clauses = [];
                 $params = [];

                 if (!empty($filters_applied['date_start'])) {
                      $where_clauses[] = "DATE(sm.movement_date) >= :date_start";
                      $params[':date_start'] = $filters_applied['date_start'];
                 }
                 if (!empty($filters_applied['date_end'])) {
                      $where_clauses[] = "DATE(sm.movement_date) <= :date_end";
                      $params[':date_end'] = $filters_applied['date_end'];
                 }
                 if (!empty($filters_applied['product_id'])) {
                      $where_clauses[] = "sm.product_id = :product_id";
                      $params[':product_id'] = $filters_applied['product_id'];
                 }
                 if (!empty($filters_applied['movement_type'])) {
                      // TODO: Valider que le movement_type est une valeur valide de l'ENUM
                      $where_clauses[] = "sm.movement_type = :movement_type";
                      $params[':movement_type'] = $filters_applied['movement_type'];
                 }
                  if (!empty($filters_applied['user_id'])) {
                       $where_clauses[] = "sm.user_id = :user_id";
                       $params[':user_id'] = $filters_applied['user_id'];
                  }

                 if (!empty($where_clauses)) {
                     $sql .= " WHERE " . implode(" AND ", $where_clauses);
                 }

                 $sql .= " ORDER BY sm.movement_date DESC";

                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                 $report_data = $stmt->fetchAll();
                 break;

             case 'sales_by_period':
                 $sql = "SELECT s.id, s.sale_date, s.total_amount, s.tax_amount, s.discount_amount, s.final_amount, u.username as seller_name, s.customer_name
                        FROM sales s
                        LEFT JOIN users u ON s.user_id = u.id ";

                 $where_clauses = [];
                 $params = [];

                 if (!empty($filters_applied['date_start'])) {
                      $where_clauses[] = "DATE(s.sale_date) >= :date_start";
                      $params[':date_start'] = $filters_applied['date_start'];
                 }
                 if (!empty($filters_applied['date_end'])) {
                      $where_clauses[] = "DATE(s.sale_date) <= :date_end";
                      $params[':date_end'] = $filters['date_end'];
                 }
                  if (!empty($filters_applied['user_id'])) {
                       $where_clauses[] = "s.user_id = :user_id";
                       $params[':user_id'] = $filters_applied['user_id'];
                  }

                 if (!empty($where_clauses)) {
                     $sql .= " WHERE " . implode(" AND ", $where_clauses);
                 }

                 $sql .= " ORDER BY s.sale_date DESC";

                 $stmt = $pdo->prepare($sql);
                 $stmt->execute($params);
                 $report_data = $stmt->fetchAll();
                 break;


            default:
                // Cela ne devrait pas arriver si le type est valide
                $report_data = [];
                break;
        }
    } catch (\PDOException $e) {
        $error_message = "Erreur lors de la génération du rapport : " . $e->getMessage();
         $report_data = [];
    }
}


// --- Récupérer les données nécessaires pour les filtres ---
$categories_for_filter = [];
$products_for_filter = [];
$users_for_filter = [];
$movement_types_for_filter = [
     '' => '-- Tous types --', // Option par défaut
     'Entree - Ajustement' => 'Entrée - Ajustement',
     'Sortie - Casse' => 'Sortie - Casse',
     'Sortie - Usage Interne' => 'Sortie - Usage Interne',
     'Entree - Approvisionnement' => 'Entrée - Approvisionnement',
     'Sortie - Vente' => 'Sortie - Vente',
     'Entree - Retour Client' => 'Entrée - Retour Client',
     'Sortie - Retour Fournisseur' => 'Sortie - Retour Fournisseur',
]; // Toutes les valeurs de l'ENUM movement_type

try {
    $sql_cats = "SELECT id, name FROM categories ORDER BY name ASC";
    $stmt_cats = $pdo->query($sql_cats);
    $categories_for_filter = $stmt_cats->fetchAll();

     $sql_prods = "SELECT id, name FROM products ORDER BY name ASC";
     $stmt_prods = $pdo->query($sql_prods);
     $products_for_filter = $stmt_prods->fetchAll();

     $sql_users = "SELECT id, username FROM users ORDER BY username ASC";
     $stmt_users = $pdo->query($sql_users);
     $users_for_filter = $stmt_users->fetchAll();

} catch (\PDOException $e) {
     // Gérer les erreurs de chargement des données pour les filtres si nécessaire
}


// --- Fin de la LOGIQUE PHP ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports - Système Quincaillerie</title>
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
         /* Styles spécifiques pour les rapports */
         .report-filters, .report-display {
             margin-bottom: 20px;
         }
         .report-table th, .report-table td {
             font-size: 0.85em; /* Taille de police légèrement réduite pour les tableaux de rapport */
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
                <a class="nav-link" href="suppliers.php"><i class="fas fa-truck me-2"></i> Fournisseurs</a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="sales.php"><i class="fas fa-cash-register me-2"></i> Ventes</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="stock_movements.php"><i class="fas fa-boxes me-2"></i> Stock</a>
            </li>
             <li class="nav-item">
                <a class="nav-link active" href="reports.php"><i class="fas fa-file-alt me-2"></i> Rapports</a> <!-- Lien actif -->
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

        <h1>Rapports</h1>

         <?php
        // Afficher les messages d'erreur
        if (!empty($error_message)) {
            echo '<div class="alert alert-danger" role="alert">' . $error_message . '</div>';
        }
        ?>

        <div class="card report-filters mb-4">
            <div class="card-header">
                Générer un rapport
            </div>
            <div class="card-body">
                <form id="reportForm" action="reports.php" method="GET">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="report_type" class="form-label">Type de Rapport</label>
                            <select class="form-select" id="report_type" name="report_type" required>
                                <option value="">-- Sélectionner un rapport --</option>
                                <?php
                                // Afficher seulement les rapports autorisés pour le rôle de l'utilisateur connecté
                                $current_user_role = get_current_user_role_id();
                                foreach ($available_reports as $value => $report_info):
                                     if (in_array($current_user_role, $report_info['roles'])):
                                ?>
                                     <option value="<?php echo htmlspecialchars($value); ?>"
                                         <?php echo ($report_type === $value) ? 'selected' : ''; ?>>
                                         <?php echo htmlspecialchars($report_info['title']); ?>
                                     </option>
                                <?php
                                     endif;
                                endforeach;
                                ?>
                            </select>
                        </div>
                         <div id="filters-section" class="col-md-8 d-flex align-items-end">
                            <div class="row g-3 w-100 filter-options filter-date_range" style="display: none;">
                                 <div class="col-md-6">
                                     <label for="date_start" class="form-label">Date de début</label>
                                     <input type="date" class="form-control" id="date_start" name="date_start" value="<?php echo htmlspecialchars($filters_applied['date_start'] ?? ''); ?>">
                                 </div>
                                  <div class="col-md-6">
                                     <label for="date_end" class="form-label">Date de fin</label>
                                     <input type="date" class="form-control" id="date_end" name="date_end" value="<?php echo htmlspecialchars($filters_applied['date_end'] ?? ''); ?>">
                                 </div>
                             </div>
                             <div class="row g-3 w-100 filter-options filter-category_id" style="display: none;">
                                 <div class="col-md-12">
                                     <label for="category_id" class="form-label">Catégorie</label>
                                     <select class="form-select" id="category_id" name="category_id">
                                         <option value="">-- Toutes les catégories --</option>
                                          <?php foreach ($categories_for_filter as $cat): ?>
                                             <option value="<?php echo htmlspecialchars($cat['id']); ?>"
                                                  <?php echo ($filters_applied['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                                  <?php echo htmlspecialchars($cat['name']); ?>
                                             </option>
                                          <?php endforeach; ?>
                                     </select>
                                 </div>
                             </div>
                             <div class="row g-3 w-100 filter-options filter-product_id" style="display: none;">
                                  <div class="col-md-12">
                                     <label for="product_id" class="form-label">Produit</label>
                                     <select class="form-select" id="product_id" name="product_id">
                                         <option value="">-- Tous les produits --</option>
                                          <?php foreach ($products_for_filter as $prod): ?>
                                             <option value="<?php echo htmlspecialchars($prod['id']); ?>"
                                                  <?php echo ($filters_applied['product_id'] == $prod['id']) ? 'selected' : ''; ?>>
                                                  <?php echo htmlspecialchars($prod['name']); ?>
                                             </option>
                                          <?php endforeach; ?>
                                     </select>
                                  </div>
                             </div>
                              <div class="row g-3 w-100 filter-options filter-movement_type" style="display: none;">
                                   <div class="col-md-12">
                                     <label for="movement_type" class="form-label">Type de mouvement</label>
                                     <select class="form-select" id="movement_type" name="movement_type">
                                          <?php foreach ($movement_types_for_filter as $value => $label): ?>
                                             <option value="<?php echo htmlspecialchars($value); ?>"
                                                   <?php echo (isset($filters_applied['movement_type']) && $filters_applied['movement_type'] === $value) ? 'selected' : ''; ?>>
                                                  <?php echo htmlspecialchars($label); ?>
                                             </option>
                                          <?php endforeach; ?>
                                     </select>
                                  </div>
                              </div>
                               <div class="row g-3 w-100 filter-options filter-user_id" style="display: none;">
                                   <div class="col-md-12">
                                     <label for="user_id" class="form-label">Utilisateur / Vendeur</label>
                                     <select class="form-select" id="user_id" name="user_id">
                                         <option value="">-- Tous les utilisateurs --</option>
                                          <?php foreach ($users_for_filter as $user): ?>
                                             <option value="<?php echo htmlspecialchars($user['id']); ?>"
                                                  <?php echo ($filters_applied['user_id'] == $user['id']) ? 'selected' : ''; ?>>
                                                  <?php echo htmlspecialchars($user['username']); ?>
                                             </option>
                                          <?php endforeach; ?>
                                     </select>
                                  </div>
                              </div>

                         </div>
                          <div class="col-md-auto d-flex align-items-end">
                             <button type="submit" class="btn btn-primary"><i class="fas fa-play me-1"></i> Générer le rapport</button>
                          </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($report_type) && empty($error_message) && $action === ''): // Afficher les résultats si un rapport a été généré pour affichage ?>
             <div class="card report-display">
                <div class="card-header">
                    <?php echo htmlspecialchars($report_title); ?>
                     <?php if (!empty($report_data)): ?>
                        <div class="float-end">
                             <a href="reports.php?action=export_pdf&report_type=<?php echo htmlspecialchars($report_type); // Ajoutez ici les paramètres de filtre  ?>&<?php echo http_build_query($filters_applied); ?>" target="_blank" class="btn btn-sm btn-secondary me-2"><i class="fas fa-file-pdf me-1"></i> Export PDF</a>
                             <a href="reports.php?action=export_csv&report_type=<?php echo htmlspecialchars($report_type); // Ajoutez ici les paramètres de filtre ?>&<?php echo http_build_query($filters_applied); ?>" class="btn btn-sm btn-secondary"><i class="fas fa-file-csv me-1"></i> Export CSV</a>
                        </div>
                     <?php endif; ?>
                </div>
                <div class="card-body">
                     <?php if (empty($report_data)): ?>
                         <div class="alert alert-info" role="alert">
                             Aucune donnée trouvée pour ce rapport avec les filtres sélectionnés.
                         </div>
                     <?php else: ?>
                         <table class="table table-striped table-hover table-sm report-table">
                             <thead>
                                 <tr>
                                     <?php
                                     // Afficher les en-têtes de colonne basés sur les clés du premier élément de données
                                     if (!empty($report_data)) {
                                         $first_row = $report_data[0];
                                         foreach (array_keys($first_row) as $column_name) {
                                             // Mapper les noms de colonne techniques à des noms plus lisibles
                                             $display_name = ucwords(str_replace(['_', 'id'], [' ', ' ID'], $column_name));
                                             $display_name = str_replace('Current Stock', 'Stock Actuel', $display_name);
                                             $display_name = str_replace('Alert Threshold', 'Seuil Alerte', $display_name);
                                             $display_name = str_replace('Purchase Price', 'Prix Achat', $display_name);
                                             $display_name = str_replace('Sale Price', 'Prix Vente', $display_name);
                                             $display_name = str_replace('Category Name', 'Catégorie', $display_name);
                                             $display_name = str_replace('Movement Type', 'Type Mouvement', $display_name);
                                             $display_name = str_replace('Movement Date', 'Date Mouvement', $display_name);
                                             $display_name = str_replace('Product Name', 'Produit', $display_name);
                                             $display_name = str_replace('Seller Name', 'Vendeur', $display_name);
                                             $display_name = str_replace('Total Amount', 'Montant Total', $display_name);
                                             $display_name = str_replace('Tax Amount', 'Montant Taxe', $display_name);
                                             $display_name = str_replace('Discount Amount', 'Montant Remise', $display_name);
                                             $display_name = str_replace('Final Amount', 'Montant Final', $display_name);
                                             $display_name = str_replace('Sale Date', 'Date Vente', $display_name);
                                             $display_name = str_replace('Contact Person', 'Contact', $display_name);


                                             echo '<th>' . htmlspecialchars($display_name) . '</th>';
                                         }
                                     }
                                     ?>
                                 </tr>
                             </thead>
                             <tbody>
                                 <?php foreach ($report_data as $row): ?>
                                     <tr>
                                         <?php foreach ($row as $key => $value): ?>
                                             <td>
                                                 <?php
                                                 // Formatage spécifique des valeurs pour l'affichage HTML
                                                  $column_name = array_keys($row)[array_search($key, array_keys($row))]; // Get original column name
                                                  $display_value = htmlspecialchars($value);

                                                 if (is_numeric($value) && (strpos($column_name, '_price') !== false || strpos($column_name, '_amount') !== false)) {
                                                      $display_value = htmlspecialchars(number_format($value, 2, ',', ' ') . ' FCFA');
                                                  } elseif ($column_name === 'movement_date' || $column_name === 'sale_date') {
                                                       $display_value = htmlspecialchars(date('d/m/Y H:i', strtotime($value)));
                                                  } elseif ($column_name === 'address' || $column_name === 'notes') {
                                                      $display_value = nl2br(htmlspecialchars($value ?? 'N/A'));
                                                  } else if ($value === null || $value === '') {
                                                       $display_value = 'N/A'; // Afficher N/A pour les valeurs nulles ou vides (sauf 0 pour le stock)
                                                       if ($column_name === 'current_stock' || $column_name === 'alert_threshold' || $column_name === 'quantity') {
                                                            $display_value = htmlspecialchars($value ?? 0); // Stock/Quantité peut être 0
                                                       }
                                                  }


                                                  echo $display_value;
                                                 ?>
                                             </td>
                                         <?php endforeach; ?>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     <?php endif; ?>
                </div>
             </div>
        <?php elseif (!empty($report_type) && !empty($error_message) && $action === '') : ?>
             <?php else: ?>
             <div class="alert alert-info" role="alert">
                 Veuillez sélectionner un type de rapport et cliquer sur "Générer le rapport". Utilisez les filtres disponibles pour affiner votre recherche.
             </div>
        <?php endif; ?>


    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script src="assets/js/script.js"></script>

     <script>
         // Script JavaScript pour afficher les filtres pertinents en fonction du type de rapport sélectionné
         document.addEventListener('DOMContentLoaded', function() {
             const reportTypeSelect = document.getElementById('report_type');
             const filtersSection = document.getElementById('filters-section');
             const filterOptions = document.querySelectorAll('.filter-options');

             // Définir quels filtres sont disponibles pour chaque type de rapport (doit correspondre à $available_reports['filters'] en PHP)
             const reportFiltersMap = {
                 'current_stock': ['category_id'],
                 'low_stock': ['category_id'],
                 'stock_movements': ['date_range', 'product_id', 'movement_type', 'user_id'],
                 'sales_by_period': ['date_range', 'user_id'],
                 // Ajoutez les mappings pour les futurs rapports ici
             };

             function updateFiltersVisibility() {
                 const selectedReportType = reportTypeSelect.value;

                 // Cacher tous les groupes de filtres d'abord
                 filterOptions.forEach(filterDiv => {
                     filterDiv.style.display = 'none';
                     // Désactiver tous les inputs/selects dans les filtres cachés pour qu'ils ne soient pas soumis
                      filterDiv.querySelectorAll('input, select, textarea').forEach(input => {
                          input.disabled = true;
                      });
                 });

                 // Afficher et activer seulement les filtres pertinents pour le type de rapport sélectionné
                 if (selectedReportType && reportFiltersMap[selectedReportType]) {
                      reportFiltersMap[selectedReportType].forEach(filterClass => {
                         const filterDiv = document.querySelector('.filter-' + filterClass);
                         if (filterDiv) {
                             filterDiv.style.display = 'flex'; // Utilisez 'flex' car nos filtres sont des rows Bootstrap
                             filterDiv.querySelectorAll('input, select, textarea').forEach(input => {
                                input.disabled = false; // Activer les inputs/selects
                             });
                         }
                      });
                      filtersSection.style.display = 'flex'; // Afficher la section globale des filtres
                 } else {
                     filtersSection.style.display = 'none'; // Cacher la section globale si aucun rapport sélectionné ou type invalide
                 }
             }

             // Mettre à jour la visibilité des filtres au chargement de la page
             updateFiltersVisibility();

             // Ajouter un écouteur d'événement pour mettre à jour les filtres lorsque le type de rapport change
             reportTypeSelect.addEventListener('change', updateFiltersVisibility);
         });
     </script>
</body>
</html>