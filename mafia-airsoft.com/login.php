<?php
require_once 'config/database.php';
require_once 'config/session.php';

$return = safe_return_url($_GET['return'] ?? $_POST['return'] ?? null, '');

if (isLoggedIn()) {
    redirect($return ?: (isAdmin() ? 'admin/index.php' : 'player/dashboard.php'));
}

$error = '';
$unverifiedEmail = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = client_ip();

    if ($email === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (rate_limit_exceeded($pdo, 'login', $email, 5, 15) || rate_limit_exceeded($pdo, 'login_ip', $ip, 20, 15)) {
        $error = 'Trop de tentatives. Réessaie dans 15 minutes ou utilise « Mot de passe oublié ».';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if (empty($user['email_verified_at'])) {
                $unverifiedEmail = $user['email'];
                $error = 'Ton adresse email n\'est pas encore confirmée. Clique sur le lien reçu par email.';
            } else {
                // Mise à niveau automatique du hachage si l'algorithme a évolué
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                rate_limit_clear($pdo, 'login', $email);
                login_user($pdo, $user);

                if ($return) {
                    redirect($return);
                }
                redirect((int)$user['is_admin'] === 1 ? 'admin/index.php' : 'player/dashboard.php');
            }
        } else {
            rate_limit_hit($pdo, 'login', $email);
            rate_limit_hit($pdo, 'login_ip', $ip);
            $error = 'Email ou mot de passe incorrect.';
        }
    }
}

$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="auth-container">
        <div class="auth-box">
            <h1>Connexion</h1>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= e($error) ?>
                    <?php if ($unverifiedEmail): ?>
                        <br><a href="resend_verification.php?email=<?= urlencode($unverifiedEmail) ?>">Renvoyer l'email de confirmation</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autocomplete="email"
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary">Se connecter</button>
            </form>

            <p class="auth-link"><a href="forgot_password.php">Mot de passe oublié ?</a></p>
            <p class="auth-link">Pas encore de compte ?
                <a href="register.php<?= $return ? '?return=' . urlencode($return) : '' ?>">Créer un compte</a></p>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
