<?php
/**
 * Panel admin – Joueurs
 * Recherche, filtres (à confirmer, abonnés, admins), validation d'email,
 * droits administrateur, photo, suppression, ajout manuel d'un joueur.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
requireAdmin();

$filter = (string)($_GET['filter'] ?? 'all');
$q = trim((string)($_GET['q'] ?? ''));
$backUrl = 'players.php?' . http_build_query(array_filter(['filter' => $filter !== 'all' ? $filter : null, 'q' => $q ?: null]));
$addError = '';
$addForm = [];

// ---------- Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $userId = (int)($_POST['user_id'] ?? 0);
    $me = current_user_id();

    if ($action === 'add_player') {
        $addForm = [
            'pseudo'     => trim($_POST['pseudo'] ?? ''),
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name'  => trim($_POST['last_name'] ?? ''),
            'email'      => trim($_POST['email'] ?? ''),
            'phone'      => trim($_POST['phone'] ?? ''),
        ];
        $password = $_POST['password'] ?? '';

        if (in_array('', [$addForm['pseudo'], $addForm['first_name'], $addForm['last_name'], $addForm['email']], true) || $password === '') {
            $addError = 'Pseudo, prénom, nom, email et mot de passe sont obligatoires.';
        } elseif ($problem = pseudo_problem($addForm['pseudo'])) {
            $addError = $problem;
        } elseif (!filter_var($addForm['email'], FILTER_VALIDATE_EMAIL)) {
            $addError = 'Email invalide.';
        } elseif ($problem = password_problem($password)) {
            $addError = $problem;
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR pseudo = ?");
            $stmt->execute([$addForm['email'], $addForm['pseudo']]);
            if ($stmt->fetch()) {
                $addError = 'Cet email ou ce pseudo est déjà utilisé.';
            }
        }

        if ($addError === '') {
            $picture = null;
            if (!empty($_FILES['profile_picture']['name']) && ($ext = uploaded_image_extension($_FILES['profile_picture'], 3 * 1024 * 1024))) {
                $name = random_upload_name('profile', $ext);
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], MAT_ROOT . '/uploads/profiles/' . $name)) {
                    $picture = 'uploads/profiles/' . $name;
                }
            }
            // Compte créé par un admin : email considéré comme vérifié
            $pdo->prepare("
                INSERT INTO users (pseudo, first_name, last_name, email, email_verified_at, password_hash, phone, profile_picture)
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
            ")->execute([$addForm['pseudo'], $addForm['first_name'], $addForm['last_name'], $addForm['email'],
                         password_hash($password, PASSWORD_DEFAULT), $addForm['phone'] ?: null, $picture]);
            flash_set('success', 'Joueur « ' . $addForm['pseudo'] . ' » ajouté.');
            header('Location: ' . $backUrl);
            exit;
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            flash_set('error', 'Joueur introuvable.');
        } elseif ($action === 'verify_email') {
            $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")->execute([$userId]);
            flash_set('success', 'Email de ' . $target['pseudo'] . ' validé : il peut se connecter.');
        } elseif ($action === 'update_email') {
            $newEmail = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $taken = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?");
            $taken->execute([$newEmail, $userId]);
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 100) {
                flash_set('error', 'Adresse email invalide : rien n\'a été modifié.');
            } elseif ($newEmail === mb_strtolower($target['email'])) {
                flash_set('info', 'C\'est déjà l\'adresse de ' . $target['pseudo'] . '.');
            } elseif ($taken->fetchColumn()) {
                flash_set('error', 'Cette adresse est déjà utilisée par un autre compte.');
            } else {
                // Changée par un organisateur en accord avec le joueur : le compte reste confirmé
                $pdo->prepare("UPDATE users SET email = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
                    ->execute([$newEmail, $userId]);
                $html = '<h2 style="color:#ffffff;margin-top:0;">Adresse email mise à jour</h2>'
                    . '<p>Salut ' . e($target['pseudo']) . ', un organisateur a enregistré cette adresse sur ton compte ' . e(SITE_NAME) . '. '
                    . 'C\'est désormais elle qui sert pour te connecter et recevoir tes billets.</p>'
                    . mail_button(SITE_URL . '/login.php', 'Me connecter')
                    . '<p style="font-size:13px;color:#a3a3a3;">Si ce n\'est pas ton compte, ignore cet email ou réponds-nous.</p>';
                $sent = send_mail($newEmail, $target['pseudo'], 'Ton adresse email a été mise à jour', $html);
                flash_set('success', 'Email de ' . $target['pseudo'] . ' changé en ' . $newEmail
                    . ($sent ? ' : un email de vérification vient de lui être envoyé.' : ' (l\'email de vérification n\'a pas pu partir : voir Diagnostic).'));
            }
        } elseif ($action === 'resend_verification') {
            if (!empty($target['email_verified_at'])) {
                flash_set('info', 'Ce compte est déjà confirmé.');
            } elseif (send_verification_email($pdo, $target)) {
                flash_set('success', 'Email de confirmation renvoyé à ' . $target['email'] . '.');
            } else {
                flash_set('error', 'L\'email n\'a pas pu partir. Va dans Diagnostic pour voir pourquoi.');
            }
        } elseif ($action === 'update_picture') {
            $ext = uploaded_image_extension($_FILES['profile_picture'] ?? [], 3 * 1024 * 1024);
            if (!$ext) {
                flash_set('error', 'Image invalide (JPG, PNG, GIF ou WEBP, 3 Mo max).');
            } else {
                $name = random_upload_name('profile', $ext);
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], MAT_ROOT . '/uploads/profiles/' . $name)) {
                    if (!empty($target['profile_picture'])) {
                        delete_upload($target['profile_picture'], 'uploads/profiles/');
                    }
                    $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute(['uploads/profiles/' . $name, $userId]);
                    flash_set('success', 'Photo de ' . $target['pseudo'] . ' mise à jour.');
                }
            }
        } elseif ($action === 'toggle_admin') {
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
            if ($userId === $me) {
                flash_set('error', 'Tu ne peux pas modifier tes propres droits.');
            } elseif ((int)$target['is_admin'] === 1 && $admins <= 1) {
                flash_set('error', 'Il doit rester au moins un administrateur.');
            } else {
                $new = (int)$target['is_admin'] === 1 ? 0 : 1;
                $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$new, $userId]);
                flash_set('success', $new
                    ? $target['pseudo'] . ' est maintenant administrateur (accès au panel et au scanner).'
                    : $target['pseudo'] . ' n\'est plus administrateur.');
            }
        } elseif ($action === 'delete_player') {
            if ((int)$target['is_admin'] === 1) {
                flash_set('error', 'Retire d\'abord les droits administrateur avant de supprimer ce compte.');
            } else {
                if (!empty($target['profile_picture'])) {
                    delete_upload($target['profile_picture'], 'uploads/profiles/');
                }
                $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0")->execute([$userId]);
                flash_set('success', 'Compte de ' . $target['pseudo'] . ' supprimé (ses reçus de paiement restent archivés).');
            }
        }
        header('Location: ' . $backUrl);
        exit;
    }
}

// ---------- Liste
$filters = [
    'all'        => ['Tous', '1=1'],
    'pending'    => ['À confirmer', 'u.email_verified_at IS NULL'],
    'subscribed' => ['Abonnés alertes', 'u.notify_new_events = 1 AND u.email_verified_at IS NOT NULL'],
    'admins'     => ['Admins', 'u.is_admin = 1'],
];
if (!isset($filters[$filter])) {
    $filter = 'all';
}
$counts = [];
foreach ($filters as $key => [, $cond]) {
    $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE $cond")->fetchColumn();
}

$where = $filters[$filter][1];
$params = [];
if ($q !== '') {
    $where .= " AND (u.pseudo LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)";
    $params = array_fill(0, 5, '%' . $q . '%');
}
$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM registrations r WHERE r.user_id = u.id) AS games,
           (SELECT MAX(e.event_date) FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = u.id) AS last_game,
           (SELECT COALESCE(SUM(o.amount_cents), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid') AS spent
    FROM users u
    WHERE $where
    ORDER BY u.is_admin DESC, u.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);
$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Joueurs - Administration</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="a-page-head">
            <div>
                <h1>Joueurs</h1>
                <div class="a-sub"><?= $counts['all'] ?> compte(s) · <?= $counts['subscribed'] ?> abonné(s) aux alertes</div>
            </div>
            <div class="a-actions">
                <a class="a-btn" href="message.php?target=<?= $filter === 'subscribed' ? 'subscribers' : 'all' ?>"><?= admin_svg('send', 18) ?>
                    <?= $filter === 'subscribed' ? 'Écrire aux abonnés' : 'Écrire à tous' ?></a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <details class="a-collapse" <?= $addError ? 'open' : '' ?>>
            <summary><?= admin_svg('plus', 18) ?> Ajouter un joueur manuellement</summary>
            <div class="a-collapse-body">
                <?php if ($addError): ?><div class="alert alert-error"><?= e($addError) ?></div><?php endif; ?>
                <p class="a-small a-muted" style="margin-bottom:0.75rem;">Les joueurs peuvent aussi créer leur compte eux-mêmes sur le site. Un compte ajouté ici est confirmé d'office.</p>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="action" value="add_player">
                    <div class="form-row">
                        <div class="form-group"><label for="pseudo">Pseudo *</label>
                            <input type="text" id="pseudo" name="pseudo" required maxlength="30" value="<?= e($addForm['pseudo'] ?? '') ?>"></div>
                        <div class="form-group"><label for="email">Email *</label>
                            <input type="email" id="email" name="email" required value="<?= e($addForm['email'] ?? '') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="first_name">Prénom *</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="100" value="<?= e($addForm['first_name'] ?? '') ?>"></div>
                        <div class="form-group"><label for="last_name">Nom *</label>
                            <input type="text" id="last_name" name="last_name" required maxlength="100" value="<?= e($addForm['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="password">Mot de passe *</label>
                            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                            <small>8 caractères minimum (le joueur pourra le changer, ou utiliser « mot de passe oublié »)</small></div>
                        <div class="form-group"><label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone" value="<?= e($addForm['phone'] ?? '') ?>"></div>
                    </div>
                    <div class="form-group"><label for="profile_picture">Photo (facultatif)</label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*"></div>
                    <button type="submit" class="a-btn a-btn-primary"><?= admin_svg('plus', 18) ?> Ajouter le joueur</button>
                </form>
            </div>
        </details>

        <div class="a-toolbar">
            <nav class="a-tabs" aria-label="Filtrer les joueurs">
                <?php foreach ($filters as $key => [$label]): ?>
                    <a class="a-tab<?= $filter === $key ? ' is-active' : '' ?>" href="?filter=<?= $key ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                        <?= e($label) ?><span class="a-count"><?= $counts[$key] ?></span></a>
                <?php endforeach; ?>
            </nav>
            <form class="a-search" method="GET" role="search">
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Pseudo, nom, email, téléphone…" aria-label="Rechercher un joueur">
                <button type="submit" class="a-btn a-btn-icon" title="Rechercher"><?= admin_svg('search', 18) ?></button>
            </form>
        </div>

        <section class="a-card">
            <?php if (!$players): ?>
                <p class="a-empty"><?= $q !== '' ? 'Aucun joueur ne correspond à « ' . e($q) . ' ».' : 'Aucun joueur dans cette catégorie.' ?></p>
            <?php else: ?>
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Joueur</th>
                            <th>Contact</th>
                            <th>Parties</th>
                            <th>Payé</th>
                            <th>Statut</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($players as $p): $isMe = (int)$p['id'] === current_user_id(); ?>
                            <tr>
                                <td class="t-head">
                                    <span style="display:flex;align-items:center;gap:0.65rem;">
                                        <?php if ($p['profile_picture']): ?>
                                            <img class="a-avatar" src="../<?= e($p['profile_picture']) ?>" alt="">
                                        <?php else: ?>
                                            <span class="a-avatar a-avatar-empty"><?= e(mb_strtoupper(mb_substr($p['pseudo'], 0, 1))) ?></span>
                                        <?php endif; ?>
                                        <span style="min-width:0;">
                                            <span class="t-title"><?= e($p['pseudo']) ?><?= $isMe ? ' (toi)' : '' ?></span>
                                            <span class="t-sub"><?= e(full_name($p['first_name'], $p['last_name']) ?: 'Nom non renseigné') ?> · membre depuis <?= e(date('m/Y', strtotime($p['created_at']))) ?></span>
                                        </span>
                                    </span>
                                </td>
                                <td data-label="Contact">
                                    <span>
                                        <a class="a-link" href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a>
                                        <button type="button" class="a-email-edit" title="Corriger l'adresse email"
                                                data-user="<?= (int)$p['id'] ?>" data-email="<?= e($p['email']) ?>" data-pseudo="<?= e($p['pseudo']) ?>"><?= admin_svg('edit', 14) ?></button>
                                        <?php if ($p['phone']): ?><span class="t-sub"><a class="a-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>"><?= e($p['phone']) ?></a></span><?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Parties">
                                    <span><?= (int)$p['games'] ?>
                                        <?php if ($p['last_game']): ?><span class="t-sub">dernière : <?= e(date('d/m/Y', strtotime($p['last_game']))) ?></span><?php endif; ?></span>
                                </td>
                                <td data-label="Payé"><span class="t-num"><?= e(format_price((int)$p['spent'])) ?></span></td>
                                <td data-label="Statut">
                                    <span style="display:inline-flex;flex-wrap:wrap;gap:0.3rem;justify-content:flex-end;">
                                        <?php if ((int)$p['is_admin'] === 1): ?><span class="a-badge st-danger">Admin</span><?php endif; ?>
                                        <?php if (empty($p['email_verified_at'])): ?><span class="a-badge st-warn">Email à confirmer</span>
                                        <?php else: ?><span class="a-badge st-ok">Confirmé</span><?php endif; ?>
                                        <?php if (!empty($p['notify_new_events'])): ?><span class="a-badge st-info" title="Abonné aux alertes">🔔</span><?php endif; ?>
                                    </span>
                                </td>
                                <td class="t-actions-cell">
                                    <div class="t-actions">
                                        <?php if (empty($p['email_verified_at'])): ?>
                                            <form class="a-inline" method="POST" action="<?= e($backUrl) ?>">
                                                <input type="hidden" name="action" value="verify_email"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="a-btn a-btn-sm a-btn-ok"><?= admin_svg('check', 16) ?> Valider</button>
                                            </form>
                                            <form class="a-inline" method="POST" action="<?= e($backUrl) ?>">
                                                <input type="hidden" name="action" value="resend_verification"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="a-btn a-btn-sm a-btn-icon" title="Renvoyer l'email de confirmation"><?= admin_svg('mail', 16) ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="message.php?to=<?= urlencode($p['email']) ?>" class="a-btn a-btn-sm a-btn-icon" title="Lui écrire"><?= admin_svg('send', 16) ?></a>
                                        <a href="orders.php?q=<?= urlencode($p['email']) ?>" class="a-btn a-btn-sm a-btn-icon" title="Ses réservations"><?= admin_svg('card', 16) ?></a>
                                        <form class="a-inline" method="POST" enctype="multipart/form-data" action="<?= e($backUrl) ?>">
                                            <input type="hidden" name="action" value="update_picture"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                            <label class="a-btn a-btn-sm a-btn-icon" title="Changer la photo" style="margin:0;"><?= admin_svg('image', 16) ?>
                                                <input type="file" name="profile_picture" accept="image/*" style="display:none;" onchange="this.form.submit()"></label>
                                        </form>
                                        <?php if (!$isMe): ?>
                                            <form class="a-inline" method="POST" action="<?= e($backUrl) ?>"
                                                  onsubmit="return confirm(<?= e(js_str((int)$p['is_admin'] === 1 ? 'Retirer les droits administrateur de ' . $p['pseudo'] . ' ?' : 'Donner les droits administrateur à ' . $p['pseudo'] . ' ? Il aura accès à tout le panel (paiements, joueurs, scanner).')) ?>);">
                                                <input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="a-btn a-btn-sm a-btn-icon" title="<?= (int)$p['is_admin'] === 1 ? 'Retirer les droits admin' : 'Nommer administrateur' ?>"><?= admin_svg('shield', 16) ?></button>
                                            </form>
                                            <?php if ((int)$p['is_admin'] !== 1): ?>
                                                <form class="a-inline" method="POST" action="<?= e($backUrl) ?>"
                                                      onsubmit="return confirm(<?= e(js_str('Supprimer définitivement le compte de ' . $p['pseudo'] . ' ?')) ?>);">
                                                    <input type="hidden" name="action" value="delete_player"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
                                                    <button type="submit" class="a-btn a-btn-sm a-btn-icon a-btn-danger" title="Supprimer le compte"><?= admin_svg('trash', 16) ?></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <!-- Correction d'une adresse email (rempli par le bouton ✎ à côté de l'adresse) -->
        <form id="emailForm" method="POST" action="<?= e($backUrl) ?>" hidden>
            <input type="hidden" name="action" value="update_email">
            <input type="hidden" name="user_id" value="">
            <input type="hidden" name="email" value="">
        </form>
    </main>

    <script>
    document.querySelectorAll('.a-email-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var current = btn.getAttribute('data-email');
            var value = prompt('Nouvelle adresse email de ' + btn.getAttribute('data-pseudo') + ' :', current);
            if (value === null) { return; }
            value = value.trim();
            if (!value || value.toLowerCase() === current.toLowerCase()) { return; }
            var f = document.getElementById('emailForm');
            f.elements['user_id'].value = btn.getAttribute('data-user');
            f.elements['email'].value = value;
            f.submit();
        });
    });
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
