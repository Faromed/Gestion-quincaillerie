<?php
// Démarre la session PHP
session_start();

// Inclure les fichiers nécessaires
require_once __DIR__ . '/../app/config/db.php'; // Inclut la connexion DB et la constante TAX_RATE
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

// Vérifier si l'utilisateur a un rôle autorisé (Admin ou Vendeur) pour générer des factures
$allowed_roles = [1, 2];
if (!in_array(get_current_user_role_id(), $allowed_roles)) {
    // Afficher un message d'erreur ou rediriger
    die("Accès non autorisé pour générer des factures."); // Simple message pour l'instant
}

// --- LOGIQUE PHP POUR LA GÉNÉRATION DE FACTURE ---

$sale_id = filter_var($_GET['sale_id'] ?? '', FILTER_VALIDATE_INT);

if ($sale_id === false) {
    die("ID de vente invalide."); // Gérer l'erreur d'ID invalide
}

// Récupérer les détails de la vente
$sale = null;
$sale_items = [];

try {
    // Récupérer les informations de la vente principale
    $sql_sale = "SELECT s.*, u.username as seller_name
                 FROM sales s
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.id = :sale_id LIMIT 1";
    $stmt_sale = $pdo->prepare($sql_sale);
    $stmt_sale->execute([':sale_id' => $sale_id]);
    $sale = $stmt_sale->fetch();

    if (!$sale) {
        die("Vente introuvable."); // Gérer le cas où la vente n'existe pas
    }

    // Récupérer les articles de cette vente
    $sql_items = "SELECT si.*, p.name as product_name, p.barcode, p.reference
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.id
                  WHERE si.sale_id = :sale_id";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([':sale_id' => $sale_id]);
    $sale_items = $stmt_items->fetchAll();

    if (empty($sale_items)) {
         die("Aucun article trouvé pour cette vente."); // Gérer le cas où la vente est vide (ne devrait pas arriver normalement)
    }

} catch (\PDOException $e) {
    die("Erreur lors du chargement des données de la vente : " . $e->getMessage()); // Gérer les erreurs DB
}

// --- PRÉPARATION DU CONTENU HTML DE LA FACTURE ---

// Informations de l'entreprise (placeholders pour l'instant)
$company_name = "Votre Quincaillerie Moderne";
$company_address = "Votre Adresse, Votre Ville";
$company_phone = "+XXX XXX XXX XXX";
$company_email = "contact@votrequincaillerie.com";
$company_logo_path = 'assets/img/logo.png'; // Chemin vers le logo (à adapter si nécessaire) - Doit être un chemin accessible par Dompdf

// Vérifier si le fichier logo existe
$logo_html = '';
$logo_absolute_path = realpath(__DIR__ . '/' . $company_logo_path); // Obtenir le chemin absolu
if ($company_logo_path && file_exists($logo_absolute_path)) {
    // Incorporer l'image en base64 (plus fiable pour Dompdf)
    $logo_type = pathinfo($logo_absolute_path, PATHINFO_EXTENSION);
    $logo_data = file_get_contents($logo_absolute_path);
    $base64_logo = 'data:image/' . $logo_type . ';base64,' . base64_encode($logo_data);
    $logo_html = '<img src="' . $base64_logo . '" alt="Logo" style="height: 80px;">';
} else {
    // Afficher le nom de l'entreprise en texte si le logo n'existe pas
     $logo_html = '<h2>' . htmlspecialchars($company_name) . '</h2>';
}


