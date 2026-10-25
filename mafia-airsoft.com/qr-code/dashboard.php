<?php
/**
 * Tableau de bord de gestion des billets
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
requireAdmin();

// Partie affichée : celle demandée, sinon la prochaine (ou la plus récente)
$selectedEventId = isset($_GET['event']) ? (int)$_GET['event'] : null;

// Récupérer tous les événements
$stmt = $pdo->query("
    SELECT e.*, 
           COUNT(t.id) as total_tickets,
           SUM(CASE WHEN t.scanned_at IS NOT NULL THEN 1 ELSE 0 END) as scanned_tickets
    FROM events e
    LEFT JOIN tickets t ON e.id = t.event_id
    GROUP BY e.id
    ORDER BY e.event_date DESC
");
$events = $stmt->fetchAll();
if (!$selectedEventId && $events) {
    $upcoming = array_filter($events, fn($ev) => strtotime($ev['event_date']) >= strtotime('today'));
    $selectedEventId = (int)($upcoming ? end($upcoming)['id'] : $events[0]['id']);
}
$selectedEvent = null;
foreach ($events as $ev) {
    if ((int)$ev['id'] === (int)$selectedEventId) {
        $selectedEvent = $ev;
    }
}

// Statistiques pour l'événement sélectionné
$tickets = [];
$stats = [
    'total' => 0,
    'scanned' => 0,
    'pending' => 0,
    'rate' => 0
];

if ($selectedEventId) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.code AS ticket_code, (t.scanned_at IS NOT NULL) AS is_scanned,
               t.scanned_at, t.created_at,
               e.title as event_title,
               e.event_date,
               CONCAT_WS(' ',
                   NULLIF(TRIM(CONCAT_WS(' ', COALESCE(r.first_name, u.first_name), COALESCE(r.last_name, u.last_name))), ''),
                   CONCAT('(', COALESCE(r.pseudo, u.pseudo, 'invité à compléter'), ')')
               ) as user_name,
               COALESCE(u.email, CONCAT(o.buyer_email, ' (acheteur)')) as user_email,
               scanner.pseudo as scanned_by_name
        FROM tickets t
        JOIN registrations r ON r.id = t.registration_id
        JOIN events e ON t.event_id = e.id
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN orders o ON r.order_id = o.id
        LEFT JOIN users scanner ON t.scanned_by = scanner.id
        WHERE t.event_id = ?
        ORDER BY is_scanned ASC, t.created_at DESC
    ");
    $stmt->execute([$selectedEventId]);
    $tickets = $stmt->fetchAll();
    
    $stats['total'] = count($tickets);
    $stats['scanned'] = array_reduce($tickets, fn($sum, $t) => $sum + ($t['is_scanned'] ? 1 : 0), 0);
    $stats['pending'] = $stats['total'] - $stats['scanned'];
    $stats['rate'] = $stats['total'] > 0 ? round(($stats['scanned'] / $stats['total']) * 100, 1) : 0;
}
?>
<?php site_head('Billets', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Billets</h1>
                <div class="a-sub">Suivi des billets et des entrées<?= $selectedEvent ? ' · ' . e($selectedEvent['title']) . ' · ' . e(ucfirst(date_fr($selectedEvent['event_date']))) : '' ?></div>
            </div>
            <div class="a-actions">
                <a href="scan.php" class="a-btn a-btn-primary"><?= ui_icon('scan', 18) ?> Scanner les billets</a>
                <?php if ($selectedEvent): ?>
                    <a href="../admin/view_event.php?id=<?= (int)$selectedEvent['id'] ?>#inscrits" class="a-btn"><?= ui_icon('users', 18) ?> Inscrits</a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="a-filter">
            <select name="event" aria-label="Partie" onchange="this.form.submit()">
                <?php if (!$events): ?><option value="">Aucune partie</option><?php endif; ?>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>" <?= (int)$ev['id'] === (int)$selectedEventId ? 'selected' : '' ?>>
                        <?= e(date('d/m/Y', strtotime($ev['event_date'])) . ' – ' . $ev['title'] . ' (' . (int)$ev['total_tickets'] . ' billet' . ((int)$ev['total_tickets'] > 1 ? 's' : '') . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="a-btn">Afficher</button></noscript>
        </form>

        <?php if ($selectedEvent): ?>
            <div class="a-grid a-grid-4" style="margin-bottom:1rem;">
                <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('ticket', 15) ?> Billets</span><span class="a-kpi-value"><?= (int)$stats['total'] ?></span></div>
                <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('usercheck', 15) ?> Présents</span><span class="a-kpi-value"><?= (int)$stats['scanned'] ?></span></div>
                <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('clock', 15) ?> En attente</span><span class="a-kpi-value"><?= (int)$stats['pending'] ?></span></div>
                <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('activity', 15) ?> Présence</span>
                    <span class="a-kpi-value"><?= e(str_replace('.', ',', (string)$stats['rate'])) ?> %</span>
                    <?= admin_progress((int)$stats['scanned'], max(1, (int)$stats['total'])) ?></div>
            </div>

            <section class="a-card">
                <div class="a-toolbar">
                    <nav class="a-tabs" aria-label="Filtrer les billets">
                        <button type="button" class="a-tab is-active" data-filter="all">Tous <span class="a-count"><?= (int)$stats['total'] ?></span></button>
                        <button type="button" class="a-tab" data-filter="scanned">Présents <span class="a-count"><?= (int)$stats['scanned'] ?></span></button>
                        <button type="button" class="a-tab" data-filter="pending">En attente <span class="a-count"><?= (int)$stats['pending'] ?></span></button>
                    </nav>
                    <div class="a-search">
                        <input type="search" id="ticketSearch" placeholder="Nom, pseudo, email ou code…" aria-label="Rechercher un billet">
                    </div>
                </div>

                <?php if (!$tickets): ?>
                    <p class="a-empty">Aucun billet pour cette partie.</p>
                <?php else: ?>
                    <table class="rtable" id="ticketTable">
                        <thead>
                            <tr><th>Participant</th><th>Statut</th><th>Code</th><th>Contact</th><th>Créé le</th><th>Entrée</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $t): ?>
                                <tr data-status="<?= $t['is_scanned'] ? 'scanned' : 'pending' ?>"
                                    data-search="<?= e(mb_strtolower($t['user_name'] . ' ' . $t['user_email'] . ' ' . $t['ticket_code'])) ?>">
                                    <td class="t-head"><span class="t-title"><?= e($t['user_name']) ?></span></td>
                                    <td data-label="Statut"><?= $t['is_scanned'] ? '<span class="a-badge st-ok">Présent</span>' : '<span class="a-badge st-warn">En attente</span>' ?></td>
                                    <td data-label="Code"><code class="a-code"><?= e($t['ticket_code']) ?></code></td>
                                    <td data-label="Contact"><span class="a-small"><?= e($t['user_email']) ?></span></td>
                                    <td data-label="Créé le"><span><?= e(date('d/m/Y H\hi', strtotime($t['created_at']))) ?></span></td>
                                    <td data-label="Entrée"><span><?= $t['scanned_at'] ? e(date('d/m H\hi', strtotime($t['scanned_at']))) . ($t['scanned_by_name'] ? ' · ' . e($t['scanned_by_name']) : '') : '—' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="a-empty" id="ticketEmpty" hidden>Aucun billet ne correspond.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="a-card"><p class="a-empty">Aucune partie pour l'instant.</p></section>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>
    <script>
    (function () {
        var table = document.getElementById('ticketTable');
        if (!table) { return; }
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        var search = document.getElementById('ticketSearch');
        var empty = document.getElementById('ticketEmpty');
        var filter = 'all';
        function apply() {
            var q = (search.value || '').trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var ok = (filter === 'all' || row.getAttribute('data-status') === filter)
                    && (!q || row.getAttribute('data-search').indexOf(q) !== -1);
                row.hidden = !ok;
                if (ok) { shown++; }
            });
            empty.hidden = shown > 0;
        }
        document.querySelectorAll('[data-filter]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                filter = btn.getAttribute('data-filter');
                apply();
            });
        });
        search.addEventListener('input', apply);
    })();
    </script>
</body>
</html>
