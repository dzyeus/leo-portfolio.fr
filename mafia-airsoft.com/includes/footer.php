<?php
/** Pied de page commun à tout le site. */
$base_path = base_path();
[$footer_phone, $footer_phone_href] = asso_phone();
$footer_email = defined('ASSO_EMAIL') && ASSO_EMAIL !== '' ? ASSO_EMAIL : 'contact@mafia-airsoft.com';
?>
<footer class="s-footer">
    <div class="s-wrap s-footer-grid">
        <div class="s-footer-brand">
            <a class="s-brand" href="<?= e($base_path) ?>index.php">
                <img src="<?= e($base_path) ?>images/logo.png" alt="" width="42" height="42" loading="lazy">
                <span class="s-brand-text">
                    <span class="s-brand-name">Mafia Airsoft Team</span>
                    <span class="s-brand-tag">Association d'airsoft</span>
                </span>
            </a>
            <p>Association d'airsoft passionnée par le jeu tactique et le fair-play.</p>
            <p class="s-footer-motto">Memento Mori</p>
            <p class="s-footer-note"><?= ui_icon('shield', 18) ?><span>Toutes nos parties respectent les règles de sécurité en vigueur. Protection oculaire obligatoire.</span></p>
        </div>

        <div>
            <h2>Navigation</h2>
            <ul>
                <li><a href="<?= e($base_path) ?>index.php">Accueil</a></li>
                <li><a href="<?= e($base_path) ?>events.php">Parties</a></li>
                <li><a href="<?= e($base_path) ?>blog.php">Blog</a></li>
                <?php if (isLoggedIn()): ?>
                    <li><a href="<?= e($base_path) ?>player/dashboard.php">Mon espace</a></li>
                <?php else: ?>
                    <li><a href="<?= e($base_path) ?>register.php">Créer un compte</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <div>
            <h2>Contact</h2>
            <ul>
                <li><?= ui_icon('mail', 17) ?><a href="mailto:<?= e($footer_email) ?>"><?= e($footer_email) ?></a></li>
                <li><?= ui_icon('phone', 17) ?><a href="tel:<?= e($footer_phone_href) ?>"><?= e($footer_phone) ?></a></li>
                <li><?= ui_icon('pin', 17) ?><span>Puy-de-Dôme (63)</span></li>
            </ul>
        </div>
    </div>

    <div class="s-wrap s-footer-bottom">
        <span>&copy; <?= date('Y') ?> Mafia Airsoft Team. Tous droits réservés.</span>
        <nav class="s-footer-legal" aria-label="Informations légales">
            <a href="<?= e($base_path) ?>cgv.php">Conditions générales</a>
            <a href="<?= e($base_path) ?>confidentialite.php">Confidentialité</a>
            <a href="<?= e($base_path) ?>mentions-legales.php">Mentions légales</a>
        </nav>
        <span class="s-footer-credit"><img src="<?= e($base_path) ?>images/icons/france.png" alt="" width="18" height="12" loading="lazy">Conçu et développé en France</span>
    </div>
</footer>

<script src="<?= e(asset_url('js/main.js', $base_path)) ?>"></script>