$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Facture N° ' . htmlspecialchars($sale['id']) . '</title>
    <style>
        body { font-family: sans-serif; margin: 5mm; }
        .header { width: 100%; margin-bottom: 20mm; }
        .header td { vertical-align: top; }
        .company-info { width: 50%; }
        .invoice-info { width: 50%; text-align: right; }
        .invoice-info h1 { color: #333; }
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 20mm; }
        .details-table th, .details-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .details-table th { background-color: #f2f2f2; }
        .totals-table { width: 50%; float: right; border-collapse: collapse; }
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
            <td class="invoice-info">
                <h1>FACTURE</h1>
                <p>
                    <strong>N° Facture :</strong> ' . htmlspecialchars($sale['id']) . '<br>
                    <strong>Date :</strong> ' . htmlspecialchars(date('d/m/Y H:i', strtotime($sale['sale_date']))) . '<br>
                    <strong>Vendeur :</strong> ' . htmlspecialchars($sale['seller_name'] ?? 'N/A') . '<br>
                    ' . (!empty($sale['customer_name']) ? '<strong>Client :</strong> ' . htmlspecialchars($sale['customer_name']) . '<br>' : '') . '
                </p>
            </td>
        </tr>
    </table>

    <h3>Détails de la vente</h3>
    <table class="details-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Référence</th>
                <th>Code-barres</th>
                <th>Quantité</th>
                <th>Prix Unitaire</th>
                <th>Total Ligne</th>
            </tr>
        </thead>
        <tbody>';

foreach ($sale_items as $item) {
    $html .= '
            <tr>
                <td>' . htmlspecialchars($item['product_name']) . '</td>
                <td>' . htmlspecialchars($item['reference'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($item['barcode'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($item['quantity']) . '</td>
                <td>' . htmlspecialchars(number_format($item['unit_price'], 2, ',', ' ') . ' FCFA') . '</td>
                <td>' . htmlspecialchars(number_format($item['line_total'], 2, ',', ' ') . ' FCFA') . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <th>Sous-total :</th>
            <td>' . htmlspecialchars(number_format($sale['total_amount'], 2, ',', ' ') . ' FCFA') . '</td>
        </tr>';
if ($sale['discount_amount'] > 0) {
    $html .= '
        <tr>
            <th>Remise :</th>
            <td>- ' . htmlspecialchars(number_format($sale['discount_amount'], 2, ',', ' ') . ' FCFA') . '</td>
        </tr>';
}
if ($sale['tax_amount'] > 0) {
    $html .= '
        <tr>
            <th>Taxe :</th>
            <td>' . htmlspecialchars(number_format($sale['tax_amount'], 2, ',', ' ') . ' FCFA') . '</td>
        </tr>';
}
$html .= '
        <tr>
            <th>Total Final :</th>
            <td>' . htmlspecialchars(number_format($sale['final_amount'], 2, ',', ' ') . ' FCFA') . '</td>
        </tr>
    </table>

    <div style="clear: both;"></div> <!-- Clear float -->

    ' . (!empty($sale['notes']) ? '
    <div style="margin-top: 20mm;">
        <strong>Notes :</strong><br>
        ' . nl2br(htmlspecialchars($sale['notes'])) . '
    </div>' : '') . '


    <div class="footer">
        <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($company_name) . '. Tous droits réservés.</p>
         <p>Généré le ' . htmlspecialchars(date('d/m/Y H:i')) . '</p>
    </div>

</body>
</html>';


// --- GÉNÉRATION DU PDF AVEC DOMPDF ---

// Configurer Dompdf (options optionnelles)
$options = new Options();
$options->set('isHtml5ParserEnabled', true); // Permettre le parsing HTML5
$options->set('isRemoteEnabled', true);     // Permettre l'accès aux ressources externes (images, CSS via URL si besoin, mais base64 est plus sûr)
// Ajoutez d'autres options si nécessaire (ex: 'defaultFont' => 'Arial')

$dompdf = new Dompdf($options);

// Charger le HTML dans Dompdf
$dompdf->loadHtml($html);

// (Optionnel) Définir la taille et l'orientation du papier
$dompdf->setPaper('A4', 'portrait'); // Format A4, orientation portrait

// Rendre le HTML en PDF
$dompdf->render();

// Envoyer le PDF au navigateur
// 'Facture_N_XXX.pdf' - Nom du fichier de sortie
// 'inline' - Afficher dans le navigateur
// 'attachment' - Télécharger le fichier
// $dompdf->stream('Facture_N_' . $sale['id'] . '.pdf', ['Attachment' => false]);

exit(0); // Terminer le script

?>