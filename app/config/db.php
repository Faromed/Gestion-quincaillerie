<?php

// Configuration de la base de données
define('DB_HOST', 'localhost'); // Ou l'adresse de votre serveur de base de données
define('DB_NAME', 'quincaillerie_db'); // Le nom de la base de données que vous avez créée
define('DB_USER', 'root'); // Votre nom d'utilisateur MySQL
define('DB_PASS', ''); // Votre mot de passe MySQL

// Configuration générale
define('TAX_RATE', 0.18); // Taux de TVA (Exemple: 18%) - à adapter

// DSN (Data Source Name)
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";

// Options PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lève des exceptions en cas d'erreur
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupère les résultats sous forme de tableau associatif par défaut
    PDO::ATTR_EMULATE_PREPARES   => false,                // Désactive l'émulation des requêtes préparées pour plus de sécurité
];

// Connexion à la base de données
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // echo "Connexion à la base de données réussie !"; // Ligne de test, à commenter ou supprimer en production
} catch (\PDOException $e) {
    // En cas d'erreur, arrête le script et affiche l'erreur (ou enregistre-la dans un log en production)
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
    // die("Erreur de connexion à la base de données : " . $e->getMessage()); // Alternative simple pour les tests
}

// $pdo est maintenant l'objet de connexion que vous utiliserez pour toutes les requêtes
?>