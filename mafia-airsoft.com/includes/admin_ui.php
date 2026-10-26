<?php
/**
 * Éléments communs du panel administrateur :
 * icônes SVG, navigation (barre sur ordinateur, onglets en bas sur mobile),
 * statuts et barres de remplissage des parties, statistiques par partie.
 */

if (defined('MAT_ADMIN_UI')) {
    return;
}
define('MAT_ADMIN_UI', true);

require_once __DIR__ . '/ui.php'; // icônes, js_str(), barre de remplissage, dates

/** Sections du panel (ordre de la navigation). */
function admin_sections(): array
{
    return [
        'dashboard' => ['Tableau de bord', 'admin/index.php',        'home'],
        'events'    => ['Parties',         'admin/events.php',       'calendar'],
        'orders'    => ['Réservations',    'admin/orders.php',       'card'],
        'players'   => ['Joueurs',         'admin/players.php',      'users'],
        'message'   => ['Messages',        'admin/message.php',      'send'],
        'scan'      => ['Scanner',         'qr-code/scan.php',       'scan'],
        'tickets'   => ['Billets',         'qr-code/dashboard.php',  'ticket'],
        'blog'      => ['Blog',            'admin/manage_blog.php',  'file'],
        'gallery'   => ['Galerie',         'admin/manage_gallery.php','image'],
        'diagnostic'=> ['Diagnostic',      'admin/diagnostic.php',   'activity'],
    ];
}

/** Section active selon la page affichée. */
function admin_current_section(): string
{
    $self = $_SERVER['PHP_SELF'] ?? '';
    $file = basename($self);
    if (str_contains($self, '/qr-code/')) {
        return $file === 'scan.php' ? 'scan' : 'tickets';
    }
    return match ($file) {
        'index.php'                                   => 'dashboard',
        'events.php', 'create_event.php', 'edit_event.php', 'view_event.php',
        'manage_teams.php', 'delete_event.php', 'duplicate_event.php' => 'events',
        'orders.php'                                  => 'orders',
        'players.php', 'player.php'                   => 'players',
        'message.php'                                 => 'message',
        'manage_blog.php'                             => 'blog',
        'manage_gallery.php'                          => 'gallery',
        'diagnostic.php'                              => 'diagnostic',
        default                                       => '',
    };
}

/** Barre de navigation admin (affichée par includes/header.php sur les pages admin). */
function admin_render_nav(string $base): void
{
    $current = admin_current_section();
    $sections = admin_sections();
    // Ordinateur : sections principales visibles, le reste dans « Plus »
    $deskMain = ['dashboard', 'events', 'orders', 'players', 'message', 'scan'];
    $deskMore = array_diff(array_keys($sections), $deskMain);
    // Mobile : 4 onglets + « Plus »
    $mobileMain = ['dashboard', 'events', 'scan', 'orders'];
    ?>
    <?php if (!defined('ADMIN_CSS_IN_HEAD')): ?><link rel="stylesheet" href="<?= e(asset_url('css/admin-panel.css', $base)) ?>"><?php endif; ?>
    <nav class="admin-bar" aria-label="Navigation du panel administrateur">
        <div class="s-wrap admin-bar-inner">
            <div class="admin-bar-links">
                <?php foreach ($deskMain as $key): [$label, $url, $ico] = $sections[$key]; ?>
                    <a href="<?= e($base . $url) ?>" class="admin-bar-link<?= $current === $key ? ' is-active' : '' ?>" title="<?= e($label) ?>"
                       <?= $current === $key ? 'aria-current="page"' : '' ?>>
                        <?= admin_svg($ico, 18) ?><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
                <details class="admin-bar-more<?= in_array($current, $deskMore, true) ? ' is-active' : '' ?>">
                    <summary class="admin-bar-link<?= in_array($current, $deskMore, true) ? ' is-active' : '' ?>" title="Plus">
                        <?= admin_svg('menu', 18) ?><span><?= in_array($current, $deskMore, true) ? e($sections[$current][0]) : 'Plus' ?></span>
                        <svg class="admin-bar-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="admin-bar-menu">
                        <?php foreach ($deskMore as $key): [$label, $url, $ico] = $sections[$key]; ?>
                            <a href="<?= e($base . $url) ?>" class="<?= $current === $key ? 'is-active' : '' ?>"><?= admin_svg($ico, 18) ?> <?= e($label) ?></a>
                        <?php endforeach; ?>
                        <a href="<?= e($base) ?>admin/create_event.php"><?= admin_svg('plus', 18) ?> Créer une partie</a>
                    </div>
                </details>
            </div>
            <a href="<?= e($base) ?>index.php" class="admin-bar-site" title="Revenir sur le site public">
                <?= admin_svg('external', 17) ?><span>Voir le site</span>
            </a>
        </div>
    </nav>

    <nav class="admin-tabbar" aria-label="Navigation admin mobile">
        <?php foreach ($mobileMain as $key): [$label, $url, $ico] = $sections[$key]; ?>
            <a href="<?= e($base . $url) ?>" class="admin-tab<?= $key === 'scan' ? ' admin-tab-scan' : '' ?><?= $current === $key ? ' is-active' : '' ?>">
                <?= admin_svg($ico, 22) ?><span><?= e($key === 'dashboard' ? 'Accueil' : $label) ?></span>
            </a>
        <?php endforeach; ?>
        <details class="admin-tab admin-tab-more<?= !in_array($current, $mobileMain, true) && $current !== '' ? ' is-active' : '' ?>">
            <summary><?= admin_svg('menu', 22) ?><span>Plus</span></summary>
            <div class="admin-more-sheet">
                <a href="<?= e($base) ?>index.php" class="admin-more-site"><?= admin_svg('external', 20) ?> Voir le site</a>
                <?php foreach ($sections as $key => [$label, $url, $ico]): if (in_array($key, $mobileMain, true)) continue; ?>
                    <a href="<?= e($base . $url) ?>" class="<?= $current === $key ? 'is-active' : '' ?>"><?= admin_svg($ico, 20) ?> <?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="<?= e($base) ?>admin/create_event.php"><?= admin_svg('plus', 20) ?> Créer une partie</a>
                <a href="<?= e(logout_url($base)) ?>"><?= admin_svg('x', 20) ?> Déconnexion</a>
            </div>
        </details>
    </nav>
    <script>
    // Les menus « Plus » se ferment en cliquant ailleurs ou avec Échap
    (function () {
        var menus = document.querySelectorAll('.admin-bar-more, .admin-tab-more');
        function closeAll(except) { menus.forEach(function (d) { if (d !== except) { d.removeAttribute('open'); } }); }
        document.addEventListener('click', function (e) {
            var inside = e.target.closest ? e.target.closest('.admin-bar-more, .admin-tab-more') : null;
            closeAll(inside);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(null); } });
    })();
    </script>
    <?php
}

