<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'includes/legal_layout.php';

$refundDays = (int)CANCEL_REFUND_DAYS;
legal_page_start('Conditions générales de vente et de participation');
?>
        <section class="dashboard-section">
            <h2>1. Objet</h2>
            <p>Les présentes conditions encadrent l'inscription et le paiement des parties d'airsoft organisées par
               l'association <strong><?= e(ASSO_NAME) ?></strong> (association loi 1901, <?= e(asso_address_inline()) ?><?= ASSO_RNA ? ', RNA ' . e(ASSO_RNA) : '' ?>),
               ci-après « l'organisateur », via le site <?= e(SITE_URL) ?>.</p>
            <p>Toute réservation implique l'acceptation sans réserve de ces conditions, cochée au moment de la réservation.
               La version applicable est celle en vigueur à la date de la réservation.</p>
        </section>

        <section class="dashboard-section">
            <h2>2. Compte joueur</h2>
            <p>La réservation nécessite un compte personnel avec des informations exactes (nom, prénom, pseudo, email).
               Chaque joueur est responsable de la confidentialité de son mot de passe. L'organisateur peut suspendre
               un compte en cas d'informations fausses ou de manquement aux présentes conditions.</p>
        </section>

        <section class="dashboard-section">
            <h2>3. Réservation</h2>
            <p>Le joueur choisit une partie, une équipe et un nombre de places (pour lui et des invités), dans la limite
               des places disponibles. Pour une partie payante, les places sont bloquées pendant le paiement
               (<?= (int)CHECKOUT_HOLD_MINUTES ?> minutes maximum) puis libérées si le paiement n'aboutit pas.</p>
            <p>La réservation est définitive dès la confirmation du paiement (ou dès l'inscription pour une partie gratuite).
               Un email de confirmation est envoyé avec les billets et, pour une partie payante, le reçu.</p>
            <p>Le joueur qui réserve pour des invités s'engage à renseigner leurs vrais nom, prénom et pseudo, à leur
               transmettre ces conditions et répond de leur respect par ses invités.</p>
        </section>

        <section class="dashboard-section">
            <h2>4. Prix et paiement</h2>
            <p>Le prix est indiqué en euros, par place, sur la page de chaque partie. <?= e(ASSO_VAT_MENTION) ?>.</p>
            <p>Le paiement se fait en ligne par carte bancaire via notre prestataire de paiement sécurisé <strong>Stripe</strong>.
               L'organisateur n'a jamais accès aux données de ta carte.</p>
        </section>

        <section class="dashboard-section">
            <h2>5. Billets</h2>
            <p>Chaque place donne lieu à un billet nominatif avec un QR code unique, valable une seule fois,
               à présenter à l'entrée (sur téléphone ou imprimé). L'organisateur peut demander une pièce d'identité.
               Un billet déjà scanné ou ne correspondant pas au participant peut être refusé.</p>
        </section>

        <section class="dashboard-section">
            <h2>6. Absence de droit de rétractation</h2>
            <p>Conformément à l'article <strong>L221-28, 12° du Code de la consommation</strong>, le droit de rétractation
               de 14 jours <strong>ne s'applique pas</strong> aux prestations d'activités de loisirs fournies à une date
               déterminée, ce qui est le cas des parties d'airsoft. Le joueur en est informé et l'accepte avant de payer.</p>
        </section>

        <section class="dashboard-section">
            <h2>7. Annulation par le joueur</h2>
            <?php if ($refundDays > 0): ?>
                <p>Le joueur peut demander l'annulation d'une place payée en écrivant à
                   <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a> :</p>
                <ul>
                    <li>au moins <strong><?= $refundDays ?> jours</strong> avant la partie : remboursement intégral sur la carte utilisée ;</li>
                    <li>moins de <?= $refundDays ?> jours avant la partie ou en cas d'absence : aucun remboursement.</li>
                </ul>
            <?php else: ?>
                <p>Les places payées ne sont pas remboursables à la demande du joueur, y compris en cas d'absence.</p>
            <?php endif; ?>
            <p>Le joueur peut, avec l'accord de l'organisateur, céder sa place à une autre personne (le billet sera alors
               modifié à son nom). Une inscription à une partie gratuite peut être annulée à tout moment depuis le site.</p>
        </section>

        <section class="dashboard-section">
            <h2>8. Annulation ou modification par l'organisateur</h2>
            <p>L'organisateur peut annuler ou reporter une partie (météo dangereuse, terrain indisponible, nombre de joueurs
               insuffisant, cas de force majeure…). Les joueurs sont prévenus par email et intégralement remboursés,
               ou peuvent accepter un report sur une autre date.</p>
            <p>Le scénario, les horaires et la répartition des équipes peuvent être ajustés pour le bon déroulement du jeu,
               sans donner droit à remboursement.</p>
        </section>

        <section class="dashboard-section">
            <h2>9. Conditions de participation et sécurité</h2>
            <ul>
                <li>Les participants de moins de <?= (int)ADULT_AGE ?> ans doivent présenter une autorisation parentale signée
                    et respecter la réglementation sur l'usage des répliques selon leur âge.</li>
                <li>Une protection oculaire homologuée est <strong>obligatoire</strong> et ne doit jamais être retirée en zone de jeu.</li>
                <li>Les répliques peuvent être contrôlées au chronographe ; toute réplique non conforme aux limites annoncées est refusée.</li>
                <li>Alcool, stupéfiants et comportements dangereux sont interdits. Le fair-play et les consignes des organisateurs s'imposent à tous.</li>
                <li>L'organisateur peut refuser l'accès ou exclure un participant qui ne respecte pas ces règles,
                    <strong>sans remboursement</strong>.</li>
            </ul>
        </section>

        <section class="dashboard-section">
            <h2>10. Responsabilité et assurance</h2>
            <p>Chaque participant est responsable de son matériel et des dommages qu'il cause ; il est conseillé de disposer
               d'une assurance responsabilité civile. La responsabilité de l'organisateur ne saurait être engagée en cas de
               non-respect des consignes de sécurité, de perte ou de vol de matériel personnel.</p>
        </section>

        <section class="dashboard-section">
            <h2>11. Droit à l'image</h2>
            <p>Des photos et vidéos peuvent être prises pendant les parties et publiées sur le site et les réseaux de
               l'association. Tout participant peut s'y opposer en le signalant aux organisateurs ou en écrivant à
               <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a>.</p>
        </section>

        <section class="dashboard-section">
            <h2>12. Données personnelles</h2>
            <p>Les données nécessaires à la réservation sont traitées conformément à notre
               <a href="confidentialite.php">politique de confidentialité</a>.</p>
        </section>

        <section class="dashboard-section">
            <h2>13. Réclamations et litiges</h2>
            <p>Toute réclamation peut être adressée à <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a>.
               Nous nous engageons à rechercher une solution amiable.</p>
            <p>Les présentes conditions sont soumises au droit français. À défaut d'accord amiable, le litige sera porté
               devant les tribunaux compétents.</p>
        </section>
<?php legal_page_end(); ?>
