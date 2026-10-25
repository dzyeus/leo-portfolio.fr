<?php
/**
 * Panel admin – Parties : à venir / passées / masquées, recherche,
 * remplissage, encaissé, et toutes les actions (voir, modifier, équipes,
 * dupliquer, supprimer).
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
requireAdmin();

$tab = $_GET['tab'] ?? 'upcoming';
$q = trim((string)($_GET['q'] ?? ''));

$filters = [
    'upcoming' => "e.event_date >= NOW() AND e.is_active = 1",
    'hidden'   => "e.event_date >= NOW() AND e.is_active = 0",
    'past'     => "e.event_date < NOW()",
];
if (!isset($filters[$tab])) {
    $tab = 'upcoming';
}

$counts = [];
foreach ($filters as $key => $where) {
    $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM events e WHERE $where")->fetchColumn();
}

$where = $filters[$tab];
$params = [];
if ($q !== '') {
    $where .= " AND (e.title LIKE ? OR e.location LIKE ?)";
    $params = ['%' . $q . '%', '%' . $q . '%'];
}
$events = admin_events_with_stats($pdo, $where, $params, $tab === 'past' ? 'e.event_date DESC' : 'e.event_date ASC');

$tabLabels = ['upcoming' => 'À venir', 'past' => 'Passées', 'hidden' => 'Masquées'];
$flashes = flash_get();
?>
<?php site_head('Parties', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Parties</h1>
                <div class="a-sub"><?= $counts['upcoming'] ?> à venir · <?= $counts['hidden'] ?> masquée(s) · <?= $counts['past'] ?> passée(s)</div>
            </div>
            <div class="a-actions">
                <a href="create_event.php" class="a-btn a-btn-primary"><?= admin_svg('plus', 18) ?> Créer une partie</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <div class="a-toolbar">
            <nav class="a-tabs" aria-label="Filtrer les parties">
                <?php foreach ($tabLabels as $key => $label): ?>
                    <a class="a-tab<?= $tab === $key ? ' is-active' : '' ?>" href="?tab=<?= $key ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                        <?= e($label) ?><span class="a-count"><?= $counts[$key] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="a-search" method="GET" role="search">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher un titre ou un lieu…" aria-label="Rechercher une partie">
                <button type="submit" class="a-btn a-btn-icon" title="Rechercher"><?= admin_svg('search', 18) ?></button>
            </form>
        </div>

        <section class="a-card">
            <?php if (!$events): ?>
                <p class="a-empty">
                    <?= $q !== '' ? 'Aucune partie ne correspond à « ' . e($q) . ' ».' : 'Aucune partie dans cette catégorie.' ?>
                </p>
            <?php else: ?>
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Partie</th>
                            <th>Date</th>
                            <th>Prix</th>
                            <th>Remplissage</th>
                            <th>Encaissé</th>
                            <th>Statut</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev): ?>
                            <tr>
                                <td class="t-head">
                                    <a class="t-title" href="view_event.php?id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a>
                                    <span class="t-sub"><?= e($ev['location']) ?></span>
                                </td>
                                <td data-label="Date">
                                    <span>
                                        <?= e(date('d/m/Y H\hi', strtotime($ev['event_date']))) ?>
                                        <span class="t-sub"><?= e(admin_relative_day($ev['event_date'])) ?></span>
                                    </span>
                                </td>
                                <td data-label="Prix"><span class="t-num"><?= event_is_free($ev) ? 'Gratuit' : e(format_price((int)$ev['price_cents'])) ?></span></td>
                                <td data-label="Remplissage">
                                    <div class="a-fill">
                                        <span class="a-fill-text"><strong><?= $ev['taken'] ?></strong>/<?= $ev['capacity'] ?>
                                            <?php if ($tab === 'past' && $ev['registered'] > 0): ?> · <?= $ev['present'] ?> présent(s)<?php endif; ?></span>
                                        <?= admin_progress($ev['taken'], $ev['capacity']) ?>
                                    </div>
                                </td>
                                <td data-label="Encaissé"><span class="t-num"><?= e(format_price($ev['paid_cents'])) ?></span></td>
                                <td data-label="Statut">
                                    <span>
                                        <span class="a-badge <?= $ev['status_class'] ?>"><?= e($ev['status_label']) ?></span>
                                        <?php if ($tab !== 'past' && empty($ev['announced_at']) && !empty($ev['registration_open']) && !empty($ev['is_active'])): ?>
                                            <span class="a-badge st-info" title="Les abonnés n'ont pas encore été prévenus">Non annoncée</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="t-actions-cell">
                                    <div class="t-actions">
                                        <a href="view_event.php?id=<?= (int)$ev['id'] ?>" class="a-btn a-btn-sm" title="Inscrits, présence, export"><?= admin_svg('users', 16) ?> Inscrits</a>
                                        <a href="edit_event.php?id=<?= (int)$ev['id'] ?>" class="a-btn a-btn-sm a-btn-icon" title="Modifier"><?= admin_svg('edit', 16) ?></a>
                                        <a href="manage_teams.php?event_id=<?= (int)$ev['id'] ?>" class="a-btn a-btn-sm a-btn-icon" title="Équipes"><?= admin_svg('shield', 16) ?></a>
                                        <form class="a-inline" method="POST" action="duplicate_event.php">
                                            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                                            <button type="submit" class="a-btn a-btn-sm a-btn-icon" title="Dupliquer (même partie une semaine plus tard)"><?= admin_svg('copy', 16) ?></button>
                                        </form>
                                        <a href="delete_event.php?id=<?= (int)$ev['id'] ?>" class="a-btn a-btn-sm a-btn-icon a-btn-danger" title="Supprimer"><?= admin_svg('trash', 16) ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
