<?php
// Déterminer le chemin de base selon le dossier actuel
$base_path = '';
if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
    $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/player/') !== false) {
    $base_path = '../';
} elseif (strpos($_SERVER['PHP_SELF'], '/qr-code/') !== false) {
    $base_path = '../';
}
?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h3>💀 MAFIA AIRSOFT TEAM</h3>
                <p>Association d'airsoft passionnée par le jeu tactique et le fair-play.</p>
                <p class="motto">Memento Mori</p>
            </div>
            
            <div class="footer-section">
                <h4>Navigation</h4>
                <ul>
                    <li><a href="<?= $base_path ?>index.php">Accueil</a></li>
                    <li><a href="<?= $base_path ?>events.php">Parties</a></li>
                    <li><a href="<?= $base_path ?>blog.php">Blog</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="<?= $base_path ?>player/dashboard.php">Mon espace</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4>Contact</h4>
                <ul>
                    <li>📧 contact@mafia-airsoft.com</li>
                    <li>📱 +33 06 80 85 08 46</li>
                    <li>📍 Puy-de-Dôme</li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4>Sécurité</h4>
                <p>Toutes nos parties respectent les règles de sécurité en vigueur. Protection obligatoire.</p>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> Mafia Airsoft Team. Tous droits réservés.</p>
            <p class="footer-legal">
                <a href="<?= $base_path ?>cgv.php">Conditions générales</a> ·
                <a href="<?= $base_path ?>confidentialite.php">Confidentialité</a> ·
                <a href="<?= $base_path ?>mentions-legales.php">Mentions légales</a>
            </p>
            <p class="footer-credits">
                <img src="<?= $base_path ?>images/icons/france.png" alt="France" class="flag-fr"> Conçu et développé en France
            </p>
        </div>
    </div>
</footer>

<script src="<?= $base_path ?>js/main.js"></script>
<?php if (basename($_SERVER['PHP_SELF']) === 'event.php'): ?>
<script src="<?= $base_path ?>js/event.js"></script>
<?php endif; ?>
