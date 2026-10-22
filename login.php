<?php
require_once __DIR__ . '/config.php';

$error = '';
$username = '';

// Si le formulaire est soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_valid()) {
        $error = "Session expirée, recharge la page.";
    } elseif (login_blocked()) {
        $error = "Trop de tentatives. Réessaie dans 15 minutes.";
    } elseif ($username === '' || $password === '') {
        $error = "Merci de remplir tous les champs.";
    } else {
        // Récupérer l'admin depuis la base
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        // Hachage factice si le compte n'existe pas : même temps de réponse
        $hash = $admin['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuR2Ur1Zt/5i8xF2y5b9w0bNq3nX0q2ry';
        if (password_verify($password, $hash) && $admin) {
            // Connexion OK → nouvelle session (anti vol de session) puis infos de l'admin
            login_succeeded();
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];

            // Redirection vers le panel
            header('Location: panel.php');
            exit;
        } else {
            login_failed();
            $error = "Identifiants incorrects.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion - Panel</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">

    <div class="card card-centered">
        <h2>Connexion au panel</h2>

        <?php if ($error): ?>
            <p class="alert-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    placeholder="Admin">
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="********">
            </div>

            <button type="submit" class="btn-primary btn-full">
                Se connecter
            </button>

            <p class="back-link">
                <a href="index.html" class="link-muted">
                    Retour au portfolio
                </a>
            </p>
        </form>
    </div>
</body>
</html>
