<?php
require_once 'config/database.php';
require_once 'config/session.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$userId = user_token_check($pdo, $token, 'reset_password');
$error = '';

if ($userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = $_POST['password'] ?? '';

    if ($problem = password_problem($password)) {
        $error = $problem;
    } elseif ($password !== ($_POST['password_confirm'] ?? '')) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } elseif (user_token_consume($pdo, $token, 'reset_password') === $userId) {
        // Le lien prouve que la personne a accès à l'email : on le considère confirmé
        $pdo->prepare("
            UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?
        ")->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        $pdo->prepare("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'reset_password'")->execute([$userId]);

        flash_set('success', 'Mot de passe modifié. Tu peux te connecter.');
        redirect('login.php');
    } else {
        $userId = null;
    }
}
?>
<?php site_head('Nouveau mot de passe', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Nouveau mot de passe</h1></div>

            <?php if (!$userId): ?>
                <div class="alert alert-error">Ce lien est invalide ou expiré (il est valable 1 heure).</div>
                <p class="s-auth-link"><a href="forgot_password.php">Recevoir un nouveau lien</a></p>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="POST" action="reset_password.php" class="s-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="s-field">
                        <label for="password">Nouveau mot de passe</label>
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                        <small class="s-hint">8 caractères minimum.</small>
                    </div>
                    <div class="s-field">
                        <label for="password_confirm">Confirmer</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Enregistrer</button>
                </form>
            <?php endif; ?>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
