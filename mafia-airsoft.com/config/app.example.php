<?php
/**
 * Configuration de l'application – MODÈLE
 *
 * 1. Copie ce fichier en "config/app.php" sur le serveur
 * 2. Remplis les valeurs ci-dessous
 * 3. Ne commite JAMAIS config/app.php (il est dans .gitignore)
 */

// ---------------------------------------------------------------------
// Site
// ---------------------------------------------------------------------
// URL publique du site, sans "/" à la fin (sert aux liens dans les emails et pour Stripe)
define('SITE_URL', 'https://mafia-airsoft.com');
define('SITE_NAME', 'Mafia Airsoft Team');

// ---------------------------------------------------------------------
// Association (affiché sur les reçus de paiement)
// ---------------------------------------------------------------------
define('ASSO_NAME', 'Mafia Airsoft Team');
define('ASSO_ADDRESS', "Adresse du siège\n63000 Ville");
define('ASSO_RNA', 'W000000000');          // N° RNA (récépissé de préfecture)
define('ASSO_SIRET', '');                   // Laisser vide si pas encore de SIRET
define('ASSO_EMAIL', 'contact@mafia-airsoft.com');
// Mention TVA : une association non lucrative est en général exonérée.
// Vérifie la mention exacte qui s'applique à votre asso.
define('ASSO_VAT_MENTION', 'TVA non applicable, article 261-7-1° du CGI');

// ---------------------------------------------------------------------
// Stripe  (Dashboard Stripe > Développeurs)
// ---------------------------------------------------------------------
// Clé secrète : sk_test_... en test, sk_live_... en production
define('STRIPE_SECRET_KEY', 'sk_test_A_REMPLACER');
// Clé de signature du webhook (Développeurs > Webhooks > ton endpoint) : whsec_...
define('STRIPE_WEBHOOK_SECRET', 'whsec_A_REMPLACER');
// Produit « Place – Partie d'airsoft » (le prix vient de chaque partie)
define('STRIPE_PRODUCT_ID', 'prod_VUJ7xInHQfqLri');

// ---------------------------------------------------------------------
// Parties
// ---------------------------------------------------------------------
define('DEFAULT_EVENT_PRICE_CENTS', 1000);  // Prix proposé à la création d'une partie (10 €)
define('MAX_PLACES_PER_ORDER', 5);          // Nombre max de places par achat
define('CHECKOUT_HOLD_MINUTES', 30);        // Durée de réservation des places pendant le paiement (min. 30)

// ---------------------------------------------------------------------
// Emails (SMTP)
// ---------------------------------------------------------------------
// Si tu avais déjà un fichier qr-code/email_config.php sur le serveur,
// tu peux recopier les mêmes valeurs ici.
define('SMTP_HOST', 'mail.mafia-airsoft.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'contact@mafia-airsoft.com');
define('SMTP_PASSWORD', 'A_REMPLACER');
define('SMTP_SECURE', 'ssl');               // 'ssl' (port 465) ou 'tls' (port 587)

define('FROM_EMAIL', 'contact@mafia-airsoft.com');
define('FROM_NAME', 'Mafia Airsoft Team');
define('REPLY_TO_EMAIL', 'contact@mafia-airsoft.com');
define('REPLY_TO_NAME', 'Mafia Airsoft Team');
