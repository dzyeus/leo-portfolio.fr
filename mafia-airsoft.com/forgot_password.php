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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="auth-container">
        <div class="auth-box">
            <h1>Mot de passe oublié</h1>

            <?php if ($sent): ?>
                <div class="alert alert-success">
                    Si un compte existe avec cette adresse, tu vas recevoir un email avec un lien
                    pour choisir un nouveau mot de passe (valable 1 heure). Pense à regarder dans les spams.
                </div>
                <p class="auth-link"><a href="login.php">Retour à la connexion</a></p>
            <?php else: ?>
                <p style="margin-bottom: var(--spacing-md);">Indique l'email de ton compte, on t'envoie un lien pour changer ton mot de passe.</p>
                <form method="POST" action="forgot_password.php" class="auth-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required autocomplete="email">
                    </div>
                    <button type="submit" class="btn btn-primary">Envoyer le lien</button>
                </form>
                <p class="auth-link"><a href="login.php">Retour à la connexion</a></p>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
