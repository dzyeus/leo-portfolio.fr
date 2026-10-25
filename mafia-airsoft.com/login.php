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

        // Hachage factice si le compte n'existe pas : même temps de réponse (pas de devinette d'emails)
        $hash = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuR2Ur1Zt/5i8xF2y5b9w0bNq3nX0q2ry';
        if (password_verify($password, $hash) && $user) {
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
<?php site_head('Connexion', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Connexion</h1><p>Content de te revoir sur le terrain.</p></div>

            <?php render_flashes($flashes); ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= e($error) ?>
                    <?php if ($unverifiedEmail): ?>
                        <br><a href="resend_verification.php?email=<?= urlencode($unverifiedEmail) ?>">Renvoyer l'email de confirmation</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="s-form">
                <?= csrf_field() ?>
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <div class="s-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autocomplete="email"
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="s-field">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>

                <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Se connecter</button>
            </form>

            <p class="s-auth-link"><a href="forgot_password.php">Mot de passe oublié ?</a></p>
            <p class="s-auth-link">Pas encore de compte ?
                <a href="register.php<?= $return ? '?return=' . urlencode($return) : '' ?>">Créer un compte</a></p>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
