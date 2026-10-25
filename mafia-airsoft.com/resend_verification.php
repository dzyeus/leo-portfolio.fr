<?php
require_once 'config/database.php';
require_once 'config/session.php';

$sent = false;
$email = trim($_POST['email'] ?? $_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (filter_var($email, FILTER_VALIDATE_EMAIL)
        && !rate_limit_exceeded($pdo, 'resend_verify', $email, 3, 60)
        && !rate_limit_exceeded($pdo, 'resend_verify_ip', client_ip(), 10, 60)) {
        rate_limit_hit($pdo, 'resend_verify', $email);
        rate_limit_hit($pdo, 'resend_verify_ip', client_ip());

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND email_verified_at IS NULL");
        $stmt->execute([$email]);
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            send_verification_email($pdo, $user);
        }
    }
    // Même message dans tous les cas (on ne révèle pas quels emails ont un compte)
    $sent = true;
}
?>
<?php site_head('Renvoyer la confirmation', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Confirmer mon email</h1></div>

            <?php if ($sent): ?>
                <div class="alert alert-success" data-keep>
                    Si un compte non confirmé existe avec cette adresse, un nouveau lien vient d'être envoyé.
                    Pense à regarder dans les spams.
                </div>
                <p class="s-auth-link"><a href="login.php">Aller à la connexion</a></p>
            <?php else: ?>
                <form method="POST" action="resend_verification.php" class="s-form">
                    <?= csrf_field() ?>
                    <div class="s-field">
                        <label for="email">Email du compte</label>
                        <input type="email" id="email" name="email" required value="<?= e($email) ?>">
                    </div>
                    <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Renvoyer le lien</button>
                </form>
            <?php endif; ?>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
