<?php
/**
 * Panel admin – Fiche d'un joueur
 * Voir le compte (informations, parties, réservations) et le modifier sur la
 * même page : coordonnées, email, photo, alertes, droits, mot de passe.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/admin_ui.php';
requireAdmin();

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    flash_set('error', 'Joueur introuvable.');
    redirect('players.php');
}
$me = current_user_id();
$isMe = (int)$player['id'] === $me;
$self = 'player.php?id=' . $userId;

// =====================================================================
// Actions
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_account') {
        $form = [
            'pseudo'     => trim((string)($_POST['pseudo'] ?? '')),
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name'  => trim((string)($_POST['last_name'] ?? '')),
            'email'      => mb_strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone'      => trim((string)($_POST['phone'] ?? '')),
            'notify_new_events' => !empty($_POST['notify_new_events']) ? 1 : 0,
        ];
        $errors = [];
        if ($problem = pseudo_problem($form['pseudo'])) {
            $errors[] = $problem;
        }
        if ($form['first_name'] === '' || $form['last_name'] === '' || mb_strlen($form['first_name']) > 100 || mb_strlen($form['last_name']) > 100) {
            $errors[] = 'Le prénom et le nom sont obligatoires (ils apparaissent sur les billets).';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
            $errors[] = 'Adresse email invalide.';
        }
        if ($form['phone'] !== '' && !preg_match('/^[0-9 +().\-]{6,20}$/', $form['phone'])) {
            $errors[] = 'Numéro de téléphone invalide.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE pseudo = ? AND id <> ?");
            $stmt->execute([$form['pseudo'], $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'Ce pseudo est déjà pris par un autre joueur.';
            }
            $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?");
            $stmt->execute([$form['email'], $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'Cette adresse email est déjà utilisée par un autre compte.';
            }
        }
        $newPicture = null;
        if (!$errors && !empty($_FILES['profile_picture']['name'])) {
            $ext = uploaded_image_extension($_FILES['profile_picture'], 3 * 1024 * 1024);
            if ($ext === null) {
                $errors[] = 'Photo refusée : JPG, PNG, GIF ou WEBP de 3 Mo maximum.';
            } else {
                $name = random_upload_name('profile', $ext);
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], MAT_ROOT . '/uploads/profiles/' . $name)) {
                    $newPicture = 'uploads/profiles/' . $name;
                }
            }
        }

        if ($errors) {
            $_SESSION['admin_player_draft'] = ['id' => $userId] + $form;
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
            redirect($self . '&edit=1#modifier');
        }

        $picture = $player['profile_picture'];
        if ($newPicture || !empty($_POST['remove_picture'])) {
            if (!empty($player['profile_picture'])) {
                delete_upload($player['profile_picture'], 'uploads/profiles/');
            }
            $picture = $newPicture;
        }
        $emailChanged = $form['email'] !== mb_strtolower((string)$player['email']);

        $pdo->prepare("
            UPDATE users SET pseudo = ?, first_name = ?, last_name = ?, email = ?, phone = ?, notify_new_events = ?, profile_picture = ?
            WHERE id = ?
        ")->execute([$form['pseudo'], $form['first_name'], $form['last_name'], $form['email'], $form['phone'] ?: null,
                     $form['notify_new_events'], $picture, $userId]);
        // Les places à venir du joueur suivent son compte (noms imprimés sur les billets)
        $pdo->prepare("
            UPDATE registrations r JOIN events e ON e.id = r.event_id
            SET r.first_name = ?, r.last_name = ?, r.pseudo = ?
            WHERE r.user_id = ? AND e.event_date > NOW()
        ")->execute([$form['first_name'], $form['last_name'], $form['pseudo'], $userId]);

        $msg = 'Compte de ' . $form['pseudo'] . ' enregistré.';
        if ($emailChanged) {
            // Le compte reste confirmé : l'admin a vérifié l'adresse avec le joueur. Un email part pour s'en assurer.
            $html = '<h2 style="color:#ffffff;margin-top:0;">Adresse email mise à jour</h2>'
                . '<p>Salut ' . e($form['pseudo']) . ', un organisateur a enregistré cette adresse sur ton compte ' . e(SITE_NAME) . '. '
                . 'C\'est désormais elle qui sert pour te connecter et recevoir tes billets.</p>'
                . mail_button(SITE_URL . '/login.php', 'Me connecter')
                . '<p style="font-size:13px;color:#a3a3a3;">Si ce n\'est pas ton compte, ignore cet email ou réponds-nous.</p>';
            $sent = send_mail($form['email'], $form['pseudo'], 'Ton adresse email a été mise à jour', $html);
            $msg .= $sent ? ' Un email de vérification a été envoyé à la nouvelle adresse.' : ' (L\'email de vérification n\'a pas pu partir : voir Diagnostic.)';
        }
        unset($_SESSION['admin_player_draft']);
        flash_set('success', $msg);
        redirect($self);
    }

    if ($action === 'verify_email') {
        $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")->execute([$userId]);
        flash_set('success', 'Email validé : ' . $player['pseudo'] . ' peut se connecter.');
    } elseif ($action === 'resend_verification') {
        if (!empty($player['email_verified_at'])) {
            flash_set('info', 'Ce compte est déjà confirmé.');
        } elseif (send_verification_email($pdo, $player)) {
            flash_set('success', 'Email de confirmation renvoyé à ' . $player['email'] . '.');
        } else {
            flash_set('error', 'L\'email n\'a pas pu partir. Va dans Diagnostic pour voir pourquoi.');
        }
    } elseif ($action === 'send_reset') {
        if (send_password_reset_email($pdo, $player)) {
            flash_set('success', 'Lien pour choisir un nouveau mot de passe envoyé à ' . $player['email'] . ' (valable 1 heure).');
        } else {
            flash_set('error', 'L\'email n\'a pas pu partir. Va dans Diagnostic pour voir pourquoi.');
        }
    } elseif ($action === 'resend_tickets') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        if (!$stmt->fetch()) {
            flash_set('error', 'Réservation introuvable pour ce joueur.');
        } elseif (order_send_tickets($pdo, $orderId)) {
            flash_set('success', 'Billets de la réservation n° ' . $orderId . ' renvoyés à ' . $player['email'] . '.');
        } else {
            flash_set('error', 'Billets non envoyés (aucun billet prêt ou email non configuré : voir Diagnostic).');
        }
    } elseif ($action === 'toggle_admin') {
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
        if ($isMe) {
            flash_set('error', 'Tu ne peux pas modifier tes propres droits.');
        } elseif ((int)$player['is_admin'] === 1 && $admins <= 1) {
            flash_set('error', 'Il doit rester au moins un administrateur.');
        } else {
            $new = (int)$player['is_admin'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$new, $userId]);
            flash_set('success', $new
                ? $player['pseudo'] . ' est maintenant administrateur (accès au panel et au scanner).'
                : $player['pseudo'] . ' n\'est plus administrateur.');
        }
    } elseif ($action === 'delete_player') {
        if ((int)$player['is_admin'] === 1) {
            flash_set('error', 'Retire d\'abord les droits administrateur avant de supprimer ce compte.');
        } else {
            if (!empty($player['profile_picture'])) {
                delete_upload($player['profile_picture'], 'uploads/profiles/');
            }
            $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0")->execute([$userId]);
            flash_set('success', 'Compte de ' . $player['pseudo'] . ' supprimé (ses reçus de paiement restent archivés).');
            redirect('players.php');
        }
    }
    redirect($self);
}

// =====================================================================
// Données affichées
// =====================================================================
$stmt = $pdo->prepare("
    SELECT r.id, r.team, r.registered_at, r.order_id,
           e.id AS event_id, e.title, e.event_date, e.location,
           et.team_name, et.team_color, t.scanned_at, o.status AS order_status
    FROM registrations r
    JOIN events e ON e.id = r.event_id
    LEFT JOIN event_teams et ON et.event_id = r.event_id AND et.team_key = r.team
    LEFT JOIN tickets t ON t.registration_id = r.id
    LEFT JOIN orders o ON o.id = r.order_id
    WHERE r.user_id = ?
    ORDER BY e.event_date DESC
");
$stmt->execute([$userId]);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);
$now = time();
$upcoming = array_values(array_filter($games, fn($g) => strtotime($g['event_date']) >= $now));
$past = array_values(array_filter($games, fn($g) => strtotime($g['event_date']) < $now));
$present = count(array_filter($past, fn($g) => !empty($g['scanned_at'])));

$stmt = $pdo->prepare("
    SELECT o.*, e.title AS event_title, e.event_date,
           (SELECT COUNT(*) FROM registrations r WHERE r.order_id = o.id) AS places_created,
           (SELECT COUNT(*) FROM registrations r WHERE r.order_id = o.id AND r.user_id IS NULL
                AND (r.first_name IS NULL OR r.first_name = '' OR r.pseudo IS NULL OR r.pseudo = '')) AS guests_missing
    FROM orders o JOIN events e ON e.id = o.event_id
    WHERE o.user_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$spent = array_sum(array_map(fn($o) => $o['status'] === 'paid' ? (int)$o['amount_cents'] : 0, $orders));

// Formulaire : valeurs du compte, ou saisie gardée après une erreur
$form = $player;
$draft = $_SESSION['admin_player_draft'] ?? null;
unset($_SESSION['admin_player_draft']);
if ($draft && (int)$draft['id'] === $userId) {
    $form = array_merge($form, $draft);
}
$editOpen = isset($_GET['edit']) || $draft;
$avatar = (string)($player['profile_picture'] ?? '');
$flashes = flash_get();
?>
<?php site_head($player['pseudo'] . ' · Joueur', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div class="a-player-head">
                <span class="s-avatar a-player-avatar"><?php if ($avatar !== ''): ?><img src="../<?= e($avatar) ?>" alt=""><?php else: ?><?= e(initial($player['pseudo'])) ?><?php endif; ?></span>
                <div>
                    <a class="a-link" href="players.php">← Joueurs</a>
                    <h1><?= e($player['pseudo']) ?><?= $isMe ? ' <span class="a-muted" style="font-size:0.6em;">(toi)</span>' : '' ?></h1>
                    <div class="a-sub"><?= e(full_name($player['first_name'], $player['last_name']) ?: 'Nom non renseigné') ?> · membre depuis <?= e(date('d/m/Y', strtotime($player['created_at']))) ?></div>
                    <div class="a-player-badges">
                        <?php if ((int)$player['is_admin'] === 1): ?><span class="a-badge st-danger">Admin</span><?php endif; ?>
                        <?= empty($player['email_verified_at']) ? '<span class="a-badge st-warn">Email à confirmer</span>' : '<span class="a-badge st-ok">Compte confirmé</span>' ?>
                        <?php if (!empty($player['notify_new_events'])): ?><span class="a-badge st-info"><?= ui_icon('bell', 13) ?> Alertes</span><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="a-actions">
                <a href="<?= e($self) ?>&amp;edit=1#modifier" class="a-btn a-btn-primary"><?= ui_icon('edit', 18) ?> Modifier le compte</a>
                <a href="message.php?to=<?= e(urlencode($player['email'])) ?>" class="a-btn"><?= ui_icon('send', 18) ?> Lui écrire</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <div class="a-grid a-grid-4" style="margin-bottom:1rem;">
            <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('calendar', 15) ?> À venir</span><span class="a-kpi-value"><?= count($upcoming) ?></span></div>
            <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('target', 15) ?> Parties jouées</span><span class="a-kpi-value"><?= count($past) ?></span>
                <span class="a-kpi-hint"><?= $present ?> présence(s) pointée(s)</span></div>
            <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('euro', 15) ?> Payé</span><span class="a-kpi-value"><?= e(format_price($spent)) ?></span></div>
            <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('clock', 15) ?> Dernière connexion</span>
                <span class="a-kpi-value a-kpi-small"><?= !empty($player['last_login_at']) ? e(admin_ago($player['last_login_at'])) : '—' ?></span></div>
        </div>

        <div class="a-grid a-grid-main">
            <div class="a-stack">
                <!-- Parties -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('calendar', 18) ?> Ses parties</h2><span class="a-badge st-muted"><?= count($games) ?></span></div>
                    <?php if (!$games): ?>
                        <p class="a-empty">Aucune inscription pour l'instant.</p>
                    <?php else: ?>
                        <div class="a-list">
                            <?php foreach ($games as $g):
                                $isPast = strtotime($g['event_date']) < $now; ?>
                                <div class="a-row">
                                    <div class="a-row-main">
                                        <a class="a-row-title" href="view_event.php?id=<?= (int)$g['event_id'] ?>"><?= e($g['title']) ?></a>
                                        <span class="a-row-sub"><?= e(ucfirst(date_fr($g['event_date']))) ?> · <?= e(admin_relative_day($g['event_date'])) ?></span>
                                    </div>
                                    <div class="a-row-side">
                                        <span class="a-badge st-muted" style="border-left:3px solid <?= e(safe_color($g['team_color'] ?? '')) ?>;"><?= e($g['team_name'] ?? $g['team']) ?></span>
                                        <?php if ($g['scanned_at']): ?>
                                            <span class="a-badge st-ok">Présent</span>
                                        <?php elseif ($isPast): ?>
                                            <span class="a-badge st-muted">Non pointé</span>
                                        <?php else: ?>
                                            <span class="a-badge st-info">Inscrit</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <!-- Réservations -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('card', 18) ?> Ses réservations</h2><span class="a-badge st-muted"><?= count($orders) ?></span></div>
                    <?php if (!$orders): ?>
                        <p class="a-empty">Aucune réservation.</p>
                    <?php else: ?>
                        <table class="rtable">
                            <thead><tr><th>Réservation</th><th>Places</th><th>Montant</th><th>Statut</th><th style="text-align:right;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($orders as $o): $active = in_array($o['status'], ['paid', 'free'], true); ?>
                                    <tr>
                                        <td class="t-head">
                                            <a class="t-title" href="../order.php?id=<?= (int)$o['id'] ?>">n° <?= (int)$o['id'] ?> · <?= e($o['event_title']) ?></a>
                                            <span class="t-sub"><?= e(date('d/m/Y', strtotime($o['event_date']))) ?> · réservé <?= e(admin_ago($o['paid_at'] ?? $o['created_at'])) ?></span>
                                        </td>
                                        <td data-label="Places"><span><?= (int)$o['quantity'] ?>
                                            <?php if ($active && (int)$o['guests_missing'] > 0): ?><span class="a-badge st-warn"><?= (int)$o['guests_missing'] ?> à compléter</span><?php endif; ?></span></td>
                                        <td data-label="Montant"><span class="t-num"><?= (int)$o['amount_cents'] > 0 ? e(format_price((int)$o['amount_cents'])) : 'Gratuit' ?></span></td>
                                        <td data-label="Statut"><?= admin_order_badge($o['status']) ?></td>
                                        <td class="t-actions-cell">
                                            <div class="t-actions">
                                                <a href="../order.php?id=<?= (int)$o['id'] ?>" class="a-btn a-btn-sm"><?= ui_icon('eye', 16) ?> Voir</a>
                                                <?php if (in_array($o['status'], ['paid', 'refunded'], true)): ?>
                                                    <a href="../receipt.php?order=<?= (int)$o['id'] ?>" class="a-btn a-btn-sm a-btn-icon" target="_blank" rel="noopener" title="Reçu PDF"><?= ui_icon('file', 16) ?></a>
                                                <?php endif; ?>
                                                <?php if ($active && (int)$o['places_created'] > 0): ?>
                                                    <form class="a-inline" method="POST" action="<?= e($self) ?>">
                                                        <input type="hidden" name="action" value="resend_tickets">
                                                        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                        <button type="submit" class="a-btn a-btn-sm a-btn-icon" title="Renvoyer les billets par email"><?= ui_icon('mail', 16) ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="a-stack">
                <!-- Informations -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('user', 18) ?> Informations</h2></div>
                    <div class="a-list">
                        <div class="a-kv"><span>Pseudo</span><strong><?= e($player['pseudo']) ?></strong></div>
                        <div class="a-kv"><span>Prénom et nom</span><strong><?= e(full_name($player['first_name'], $player['last_name']) ?: '—') ?></strong></div>
                        <div class="a-kv"><span>Email</span><strong><a class="a-link" href="mailto:<?= e($player['email']) ?>"><?= e($player['email']) ?></a></strong></div>
                        <div class="a-kv"><span>Téléphone</span><strong><?= $player['phone'] ? '<a class="a-link" href="tel:' . e(preg_replace('/[^0-9+]/', '', $player['phone'])) . '">' . e($player['phone']) . '</a>' : '—' ?></strong></div>
                        <div class="a-kv"><span>Alertes nouvelles parties</span><strong><?= !empty($player['notify_new_events']) ? 'Oui' : 'Non' ?></strong></div>
                        <div class="a-kv"><span>Conditions acceptées</span><strong><?= !empty($player['terms_accepted_at']) ? e(date('d/m/Y', strtotime($player['terms_accepted_at']))) : '—' ?></strong></div>
                    </div>
                </section>

                <!-- Modifier -->
                <details class="a-collapse a-edit" id="modifier" <?= $editOpen ? 'open' : '' ?>>
                    <summary><?= ui_icon('edit', 18) ?> Modifier le compte</summary>
                    <div class="a-collapse-body">
                        <form method="POST" action="<?= e($self) ?>" enctype="multipart/form-data" class="s-form">
                            <input type="hidden" name="action" value="update_account">
                            <div class="s-field">
                                <label for="pseudo">Pseudo <span class="s-req">*</span></label>
                                <input type="text" id="pseudo" name="pseudo" required maxlength="30" value="<?= e($form['pseudo']) ?>">
                            </div>
                            <div class="s-form-row">
                                <div class="s-field">
                                    <label for="first_name">Prénom <span class="s-req">*</span></label>
                                    <input type="text" id="first_name" name="first_name" required maxlength="100" value="<?= e($form['first_name']) ?>">
                                </div>
                                <div class="s-field">
                                    <label for="last_name">Nom <span class="s-req">*</span></label>
                                    <input type="text" id="last_name" name="last_name" required maxlength="100" value="<?= e($form['last_name']) ?>">
                                </div>
                            </div>
                            <div class="s-field">
                                <label for="email">Email <span class="s-req">*</span></label>
                                <input type="email" id="email" name="email" required maxlength="100" value="<?= e($form['email']) ?>">
                                <span class="s-hint">Si tu la changes, un email part à la nouvelle adresse pour vérifier qu'elle fonctionne.</span>
                            </div>
                            <div class="s-field">
                                <label for="phone">Téléphone</label>
                                <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($form['phone']) ?>">
                            </div>
                            <div class="s-field">
                                <label for="profile_picture">Photo</label>
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp">
                            </div>
                            <?php if ($avatar !== ''): ?>
                                <div class="s-check">
                                    <input type="checkbox" id="remove_picture" name="remove_picture" value="1">
                                    <label for="remove_picture">Retirer la photo actuelle</label>
                                </div>
                            <?php endif; ?>
                            <div class="s-check">
                                <input type="checkbox" id="notify_new_events" name="notify_new_events" value="1" <?= !empty($form['notify_new_events']) ? 'checked' : '' ?>>
                                <label for="notify_new_events">Prévenir par email des nouvelles parties</label>
                            </div>
                            <p class="s-hint" style="margin-bottom:0.9rem;">Les noms imprimés sur ses billets des parties à venir sont mis à jour en même temps.</p>
                            <button type="submit" class="a-btn a-btn-primary a-btn-block"><?= ui_icon('check', 18) ?> Enregistrer</button>
                        </form>
                    </div>
                </details>

                <!-- Accès et sécurité -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('shield', 18) ?> Accès</h2></div>
                    <div class="a-access">
                        <?php if (empty($player['email_verified_at'])): ?>
                            <form method="POST" action="<?= e($self) ?>">
                                <input type="hidden" name="action" value="verify_email">
                                <button type="submit" class="a-btn a-btn-ok a-btn-block"><?= ui_icon('check', 18) ?> Valider son email</button>
                            </form>
                            <form method="POST" action="<?= e($self) ?>">
                                <input type="hidden" name="action" value="resend_verification">
                                <button type="submit" class="a-btn a-btn-block"><?= ui_icon('mail', 18) ?> Renvoyer l'email de confirmation</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="<?= e($self) ?>" data-confirm="<?= e('Envoyer à ' . $player['email'] . ' un lien pour choisir un nouveau mot de passe ?') ?>">
                            <input type="hidden" name="action" value="send_reset">
                            <button type="submit" class="a-btn a-btn-block"><?= ui_icon('lock', 18) ?> Envoyer un lien « nouveau mot de passe »</button>
                        </form>
                        <?php if (!$isMe): ?>
                            <form method="POST" action="<?= e($self) ?>" data-confirm="<?= e((int)$player['is_admin'] === 1 ? 'Retirer les droits administrateur de ' . $player['pseudo'] . ' ?' : 'Donner les droits administrateur à ' . $player['pseudo'] . ' ? Il aura accès à tout le panel (paiements, joueurs, scanner).') ?>">
                                <input type="hidden" name="action" value="toggle_admin">
                                <button type="submit" class="a-btn a-btn-block"><?= ui_icon('shield', 18) ?> <?= (int)$player['is_admin'] === 1 ? 'Retirer les droits admin' : 'Nommer administrateur' ?></button>
                            </form>
                            <?php if ((int)$player['is_admin'] !== 1): ?>
                                <form method="POST" action="<?= e($self) ?>" data-confirm="<?= e('Supprimer définitivement le compte de ' . $player['pseudo'] . ' ? Ses reçus de paiement restent archivés.') ?>">
                                    <input type="hidden" name="action" value="delete_player">
                                    <button type="submit" class="a-btn a-btn-danger a-btn-block"><?= ui_icon('trash', 18) ?> Supprimer le compte</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <p class="s-hint" style="margin-top:0.75rem;">Pour des raisons de sécurité, personne ne voit ni ne choisit le mot de passe d'un joueur : il le choisit lui-même grâce au lien.</p>
                </section>
            </aside>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
