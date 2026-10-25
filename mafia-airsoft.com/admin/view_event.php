<?php
/**
 * Panel admin – Fiche d'une partie
 * Infos, remplissage par équipe, liste des inscrits (joueurs + invités) avec
 * recherche, pointage manuel de la présence, export CSV, impression.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? 0);
$list = admin_events_with_stats($pdo, 'e.id = ?', [$event_id]);
$event = $list[0] ?? null;

if (!$event) {
    flash_set('error', 'Partie introuvable.');
    header('Location: events.php');
    exit;
}

// ---------- Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? (isset($_POST['delete_registration']) ? 'delete_registration' : '');
    $reg_id = (int)($_POST['registration_id'] ?? 0);

    if ($action === 'delete_registration') {
        // La place et son billet sont supprimés (pas de remboursement automatique)
        $pdo->prepare("DELETE FROM registrations WHERE id = ? AND event_id = ?")->execute([$reg_id, $event_id]);
        flash_set('success', 'Inscription supprimée. Si la place était payée, pense à la rembourser dans Stripe.');
    } elseif ($action === 'toggle_presence') {
        // Pointage manuel (QR code illisible, téléphone déchargé…)
        ticket_ensure($pdo, $reg_id);
        $stmt = $pdo->prepare("SELECT t.id, t.scanned_at FROM tickets t JOIN registrations r ON r.id = t.registration_id
                               WHERE t.registration_id = ? AND r.event_id = ?");
        $stmt->execute([$reg_id, $event_id]);
        if ($ticket = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($ticket['scanned_at']) {
                $pdo->prepare("UPDATE tickets SET scanned_at = NULL, scanned_by = NULL WHERE id = ?")->execute([$ticket['id']]);
            } else {
                $pdo->prepare("UPDATE tickets SET scanned_at = NOW(), scanned_by = ? WHERE id = ?")
                    ->execute([current_user_id(), $ticket['id']]);
            }
        }
    }
    header('Location: view_event.php?id=' . $event_id . '#inscrits');
    exit;
}

// ---------- Inscrits (joueurs et invités) avec billet et paiement
$stmt = $pdo->prepare("
    SELECT r.id, r.user_id, r.team, r.registered_at,
           COALESCE(r.pseudo, u.pseudo) AS pseudo,
           TRIM(CONCAT_WS(' ', COALESCE(r.first_name, u.first_name), COALESCE(r.last_name, u.last_name))) AS full_name,
           COALESCE(u.email, o.buyer_email) AS email,
           u.phone,
           o.id AS order_id, o.status AS order_status, o.buyer_name,
           t.scanned_at
    FROM registrations r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN orders o ON r.order_id = o.id
    LEFT JOIN tickets t ON t.registration_id = r.id
    WHERE r.event_id = ?
    ORDER BY r.registered_at
");
$stmt->execute([$event_id]);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamsByKey = [];
foreach ($event['teams'] as $t) {
    $teamsByKey[$t['team_key']] = $t;
}
$teamFilter = (string)($_GET['team'] ?? '');
if ($teamFilter !== '' && isset($teamsByKey[$teamFilter])) {
    $registrations = array_values(array_filter($registrations, fn($r) => $r['team'] === $teamFilter));
} else {
    $teamFilter = '';
}

$isPast = strtotime($event['event_date']) < time();
$isToday = date('Y-m-d', strtotime($event['event_date'])) === date('Y-m-d');
$flashes = flash_get();
?>
<?php site_head($event['title'], ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div style="min-width:0;">
                <a class="a-link" href="events.php">← Parties</a>
                <h1><?= e($event['title']) ?></h1>
                <div class="a-meta" style="margin-top:0.35rem;">
                    <span><?= admin_svg('calendar', 16) ?> <?= e(ucfirst(date_fr($event['event_date']))) ?> · <?= e(admin_relative_day($event['event_date'])) ?></span>
                    <span><?= admin_svg('pin', 16) ?> <?= e($event['location']) ?></span>
                    <span><?= admin_svg('euro', 16) ?> <?= event_is_free($event) ? 'Gratuit' : e(format_price((int)$event['price_cents'])) . ' / place' ?></span>
                    <span class="a-badge <?= $event['status_class'] ?>"><?= e($event['status_label']) ?></span>
                </div>
            </div>
            <div class="a-actions a-actions-grid">
                <?php if (!$isPast): ?>
                    <a href="../qr-code/scan.php" class="a-btn <?= $isToday ? 'a-btn-primary' : '' ?>"><?= admin_svg('scan', 18) ?> Scanner</a>
                <?php endif; ?>
                <a href="edit_event.php?id=<?= $event_id ?>" class="a-btn"><?= admin_svg('edit', 18) ?> Modifier</a>
                <a href="manage_teams.php?event_id=<?= $event_id ?>" class="a-btn"><?= admin_svg('shield', 18) ?> Équipes</a>
                <a href="export_event.php?id=<?= $event_id ?>" class="a-btn"><?= admin_svg('download', 18) ?> Export Excel</a>
                <button type="button" class="a-btn" onclick="window.print()"><?= admin_svg('printer', 18) ?> Imprimer</button>
                <a href="delete_event.php?id=<?= $event_id ?>" class="a-btn a-btn-danger"><?= admin_svg('trash', 18) ?> Supprimer</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <!-- Chiffres clés -->
        <div class="a-grid a-grid-4" style="margin-bottom:1rem;">
            <div class="a-kpi">
                <span class="a-kpi-label"><?= admin_svg('users', 15) ?> Inscrits</span>
                <span class="a-kpi-value"><?= $event['registered'] ?><span class="a-muted" style="font-size:0.6em;"> / <?= $event['capacity'] ?></span></span>
                <?= admin_progress($event['taken'], $event['capacity']) ?>
            </div>
            <div class="a-kpi">
                <span class="a-kpi-label"><?= admin_svg('check', 15) ?> Présents</span>
                <span class="a-kpi-value"><?= $event['present'] ?><span class="a-muted" style="font-size:0.6em;"> / <?= $event['registered'] ?></span></span>
                <span class="a-kpi-hint">billets scannés ou pointés</span>
            </div>
            <div class="a-kpi">
                <span class="a-kpi-label"><?= admin_svg('euro', 15) ?> Encaissé</span>
                <span class="a-kpi-value"><?= e(format_price($event['paid_cents'])) ?></span>
                <span class="a-kpi-hint"><?= $event['paid_orders'] ?> paiement(s)</span>
            </div>
            <div class="a-kpi">
                <span class="a-kpi-label"><?= admin_svg('alert', 15) ?> Invités sans nom</span>
                <span class="a-kpi-value"><?= $event['guests_missing'] ?></span>
                <span class="a-kpi-hint"><?= $event['guests_missing'] ? 'billet non généré' : 'tout est complet' ?></span>
            </div>
        </div>

        <!-- Équipes -->
        <section class="a-card">
            <div class="a-card-head"><h2><?= admin_svg('shield', 18) ?> Équipes</h2>
                <a class="a-link" href="manage_teams.php?event_id=<?= $event_id ?>">Gérer →</a></div>
            <div class="a-teams" style="margin-bottom:0;">
                <?php foreach ($event['teams'] as $t): ?>
                    <a class="a-team" href="?id=<?= $event_id ?>&team=<?= urlencode($t['team_key']) ?>#inscrits" style="text-decoration:none;">
                        <div class="a-team-name"><span><?= e($t['team_name']) ?></span><span><?= (int)$t['current_players'] ?>/<?= (int)$t['max_players'] ?></span></div>
                        <?= admin_progress((int)$t['current_players'], (int)$t['max_players'], $t['team_color']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Inscrits -->
        <section class="a-card" id="inscrits">
            <div class="a-card-head">
                <h2><?= admin_svg('users', 18) ?> Inscrits
                    <?php if ($teamFilter): ?><span class="a-badge st-muted"><?= e($teamsByKey[$teamFilter]['team_name']) ?></span><?php endif; ?></h2>
                <div class="a-actions">
                    <?php if ($teamFilter): ?><a class="a-link" href="?id=<?= $event_id ?>#inscrits">Toutes les équipes</a><?php endif; ?>
                    <?php if ($registrations): ?>
                        <a class="a-btn a-btn-sm" href="message.php?event=<?= $event_id ?>"><?= admin_svg('send', 16) ?> Écrire aux inscrits</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$registrations): ?>
                <p class="a-empty">Aucun inscrit<?= $teamFilter ? ' dans cette équipe' : '' ?> pour l'instant.</p>
            <?php else: ?>
                <div class="a-search" style="max-width:none;margin-bottom:0.75rem;">
                    <input type="search" id="regSearch" placeholder="Rechercher un pseudo, un nom, un email…" aria-label="Rechercher un inscrit">
                </div>
                <table class="rtable" id="regTable">
                    <thead>
                        <tr>
                            <th>Joueur</th>
                            <th>Équipe</th>
                            <th>Contact</th>
                            <th>Réservation</th>
                            <th>Présence</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $r):
                            $team = $teamsByKey[$r['team']] ?? null;
                            $missing = !$r['user_id'] && (trim((string)$r['full_name']) === '' || trim((string)$r['pseudo']) === ''); ?>
                            <tr data-search="<?= e(mb_strtolower($r['pseudo'] . ' ' . $r['full_name'] . ' ' . $r['email'] . ' ' . $r['buyer_name'])) ?>">
                                <td class="t-head">
                                    <span class="t-title"><?= e($r['pseudo'] ?: 'Invité') ?></span>
                                    <span class="t-sub">
                                        <?= $missing ? ui_icon('alert', 14) . ' Nom à compléter par ' . e($r['buyer_name'] ?? 'l\'acheteur') : e($r['full_name']) ?>
                                        <?php if (!$r['user_id'] && !$missing): ?> · invité de <?= e($r['buyer_name'] ?? '?') ?><?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Équipe">
                                    <span class="a-badge st-muted" style="border-left:3px solid <?= e(safe_color($team['team_color'] ?? '')) ?>;">
                                        <?= e($team['team_name'] ?? $r['team']) ?></span>
                                </td>
                                <td data-label="Contact">
                                    <span>
                                        <?php if ($r['email']): ?><a class="a-link" href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?>
                                        <?php if ($r['phone']): ?><span class="t-sub"><a class="a-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></span><?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Réservation">
                                    <?php if ($r['order_id']): ?>
                                        <span><a class="a-link" href="../order.php?id=<?= (int)$r['order_id'] ?>">n° <?= (int)$r['order_id'] ?></a>
                                            <?= admin_order_badge($r['order_status']) ?></span>
                                    <?php else: ?>
                                        <span class="a-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Présence">
                                    <form class="a-inline" method="POST">
                                        <input type="hidden" name="action" value="toggle_presence">
                                        <input type="hidden" name="registration_id" value="<?= (int)$r['id'] ?>">
                                        <?php if ($r['scanned_at']): ?>
                                            <button type="submit" class="a-btn a-btn-sm a-btn-ok" title="Annuler le pointage">
                                                <?= admin_svg('check', 16) ?> Présent · <?= e(date('H\hi', strtotime($r['scanned_at']))) ?></button>
                                        <?php else: ?>
                                            <button type="submit" class="a-btn a-btn-sm" title="Pointer manuellement (sans scanner)">Pointer présent</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td class="t-actions-cell">
                                    <div class="t-actions">
                                        <?php if (!$missing): ?>
                                            <a href="../ticket.php?registration=<?= (int)$r['id'] ?>" class="a-btn a-btn-sm" target="_blank" rel="noopener" title="Billet PDF"><?= admin_svg('ticket', 16) ?> Billet</a>
                                        <?php endif; ?>
                                        <form class="a-inline" method="POST"
                                              onsubmit="return confirm('<?= $r['order_status'] === 'paid' ? 'Place payée : pense à la rembourser dans Stripe. ' : '' ?>Supprimer cette inscription et son billet ?');">
                                            <input type="hidden" name="action" value="delete_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="a-btn a-btn-sm a-btn-icon a-btn-danger" title="Supprimer l'inscription"><?= admin_svg('trash', 16) ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="a-empty" id="regNoResult" style="display:none;">Aucun inscrit ne correspond.</p>
            <?php endif; ?>
        </section>

        <?php if ($event['description'] || $event['scenario']): ?>
            <details class="a-collapse" style="margin-top:1rem;">
                <summary><?= admin_svg('file', 18) ?> Description et scénario</summary>
                <div class="a-collapse-body">
                    <?php if ($event['description']): ?><p style="margin-bottom:0.75rem;"><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
                    <?php if ($event['scenario']): ?><p class="a-muted"><?= nl2br(e($event['scenario'])) ?></p><?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>
    <script>
        // Recherche instantanée dans la liste des inscrits
        (function () {
            var input = document.getElementById('regSearch');
            if (!input) return;
            var rows = document.querySelectorAll('#regTable tbody tr');
            var empty = document.getElementById('regNoResult');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase(), shown = 0;
                rows.forEach(function (tr) {
                    var ok = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
                    tr.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                empty.style.display = shown ? 'none' : 'block';
            });
        })();
    </script>
</body>
</html>
