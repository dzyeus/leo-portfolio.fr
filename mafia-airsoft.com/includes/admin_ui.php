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

/** Icône SVG (trait, couleur du texte). */
function admin_svg(string $name, int $size = 20): string
{
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'card'     => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'ticket'   => '<path d="M2 9a3 3 0 0 0 0 6v3a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-3a3 3 0 0 0 0-6V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
        'scan'     => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'menu'     => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'euro'     => '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'usercheck'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'arrow'    => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'printer'  => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'eyeoff'   => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>',
    ];
    $p = $paths[$name] ?? $paths['arrow'];
    return '<svg class="a-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Chaîne JavaScript sûre (à placer dans un attribut HTML via e()). */
function js_str(string $text): string
{
    return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

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
        'players.php'                                 => 'players',
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
    $mobileMain = ['dashboard', 'events', 'scan', 'orders'];
    ?>
    <link rel="stylesheet" href="<?= e($base) ?>css/admin-panel.css">
    <nav class="admin-bar" aria-label="Navigation du panel administrateur">
        <div class="container admin-bar-inner">
            <?php foreach ($sections as $key => [$label, $url, $ico]): ?>
                <a href="<?= e($base . $url) ?>" class="admin-bar-link<?= $current === $key ? ' is-active' : '' ?>"
                   <?= $current === $key ? 'aria-current="page"' : '' ?>>
                    <?= admin_svg($ico, 18) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
            <a href="<?= e($base) ?>index.php" class="admin-bar-link admin-bar-site"><?= admin_svg('external', 18) ?><span>Voir le site</span></a>
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
                <?php foreach ($sections as $key => [$label, $url, $ico]): if (in_array($key, $mobileMain, true)) continue; ?>
                    <a href="<?= e($base . $url) ?>" class="<?= $current === $key ? 'is-active' : '' ?>"><?= admin_svg($ico, 20) ?> <?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="<?= e($base) ?>admin/create_event.php"><?= admin_svg('plus', 20) ?> Créer une partie</a>
                <a href="<?= e($base) ?>index.php"><?= admin_svg('external', 20) ?> Voir le site</a>
                <a href="<?= e($base) ?>logout.php"><?= admin_svg('x', 20) ?> Déconnexion</a>
            </div>
        </details>
    </nav>
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
    $pct = $capacity > 0 ? min(100, (int)round($taken * 100 / $capacity)) : 0;
    $cls = $pct >= 100 ? ' is-full' : ($pct >= 80 ? ' is-high' : '');
    $style = 'width:' . $pct . '%' . ($color !== '' ? ';background:' . e(safe_color($color)) : '');
    return '<div class="a-progress' . $cls . '" title="' . $taken . ' / ' . $capacity . ' places">'
        . '<div class="a-progress-bar" style="' . $style . '"></div></div>';
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
    $cls = match ($status) {
        'paid' => 'st-ok', 'free' => 'st-info', 'pending' => 'st-warn',
        'refunded' => 'st-danger', default => 'st-muted',
    };
    return '<span class="a-badge ' . $cls . '">' . e(order_status_label($status)) . '</span>';
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
