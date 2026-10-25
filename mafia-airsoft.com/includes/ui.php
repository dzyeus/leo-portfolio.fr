<?php
/**
 * Éléments d'interface communs à tout le site (public et panel admin) :
 * icônes SVG, barre de remplissage, dates en français, liens d'itinéraire,
 * en-tête HTML des pages publiques (<head>, partage sur WhatsApp…).
 */

if (defined('MAT_UI')) {
    return;
}
define('MAT_UI', true);

/** Icône SVG (trait, couleur du texte). */
function ui_icon(string $name, int $size = 20, string $class = 'a-ico'): string
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
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'login'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'navigation' => '<polygon points="3 11 22 2 13 21 11 13 3 11"/>',
        'chevron'  => '<polyline points="9 18 15 12 9 6"/>',
        'chevronleft' => '<polyline points="15 18 9 12 15 6"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'target'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'phone'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'backpack' => '<path d="M5 20V9a7 7 0 0 1 14 0v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M9 4.5V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1.5"/><path d="M8 21v-6h8v6"/>',
        'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'camera'   => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'help'     => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    ];
    $p = $paths[$name] ?? $paths['arrow'];
    return '<svg class="' . $class . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Ancien nom (panel admin). */
function admin_svg(string $name, int $size = 20): string
{
    return ui_icon($name, $size);
}

