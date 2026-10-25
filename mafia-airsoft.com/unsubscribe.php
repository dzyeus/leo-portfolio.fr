<?php
/**
 * Désabonnement des alertes « nouvelle partie » depuis le lien de l'email.
 * Le lien est signé (HMAC) : pas besoin d'être connecté.
 * Gère aussi le désabonnement « en un clic » des messageries (RFC 8058).
 */
define('SKIP_CSRF', true); // protégé par la signature du lien
require_once 'config/database.php';
require_once 'config/session.php';

$userId = (int)($_GET['u'] ?? 0);
$token = (string)($_GET['t'] ?? '');
$valid = $userId > 0 && hash_equals(unsubscribe_token($userId), $token);
$done = false;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("UPDATE users SET notify_new_events = 0 WHERE id = ?")->execute([$userId]);
    $done = true;
    // Désabonnement automatique par la messagerie : pas besoin de page
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code(200);
        exit('OK');
    }
}
?>
<?php site_head('Alertes email', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Alertes email</h1></div>

            <?php if (!$valid): ?>
                <div class="alert alert-error">Ce lien de désabonnement est invalide.</div>
                <p class="s-auth-link">Tu peux gérer tes alertes depuis <a href="player/profile.php">ton profil</a>.</p>
            <?php elseif ($done): ?>
                <div class="alert alert-success" data-keep>C'est fait : tu ne recevras plus d'email pour les nouvelles parties.</div>
                <p class="s-auth-link">Tu peux te réabonner à tout moment depuis <a href="player/profile.php">ton profil</a>.</p>
            <?php else: ?>
                <p style="margin-bottom: var(--spacing-md);">Ne plus recevoir d'email quand une nouvelle partie est ouverte aux inscriptions ?</p>
                <form method="POST" action="unsubscribe.php?u=<?= $userId ?>&amp;t=<?= e($token) ?>" class="s-form">
                    <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Me désabonner</button>
                </form>
            <?php endif; ?>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
