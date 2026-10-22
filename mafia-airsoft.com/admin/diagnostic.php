<?php
/**
 * Diagnostic admin : vérifie la configuration Stripe (paiement) et les emails,
 * avec le message d'erreur exact et une explication en clair.
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$admin = current_user($pdo);
$to = trim($_POST['to'] ?? $admin['email'] ?? '');
$result = null;
$stripeResult = null;
$action = $_POST['action'] ?? '';

// ---------- Stripe : clé et webhook
$key = (string)STRIPE_SECRET_KEY;
$keyPlaceholder = $key === '' || str_contains($key, 'A_REMPLACER');
$keyType = match (true) {
    $keyPlaceholder                  => 'non renseignée',
    str_starts_with($key, 'sk_test_') => 'secrète de TEST',
    str_starts_with($key, 'sk_live_') => 'secrète LIVE (paiements réels)',
    str_starts_with($key, 'rk_')      => 'restreinte',
    str_starts_with($key, 'pk_')      => 'PUBLIQUE (mauvaise clé)',
    default                          => 'format inconnu',
};
$keyOk = str_starts_with($key, 'sk_test_') || str_starts_with($key, 'sk_live_');
$keyClean = $key === trim($key);
$whsec = (string)STRIPE_WEBHOOK_SECRET;
$whsecOk = str_starts_with($whsec, 'whsec_') && !str_contains($whsec, 'A_REMPLACER');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'stripe') {
    // Crée une vraie session de paiement (1 place à 10 €) puis l'annule aussitôt
    $fakeOrder = ['id' => 'diagnostic', 'quantity' => 1, 'unit_price_cents' => 1000,
                  'buyer_email' => $admin['email'] ?? ASSO_EMAIL];
    $fakeEvent = ['id' => 0, 'title' => 'Test diagnostic', 'event_date' => date('Y-m-d H:i:s', time() + 86400)];
    try {
        $session = stripe_create_checkout_session($fakeOrder, $fakeEvent);
        try {
            stripe_request('POST', 'checkout/sessions/' . rawurlencode($session['id']) . '/expire');
        } catch (Throwable $e) {
            // sans importance : elle expirera toute seule
        }
        $stripeResult = ['ok' => true, 'mode' => !empty($session['livemode']) ? 'LIVE' : 'TEST'];
    } catch (Throwable $e) {
        $stripeResult = ['ok' => false, 'error' => $e->getMessage(), 'hint' => stripe_error_hint($e->getMessage())];
    }
}

// ---------- Emails
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'email') {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'error' => 'Adresse email invalide.', 'log' => [], 'hint' => ''];
    } else {
        $result = smtp_test($to);
    }
}

$vendorOk = is_file(MAT_ROOT . '/vendor/autoload.php');
$configOk = is_file(MAT_ROOT . '/config/app.php');
$secureOk = (SMTP_SECURE === 'ssl' && (int)SMTP_PORT === 465) || (SMTP_SECURE === 'tls' && (int)SMTP_PORT === 587);
$fromOk = strcasecmp(FROM_EMAIL, SMTP_USERNAME) === 0
    || strcasecmp(substr(strrchr(FROM_EMAIL, '@') ?: '', 1), substr(strrchr(SMTP_USERNAME, '@') ?: '', 1)) === 0;

// Joueurs en attente de confirmation
$pending = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL")->fetchColumn();

function check_line(bool $ok, string $label, string $detail = ''): void
{
    echo '<li>' . ($ok ? '✅' : '❌') . ' ' . e($label) . ($detail !== '' ? ' <small>(' . e($detail) . ')</small>' : '') . '</li>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic - Administration</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .smtp-log { background:#0a0a0a; border:1px solid var(--tactical-border); border-radius:var(--radius-sm);
                    padding:var(--spacing-md); max-height:420px; overflow:auto; font-family:monospace; font-size:0.8rem;
                    color:#d4d4d4; white-space:pre-wrap; word-break:break-all; }
        .check-list { list-style:none; padding:0; line-height:2; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-panel">
            <div class="page-header">
                <h1>🩺 Diagnostic paiement et emails</h1>
                <a href="index.php" class="btn btn-secondary">← Retour au dashboard</a>
            </div>

            <section class="dashboard-section">
                <h2>💳 Paiement Stripe</h2>
                <ul class="check-list">
                    <?php check_line($keyOk, 'Clé Stripe', $keyType . ($keyOk ? ' – se termine par …' . substr($key, -4) : '')); ?>
                    <?php check_line($keyClean, 'Pas d\'espace avant/après la clé'); ?>
                    <?php check_line($whsecOk, 'Clé du webhook (whsec_…)', $whsecOk ? 'renseignée' : 'à renseigner après avoir créé le webhook – sans elle, les paiements ne sont pas confirmés automatiquement'); ?>
                    <?php check_line(str_starts_with(SITE_URL, 'https://'), 'Adresse du site', SITE_URL); ?>
                    <li>ℹ️ Produit : <?= STRIPE_PRODUCT_ID !== '' ? e(STRIPE_PRODUCT_ID) . ' <small>(s\'il n\'existe pas avec cette clé, le site s\'en passe automatiquement)</small>' : 'aucun (nom envoyé directement)' ?></li>
                </ul>
                <form method="POST" style="margin-top: var(--spacing-md);">
                    <input type="hidden" name="action" value="stripe">
                    <button type="submit" class="btn btn-primary">Tester la création d'un paiement</button>
                    <small style="color:#a3a3a3;">Crée une page de paiement de 10 € puis l'annule aussitôt (rien n'est débité).</small>
                </form>
                <?php if ($stripeResult): ?>
                    <?php if ($stripeResult['ok']): ?>
                        <div class="alert alert-success" style="margin-top: var(--spacing-md);">
                            ✅ Stripe fonctionne (mode <?= e($stripeResult['mode']) ?>). Les réservations payantes peuvent démarrer.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error" style="margin-top: var(--spacing-md);">❌ Stripe : <?= e($stripeResult['error']) ?></div>
                        <?php if ($stripeResult['hint']): ?>
                            <div class="alert alert-warning">💡 <?= e($stripeResult['hint']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="dashboard-section">
                <h2>✉️ Emails : configuration</h2>
                <ul class="check-list">
                    <?php check_line($configOk, 'Fichier config/app.php présent'); ?>
                    <?php check_line($vendorOk, 'PHPMailer installé (dossier vendor/)'); ?>
                    <?php check_line(smtp_configured(), 'SMTP renseigné', 'serveur : ' . (SMTP_HOST ?: 'vide') . ', compte : ' . (SMTP_USERNAME ?: 'vide')); ?>
                    <?php check_line($secureOk, 'Port et sécurité cohérents', SMTP_PORT . ' / ' . (SMTP_SECURE ?: 'aucune') . ' — attendu 465/ssl ou 587/tls'); ?>
                    <?php check_line($fromOk, 'Expéditeur du même domaine que le compte SMTP', 'FROM_EMAIL : ' . FROM_EMAIL); ?>
                    <?php check_line(str_ends_with(strtolower(SMTP_HOST), '.o2switch.net'), 'Serveur au format xxxx.o2switch.net',
                        'recommandé par o2switch pour éviter les erreurs de certificat'); ?>
                </ul>
                <?php if ($pending > 0): ?>
                    <p style="margin-top: var(--spacing-md);">⏳ <?= $pending ?> compte(s) en attente de confirmation d'email.
                        <a href="players.php">Les voir dans « Joueurs »</a> (validation manuelle possible).</p>
                <?php endif; ?>
            </section>

            <section class="dashboard-section">
                <h2>✉️ Envoyer un email de test</h2>
                <form method="POST" class="admin-form">
                    <input type="hidden" name="action" value="email">
                    <div class="form-group">
                        <label for="to">Adresse de réception</label>
                        <input type="email" id="to" name="to" required value="<?= e($to) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Envoyer le test</button>
                </form>
            </section>

            <?php if ($result): ?>
                <section class="dashboard-section">
                    <h2>Résultat de l'envoi</h2>
                    <?php if ($result['ok']): ?>
                        <div class="alert alert-success">
                            ✅ Le serveur mail a accepté l'email. S'il n'arrive pas d'ici quelques minutes, regarde dans les
                            <strong>spams</strong> : il faut alors vérifier SPF / DKIM (cPanel › Délivrabilité des emails).
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error">❌ Échec : <?= e($result['error']) ?></div>
                        <?php if ($result['hint']): ?>
                            <div class="alert alert-warning">💡 <?= e($result['hint']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($result['log']): ?>
                        <p style="margin: var(--spacing-md) 0 var(--spacing-sm);">Discussion avec le serveur mail (mot de passe masqué) :</p>
                        <div class="smtp-log"><?= e(implode("\n", $result['log'])) ?></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
