<?php
/**
 * Mise en page commune des pages légales (CGV, mentions légales, confidentialité).
 * Même présentation que le reste du site (sections en cartes).
 */

function legal_page_start(string $title, string $intro = ''): void
{
    site_head($title, ['description' => $title . ' du site de la Mafia Airsoft Team.']);
    include __DIR__ . '/header.php'; ?>
    <main class="s-wrap">
        <div class="s-legal">
            <div class="a-page-head">
                <div>
                    <h1><?= e($title) ?></h1>
                    <div class="a-sub">Version du <?= e(date('d/m/Y', strtotime(TERMS_VERSION))) ?><?= $intro ? ' · ' . e($intro) : '' ?></div>
                </div>
            </div>
<?php }

function legal_page_end(): void
{
    $self = basename($_SERVER['PHP_SELF'] ?? ''); ?>
            <nav class="s-legal-nav" aria-label="Autres pages légales">
                <?php foreach (['cgv.php' => 'Conditions générales', 'confidentialite.php' => 'Confidentialité', 'mentions-legales.php' => 'Mentions légales'] as $url => $label): ?>
                    <a class="a-btn a-btn-sm<?= $self === $url ? ' a-btn-primary' : '' ?>" href="<?= e($url) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
<?php }

/** Adresse de l'association sur une ligne. */
function asso_address_inline(): string
{
    return trim(preg_replace('/\s*\n\s*/', ', ', ASSO_ADDRESS));
}
