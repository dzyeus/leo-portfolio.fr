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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renvoyer la confirmation - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="auth-container">
        <div class="auth-box">
            <h1>Confirmer mon email</h1>

            <?php if ($sent): ?>
                <div class="alert alert-success">
                    Si un compte non confirmé existe avec cette adresse, un nouveau lien vient d'être envoyé.
                    Pense à regarder dans les spams.
                </div>
                <p class="auth-link"><a href="login.php">Aller à la connexion</a></p>
            <?php else: ?>
                <form method="POST" action="resend_verification.php" class="auth-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="email">Email du compte</label>
                        <input type="email" id="email" name="email" required value="<?= e($email) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Renvoyer le lien</button>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