/** Chaîne JavaScript sûre (à placer dans un attribut HTML via e()). */
function js_str(string $text): string
{
    return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/** Barre de remplissage « 12 / 33 ». */
function ui_progress(int $taken, int $capacity, string $color = ''): string
{
    $pct = $capacity > 0 ? min(100, (int)round($taken * 100 / $capacity)) : 0;
    $cls = $pct >= 100 ? ' is-full' : ($pct >= 80 ? ' is-high' : '');
    $style = 'width:' . $pct . '%' . ($color !== '' ? ';background:' . e(safe_color($color)) : '');
    return '<div class="a-progress' . $cls . '" role="img" aria-label="' . $taken . ' places prises sur ' . $capacity . '">'
        . '<div class="a-progress-bar" style="' . $style . '"></div></div>';
}

// ---------------------------------------------------------------------
// Chemins et fichiers
// ---------------------------------------------------------------------

/** Chemin relatif vers la racine du site depuis la page affichée. */
function base_path(): string
{
    if (defined('SITE_BASE')) {
        return SITE_BASE; // page d'erreur : liens depuis la racine
    }
    $self = $_SERVER['PHP_SELF'] ?? '';
    foreach (['/admin/', '/player/', '/qr-code/'] as $dir) {
        if (strpos($self, $dir) !== false) {
            return '../';
        }
    }
    return '';
}

/** Lien vers un fichier du site avec sa version (le navigateur recharge le fichier quand il change). */
function asset_url(string $path, ?string $base = null): string
{
    $file = MAT_ROOT . '/' . $path;
    return ($base ?? base_path()) . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

// ---------------------------------------------------------------------
// Dates en français
// ---------------------------------------------------------------------

function fr_month(string $datetime, bool $short = false): string
{
    $long = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $abbr = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $m = (int)date('n', strtotime($datetime)) - 1;
    return $short ? $abbr[$m] : $long[$m];
}

function fr_weekday(string $datetime, bool $short = false): string
{
    $long = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $abbr = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    $d = (int)date('w', strtotime($datetime));
    return $short ? $abbr[$d] : $long[$d];
}

/** « dim. 1 nov. » */
function date_fr_short(string $datetime): string
{
    return fr_weekday($datetime, true) . ' ' . (int)date('j', strtotime($datetime)) . ' ' . fr_month($datetime, true);
}

/** « 11h00 » */
function time_fr(string $datetime): string
{
    return date('H\hi', strtotime($datetime));
}

/** « dans 6 jours », « demain », « aujourd'hui » */
function days_until_fr(string $datetime): string
{
    $d = (int)floor((strtotime(date('Y-m-d', strtotime($datetime))) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $d === 0 => "aujourd'hui",
        $d === 1 => 'demain',
        $d > 1   => 'dans ' . $d . ' jours',
        $d === -1 => 'hier',
        default  => 'il y a ' . abs($d) . ' jours',
    };
}

// ---------------------------------------------------------------------
// Lieux, contact
// ---------------------------------------------------------------------

/** Le lieu est-il des coordonnées GPS (« 45.24, 3.30 ») ? */
function location_is_gps(string $location): bool
{
    return (bool)preg_match('/^\s*-?\d{1,2}\.\d+\s*[,;]\s*-?\d{1,3}\.\d+\s*$/', $location);
}

/** Lien Google Maps (itinéraire vers des coordonnées, sinon recherche du lieu). */
function maps_url(string $location): string
{
    if (location_is_gps($location)) {
        $coords = preg_replace('/\s+/', '', str_replace(';', ',', $location));
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($coords);
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($location);
}

/** Téléphone de l'association : [texte affiché, lien tel:]. */
function asso_phone(): array
{
    $raw = trim((string)(defined('ASSO_PHONE') && ASSO_PHONE !== '' ? ASSO_PHONE : '06 80 85 08 46'));
    $digits = preg_replace('/\D+/', '', $raw);
    if (str_starts_with($digits, '33')) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10) {
        return [trim(chunk_split($digits, 2, ' ')), '+33' . substr($digits, 1)];
    }
    return [$raw, preg_replace('/[^0-9+]/', '', $raw)];
}

// ---------------------------------------------------------------------
// Pages publiques
// ---------------------------------------------------------------------

/**
 * Début d'une page publique : <!DOCTYPE>, <head> (titre, description, aperçu
 * pour WhatsApp / réseaux sociaux, styles) et ouverture de <body>.
 * Options : description, image (chemin depuis la racine), noindex, body_class, base,
 * admin (page du panel : pas d'indexation + styles du panel).
 */
function site_head(string $title, array $o = []): void
{
    if (!defined('SITE_HEAD')) {
        define('SITE_HEAD', true);
    }
    $base = $o['base'] ?? base_path();
    $isAdmin = !empty($o['admin']); // page du panel administrateur
    if ($isAdmin) {
        $o['noindex'] = true;
        $o['body_class'] = trim('s-admin ' . ($o['body_class'] ?? ''));
        if (!defined('ADMIN_CSS_IN_HEAD')) {
            define('ADMIN_CSS_IN_HEAD', true);
        }
    }
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Mafia Airsoft Team';
    $fullTitle = $title === '' ? $siteName : $title . ' · ' . $siteName;
    $description = $o['description'] ?? 'Mafia Airsoft Team, association d\'airsoft du Puy-de-Dôme : parties en forêt, scénarios, inscriptions et paiement en ligne.';
    $description = mb_substr(trim(preg_replace('/\s+/', ' ', $description)), 0, 200);
    $siteUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    $image = $siteUrl . '/' . ltrim($o['image'] ?? 'images/logo.png', '/');
    $url = $siteUrl . ($_SERVER['REQUEST_URI'] ?? '/');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($fullTitle) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <?php if (!empty($o['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <meta name="theme-color" content="#0a0a0a">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($title === '' ? $siteName : $title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:image" content="<?= e($image) ?>">
    <meta property="og:url" content="<?= e($url) ?>">
    <meta property="og:locale" content="fr_FR">
    <link rel="icon" type="image/x-icon" href="<?= e($base) ?>images/favicon.ico">
    <link rel="apple-touch-icon" href="<?= e($base) ?>images/logo.png">
    <link rel="stylesheet" href="<?= e(asset_url('css/ui.css', $base)) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('css/site.css', $base)) ?>">
    <?php if ($isAdmin): ?><link rel="stylesheet" href="<?= e(asset_url('css/admin-panel.css', $base)) ?>"><?php endif; ?>
</head>
<body class="s-site<?= !empty($o['body_class']) ? ' ' . e($o['body_class']) : '' ?>">
<?php
}

/** Messages (succès, erreur…) en haut de page. */
function render_flashes(array $flashes): void
{
    foreach ($flashes as $flash) {
        $type = in_array($flash['type'] ?? '', ['success', 'error', 'info', 'warning'], true) ? $flash['type'] : 'info';
        echo '<div class="alert alert-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($flash['message']) . '</div>';
    }
}

/** Badge de statut d'une réservation. */
function order_badge(string $status): string
{
    $cls = match ($status) {
        'paid' => 'st-ok', 'free' => 'st-info', 'pending' => 'st-warn',
        'refunded' => 'st-danger', default => 'st-muted',
    };
    return '<span class="a-badge ' . $cls . '">' . e(order_status_label($status)) . '</span>';
}

/** Initiale d'un pseudo pour les avatars sans photo. */
function initial(string $text): string
{
    $text = trim($text);
    return $text === '' ? '?' : mb_strtoupper(mb_substr($text, 0, 1));
}