// ---------------------------------------------------------------------
// Parties : statut, remplissage, statistiques
// ---------------------------------------------------------------------

/** Statut lisible d'une partie : [libellé, classe CSS]. */
function admin_event_status(array $event, int $taken = 0, int $capacity = 0): array
{
    if (strtotime($event['event_date']) < time()) {
        return ['Terminée', 'st-muted'];
    }
    if (empty($event['is_active'])) {
        return ['Masquée', 'st-muted'];
    }
    if (empty($event['registration_open'])) {
        return ['Inscriptions fermées', 'st-warn'];
    }
    if ($capacity > 0 && $taken >= $capacity) {
        return ['Complet', 'st-warn'];
    }
    return ['Ouverte', 'st-ok'];
}

/** « dans 9 j », « demain », « aujourd'hui », « il y a 3 j ». */
function admin_relative_day(string $datetime): string
{
    $d = (int)floor((strtotime(date('Y-m-d', strtotime($datetime))) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $d === 0 => "aujourd'hui",
        $d === 1 => 'demain',
        $d === -1 => 'hier',
        $d > 1 => 'dans ' . $d . ' j',
        default => 'il y a ' . abs($d) . ' j',
    };
}

/** Barre de remplissage « 12 / 33 ». */
function admin_progress(int $taken, int $capacity, string $color = ''): string
{
    return ui_progress($taken, $capacity, $color);
}

/**
 * Parties + statistiques (places prises, capacité, inscrits confirmés, présents,
 * encaissé, invités à compléter). $where/$params : filtre SQL sur la table e.
 */
function admin_events_with_stats(PDO $pdo, string $where = '1=1', array $params = [], string $order = 'e.event_date ASC'): array
{
    $events = getEventsWithTeams($pdo, $where, $params);
    if ($order === 'e.event_date DESC') {
        $events = array_reverse($events);
    }
    if (!$events) {
        return [];
    }
    $ids = array_map(fn($e) => (int)$e['id'], $events);
    $in = implode(',', $ids);

    $stats = [];
    foreach ($pdo->query("
        SELECT r.event_id,
               COUNT(*) AS registered,
               SUM(t.scanned_at IS NOT NULL) AS present,
               SUM(r.user_id IS NULL AND (r.first_name IS NULL OR r.first_name = '' OR r.pseudo IS NULL OR r.pseudo = '')) AS guests_missing
        FROM registrations r LEFT JOIN tickets t ON t.registration_id = r.id
        WHERE r.event_id IN ($in) GROUP BY r.event_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stats[(int)$row['event_id']] = $row;
    }
    $revenue = [];
    foreach ($pdo->query("
        SELECT event_id, SUM(amount_cents) AS paid_cents, COUNT(*) AS paid_orders
        FROM orders WHERE event_id IN ($in) AND status = 'paid' GROUP BY event_id
    ")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenue[(int)$row['event_id']] = $row;
    }

    foreach ($events as &$e) {
        $id = (int)$e['id'];
        $e['taken'] = (int)array_sum(array_column($e['teams'], 'current_players'));
        $e['capacity'] = (int)array_sum(array_column($e['teams'], 'max_players'));
        $e['registered'] = (int)($stats[$id]['registered'] ?? 0);
        $e['present'] = (int)($stats[$id]['present'] ?? 0);
        $e['guests_missing'] = (int)($stats[$id]['guests_missing'] ?? 0);
        $e['paid_cents'] = (int)($revenue[$id]['paid_cents'] ?? 0);
        $e['paid_orders'] = (int)($revenue[$id]['paid_orders'] ?? 0);
        [$e['status_label'], $e['status_class']] = admin_event_status($e, $e['taken'], $e['capacity']);
    }
    unset($e);
    return $events;
}

/** Badge de statut de commande. */
function admin_order_badge(string $status): string
{
    return order_badge($status);
}

/** « il y a 5 min », « il y a 2 h », « 12/10 à 14h05 ». */
function admin_ago(string $datetime): string
{
    $s = time() - strtotime($datetime);
    if ($s < 60) return "à l'instant";
    if ($s < 3600) return 'il y a ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'il y a ' . floor($s / 3600) . ' h';
    if ($s < 7 * 86400) return 'il y a ' . floor($s / 86400) . ' j';
    return date('d/m à H\hi', strtotime($datetime));
}
