<?php
/**
 * Page « introuvable » (ErrorDocument 404 dans .htaccess).
 * Les liens sont absolus : la page peut s'afficher depuis n'importe quel dossier.
 */
http_response_code(404);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

define('SITE_BASE', '/'); // les liens partent de la racine du site
site_head('Page introuvable', ['noindex' => true]);
include __DIR__ . '/includes/header.php';
?>
<main class="s-wrap">
    <div class="s-404">
        <div class="s-404-code">404</div>
        <h1>Objectif introuvable</h1>
        <p>La page que tu cherches a été évacuée du terrain… ou n'a jamais existé.</p>
        <div class="s-form-actions">
            <a href="/index.php" class="a-btn a-btn-primary a-btn-lg"><?= ui_icon('home', 18) ?> Retour à l'accueil</a>
            <a href="/events.php" class="a-btn a-btn-lg"><?= ui_icon('calendar', 18) ?> Voir les parties</a>
        </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
