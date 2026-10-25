<?php
require_once 'config/database.php';
require_once 'config/session.php';

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL)
        && !rate_limit_exceeded($pdo, 'reset', $email, 3, 60)
        && !rate_limit_exceeded($pdo, 'reset_ip', client_ip(), 10, 60)) {
        rate_limit_hit($pdo, 'reset', $email);
        rate_limit_hit($pdo, 'reset_ip', client_ip());

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            send_password_reset_email($pdo, $user);
        }
    }
    // Même message dans tous les cas (on ne révèle pas quels emails ont un compte)
    $sent = true;
}
?>
<?php site_head('Mot de passe oublié', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Mot de passe oublié</h1><p>Indique ton email : on t'envoie un lien pour en choisir un nouveau.</p></div>

            <?php if ($sent): ?>
                <div class="alert alert-success" data-keep>
                    Si un compte existe avec cette adresse, tu vas recevoir un email avec un lien
                    pour choisir un nouveau mot de passe (valable 1 heure). Pense à regarder dans les spams.
                </div>
                <p class="s-auth-link"><a href="login.php">Retour à la connexion</a></p>
            <?php else: ?>
                <form method="POST" action="forgot_password.php" class="s-form">
                    <?= csrf_field() ?>
                    <div class="s-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required autocomplete="email">
                    </div>
                    <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Envoyer le lien</button>
                </form>
                <p class="s-auth-link"><a href="login.php">Retour à la connexion</a></p>
            <?php endif; ?>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
