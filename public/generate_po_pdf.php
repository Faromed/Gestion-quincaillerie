<?php
// Démarre la session PHP
session_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../app/config/db.php'; // Inclut la connexion DB
require_once __DIR__ . '/../app/includes/functions.php';

// Inclure l'autoload de Dompdf
require_once __DIR__ . '/../vendor/autoload.php';

// Références des classes Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

// Si l'utilisateur n'est PAS connecté, le rediriger vers la page de connexion
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Stockiste) pour générer des bons de commande
$allowed_roles = [1, 3];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    die("Accès non autorisé pour générer des bons de commande."); // Simple message pour l'instant
}

// --- LOGIQUE PHP POUR LA GÉNÉRATION DU BON DE COMMANDE PDF ---

$purchase_order_id = filter_var($_GET['purchase_order_id'] ?? '', FILTER_VALIDATE_INT);

if ($purchase_order_id === false) {
    die("ID de commande fournisseur invalide."); // Gérer l'erreur d'ID invalide
}

// Récupérer les détails de la commande fournisseur
$purchase_order = null;
$po_items = [];

try {
    // Récupérer les informations de la commande principale, joindre le fournisseur
    $sql_po = "SELECT po.*, s.name as supplier_name, s.contact_person, s.email, s.phone, s.address as supplier_address, u.username as creator_name
              FROM purchase_orders po
              JOIN suppliers s ON po.supplier_id = s.id
              LEFT JOIN users u ON po.user_id = u.id
              WHERE po.id = :purchase_order_id LIMIT 1";
    $stmt_po = $pdo->prepare($sql_po);
    $stmt_po->execute([':purchase_order_id' => $purchase_order_id]);
    $purchase_order = $stmt_po->fetch();

    if (!$purchase_order) {
        die("Commande fournisseur introuvable."); // Gérer le cas où la commande n'existe pas
    }

    // Récupérer les articles de cette commande
    $sql_items = "SELECT poi.*, p.name as product_name, p.barcode, p.reference
                  FROM purchase_order_items poi
                  JOIN products p ON poi.product_id = p.id
                  WHERE poi.purchase_order_id = :purchase_order_id";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([':purchase_order_id' => $purchase_order_id]);
    $po_items = $stmt_items->fetchAll();

    if (empty($po_items)) {
         die("Aucun article trouvé pour cette commande fournisseur."); // Gérer le cas où la commande est vide
    }

} catch (\PDOException $e) {
    die("Erreur lors du chargement des données de la commande fournisseur : " . $e->getMessage()); // Gérer les erreurs DB
}

// --- PRÉPARATION DU CONTENU HTML DU BON DE COMMANDE ---

// Informations de l'entreprise (placeholders pour l'instant)
$company_name = "Votre Quincaillerie Moderne";
$company_address = "Votre Adresse, Votre Ville";
$company_phone = "+XXX XXX XXX XXX";
$company_email = "contact@votrequincaillerie.com";
$company_logo_path = 'assets/img/logo.png'; // Chemin vers le logo (à adapter si nécessaire)

// Vérifier si le fichier logo existe et l'incorporer en base64
$logo_html = '';
$logo_absolute_path = realpath(__DIR__ . '/' . $company_logo_path);
if ($company_logo_path && file_exists($logo_absolute_path)) {
    $logo_type = pathinfo($logo_absolute_path, PATHINFO_EXTENSION);
    $logo_data = file_get_contents($logo_absolute_path);
    $base64_logo = 'data:image/' . $logo_type . ';base64,' . base64_encode($logo_data);
    $logo_html = '<img src="' . $base64_logo . '" alt="Logo" style="height: 80px;">';
} else {
     $logo_html = '<h2>' . htmlspecialchars($company_name) . '</h2>';
}


