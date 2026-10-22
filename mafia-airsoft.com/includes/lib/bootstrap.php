<?php
/**
 * Chargement commun : configuration + bibliothèques du site.
 * Inclus automatiquement par config/session.php (et directement par le webhook Stripe).
 */

if (defined('MAT_BOOTSTRAPPED')) {
    return;
}
define('MAT_BOOTSTRAPPED', true);
define('MAT_ROOT', dirname(__DIR__, 2));

// Configuration (config/app.php, copié depuis config/app.example.php)
if (is_file(MAT_ROOT . '/config/app.php')) {
    require_once MAT_ROOT . '/config/app.php';
}
// Ancienne config email, si elle existe encore sur le serveur
if (!defined('SMTP_HOST') && is_file(MAT_ROOT . '/qr-code/email_config.php')) {
    require_once MAT_ROOT . '/qr-code/email_config.php';
}

// Valeurs par défaut si une constante manque dans config/app.php
$matDefaults = [
    'SITE_URL'                  => 'https://mafia-airsoft.com',
    'SITE_NAME'                 => 'Mafia Airsoft Team',
    'ASSO_NAME'                 => 'Mafia Airsoft Team',
    'ASSO_ADDRESS'              => '',
    'ASSO_RNA'                  => '',
    'ASSO_SIRET'                => '',
    'ASSO_EMAIL'                => 'contact@mafia-airsoft.com',
    'ASSO_VAT_MENTION'          => 'TVA non applicable, article 261-7-1° du CGI',
    'STRIPE_SECRET_KEY'         => '',
    'STRIPE_WEBHOOK_SECRET'     => '',
    'STRIPE_PRODUCT_ID'         => '',
    'DEFAULT_EVENT_PRICE_CENTS' => 1000,
    'MAX_PLACES_PER_ORDER'      => 5,
    'CHECKOUT_HOLD_MINUTES'     => 30,
    'SMTP_HOST'                 => '',
    'SMTP_PORT'                 => 465,
    'SMTP_USERNAME'             => '',
    'SMTP_PASSWORD'             => '',
    'SMTP_SECURE'               => 'ssl',
    'FROM_EMAIL'                => 'contact@mafia-airsoft.com',
    'FROM_NAME'                 => 'Mafia Airsoft Team',
    'REPLY_TO_EMAIL'            => 'contact@mafia-airsoft.com',
    'REPLY_TO_NAME'             => 'Mafia Airsoft Team',
];
foreach ($matDefaults as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
unset($matDefaults, $name, $value);

// Dépendances Composer (TCPDF, PHPMailer) : chargées seulement quand on en a besoin
function require_vendor(): void
{
    static $loaded = false;
    if (!$loaded) {
        $autoload = MAT_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Dépendances manquantes : lancer "composer install" à la racine du site.');
        }
        require_once $autoload;
        $loaded = true;
    }
}

// PDO : on veut des exceptions en cas d'erreur SQL
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/receipts.php';
require_once __DIR__ . '/orders.php';
