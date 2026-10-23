<?php
/**
 * Panel admin – Tableau de bord
 * Prochaine partie, chiffres utiles, points à traiter, actions rapides,
 * parties à venir et dernières réservations.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
requireAdmin();

// ---------- Parties à venir (visibles) avec statistiques
$upcoming = admin_events_with_stats($pdo, "e.event_date >= NOW() AND e.is_active = 1");
$next = $upcoming[0] ?? null;

// ---------- Chiffres clés
$monthPaid = (int)$pdo->query("
    SELECT COALESCE(SUM(amount_cents), 0) FROM orders
    WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();
$monthOrders = (int)$pdo->query("
    SELECT COUNT(*) FROM orders WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();
$placesUpcoming = array_sum(array_column($upcoming, 'registered'));
$capacityUpcoming = array_sum(array_column($upcoming, 'capacity'));
$playersCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn();
$newPlayers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$subscribers = subscribers_count($pdo);

// ---------- Points à traiter
$alerts = [];
$pendingAccounts = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL")->fetchColumn();
if ($pendingAccounts > 0) {
    $alerts[] = ['st-warn', 'usercheck', $pendingAccounts . ' compte(s) en attente de confirmation d\'email', 'players.php?filter=pending'];
}
$notAnnounced = array_filter($upcoming, fn($e) => !empty($e['registration_open']) && empty($e['announced_at']));
if ($notAnnounced && $subscribers > 0) {
    $first = reset($notAnnounced);
    $alerts[] = ['st-info', 'bell', count($notAnnounced) . ' partie(s) ouverte(s) pas encore annoncée(s) aux ' . $subscribers . ' abonné(s)',
                 'edit_event.php?id=' . (int)$first['id'] . '#announce'];
}
$guestsMissing = array_sum(array_column($upcoming, 'guests_missing'));
if ($guestsMissing > 0) {
    $alerts[] = ['st-info', 'users', $guestsMissing . ' invité(s) sans nom sur les prochaines parties (billet non généré)', 'events.php'];
}
$stripeKey = (string)STRIPE_SECRET_KEY;
if ($stripeKey === '' || str_contains($stripeKey, 'A_REMPLACER')) {
    $alerts[] = ['st-danger', 'card', 'Paiement en ligne non configuré : les parties payantes ne peuvent pas être réservées', 'diagnostic.php'];
} elseif (str_contains($stripeKey, '_test_')) {
    $alerts[] = ['st-warn', 'card', 'Stripe est en mode TEST : aucun vrai paiement n\'est encaissé', 'diagnostic.php'];
}
if ($stripeKey !== '' && (STRIPE_WEBHOOK_SECRET === '' || str_contains(STRIPE_WEBHOOK_SECRET, 'A_REMPLACER'))) {
    $alerts[] = ['st-danger', 'alert', 'Webhook Stripe non renseigné : les paiements ne sont pas confirmés automatiquement', 'diagnostic.php'];
}
if (!mail_configured()) {
    $alerts[] = ['st-danger', 'mail', 'Envoi d\'emails non configuré (confirmations, billets, alertes)', 'diagnostic.php'];
}
if (ASSO_ADDRESS === '' || str_contains(ASSO_ADDRESS, 'Adresse du siège') || str_contains(ASSO_PUBLICATION_DIRECTOR, 'Prénom NOM')) {
    $alerts[] = ['st-warn', 'file', 'Infos de l\'association à compléter dans config/app.php (affichées sur les reçus et les mentions légales)', 'diagnostic.php'];
}

// ---------- Dernières réservations
$recent = $pdo->query("
    SELECT o.id, o.buyer_name, o.quantity, o.amount_cents, o.status,
           COALESCE(o.paid_at, o.created_at) AS at, e.title AS event_title
    FROM orders o JOIN events e ON e.id = o.event_id
    WHERE o.status IN ('paid', 'free', 'refunded')
    ORDER BY at DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Administration</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="a-page-head">
            <div>
                <h1>Tableau de bord</h1>
                <div class="a-sub"><?= e(ucfirst(date_fr(date('Y-m-d H:i:s'), false))) ?></div>
            </div>
            <div class="a-actions">
                <a href="create_event.php" class="a-btn a-btn-primary"><?= admin_svg('plus', 18) ?> Créer une partie</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($alerts): ?>
            <details class="a-collapse a-todo" id="todo" open>
                <summary><?= admin_svg('alert', 18) ?> À traiter <span class="a-todo-count"><?= count($alerts) ?></span></summary>
                <div class="a-alerts">
                    <?php foreach ($alerts as [$cls, $ico, $text, $link]): ?>
                        <a class="a-alert <?= $cls ?>" href="<?= e($link) ?>">
                            <?= admin_svg($ico, 20) ?><span><?= e($text) ?></span><?= admin_svg('arrow', 18) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <script>
                // Sur téléphone, la liste est repliée pour laisser la prochaine partie visible
                if (window.matchMedia('(max-width: 900px)').matches) document.getElementById('todo').removeAttribute('open');
            </script>
        <?php endif; ?>

        <!-- Ligne 1 : prochaine partie + chiffres clés -->
        <div class="a-grid a-grid-main">
            <?php if ($next): ?>
                <section class="a-card a-next">
                    <div class="a-next-when"><?= admin_svg('clock', 16) ?> Prochaine partie · <?= e(admin_relative_day($next['event_date'])) ?></div>
                    <h2 class="a-next-title"><?= e($next['title']) ?></h2>
                    <div class="a-meta">
                        <span><?= admin_svg('calendar', 16) ?> <?= e(ucfirst(date_fr($next['event_date']))) ?></span>
                        <span><?= admin_svg('pin', 16) ?> <?= e($next['location']) ?></span>
                        <span><?= admin_svg('euro', 16) ?> <?= event_is_free($next) ? 'Gratuit' : e(format_price((int)$next['price_cents'])) . ' / place' ?></span>
                        <span class="a-badge <?= $next['status_class'] ?>"><?= e($next['status_label']) ?></span>
                    </div>

                    <div class="a-next-fill">
                        <div class="a-fill-text" style="display:flex;justify-content:space-between;gap:1rem;margin-bottom:0.35rem;">
                            <span><strong><?= $next['taken'] ?></strong> / <?= $next['capacity'] ?> places prises</span>
                            <?php if (!event_is_free($next)): ?>
                                <span><strong><?= e(format_price($next['paid_cents'])) ?></strong> encaissés</span>
                            <?php endif; ?>
                        </div>
                        <?= admin_progress($next['taken'], $next['capacity']) ?>
                    </div>

                    <?php if ($next['teams']): ?>
                        <div class="a-teams">
                            <?php foreach ($next['teams'] as $t): ?>
                                <div class="a-team">
                                    <div class="a-team-name"><span><?= e($t['team_name']) ?></span><span><?= (int)$t['current_players'] ?>/<?= (int)$t['max_players'] ?></span></div>
                                    <?= admin_progress((int)$t['current_players'], (int)$t['max_players'], $t['team_color']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($next['guests_missing'] > 0): ?>
                        <p class="a-small a-muted" style="margin-bottom:0.75rem;">⚠️ <?= $next['guests_missing'] ?> invité(s) sans nom : leur billet n'est pas encore généré.</p>
                    <?php endif; ?>

                    <div class="a-next-actions">
                        <a href="view_event.php?id=<?= (int)$next['id'] ?>" class="a-btn a-btn-primary"><?= admin_svg('users', 18) ?> Voir les inscrits</a>
                        <a href="../qr-code/scan.php" class="a-btn"><?= admin_svg('scan', 18) ?> Scanner les billets</a>
                        <a href="edit_event.php?id=<?= (int)$next['id'] ?>" class="a-btn"><?= admin_svg('edit', 18) ?> Modifier</a>
                    </div>
                </section>
            <?php else: ?>
                <section class="a-card a-next">
                    <div class="a-next-when"><?= admin_svg('calendar', 16) ?> Prochaine partie</div>
                    <h2 class="a-next-title">Aucune partie programmée</h2>
                    <p class="a-muted" style="margin-bottom:1rem;">Crée la prochaine partie : les joueurs abonnés aux alertes seront prévenus par email.</p>
                    <a href="create_event.php" class="a-btn a-btn-primary"><?= admin_svg('plus', 18) ?> Créer une partie</a>
                </section>
            <?php endif; ?>

            <div class="a-grid a-grid-2" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                <a class="a-kpi" href="orders.php?status=paid">
                    <span class="a-kpi-label"><?= admin_svg('euro', 15) ?> Encaissé ce mois</span>
                    <span class="a-kpi-value"><?= e(format_price($monthPaid)) ?></span>
                    <span class="a-kpi-hint"><?= $monthOrders ?> paiement(s)</span>
                </a>
                <a class="a-kpi" href="events.php">
                    <span class="a-kpi-label"><?= admin_svg('ticket', 15) ?> Places réservées</span>
                    <span class="a-kpi-value"><?= $placesUpcoming ?></span>
                    <span class="a-kpi-hint">sur <?= $capacityUpcoming ?> · <?= count($upcoming) ?> partie(s) à venir</span>
                </a>
                <a class="a-kpi" href="players.php">
                    <span class="a-kpi-label"><?= admin_svg('users', 15) ?> Joueurs</span>
                    <span class="a-kpi-value"><?= $playersCount ?></span>
                    <span class="a-kpi-hint">+<?= $newPlayers ?> sur 30 jours</span>
                </a>
                <a class="a-kpi" href="players.php?filter=subscribed">
                    <span class="a-kpi-label"><?= admin_svg('bell', 15) ?> Abonnés alertes</span>
                    <span class="a-kpi-value"><?= $subscribers ?></span>
                    <span class="a-kpi-hint">prévenus des nouvelles parties</span>
                </a>
            </div>
        </div>

        <!-- Ligne 2 : parties à venir + actions rapides / réservations -->
        <div class="a-grid a-grid-main" style="margin-top:1rem;">
            <section class="a-card">
                <div class="a-card-head">
                    <h2><?= admin_svg('calendar', 18) ?> Parties à venir</h2>
                    <a class="a-link" href="events.php">Toutes les parties →</a>
                </div>
                <?php if (!$upcoming): ?>
                    <p class="a-empty">Aucune partie à venir.</p>
                <?php else: ?>
                    <div class="a-list">
                        <?php foreach (array_slice($upcoming, 0, 6) as $ev): ?>
                            <div class="a-row">
                                <div class="a-row-main">
                                    <a class="a-row-title" href="view_event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a>
                                    <div class="a-row-sub">
                                        <?= e(date('d/m/Y', strtotime($ev['event_date']))) ?> · <?= e(admin_relative_day($ev['event_date'])) ?>
                                        · <?= event_is_free($ev) ? 'Gratuit' : e(format_price((int)$ev['price_cents'])) ?>
                                    </div>
                                </div>
                                <div class="a-row-side">
                                    <div class="a-fill" style="min-width:120px;">
                                        <span class="a-fill-text"><strong><?= $ev['taken'] ?></strong>/<?= $ev['capacity'] ?> places</span>
                                        <?= admin_progress($ev['taken'], $ev['capacity']) ?>
                                    </div>
                                    <span class="a-badge <?= $ev['status_class'] ?>"><?= e($ev['status_label']) ?></span>
                                    <a href="edit_event.php?id=<?= (int)$ev['id'] ?>" class="a-btn a-btn-sm a-btn-icon" title="Modifier"><?= admin_svg('edit', 16) ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="a-stack">
                <section class="a-card">
                    <div class="a-card-head"><h2><?= admin_svg('activity', 18) ?> Actions rapides</h2></div>
                    <div class="a-tiles">
                        <a class="a-tile a-tile-primary" href="create_event.php"><?= admin_svg('plus', 22) ?> Créer une partie</a>
                        <a class="a-tile" href="../qr-code/scan.php"><?= admin_svg('scan', 22) ?> Scanner</a>
                        <a class="a-tile" href="message.php"><?= admin_svg('send', 22) ?> Écrire aux joueurs</a>
                        <a class="a-tile" href="players.php"><?= admin_svg('users', 22) ?> Joueurs</a>
                        <a class="a-tile" href="manage_blog.php"><?= admin_svg('file', 22) ?> Écrire un article</a>
                        <a class="a-tile" href="manage_gallery.php"><?= admin_svg('image', 22) ?> Ajouter des photos</a>
                    </div>
                </section>

                <section class="a-card">
                    <div class="a-card-head">
                        <h2><?= admin_svg('card', 18) ?> Dernières réservations</h2>
                        <a class="a-link" href="orders.php">Tout voir →</a>
                    </div>
                    <?php if (!$recent): ?>
                        <p class="a-empty">Aucune réservation pour l'instant.</p>
                    <?php else: ?>
                        <div class="a-list">
                            <?php foreach ($recent as $o): ?>
                                <div class="a-row">
                                    <div class="a-row-main" style="flex-basis:150px;">
                                        <a class="a-row-title" href="../order.php?id=<?= (int)$o['id'] ?>"><?= e($o['buyer_name']) ?></a>
                                        <div class="a-row-sub"><?= e($o['event_title']) ?> · <?= (int)$o['quantity'] ?> pl. · <?= e(admin_ago($o['at'])) ?></div>
                                    </div>
                                    <div class="a-row-side">
                                        <span class="a-amount"><?= (int)$o['amount_cents'] > 0 ? e(format_price((int)$o['amount_cents'])) : 'Gratuit' ?></span>
                                        <?= admin_order_badge($o['status']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
