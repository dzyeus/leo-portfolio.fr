<?php
require_once 'config/database.php';
require_once 'config/session.php';

$token = (string)($_GET['token'] ?? '');
$userId = user_token_consume($pdo, $token, 'verify_email');

if ($userId) {
    $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
        ->execute([$userId]);
    flash_set('success', 'Ton email est confirmé, tu peux te connecter.');
    redirect('login.php');
}
?>
<?php site_head("Confirmation de l'email", ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Lien invalide</h1></div>
            <div class="alert alert-error">Ce lien de confirmation est invalide, déjà utilisé ou expiré (48 h).</div>
            <p class="s-auth-link"><a href="resend_verification.php">Recevoir un nouveau lien</a></p>
            <p class="s-auth-link"><a href="login.php">Aller à la connexion</a></p>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
