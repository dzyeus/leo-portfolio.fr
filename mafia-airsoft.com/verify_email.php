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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de l'email - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="auth-container">
        <div class="auth-box">
            <h1>Lien invalide</h1>
            <div class="alert alert-error">Ce lien de confirmation est invalide, déjà utilisé ou expiré (48 h).</div>
            <p class="auth-link"><a href="resend_verification.php">Recevoir un nouveau lien</a></p>
            <p class="auth-link"><a href="login.php">Aller à la connexion</a></p>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
