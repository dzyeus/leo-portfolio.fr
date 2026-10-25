<?php
require_once 'config/database.php';
require_once 'config/session.php';

// Récupérer tous les articles publiés
$stmt = $pdo->prepare("
    SELECT bp.*, u.pseudo as author_name, u.profile_picture as author_picture
    FROM blog_posts bp
    JOIN users u ON bp.author_id = u.id
    WHERE bp.is_published = 1
    ORDER BY bp.published_at DESC
");
$stmt->execute();
$posts = $stmt->fetchAll();

/** Extrait d'un article (texte brut, 170 caractères). */
function post_excerpt(array $post): string
{
    $text = trim((string)($post['excerpt'] ?? ''));
    if ($text === '') {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$post['content'])));
    }
    return mb_strimwidth($text, 0, 170, '…');
}

site_head('Blog', ['description' => 'Actualités, comptes-rendus de parties et conseils airsoft de la Mafia Airsoft Team.']);
include 'includes/header.php';
?>
<main class="s-wrap">
    <div class="a-page-head">
        <div>
            <h1>Blog</h1>
            <div class="a-sub">Actualités, comptes-rendus de parties et conseils airsoft.</div>
        </div>
    </div>

    <?php if (!$posts): ?>
        <div class="a-card s-empty">
            <?= ui_icon('file', 40) ?>
            <p>Aucun article publié pour le moment. Reviens bientôt !</p>
        </div>
    <?php else: ?>
        <div class="s-blog-grid">
            <?php foreach ($posts as $post):
                $url = 'blog_post.php?slug=' . rawurlencode((string)$post['slug']);
                $date = (string)($post['published_at'] ?: ($post['created_at'] ?? 'now')); ?>
                <article class="a-card s-post">
                    <?php if ($post['featured_image']): ?>
                        <img class="s-post-img" src="<?= e($post['featured_image']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="s-placeholder"><img src="images/logo.png" alt="" loading="lazy"></div>
                    <?php endif; ?>
                    <div class="s-post-body">
                        <div class="s-post-meta">
                            <span><?= ui_icon('calendar', 15) ?> <?= e((int)date('j', strtotime($date)) . ' ' . fr_month($date) . ' ' . date('Y', strtotime($date))) ?></span>
                            <span><?= ui_icon('user', 15) ?> <?= e($post['author_name']) ?></span>
                        </div>
                        <h2><a href="<?= e($url) ?>"><?= e($post['title']) ?></a></h2>
                        <p><?= e(post_excerpt($post)) ?></p>
                        <span class="s-post-more">Lire l'article <?= ui_icon('arrow', 16) ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
