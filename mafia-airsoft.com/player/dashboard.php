<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
requireLogin();

// Case « Me prévenir des nouvelles parties »
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notifications') {
    $notify = !empty($_POST['notify_new_events']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET notify_new_events = ? WHERE id = ?")->execute([$notify, $_SESSION['user_id']]);
    flash_set('success', $notify
        ? 'C\'est noté : tu recevras un email à chaque nouvelle partie ouverte aux inscriptions.'
        : 'Alertes désactivées : tu ne recevras plus d\'email pour les nouvelles parties.');
    header('Location: dashboard.php');
    exit;
}

// Récupérer les informations du joueur
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Récupérer les inscriptions du joueur
$stmt = $pdo->prepare("
    SELECT e.*, r.team, r.registered_at, r.id as registration_id,
           et.team_name, et.team_color
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN event_teams et ON r.event_id = et.event_id AND r.team = et.team_key
    WHERE r.user_id = ?
    ORDER BY e.event_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$registrations = $stmt->fetchAll();

// Enrichir avec les données d'équipe
foreach ($registrations as &$reg) {
    $team_info = getEventTeam($pdo, $reg['id'], $reg['team']);
    if ($team_info) {
        $reg['team_name'] = $team_info['team_name'];
        $reg['team_color'] = $team_info['team_color'];
    } else {
        $reg['team_name'] = $reg['team'];
        $reg['team_color'] = '#a3a3a3';
    }
}
unset($reg);

// Réservations (commandes) du joueur
$stmt = $pdo->prepare("
    SELECT o.*, e.title AS event_title, e.event_date
    FROM orders o
    JOIN events e ON e.id = o.event_id
    WHERE o.user_id = ? AND o.status IN ('paid', 'free', 'pending', 'refunded')
    ORDER BY o.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$orders = $stmt->fetchAll();

$flashes = flash_get();

// Séparer les inscriptions futures et passées
$upcoming_registrations = [];
$past_registrations = [];
$now = new DateTime();

foreach ($registrations as $reg) {
    $eventDate = new DateTime($reg['event_date']);
    if ($eventDate >= $now) {
        $upcoming_registrations[] = $reg;
    } else {
        $past_registrations[] = $reg;
    }
}
$past_registrations = array_reverse($past_registrations); // les plus récentes d'abord
$avatar = (string)($user['profile_picture'] ?? '');

site_head('Mon espace', ['noindex' => true]);
include '../includes/header.php';
?>
<main class="s-wrap">
    <div class="a-page-head">
        <div style="display:flex;align-items:center;gap:0.9rem;">
            <span class="s-avatar" style="width:56px;height:56px;font-size:1.2rem;"><?php if ($avatar !== ''): ?><img src="../<?= e(ltrim($avatar, '/')) ?>" alt=""><?php else: ?><?= e(initial($user['pseudo'])) ?><?php endif; ?></span>
            <div>
                <h1>Mon espace joueur</h1>
                <div class="a-sub">Bienvenue, <strong><?= e($user['pseudo']) ?></strong> !</div>
            </div>
        </div>
        <div class="a-actions">
            <a href="../events.php" class="a-btn a-btn-primary"><?= ui_icon('calendar', 18) ?> Réserver une partie</a>
            <a href="profile.php" class="a-btn"><?= ui_icon('settings', 18) ?> Mon profil</a>
        </div>
    </div>

    <?php render_flashes($flashes); ?>
    <?php if (!profile_is_complete($user)): ?>
        <div class="alert alert-warning" data-keep>
            Complète ton prénom et ton nom pour pouvoir réserver tes places. <a href="profile.php?complete=1">Compléter mon profil</a>
        </div>
    <?php endif; ?>

    <div class="a-grid a-grid-4" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:1.25rem;">
        <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('calendar', 15) ?> À venir</span><span class="a-kpi-value"><?= count($upcoming_registrations) ?></span></div>
        <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('target', 15) ?> Parties jouées</span><span class="a-kpi-value"><?= count($past_registrations) ?></span></div>
        <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('ticket', 15) ?> Réservations</span><span class="a-kpi-value"><?= count($orders) ?></span></div>
    </div>

    <div class="a-grid a-grid-main">
        <div class="s-stack">
            <!-- Prochaines parties -->
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('calendar', 18) ?> Mes prochaines parties</h2></div>
                <?php if (!$upcoming_registrations): ?>
                    <div class="s-empty" style="padding:1.25rem 0.5rem;">
                        <p>Tu n'es inscrit à aucune partie pour le moment.</p>
                        <a href="../events.php" class="a-btn a-btn-primary">Voir les parties</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_registrations as $reg): ?>
                        <div class="s-mygame">
                            <a class="s-date" href="../event.php?id=<?= (int)$reg['id'] ?>" aria-hidden="true" tabindex="-1">
                                <span class="s-date-wd"><?= e(fr_weekday($reg['event_date'], true)) ?></span>
                                <span class="s-date-day"><?= (int)date('j', strtotime($reg['event_date'])) ?></span>
                                <span class="s-date-month"><?= e(fr_month($reg['event_date'], true)) ?></span>
                            </a>
                            <div style="min-width:0;">
                                <h3><a href="../event.php?id=<?= (int)$reg['id'] ?>"><?= e($reg['title']) ?></a></h3>
                                <div class="a-meta" style="font-size:0.95rem;">
                                    <span><?= ui_icon('clock', 15) ?> <?= e(time_fr($reg['event_date'])) ?> · <?= e(days_until_fr($reg['event_date'])) ?></span>
                                    <span><i class="s-dot" style="background:<?= e(safe_color($reg['team_color'])) ?>"></i> <?= e($reg['team_name']) ?></span>
                                </div>
                            </div>
                            <div class="s-mygame-actions">
                                <a href="../ticket.php?registration=<?= (int)$reg['registration_id'] ?>" class="a-btn a-btn-sm a-btn-primary" target="_blank" rel="noopener"><?= ui_icon('ticket', 16) ?> Mon billet</a>
                                <a href="../event.php?id=<?= (int)$reg['id'] ?>" class="a-btn a-btn-sm">Détails</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- Réservations -->
            <?php if ($orders): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('card', 18) ?> Mes réservations</h2></div>
                    <table class="rtable">
                        <thead><tr><th>Partie</th><th>Places</th><th>Montant</th><th>Statut</th><th style="text-align:right;">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td class="t-head">
                                        <a class="t-title" href="../order.php?id=<?= (int)$order['id'] ?>"><?= e($order['event_title']) ?></a>
                                        <span class="t-sub">n° <?= (int)$order['id'] ?> · <?= e(date('d/m/Y', strtotime($order['event_date']))) ?></span>
                                    </td>
                                    <td data-label="Places"><span><?= (int)$order['quantity'] ?></span></td>
                                    <td data-label="Montant"><span class="t-num"><?= (int)$order['amount_cents'] > 0 ? e(format_price((int)$order['amount_cents'])) : 'Gratuit' ?></span></td>
                                    <td data-label="Statut"><?= order_badge($order['status']) ?></td>
                                    <td class="t-actions-cell">
                                        <div class="t-actions">
                                            <a href="../order.php?id=<?= (int)$order['id'] ?>" class="a-btn a-btn-sm"><?= ui_icon('ticket', 16) ?> Billets / invités</a>
                                            <?php if (in_array($order['status'], ['paid', 'refunded'], true)): ?>
                                                <a href="../receipt.php?order=<?= (int)$order['id'] ?>" class="a-btn a-btn-sm" target="_blank" rel="noopener"><?= ui_icon('file', 16) ?> Reçu</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <!-- Historique -->
            <?php if ($past_registrations): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('clock', 18) ?> Historique</h2><span class="s-muted s-small"><?= count($past_registrations) ?> partie<?= count($past_registrations) > 1 ? 's' : '' ?></span></div>
                    <div class="a-list">
                        <?php foreach ($past_registrations as $reg): ?>
                            <div class="a-row">
                                <span class="s-past-date"><?= e(date('d/m/Y', strtotime($reg['event_date']))) ?></span>
                                <div class="a-row-main">
                                    <a class="a-row-title" href="../event.php?id=<?= (int)$reg['id'] ?>"><?= e($reg['title']) ?></a>
                                    <span class="a-row-sub"><i class="s-dot" style="background:<?= e(safe_color($reg['team_color'])) ?>"></i> <?= e($reg['team_name']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <div class="s-stack">
            <!-- Infos -->
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('user', 18) ?> Mes informations</h2></div>
                <div>
                    <div class="s-kv"><span>Pseudo</span><strong><?= e($user['pseudo']) ?></strong></div>
                    <div class="s-kv"><span>Nom</span><strong><?= e(full_name($user['first_name'] ?? '', $user['last_name'] ?? '') ?: '–') ?></strong></div>
                    <div class="s-kv"><span>Email</span><strong><?= e($user['email']) ?></strong></div>
                    <div class="s-kv"><span>Téléphone</span><strong><?= e($user['phone'] ?: '–') ?></strong></div>
                    <div class="s-kv"><span>Membre depuis</span><strong><?= e(date('d/m/Y', strtotime($user['created_at']))) ?></strong></div>
                </div>
                <a href="profile.php" class="a-btn a-btn-block" style="margin-top:1rem;"><?= ui_icon('edit', 17) ?> Modifier mon profil / mot de passe</a>
            </section>

            <!-- Alertes -->
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('bell', 18) ?> Nouvelles parties</h2></div>
                <form method="POST">
                    <input type="hidden" name="action" value="notifications">
                    <div class="s-check" style="margin-bottom:0.5rem;">
                        <input type="checkbox" id="notify_new_events" name="notify_new_events" value="1"
                               <?= !empty($user['notify_new_events']) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label for="notify_new_events">Me prévenir par email dès qu'une nouvelle partie est ouverte aux inscriptions</label>
                    </div>
                    <noscript><button type="submit" class="a-btn a-btn-sm">Enregistrer</button></noscript>
                    <p class="s-hint">Tu peux changer d'avis à tout moment ; chaque email contient un lien de désabonnement.</p>
                </form>
            </section>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
</body>
</html>
