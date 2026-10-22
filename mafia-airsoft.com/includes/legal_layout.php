<?php
/**
 * Mise en page commune des pages légales (CGV, mentions légales, confidentialité).
 * Réutilise le style du site ; seuls quelques espacements de texte sont ajoutés.
 */

function legal_page_start(string $title, string $intro = ''): void
{ ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - Mafia Airsoft Team</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .legal .dashboard-section p, .legal .dashboard-section li { line-height: 1.7; color: #e5e5e5; }
        .legal .dashboard-section p + p { margin-top: 0.75rem; }
        .legal .dashboard-section ul { padding-left: 1.3rem; margin: 0.5rem 0; }
        .legal .dashboard-section h3 { color: #ffffff; margin: 1rem 0 0.5rem; }
        .legal .legal-updated { color: #a3a3a3; margin-bottom: var(--spacing-lg); }
        .legal a { color: var(--primary-light); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container page-content legal">
        <h1><?= e($title) ?></h1>
        <p class="legal-updated">Version du <?= e(date('d/m/Y', strtotime(TERMS_VERSION))) ?><?= $intro ? ' · ' . e($intro) : '' ?></p>
<?php }

function legal_page_end(): void
{ ?>
        <p style="margin-top: var(--spacing-lg);">
            <a href="cgv.php">Conditions générales</a> ·
            <a href="confidentialite.php">Confidentialité</a> ·
            <a href="mentions-legales.php">Mentions légales</a>
        </p>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
<?php }

/** Adresse de l'association sur une ligne. */
function asso_address_inline(): string
{
    return trim(preg_replace('/\s*\n\s*/', ', ', ASSO_ADDRESS));
}
