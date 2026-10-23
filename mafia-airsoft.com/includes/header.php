<?php
$current_page = basename($_SERVER['PHP_SELF']);
$is_logged_in = isLoggedIn();
$is_admin_user = isAdmin();

// Déterminer le chemin de base selon le dossier actuel
$base_path = '';
if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
    $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/player/') !== false) {
    $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/qr-code/') !== false) {
    $base_path = '../';
}
// Pages du panel administrateur (admin/ et billets)
$is_admin_area = $is_admin_user && (strpos($_SERVER['PHP_SELF'], '/admin/') !== false
    || strpos($_SERVER['PHP_SELF'], '/qr-code/') !== false);
if ($is_admin_user) {
    require_once __DIR__ . '/admin_ui.php';
}
?>
<header class="site-header">
    <nav class="navbar">
        <div class="container">
            <div class="nav-brand">
                <a href="<?= $is_admin_user ? $base_path . 'admin/index.php' : $base_path . 'index.php' ?>">
                    <img src="<?= $base_path ?>images/logo.png" alt="Mafia Airsoft Team" class="site-logo">
                    <div class="logo-text-wrapper">
                        <span class="logo-title">MAFIA AIRSOFT TEAM</span>
                        <span class="logo-subtitle">Memento Mori</span>
                    </div>
                </a>
            </div>
            
            <button class="nav-toggle" id="navToggle">☰</button>
            
            <ul class="nav-menu" id="navMenu">
                <li><a href="<?= $base_path ?>index.php" class="<?= $current_page === 'index.php' && !$is_admin_area ? 'active' : '' ?>">Accueil</a></li>
                <li><a href="<?= $base_path ?>events.php" class="<?= $current_page === 'events.php' && !$is_admin_area ? 'active' : '' ?>">Parties</a></li>
                <li><a href="<?= $base_path ?>blog.php" class="<?= $current_page === 'blog.php' ? 'active' : '' ?>">Blog</a></li>
                <?php if ($is_admin_user): ?>
                    <li><a href="<?= $base_path ?>admin/index.php" class="<?= $is_admin_area ? 'active' : '' ?>">Panel admin</a></li>
                <?php endif; ?>
                
                <?php if ($is_logged_in): ?>
                    <?php if (!$is_admin_user): ?>
                        <li><a href="<?= $base_path ?>player/dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">Mon espace</a></li>
                    <?php endif; ?>
                    <li class="nav-user">
                        <span class="user-welcome"><?= icon('user') ?> <?= htmlspecialchars($_SESSION['pseudo']) ?></span>
                        <a href="<?= $base_path ?>logout.php" class="btn-logout">Déconnexion</a>
                    </li>
                <?php else: ?>
                    <li><a href="<?= $base_path ?>register.php" class="<?= $current_page === 'register.php' ? 'active' : '' ?>">Créer un compte</a></li>
                    <li><a href="<?= $base_path ?>login.php" class="btn btn-outline">Connexion</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>
</header>
<?php if ($is_admin_area) { admin_render_nav($base_path); } ?>
