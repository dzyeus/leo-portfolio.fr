<?php
require_once 'config/database.php';
require_once 'config/session.php';

$slug = $_GET['slug'] ?? '';

// Récupérer l'article
$stmt = $pdo->prepare("
    SELECT bp.*, u.pseudo as author_name, u.profile_picture as author_picture
    FROM blog_posts bp
    JOIN users u ON bp.author_id = u.id
    WHERE bp.slug = ? AND bp.is_published = 1
");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: blog.php');
    exit;
}

// Récupérer les images de la galerie
$stmt_gallery = $pdo->prepare("
    SELECT * FROM blog_gallery 
    WHERE blog_post_id = ? 
    ORDER BY display_order ASC
");
$stmt_gallery->execute([$post['id']]);
$gallery_images = $stmt_gallery->fetchAll();

// Incrémenter le compteur de vues
$stmt = $pdo->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?");
$stmt->execute([$post['id']]);

$published = (string)($post['published_at'] ?: $post['created_at'] ?? 'now');
$excerpt = trim((string)($post['excerpt'] ?? '')) ?: mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$post['content'])), 0, 180, '…');
$cover = (string)($post['featured_image'] ?? '');

site_head($post['title'], [
    'description' => $excerpt,
    'image' => ($cover !== '' && !preg_match('#^https?://#', $cover)) ? $cover : 'images/logo.png',
]);
include 'includes/header.php';
?>
<main class="s-wrap">
    <article class="s-article">
        <a class="s-back" href="blog.php"><?= ui_icon('chevronleft', 18) ?> Tous les articles</a>
        <header class="s-article-head">
            <h1><?= e($post['title']) ?></h1>
            <div class="s-post-meta">
                <span><?= ui_icon('calendar', 15) ?> <?= e((int)date('j', strtotime($published)) . ' ' . fr_month($published) . ' ' . date('Y', strtotime($published))) ?></span>
                <span><?= ui_icon('user', 15) ?> <?= e($post['author_name']) ?></span>
                <span><?= ui_icon('eye', 15) ?> <?= (int)$post['views'] + 1 ?> lecture<?= (int)$post['views'] + 1 > 1 ? 's' : '' ?></span>
            </div>
        </header>

        <?php if ($cover !== ''): ?>
            <figure class="s-article-cover"><img src="<?= e($cover) ?>" alt=""></figure>
        <?php endif; ?>

        <div class="a-card" style="margin-top:1.25rem;">
            <div class="s-prose"><?= nl2br(e($post['content'])) ?></div>
        </div>

        <?php if ($gallery_images): ?>
            <section class="a-card" style="margin-top:1.25rem;">
                <div class="a-card-head"><h2><?= ui_icon('camera', 18) ?> Photos</h2><span class="s-muted s-small"><?= count($gallery_images) ?> photo<?= count($gallery_images) > 1 ? 's' : '' ?></span></div>
                <div class="s-gallery">
                    <?php foreach ($gallery_images as $image): ?>
                        <button type="button" data-lightbox="<?= e($image['image_path']) ?>" data-caption="<?= e($image['caption'] ?? '') ?>" aria-label="Agrandir la photo">
                            <img src="<?= e($image['image_path']) ?>" alt="<?= e($image['caption'] ?: 'Photo de l\'article') ?>" loading="lazy">
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>
            <dialog class="s-lightbox" id="lightbox" aria-label="Photo agrandie">
                <img src="" alt="">
                <p></p>
                <div class="s-lightbox-bar">
                    <?php if (count($gallery_images) > 1): ?><button type="button" class="a-btn" data-lb="-1" aria-label="Photo précédente"><?= ui_icon('chevronleft', 18) ?></button><?php endif; ?>
                    <button type="button" class="a-btn" data-lb="close"><?= ui_icon('x', 18) ?> Fermer</button>
                    <?php if (count($gallery_images) > 1): ?><button type="button" class="a-btn" data-lb="1" aria-label="Photo suivante"><?= ui_icon('chevron', 18) ?></button><?php endif; ?>
                </div>
            </dialog>
        <?php endif; ?>

        <div class="s-form-actions" style="margin-top:1.5rem;">
            <a href="blog.php" class="a-btn"><?= ui_icon('chevronleft', 18) ?> Retour au blog</a>
            <a href="events.php" class="a-btn a-btn-primary"><?= ui_icon('calendar', 18) ?> Voir les prochaines parties</a>
        </div>
    </article>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
