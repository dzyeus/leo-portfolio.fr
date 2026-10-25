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
    str_starts_with($key, 'rk_test_') => 'restreinte de TEST (droits nécessaires : Checkout Sessions = Écriture, Produits = Lecture)',
    str_starts_with($key, 'rk_live_') => 'restreinte LIVE (droits nécessaires : Checkout Sessions = Écriture, Produits = Lecture)',
    str_starts_with($key, 'pk_')      => 'PUBLIQUE (mauvaise clé)',
    default                          => 'format inconnu',
};
$keyOk = (bool)preg_match('/^(sk|rk)_(test|live)_/', $key);
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
$siteDomain = preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST));
$fromDomain = strtolower(substr(strrchr(FROM_EMAIL, '@') ?: '', 1));
$fromOk = mail_uses_php_mail()
    ? $fromDomain === strtolower($siteDomain)
    : (strcasecmp(FROM_EMAIL, SMTP_USERNAME) === 0
       || strcasecmp($fromDomain, substr(strrchr(SMTP_USERNAME, '@') ?: '', 1)) === 0);

// Joueurs en attente de confirmation
$pending = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL")->fetchColumn();

function check_line(bool $ok, string $label, string $detail = ''): void
{
    echo '<li class="a-check ' . ($ok ? 'is-ok' : 'is-ko') . '">' . ui_icon($ok ? 'check' : 'x', 17)
        . '<span><strong>' . e($label) . '</strong>' . ($detail !== '' ? '<span class="a-check-detail">' . e($detail) . '</span>' : '') . '</span></li>';
}

