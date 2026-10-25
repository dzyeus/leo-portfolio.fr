<?php
/**
 * Panel admin – Réservations et paiements
 * Filtres (statut, partie, recherche), total encaissé, accès à la réservation,
 * au reçu, renvoi des billets par email.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
requireAdmin();

// ---------- Renvoi des billets
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if (order_send_tickets($pdo, $orderId)) {
        flash_set('success', 'Billets de la réservation n° ' . $orderId . ' renvoyés par email.');
    } else {
        flash_set('error', 'Billets non envoyés (aucun billet prêt ou email non configuré : voir Diagnostic).');
    }
    header('Location: orders.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'event', 'q']))));
    exit;
}

// ---------- Filtres
$statuses = [
    ''          => 'Toutes',
    'paid'      => 'Payées',
    'free'      => 'Gratuites',
    'pending'   => 'Paiement en cours',
    'refunded'  => 'Remboursées',
    'cancelled' => 'Annulées / abandonnées',
];
$status = (string)($_GET['status'] ?? '');
if (!isset($statuses[$status])) {
    $status = '';
}
$eventFilter = (int)($_GET['event'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
if ($status === 'cancelled') {
    $where[] = "o.status IN ('cancelled', 'expired')";
} elseif ($status !== '') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($eventFilter > 0) {
    $where[] = 'o.event_id = ?';
    $params[] = $eventFilter;
}
if ($q !== '') {
    $where[] = '(o.buyer_name LIKE ? OR o.buyer_email LIKE ? OR o.id = ? OR o.receipt_number LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', (int)ltrim($q, '#n° '), '%' . $q . '%');
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT o.*, e.title AS event_title, e.event_date,
           (SELECT COUNT(*) FROM registrations r WHERE r.order_id = o.id) AS places_created,
           (SELECT COUNT(*) FROM registrations r WHERE r.order_id = o.id AND r.user_id IS NULL
                AND (r.first_name IS NULL OR r.first_name = '' OR r.pseudo IS NULL OR r.pseudo = '')) AS guests_missing
    FROM orders o JOIN events e ON e.id = o.event_id
    WHERE $whereSql
    ORDER BY o.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(o.amount_cents), 0), COUNT(*) FROM orders o WHERE $whereSql AND o.status = 'paid'");
$stmt->execute($params);
[$totalPaid, $countPaid] = $stmt->fetch(PDO::FETCH_NUM);

$eventsList = $pdo->query("SELECT id, title, event_date FROM events ORDER BY event_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$flashes = flash_get();
?>
<?php site_head('Réservations', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Réservations</h1>
                <div class="a-sub"><?= count($orders) ?> réservation(s)<?= count($orders) === 200 ? ' (200 plus récentes)' : '' ?>
                    · <strong style="color:#fff;"><?= e(format_price((int)$totalPaid)) ?></strong> encaissés (<?= (int)$countPaid ?> paiement(s))</div>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <form class="a-filter" method="GET">
            <select name="status" aria-label="Statut" onchange="this.form.submit()">
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="event" aria-label="Partie" onchange="this.form.submit()">
                <option value="0">Toutes les parties</option>
                <?php foreach ($eventsList as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>" <?= $eventFilter === (int)$ev['id'] ? 'selected' : '' ?>>
                        <?= e(date('d/m/Y', strtotime($ev['event_date'])) . ' – ' . $ev['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="a-search">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, email, n° de réservation…" aria-label="Rechercher">
                <button type="submit" class="a-btn a-btn-icon" title="Rechercher"><?= admin_svg('search', 18) ?></button>
            </div>
            <?php if ($status !== '' || $eventFilter || $q !== ''): ?>
                <a class="a-link" href="orders.php">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <section class="a-card">
            <?php if (!$orders): ?>
                <p class="a-empty">Aucune réservation ne correspond.</p>
            <?php else: ?>
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Acheteur</th>
                            <th>Partie</th>
                            <th>Places</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): $active = in_array($o['status'], ['paid', 'free'], true); ?>
                            <tr>
                                <td class="t-head">
                                    <a class="t-title" href="../order.php?id=<?= (int)$o['id'] ?>"><?= e($o['buyer_name']) ?></a>
                                    <span class="t-sub">n° <?= (int)$o['id'] ?> · <a class="a-link" href="mailto:<?= e($o['buyer_email']) ?>"><?= e($o['buyer_email']) ?></a></span>
                                </td>
                                <td data-label="Partie">
                                    <span><a class="a-link" href="view_event.php?id=<?= (int)$o['event_id'] ?>"><?= e($o['event_title']) ?></a>
                                        <span class="t-sub"><?= e(date('d/m/Y', strtotime($o['event_date']))) ?></span></span>
                                </td>
                                <td data-label="Places">
                                    <span><?= (int)$o['quantity'] ?>
                                        <?php if ($active && (int)$o['guests_missing'] > 0): ?>
                                            <span class="a-badge st-warn" title="Invités à compléter par l'acheteur"><?= (int)$o['guests_missing'] ?> à compléter</span>
                                        <?php endif; ?></span>
                                </td>
                                <td data-label="Montant"><span class="t-num"><?= (int)$o['amount_cents'] > 0 ? e(format_price((int)$o['amount_cents'])) : 'Gratuit' ?></span></td>
                                <td data-label="Statut"><?= admin_order_badge($o['status']) ?></td>
                                <td data-label="Date"><span><?= e(admin_ago($o['paid_at'] ?? $o['created_at'])) ?></span></td>
                                <td class="t-actions-cell">
                                    <div class="t-actions">
                                        <a href="../order.php?id=<?= (int)$o['id'] ?>" class="a-btn a-btn-sm" title="Voir la réservation"><?= admin_svg('eye', 16) ?> Voir</a>
                                        <?php if (in_array($o['status'], ['paid', 'refunded'], true)): ?>
                                            <a href="../receipt.php?order=<?= (int)$o['id'] ?>" class="a-btn a-btn-sm a-btn-icon" target="_blank" rel="noopener" title="Reçu PDF"><?= admin_svg('file', 16) ?></a>
                                        <?php endif; ?>
                                        <?php if ($active && (int)$o['places_created'] > 0): ?>
                                            <form class="a-inline" method="POST" action="orders.php?<?= e(http_build_query(array_intersect_key($_GET, array_flip(['status', 'event', 'q'])))) ?>">
                                                <input type="hidden" name="action" value="resend">
                                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                <button type="submit" class="a-btn a-btn-sm a-btn-icon" title="Renvoyer les billets par email"><?= admin_svg('mail', 16) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($status === 'paid' || $status === ''): ?>
                    <p class="a-small a-muted" style="margin-top:0.75rem;">
                        Pour rembourser : Dashboard Stripe › Paiements › choisir le paiement › Rembourser.
                        Un remboursement total libère automatiquement les places.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
