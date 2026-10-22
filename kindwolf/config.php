<?php
// config.php - Configuration Base de donnees

// Configuration de la base de donnees
// define('DB_HOST', 'localhost');
// // define('DB_NAME', 'kindwolf_db');
// define('DB_USER', 'root');
// define('DB_PASS', '');

// Configuration de la base de donnees serveur
// Les identifiants ne sont PLUS dans ce fichier (il est sur GitHub) : ils sont dans
// config.local.php, à créer sur le serveur à partir de config.local.example.php.
if (!is_file(__DIR__ . '/config.local.php')) {
    http_response_code(500);
    die('Configuration manquante (config.local.php).');
}
require_once __DIR__ . '/config.local.php';

// Configuration de l'URL de base
// IMPORTANT: Changez '/kindwolf' par '/' si votre site est à la racine du domaine
// ou par '/votre-dossier' si votre site est dans un sous-dossier
define('BASE_URL', '/kindwolf');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Ne jamais afficher le détail aux visiteurs (il peut contenir des infos sur le serveur)
    error_log('Connexion BDD kindwolf : ' . $e->getMessage());
    die("Erreur de connexion à la base de données.");
}

// Parametres du site
define('SITE_NAME', 'KIND WOLF');
define('SITE_EMAIL', 'contact@kindwolf.com');
?>