<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireLogin();

$user = current_user($pdo);
if (!$user) {
    logout();
}

$return = safe_return_url($_GET['return'] ?? $_POST['return'] ?? null, '');
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $pseudo = trim($_POST['pseudo'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($problem = pseudo_problem($pseudo)) {
            $errors[] = $problem;
        }
        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            $errors[] = 'Renseigne ton prénom et ton nom (ils apparaissent sur tes billets).';
        }
        if ($phone !== '' && !preg_match('/^[0-9 +().\-]{6,20}$/', $phone)) {
            $errors[] = 'Numéro de téléphone invalide.';
        }
        if (!$errors && mb_strtolower($pseudo) !== mb_strtolower($user['pseudo'])) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE pseudo = ? AND id <> ?");
            $stmt->execute([$pseudo, $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'Ce pseudo est déjà pris.';
            }
        }

        if (!$errors) {
            $pdo->prepare("UPDATE users SET pseudo = ?, first_name = ?, last_name = ?, phone = ? WHERE id = ?")
                ->execute([$pseudo, $firstName, $lastName, $phone ?: null, $user['id']]);
            // Les places du joueur suivent son profil (pour les billets à venir)
            $pdo->prepare("
                UPDATE registrations r JOIN events e ON e.id = r.event_id
                SET r.first_name = ?, r.last_name = ?, r.pseudo = ?
                WHERE r.user_id = ? AND e.event_date > NOW()
            ")->execute([$firstName, $lastName, $pseudo, $user['id']]);

            $_SESSION['pseudo'] = $pseudo;
            if ($return) {
                flash_set('success', 'Profil complété, tu peux réserver.');
                redirect('../' . $return);
            }
            $success = 'Profil mis à jour.';
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $errors[] = 'Mot de passe actuel incorrect.';
        } elseif ($problem = password_problem($new)) {
            $errors[] = $problem;
        } elseif ($new !== ($_POST['new_password_confirm'] ?? '')) {
            $errors[] = 'Les deux nouveaux mots de passe ne correspondent pas.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            $success = 'Mot de passe modifié.';
        }
    }

    $user = current_user($pdo);
}

$form = [
    'pseudo'     => $_POST['pseudo'] ?? $user['pseudo'],
    'first_name' => $_POST['first_name'] ?? $user['first_name'],
    'last_name'  => $_POST['last_name'] ?? $user['last_name'],
    'phone'      => $_POST['phone'] ?? $user['phone'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon profil - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="dashboard">
            <h1><?= icon('edit') ?> Mon profil</h1>

            <?php if (!empty($_GET['complete']) && !profile_is_complete($user)): ?>
                <div class="alert alert-info">Renseigne ton prénom et ton nom pour pouvoir réserver : ils apparaissent sur ton billet.</div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <section class="dashboard-section">
                <h2>Mes informations</h2>
                <form method="POST" class="admin-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <input type="hidden" name="return" value="<?= e($return) ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">Prénom *</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="100" value="<?= e($form['first_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Nom *</label>
                            <input type="text" id="last_name" name="last_name" required maxlength="100" value="<?= e($form['last_name']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="pseudo">Pseudo de jeu *</label>
                            <input type="text" id="pseudo" name="pseudo" required maxlength="30" value="<?= e($form['pseudo']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($form['phone']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" value="<?= e($user['email']) ?>" disabled>
                        <small>Pour changer d'adresse email, contacte un organisateur.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
            </section>

            <section class="dashboard-section">
                <h2>Changer de mot de passe</h2>
                <form method="POST" class="admin-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="form-group">
                        <label for="current_password">Mot de passe actuel</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">Nouveau mot de passe</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                            <small>8 caractères minimum.</small>
                        </div>
                        <div class="form-group">
                            <label for="new_password_confirm">Confirmer</label>
                            <input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Changer le mot de passe</button>
                </form>
            </section>

            <a href="dashboard.php" class="btn btn-secondary">← Retour à mon espace</a>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
