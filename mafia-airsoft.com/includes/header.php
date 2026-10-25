<?php
/**
 * En-tête commun à tout le site (pages publiques, espace joueur, panel admin).
 * Sur ordinateur : logo, menu, boutons de compte. Sur mobile : bouton ☰ qui
 * ouvre un menu sombre en plein écran.
 */
$current_page = basename($_SERVER['PHP_SELF']);
$is_logged_in = isLoggedIn();
$is_admin_user = isAdmin();
$base_path = base_path();
$self_path = $_SERVER['PHP_SELF'] ?? '';
$in_player = strpos($self_path, '/player/') !== false;

// Pages du panel administrateur (admin/ et billets)
$is_admin_area = $is_admin_user && (strpos($self_path, '/admin/') !== false || strpos($self_path, '/qr-code/') !== false);
if ($is_admin_user) {
    require_once __DIR__ . '/admin_ui.php';
}

// Pages qui n'ont pas utilisé site_head() (panel admin, scanner) : on ajoute les styles communs
if (!defined('SITE_HEAD')) { ?>
<link rel="stylesheet" href="<?= e(asset_url('css/ui.css', $base_path)) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/site.css', $base_path)) ?>">
<?php }

$nav_items = [
    ['index.php',  'Accueil', 'home',     $current_page === 'index.php' && !$is_admin_area && !$in_player],
    ['events.php', 'Parties', 'calendar', in_array($current_page, ['events.php', 'event.php'], true)],
    ['blog.php',   'Blog',    'file',     in_array($current_page, ['blog.php', 'blog_post.php'], true)],
];
$account_active = $in_player || $current_page === 'order.php';

// Photo de profil pour la pastille du compte
$header_avatar = '';
if ($is_logged_in && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([current_user_id()]);
        $header_avatar = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $header_avatar = '';
    }
}
$header_pseudo = (string)($_SESSION['pseudo'] ?? '');
$avatar_html = '<span class="s-avatar">' . ($header_avatar !== ''
    ? '<img src="' . e($base_path . ltrim($header_avatar, '/')) . '" alt="">'
    : e(initial($header_pseudo))) . '</span>';
?>
<header class="s-header">
    <div class="s-wrap s-header-inner">
        <a class="s-brand" href="<?= e($base_path) ?>index.php" aria-label="Mafia Airsoft Team – accueil">
            <img src="<?= e($base_path) ?>images/logo.png" alt="" width="42" height="42">
            <span class="s-brand-text">
                <span class="s-brand-name">Mafia Airsoft Team</span>
                <span class="s-brand-tag">Memento Mori</span>
            </span>
        </a>

        <nav class="s-nav" aria-label="Navigation principale">
            <?php foreach ($nav_items as [$url, $label, $ico, $active]): ?>
                <a href="<?= e($base_path . $url) ?>" class="s-nav-link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= ui_icon($ico, 18) ?><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($is_admin_user): ?>
                <a href="<?= e($base_path) ?>admin/index.php" class="s-nav-link<?= $is_admin_area ? ' is-active' : '' ?>"><?= ui_icon('shield', 18) ?>Panel admin</a>
            <?php endif; ?>
        </nav>

        <div class="s-header-actions">
            <?php if ($is_logged_in): ?>
                <a href="<?= e($base_path) ?>player/dashboard.php" class="s-user<?= $account_active ? ' is-active' : '' ?>" title="Mon espace">
                    <?= $avatar_html ?><span><?= $is_admin_user ? e($header_pseudo) : 'Mon espace' ?></span>
                </a>
                <a href="<?= e(logout_url($base_path)) ?>" class="a-btn a-btn-sm a-btn-icon" title="Déconnexion" aria-label="Déconnexion"><?= ui_icon('logout', 18) ?></a>
            <?php else: ?>
                <a href="<?= e($base_path) ?>login.php" class="a-btn a-btn-sm<?= $current_page === 'login.php' ? ' is-current' : '' ?>"><?= ui_icon('login', 17) ?>Connexion</a>
                <a href="<?= e($base_path) ?>register.php" class="a-btn a-btn-sm a-btn-primary">Créer un compte</a>
            <?php endif; ?>
        </div>

        <button type="button" class="s-burger" id="siteMenuToggle" aria-expanded="false" aria-controls="siteMenu" aria-label="Ouvrir le menu">
            <?= ui_icon('menu', 22) ?>
        </button>
    </div>

    <div class="s-mobile-menu" id="siteMenu" hidden>
        <?php if ($is_logged_in): ?>
            <div class="s-mobile-user"><?= $avatar_html ?><span><?= e($header_pseudo) ?></span></div>
        <?php endif; ?>
        <nav class="s-mobile-links" aria-label="Menu">
            <?php foreach ($nav_items as [$url, $label, $ico, $active]): ?>
                <a href="<?= e($base_path . $url) ?>" class="<?= $active ? 'is-active' : '' ?>"><?= ui_icon($ico, 20) ?><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($is_logged_in): ?>
                <a href="<?= e($base_path) ?>player/dashboard.php" class="<?= $account_active ? 'is-active' : '' ?>"><?= ui_icon('user', 20) ?>Mon espace</a>
            <?php endif; ?>
            <?php if ($is_admin_user): ?>
                <a href="<?= e($base_path) ?>admin/index.php" class="<?= $is_admin_area ? 'is-active' : '' ?>"><?= ui_icon('shield', 20) ?>Panel admin</a>
            <?php endif; ?>
        </nav>
        <div class="s-mobile-actions">
            <?php if ($is_logged_in): ?>
                <a href="<?= e($base_path) ?>player/profile.php" class="a-btn"><?= ui_icon('settings', 18) ?>Mon profil</a>
                <a href="<?= e(logout_url($base_path)) ?>" class="a-btn"><?= ui_icon('logout', 18) ?>Déconnexion</a>
            <?php else: ?>
                <a href="<?= e($base_path) ?>login.php" class="a-btn"><?= ui_icon('login', 18) ?>Connexion</a>
                <a href="<?= e($base_path) ?>register.php" class="a-btn a-btn-primary">Créer un compte</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php if ($is_admin_area) { admin_render_nav($base_path); } ?>