function info_line(string $label, string $detailHtml): void
{
    echo '<li class="a-check is-info">' . ui_icon('info', 17) . '<span><strong>' . e($label) . '</strong>'
        . '<span class="a-check-detail">' . $detailHtml . '</span></span></li>';
}
?>
<?php site_head('Diagnostic', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Diagnostic</h1>
                <div class="a-sub">Vérifie le paiement en ligne (Stripe) et l'envoi des emails.</div>
            </div>
        </div>

        <?php if (preg_match('/^(sk|rk)_live_/', $key)): ?>
            <div class="alert alert-warning a-flash">Le site utilise une clé Stripe LIVE : les réservations débitent de vraies cartes.</div>
        <?php endif; ?>

        <div class="a-grid a-grid-2">
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('card', 18) ?> Paiement Stripe</h2></div>
                <ul class="a-checks">
                    <?php check_line($keyOk, 'Clé Stripe', $keyType . ($keyOk ? ' · se termine par …' . substr($key, -4) : '')); ?>
                    <?php check_line($keyClean, 'Pas d\'espace avant ou après la clé'); ?>
                    <?php check_line($whsecOk, 'Clé du webhook (whsec_…)', $whsecOk ? 'renseignée' : 'à renseigner après avoir créé le webhook : sans elle, les paiements ne sont pas confirmés automatiquement'); ?>
                    <?php check_line(str_starts_with(SITE_URL, 'https://'), 'Adresse du site', SITE_URL); ?>
                    <?php info_line('Produit Stripe', STRIPE_PRODUCT_ID !== '' ? e(STRIPE_PRODUCT_ID) . ' (s\'il n\'existe pas avec cette clé, le site s\'en passe automatiquement)' : 'aucun (le nom de la partie est envoyé directement)'); ?>
                </ul>
                <form method="POST" class="a-diag-test">
                    <input type="hidden" name="action" value="stripe">
                    <button type="submit" class="a-btn a-btn-primary"><?= ui_icon('activity', 18) ?> Tester la création d'un paiement</button>
                    <span class="s-hint">Crée une page de paiement de 10 € puis l'annule aussitôt : rien n'est débité.</span>
                </form>
                <?php if ($stripeResult): ?>
                    <?php if ($stripeResult['ok']): ?>
                        <div class="alert alert-success" style="margin-top:1rem;">Stripe fonctionne (mode <?= e($stripeResult['mode']) ?>). Les réservations payantes peuvent démarrer.</div>
                        <?php if ($stripeResult['mode'] === 'LIVE'): ?>
                            <div class="alert alert-warning">Clé <strong>LIVE</strong> : les paiements sont réels. Pour tester, utilise une clé
                                <code>rk_test_…</code> ou <code>sk_test_…</code> de l'environnement de test, avec le webhook de ce même environnement.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-error" style="margin-top:1rem;">Stripe : <?= e($stripeResult['error']) ?></div>
                        <?php if ($stripeResult['hint']): ?>
                            <div class="alert alert-warning"><?= e($stripeResult['hint']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('mail', 18) ?> Emails</h2></div>
                <ul class="a-checks">
                    <?php check_line($configOk, 'Fichier config/app.php présent'); ?>
                    <?php check_line($vendorOk, 'PHPMailer installé (dossier vendor/)'); ?>
                    <?php info_line('Mode d\'envoi', (mail_uses_php_mail() ? 'fonction mail() du serveur' : 'SMTP') . ' (MAIL_TRANSPORT = \'' . e(MAIL_TRANSPORT) . '\')'); ?>
                    <?php if (mail_uses_php_mail()): ?>
                        <?php check_line($fromOk, 'Expéditeur sur le domaine du site', 'FROM_EMAIL : ' . FROM_EMAIL . ' · attendu …@' . $siteDomain . ', sinon les emails finissent en spam'); ?>
                    <?php else: ?>
                        <?php check_line(smtp_configured(), 'SMTP renseigné', 'serveur : ' . (SMTP_HOST ?: 'vide') . ', compte : ' . (SMTP_USERNAME ?: 'vide')); ?>
                        <?php check_line($secureOk, 'Port et sécurité cohérents', SMTP_PORT . ' / ' . (SMTP_SECURE ?: 'aucune') . ' · attendu 465/ssl ou 587/tls'); ?>
                        <?php check_line($fromOk, 'Expéditeur du même domaine que le compte SMTP', 'FROM_EMAIL : ' . FROM_EMAIL); ?>
                        <?php check_line(!preg_match('/gmail|outlook|hotmail|yahoo|office365/i', SMTP_HOST), 'Serveur SMTP chez o2switch',
                            'o2switch bloque Gmail/Outlook en SMTP : utilise MAIL_TRANSPORT = mail ou la boîte contact@ d\'o2switch'); ?>
                    <?php endif; ?>
                </ul>
                <?php if ($pending > 0): ?>
                    <p class="s-hint" style="margin-top:0.75rem;"><?= $pending ?> compte(s) en attente de confirmation d'email :
                        <a class="a-link" href="players.php?filter=pending">les voir dans « Joueurs »</a> (validation manuelle possible).</p>
                <?php endif; ?>

                <form method="POST" class="s-form a-diag-mail">
                    <input type="hidden" name="action" value="email">
                    <div class="s-field">
                        <label for="to">Envoyer un email de test à</label>
                        <input type="email" id="to" name="to" required value="<?= e($to) ?>">
                    </div>
                    <button type="submit" class="a-btn a-btn-primary"><?= ui_icon('send', 18) ?> Envoyer le test</button>
                </form>
            </section>
        </div>

        <?php if ($result): ?>
            <section class="a-card" style="margin-top:1rem;">
                <div class="a-card-head"><h2><?= ui_icon('activity', 18) ?> Résultat de l'envoi</h2></div>
                <?php if ($result['ok']): ?>
                    <div class="alert alert-success">Le serveur mail a accepté l'email. S'il n'arrive pas d'ici quelques minutes, regarde dans les
                        <strong>spams</strong> : vérifie alors SPF / DKIM (cPanel › Délivrabilité des emails).</div>
                <?php else: ?>
                    <div class="alert alert-error">Échec : <?= e($result['error']) ?></div>
                    <?php if ($result['hint']): ?>
                        <div class="alert alert-warning"><?= e($result['hint']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($result['log']): ?>
                    <p class="s-hint" style="margin:0.75rem 0 0.4rem;">Discussion avec le serveur mail (mot de passe masqué) :</p>
                    <pre class="a-log"><?= e(implode("\n", $result['log'])) ?></pre>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
