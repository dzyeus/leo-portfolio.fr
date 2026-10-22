<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'includes/legal_layout.php';

legal_page_start('Politique de confidentialité');
?>
        <section class="dashboard-section">
            <h2>Responsable du traitement</h2>
            <p><strong><?= e(ASSO_NAME) ?></strong>, <?= e(asso_address_inline()) ?>.
               Contact pour toute question sur tes données : <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a>.</p>
        </section>

        <section class="dashboard-section">
            <h2>Données collectées et pourquoi</h2>
            <ul>
                <li><strong>Compte</strong> : pseudo, nom, prénom, email, téléphone (facultatif), mot de passe (chiffré, illisible même pour nous),
                    photo de profil éventuelle — pour gérer ton compte et tes inscriptions <em>(exécution du contrat)</em>.</li>
                <li><strong>Réservations</strong> : parties, équipes, nombre de places, nom, prénom et pseudo de tes invités,
                    billets et présence à l'entrée — pour organiser les parties et contrôler les accès <em>(exécution du contrat)</em>.</li>
                <li><strong>Paiements</strong> : montant, date, numéro de reçu, référence Stripe. Les données de carte bancaire sont
                    traitées uniquement par Stripe, jamais par nous <em>(exécution du contrat et obligations comptables)</em>.</li>
                <li><strong>Alertes nouvelles parties</strong> : seulement si tu as coché la case, désactivable à tout moment
                    <em>(consentement)</em>.</li>
                <li><strong>Sécurité</strong> : adresse IP lors des tentatives de connexion ou d'inscription, pour bloquer les
                    attaques <em>(intérêt légitime)</em>.</li>
            </ul>
        </section>

        <section class="dashboard-section">
            <h2>Destinataires</h2>
            <ul>
                <li>Les organisateurs de l'association (listes des participants, contrôle des billets).</li>
                <li>Les autres visiteurs voient uniquement le <strong>pseudo</strong> et la photo des inscrits sur la page d'une partie.</li>
                <li>Nos prestataires techniques : <?= e(HOST_NAME) ?> (hébergement, en France) et Stripe (paiement).
                    Stripe peut transférer des données hors de l'Union européenne avec des garanties appropriées
                    (clauses contractuelles types).</li>
            </ul>
            <p>Tes données ne sont jamais vendues ni utilisées à des fins publicitaires.</p>
        </section>

        <section class="dashboard-section">
            <h2>Durées de conservation</h2>
            <ul>
                <li>Compte : jusqu'à sa suppression (par toi depuis ton profil, ou sur demande).</li>
                <li>Paiements et reçus : 10 ans, durée de conservation des pièces comptables. Ils restent conservés
                    (nom et email de l'acheteur) même après la suppression du compte.</li>
                <li>Liens de confirmation d'email et de mot de passe : 48 h maximum. Traces de sécurité (IP) : 24 h.</li>
            </ul>
        </section>

        <section class="dashboard-section">
            <h2>Tes droits</h2>
            <p>Tu disposes d'un droit d'accès, de rectification, d'effacement, de limitation, d'opposition, de portabilité
               de tes données et du droit de retirer ton consentement aux alertes à tout moment.</p>
            <ul>
                <li>Modifier tes informations ou tes alertes : <a href="player/profile.php">Mon profil</a>.</li>
                <li>Télécharger tes données ou supprimer ton compte : en bas de <a href="player/profile.php">Mon profil</a>.</li>
                <li>Pour toute autre demande : <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a>.</li>
            </ul>
            <p>Si tu estimes que tes droits ne sont pas respectés, tu peux adresser une réclamation à la CNIL
               (<a href="https://www.cnil.fr" rel="noopener" target="_blank">www.cnil.fr</a>).</p>
        </section>

        <section class="dashboard-section">
            <h2>Cookies</h2>
            <p>Le site utilise uniquement un cookie technique de session, nécessaire pour rester connecté et sécuriser les
               formulaires. Il ne nécessite pas de consentement. Aucun cookie publicitaire ni de statistiques n'est déposé.</p>
        </section>
<?php legal_page_end(); ?>
