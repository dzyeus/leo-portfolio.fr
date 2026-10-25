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
    } elseif ($action === 'notifications') {
        $notify = !empty($_POST['notify_new_events']) ? 1 : 0;
        $pdo->prepare("UPDATE users SET notify_new_events = ? WHERE id = ?")->execute([$notify, $user['id']]);
        $success = $notify ? 'Alertes activées : tu seras prévenu des nouvelles parties.' : 'Alertes désactivées.';
    } elseif ($action === 'export') {
        // Droit d'accès / portabilité (RGPD) : toutes les données du compte en JSON
        $export = ['compte' => array_diff_key($user, array_flip(['password_hash']))];
        $stmt = $pdo->prepare("SELECT o.*, e.title AS partie FROM orders o JOIN events e ON e.id = o.event_id WHERE o.user_id = ?");
        $stmt->execute([$user['id']]);
        $export['reservations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("
            SELECT r.id, e.title AS partie, e.event_date, r.team, r.first_name, r.last_name, r.pseudo, r.registered_at, t.scanned_at
            FROM registrations r JOIN events e ON e.id = r.event_id LEFT JOIN tickets t ON t.registration_id = r.id
            WHERE r.user_id = ? OR r.order_id IN (SELECT id FROM orders WHERE user_id = ?)
        ");
        $stmt->execute([$user['id'], $user['id']]);
        $export['places'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mes-donnees-mafia-airsoft.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } elseif ($action === 'delete_account') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM registrations r JOIN events e ON e.id = r.event_id
            WHERE e.event_date > NOW() AND (r.user_id = ? OR r.order_id IN (SELECT id FROM orders WHERE user_id = ?))
        ");
        $stmt->execute([$user['id'], $user['id']]);
        $upcoming = (int)$stmt->fetchColumn();

        if ((int)$user['is_admin'] === 1) {
            $errors[] = 'Un compte administrateur ne peut pas être supprimé depuis cette page.';
        } elseif (!password_verify($_POST['delete_password'] ?? '', $user['password_hash'])) {
            $errors[] = 'Mot de passe incorrect : compte non supprimé.';
        } elseif ($upcoming > 0) {
            $errors[] = 'Tu as encore ' . $upcoming . ' place(s) réservée(s) pour des parties à venir. '
                . 'Annule-les (ou contacte un organisateur) avant de supprimer ton compte.';
        } else {
            if (!empty($user['profile_picture'])) {
                delete_upload($user['profile_picture'], 'uploads/profiles/');
            }
            // Les reçus de paiement restent archivés (obligation comptable) sans lien vers le compte
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);
            $_SESSION = [];
            session_regenerate_id(true);
            flash_set('success', 'Ton compte a été supprimé. À bientôt peut-être sur le terrain !');
            redirect('../login.php');
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
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
            session_regenerate_id(true);
            $_SESSION['pwd_fp'] = session_password_fingerprint($newHash); // les autres appareils sont déconnectés
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
site_head('Mon profil', ['noindex' => true]);
include '../includes/header.php';
?>
<main class="s-wrap">
    <a class="s-back" href="dashboard.php"><?= ui_icon('chevronleft', 18) ?> Mon espace</a>
    <div class="a-page-head">
        <div>
            <h1>Mon profil</h1>
            <div class="a-sub">Tes informations, ton mot de passe, tes alertes et tes données.</div>
        </div>
    </div>

    <?php if (!empty($_GET['complete']) && !profile_is_complete($user)): ?>
        <div class="alert alert-info" data-keep>Renseigne ton prénom et ton nom pour pouvoir réserver : ils apparaissent sur ton billet.</div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="a-grid a-grid-main">
        <div class="s-stack">
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('user', 18) ?> Mes informations</h2></div>
                <form method="POST" class="s-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <input type="hidden" name="return" value="<?= e($return) ?>">
                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="first_name">Prénom <span class="s-req">*</span></label>
                            <input type="text" id="first_name" name="first_name" required maxlength="100" autocomplete="given-name" value="<?= e($form['first_name']) ?>">
                        </div>
                        <div class="s-field">
                            <label for="last_name">Nom <span class="s-req">*</span></label>
                            <input type="text" id="last_name" name="last_name" required maxlength="100" autocomplete="family-name" value="<?= e($form['last_name']) ?>">
                        </div>
                    </div>
                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="pseudo">Pseudo de jeu <span class="s-req">*</span></label>
                            <input type="text" id="pseudo" name="pseudo" required maxlength="30" autocomplete="nickname" value="<?= e($form['pseudo']) ?>">
                        </div>
                        <div class="s-field">
                            <label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone" maxlength="20" autocomplete="tel" value="<?= e($form['phone']) ?>">
                        </div>
                    </div>
                    <div class="s-field">
                        <label for="email_ro">Email</label>
                        <input type="email" id="email_ro" value="<?= e($user['email']) ?>" disabled>
                        <span class="s-hint">Pour changer d'adresse email, contacte un organisateur.</span>
                    </div>
                    <button type="submit" class="a-btn a-btn-primary"><?= ui_icon('check', 17) ?> Enregistrer</button>
                </form>
            </section>

            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('lock', 18) ?> Changer de mot de passe</h2></div>
                <form method="POST" class="s-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="s-field">
                        <label for="current_password">Mot de passe actuel</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="new_password">Nouveau mot de passe</label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                            <span class="s-hint">8 caractères minimum.</span>
                        </div>
                        <div class="s-field">
                            <label for="new_password_confirm">Confirmer</label>
                            <input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="a-btn">Changer le mot de passe</button>
                    <p class="s-hint" style="margin-top:0.6rem;">Tes autres appareils seront déconnectés.</p>
                </form>
            </section>
        </div>

        <div class="s-stack">
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('bell', 18) ?> Alertes email</h2></div>
                <form method="POST" class="s-form">
                    <input type="hidden" name="action" value="notifications">
                    <div class="s-check">
                        <input type="checkbox" id="notify_new_events" name="notify_new_events" value="1" <?= !empty($user['notify_new_events']) ? 'checked' : '' ?>>
                        <label for="notify_new_events">Me prévenir par email quand une nouvelle partie est ouverte aux inscriptions</label>
                    </div>
                    <button type="submit" class="a-btn a-btn-sm">Enregistrer</button>
                </form>
            </section>

            <section class="a-card s-danger-zone">
                <div class="a-card-head"><h2><?= ui_icon('shield', 18) ?> Mes données</h2></div>
                <p class="s-muted">Conformément au RGPD, tu peux télécharger toutes les données liées à ton compte ou supprimer ton compte.
                    Voir la <a href="../confidentialite.php">politique de confidentialité</a>.</p>
                <form method="POST" style="margin:1rem 0 1.25rem;">
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="a-btn a-btn-block"><?= ui_icon('download', 17) ?> Télécharger mes données</button>
                </form>

                <?php if ((int)$user['is_admin'] !== 1): ?>
                    <hr class="s-divider">
                    <form method="POST" class="s-form" data-confirm="Supprimer définitivement ton compte ? Cette action est irréversible.">
                        <input type="hidden" name="action" value="delete_account">
                        <div class="s-field">
                            <label for="delete_password">Supprimer mon compte</label>
                            <input type="password" id="delete_password" name="delete_password" required autocomplete="current-password" placeholder="Ton mot de passe pour confirmer">
                            <span class="s-hint">Tes reçus de paiement restent archivés 10 ans (obligation comptable), sans lien avec un compte.</span>
                        </div>
                        <button type="submit" class="a-btn a-btn-danger a-btn-block"><?= ui_icon('trash', 17) ?> Supprimer mon compte</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
</body>
</html>
