<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'includes/legal_layout.php';

legal_page_start('Mentions légales');
?>
        <section class="a-card s-legal-section">
            <h2>Éditeur du site</h2>
            <p><strong><?= e(ASSO_NAME) ?></strong>, association régie par la loi du 1er juillet 1901.</p>
            <ul>
                <li>Siège : <?= e(asso_address_inline()) ?></li>
                <?php if (ASSO_RNA): ?><li>N° RNA : <?= e(ASSO_RNA) ?></li><?php endif; ?>
                <?php if (ASSO_SIRET): ?><li>SIRET : <?= e(ASSO_SIRET) ?></li><?php endif; ?>
                <li>Email : <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a></li>
                <?php if (ASSO_PHONE): ?><li>Téléphone : <?= e(ASSO_PHONE) ?></li><?php endif; ?>
            </ul>
            <?php if (ASSO_PUBLICATION_DIRECTOR): ?>
                <p>Directeur de la publication : <?= e(ASSO_PUBLICATION_DIRECTOR) ?></p>
            <?php endif; ?>
        </section>

        <section class="a-card s-legal-section">
            <h2>Hébergeur</h2>
            <ul>
                <li><?= e(HOST_NAME) ?></li>
                <li><?= e(HOST_ADDRESS) ?></li>
                <li>Téléphone : <?= e(HOST_PHONE) ?></li>
            </ul>
        </section>

        <section class="a-card s-legal-section">
            <h2>Paiements</h2>
            <p>Les paiements en ligne sont traités par Stripe Payments Europe Ltd. Aucune donnée de carte bancaire
               n'est stockée sur ce site.</p>
        </section>

        <section class="a-card s-legal-section">
            <h2>Propriété intellectuelle</h2>
            <p>Le logo, les textes et les photos de ce site appartiennent à l'association ou à leurs auteurs.
               Toute reproduction sans autorisation est interdite.</p>
        </section>

        <section class="a-card s-legal-section">
            <h2>Données personnelles et cookies</h2>
            <p>Voir la <a href="confidentialite.php">politique de confidentialité</a>. Le site n'utilise qu'un cookie
               technique de session, indispensable à la connexion ; aucun cookie publicitaire ou de mesure d'audience.</p>
        </section>
<?php legal_page_end(); ?>
