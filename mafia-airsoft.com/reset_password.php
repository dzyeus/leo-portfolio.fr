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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau mot de passe - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="auth-container">
        <div class="auth-box">
            <h1>Nouveau mot de passe</h1>

            <?php if (!$userId): ?>
                <div class="alert alert-error">Ce lien est invalide ou expiré (il est valable 1 heure).</div>
                <p class="auth-link"><a href="forgot_password.php">Recevoir un nouveau lien</a></p>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="POST" action="reset_password.php" class="auth-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="form-group">
                        <label for="password">Nouveau mot de passe</label>
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                        <small>8 caractères minimum.</small>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirmer</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