$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bon de Commande N° ' . htmlspecialchars($purchase_order['id']) . '</title>
    <style>
        body { font-family: sans-serif; margin: 5mm; }
        .header { width: 100%; margin-bottom: 20mm; }
        .header td { vertical-align: top; }
        .company-info { width: 50%; }
        .order-info { width: 50%; text-align: right; }
        .order-info h1 { color: #333; }
        .supplier-info { width: 100%; margin-bottom: 10mm; padding: 10px; border: 1px solid #ddd;}
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 20mm; }
        .details-table th, .details-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .details-table th { background-color: #f2f2f2; }
        .totals-table { width: 60%; float: right; border-collapse: collapse; }
        .totals-table th, .totals-table td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        .totals-table th { background-color: #f2f2f2; }
        .footer { width: 100%; text-align: center; margin-top: 30mm; font-size: 0.8em; color: #777; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td class="company-info">
                ' . $logo_html . '
                <p>
                    <strong>' . htmlspecialchars($company_name) . '</strong><br>
                    ' . nl2br(htmlspecialchars($company_address)) . '<br>
                    Téléphone: ' . htmlspecialchars($company_phone) . '<br>
                    Email: ' . htmlspecialchars($company_email) . '
                </p>
            </td>
            <td class="order-info">
                <h1>BON DE COMMANDE</h1>
                <p>
                    <strong>N° Commande :</strong> ' . htmlspecialchars($purchase_order['id']) . '<br>
                    <strong>Date :</strong> ' . htmlspecialchars(date('d/m/Y H:i', strtotime($purchase_order['order_date']))) . '<br>
                    <strong>Créée par :</strong> ' . htmlspecialchars($purchase_order['creator_name'] ?? 'N/A') . '<br>
                    <strong>Statut :</strong> ' . htmlspecialchars($purchase_order['status']) . '
                </p>
            </td>
        </tr>
    </table>

    <h3>Fournisseur</h3>
    <div class="supplier-info">
        <strong>' . htmlspecialchars($purchase_order['supplier_name']) . '</strong><br>
        ' . (!empty($purchase_order['contact_person']) ? 'Contact : ' . htmlspecialchars($purchase_order['contact_person']) . '<br>' : '') . '
        ' . (!empty($purchase_order['email']) ? 'Email : ' . htmlspecialchars($purchase_order['email']) . '<br>' : '') . '
        ' . (!empty($purchase_order['phone']) ? 'Téléphone : ' . htmlspecialchars($purchase_order['phone']) . '<br>' : '') . '
        ' . (!empty($purchase_order['supplier_address']) ? 'Adresse : ' . htmlspecialchars($purchase_order['supplier_address']) . '<br>' : '') . '
    </div>


    <h3>Articles commandés</h3>
    <table class="details-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Référence</th>
                <th>Code-barres</th>
                <th>Quantité Commandée</th>
                <th>Prix Unitaire Achat</th>
                <th>Total Ligne</th>
            </tr>
        </thead>
        <tbody>';

foreach ($po_items as $item) {
    $html .= '
            <tr>
                <td>' . htmlspecialchars($item['product_name']) . '</td>
                <td>' . htmlspecialchars($item['reference'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($item['barcode'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($item['quantity_ordered']) . '</td>
                <td>' . htmlspecialchars(number_format($item['unit_price'], 2, ',', ' ') . ' FCFA') . '</td>
                <td>' . htmlspecialchars(number_format($item['line_total'], 2, ',', ' ') . ' FCFA') . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <th>Total Commande :</th>
            <td>' . htmlspecialchars(number_format($purchase_order['total_amount'], 2, ',', ' ') . ' FCFA') . '</td>
        </tr>
    </table>

    <div style="clear: both;"></div> <!-- Clear float -->

    ' . (!empty($purchase_order['notes']) ? '
    <div style="margin-top: 20mm;">
        <strong>Notes de commande :</strong><br>
        ' . nl2br(htmlspecialchars($purchase_order['notes'])) . '
    </div>' : '') . '


    <div class="footer">
        <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($company_name) . '. Tous droits réservés.</p>
         <p>Bon de commande généré le ' . htmlspecialchars(date('d/m/Y H:i')) . '</p>
    </div>

</body>
</html>';


// --- GÉNÉRATION DU PDF AVEC DOMPDF ---

// Configurer Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Charger le HTML
$dompdf->loadHtml($html);

// Définir la taille et l'orientation
$dompdf->setPaper('A4', 'portrait');

// Rendre le PDF
$dompdf->render();

// Envoyer le PDF au navigateur
$filename = 'Bon_Commande_N_' . $purchase_order['id'] . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]); // false pour afficher inline

exit(0); // Terminer le script

?>