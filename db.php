<?php
// Connexion à la base du panel.
// Les identifiants ne sont PLUS dans ce fichier (il est sur GitHub) :
// ils sont dans db.config.php, à créer sur le serveur à partir de db.config.example.php.
$dbConfigFile = __DIR__ . '/db.config.php';
if (!is_file($dbConfigFile)) {
    http_response_code(500);
    die('Configuration de la base manquante (db.config.php).');
}
require $dbConfigFile;

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // En prod on évite d'afficher l'erreur exacte
    error_log('Connexion BDD panel : ' . $e->getMessage());
    die("Erreur de connexion à la base de données.");
}
unset($DB_PASS);
