<?php
require_once 'config/database.php';
require_once 'config/session.php';

if (isLoggedIn()) {
    redirect('player/dashboard.php');
}

$return = safe_return_url($_GET['return'] ?? $_POST['return'] ?? null, '');
$errors = [];
$done = false;
$form = [
    'pseudo'     => trim($_POST['pseudo'] ?? ''),
    'first_name' => trim($_POST['first_name'] ?? ''),
    'last_name'  => trim($_POST['last_name'] ?? ''),
    'email'      => trim($_POST['email'] ?? ''),
    'phone'      => trim($_POST['phone'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Piège à robots : champ invisible qui doit rester vide
    if (!empty($_POST['website'])) {
        http_response_code(400);
        exit;
    }

    if (rate_limit_exceeded($pdo, 'register', client_ip(), 5, 60)) {
        $errors[] = 'Trop de comptes créés depuis ta connexion. Réessaie plus tard.';
    }
    if ($problem = pseudo_problem($form['pseudo'])) {
        $errors[] = $problem;
    }
    if ($form['first_name'] === '' || $form['last_name'] === ''
        || mb_strlen($form['first_name']) > 100 || mb_strlen($form['last_name']) > 100) {
        $errors[] = 'Renseigne ton prénom et ton nom (ils apparaissent sur tes billets).';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
        $errors[] = 'Adresse email invalide.';
    }
    if ($form['phone'] !== '' && !preg_match('/^[0-9 +().\-]{6,20}$/', $form['phone'])) {
        $errors[] = 'Numéro de téléphone invalide.';
    }
    if ($problem = password_problem($password)) {
        $errors[] = $problem;
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Les deux mots de passe ne correspondent pas.';
    }
    if (empty($_POST['accept_rules'])) {
        $errors[] = 'Tu dois accepter les conditions générales, la politique de confidentialité et les règles de sécurité.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT email, pseudo FROM users WHERE email = ? OR pseudo = ?");
        $stmt->execute([$form['email'], $form['pseudo']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
            if (mb_strtolower($existing['pseudo']) === mb_strtolower($form['pseudo'])) {
                $errors[] = 'Ce pseudo est déjà pris.';
            }
            if (mb_strtolower($existing['email']) === mb_strtolower($form['email'])) {
                $errors[] = 'Un compte existe déjà avec cet email. <a href="login.php">Se connecter</a> ou <a href="forgot_password.php">mot de passe oublié</a>.';
            }
        }
    }

    if (!$errors) {
        rate_limit_hit($pdo, 'register', client_ip());
        $stmt = $pdo->prepare("
            INSERT INTO users (pseudo, first_name, last_name, email, password_hash, phone, is_admin,
                               terms_accepted_at, terms_version, notify_new_events)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), ?, ?)
        ");
        $stmt->execute([
            $form['pseudo'], $form['first_name'], $form['last_name'], $form['email'],
            password_hash($password, PASSWORD_DEFAULT), $form['phone'] ?: null,
            TERMS_VERSION, !empty($_POST['notify_new_events']) ? 1 : 0,
        ]);
        $user = ['id' => (int)$pdo->lastInsertId(), 'pseudo' => $form['pseudo'], 'email' => $form['email']];

        $done = true;
        $mailSent = send_verification_email($pdo, $user);
    }
}
?>
<?php site_head('Créer un compte', ['noindex' => true]); include 'includes/header.php'; ?>

    <main class="s-wrap">
        <div class="s-auth s-auth-wide"><div class="a-card">
            <div class="s-auth-head"><img src="images/logo.png" alt="" width="64" height="64"><h1>Créer un compte</h1><p>Ton compte sert à réserver tes places et recevoir tes billets.</p></div>

            <?php if ($done): ?>
                <div class="alert alert-success" data-keep>
                    Compte créé ! Un email de confirmation a été envoyé à <strong><?= e($form['email']) ?></strong>.
                    Clique sur le lien qu'il contient pour activer ton compte.
                </div>
                <?php if (!$mailSent): ?>
                    <div class="alert alert-warning">
                        L'email n'a pas pu partir pour le moment.
                        <a href="resend_verification.php?email=<?= urlencode($form['email']) ?>">Le renvoyer</a>
                    </div>
                <?php endif; ?>
                <p class="s-auth-link"><a href="login.php<?= $return ? '?return=' . urlencode($return) : '' ?>">Aller à la connexion</a></p>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $err): ?>
                            <div><?= strip_tags($err, '<a>') /* messages écrits par le site */ ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" class="s-form" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="return" value="<?= e($return) ?>">
                    <div class="s-honeypot" aria-hidden="true">
                        <label for="website">Ne pas remplir</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="s-field">
                        <label for="pseudo">Pseudo de jeu *</label>
                        <input type="text" id="pseudo" name="pseudo" required maxlength="30" autocomplete="nickname"
                               value="<?= e($form['pseudo']) ?>">
                    </div>

                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="first_name">Prénom *</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="100" autocomplete="given-name"
                                   value="<?= e($form['first_name']) ?>">
                        </div>
                        <div class="s-field">
                            <label for="last_name">Nom *</label>
                            <input type="text" id="last_name" name="last_name" required maxlength="100" autocomplete="family-name"
                                   value="<?= e($form['last_name']) ?>">
                        </div>
                    </div>

                    <div class="s-field">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required maxlength="100" autocomplete="email"
                               value="<?= e($form['email']) ?>">
                    </div>

                    <div class="s-field">
                        <label for="phone">Téléphone</label>
                        <input type="tel" id="phone" name="phone" maxlength="20" autocomplete="tel"
                               value="<?= e($form['phone']) ?>">
                        <small class="s-hint">Facultatif, utile pour te prévenir en cas de changement le jour J.</small>
                    </div>

                    <div class="s-field">
                        <label for="password">Mot de passe *</label>
                        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                        <small class="s-hint">8 caractères minimum.</small>
                    </div>

                    <div class="s-field">
                        <label for="password_confirm">Confirmer le mot de passe *</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>

                    <div class="s-check">
                        <input type="checkbox" id="notify_new_events" name="notify_new_events" value="1" <?= !empty($_POST['notify_new_events']) ? 'checked' : '' ?>>
                        <label for="notify_new_events">Me prévenir par email quand une nouvelle partie est ouverte aux inscriptions (désactivable à tout moment).</label>
                    </div>

                    <div class="s-check">
                        <input type="checkbox" id="accept_rules" name="accept_rules" value="1" required <?= !empty($_POST['accept_rules']) ? 'checked' : '' ?>>
                        <label for="accept_rules">J'accepte les <a href="cgv.php" target="_blank">conditions générales</a> et la
                            <a href="confidentialite.php" target="_blank">politique de confidentialité</a>, et je m'engage à respecter
                            les règles de sécurité (protection oculaire obligatoire) et le fair-play. *</label>
                    </div>

                    <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block">Créer mon compte</button>
                </form>

                <p class="s-auth-link">Déjà un compte ?
                    <a href="login.php<?= $return ? '?return=' . urlencode($return) : '' ?>">Se connecter</a></p>
            <?php endif; ?>
        </div></div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
