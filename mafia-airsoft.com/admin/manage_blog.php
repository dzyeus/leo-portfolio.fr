<?php
/**
 * Panel admin – Blog
 * Liste des articles, éditeur (texte, image à la une, photos de l'article),
 * publication, suppression.
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

/** Article (ou null). */
function blog_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Slug unique à partir du titre (« Opération » → « operation »). */
function blog_slug(PDO $pdo, string $title, int $currentId): string
{
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($base ?: $title)), '-') ?: 'article';
    $base = substr($base, 0, 200);
    $slug = $base;
    $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ? AND id <> ?");
    for ($n = 2; ; $n++) {
        $stmt->execute([$slug, $currentId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $n;
    }
}

/** Enregistre les photos envoyées pour un article. Renvoie le nombre de photos ajoutées. */
function blog_save_photos(PDO $pdo, int $postId): int
{
    $files = $_FILES['gallery_images'] ?? null;
    if (!$files || !is_array($files['tmp_name'] ?? null)) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), -1) + 1 FROM blog_gallery WHERE blog_post_id = ?");
    $stmt->execute([$postId]);
    $order = (int)$stmt->fetchColumn();
    $insert = $pdo->prepare("INSERT INTO blog_gallery (blog_post_id, image_path, caption, display_order) VALUES (?, ?, '', ?)");
    $added = 0;
    foreach ($files['tmp_name'] as $key => $tmp) {
        $ext = uploaded_image_extension([
            'error'    => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
            'tmp_name' => $tmp,
            'size'     => $files['size'][$key] ?? 0,
        ]);
        if ($ext === null) {
            continue;
        }
        $name = random_upload_name('gallery', $ext);
        if (move_uploaded_file($tmp, MAT_ROOT . '/uploads/blog/' . $name)) {
            $insert->execute([$postId, 'uploads/blog/' . $name, $order++]);
            $added++;
        }
    }
    return $added;
}

// =====================================================================
// Actions
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postId = (int)($_POST['post_id'] ?? 0);

    if ($action === 'save') {
        $existing = $postId > 0 ? blog_find($pdo, $postId) : null;
        if ($postId > 0 && !$existing) {
            redirect('manage_blog.php');
        }
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        $content = trim((string)($_POST['content'] ?? ''));
        $excerpt = mb_substr(trim((string)($_POST['excerpt'] ?? '')), 0, 600);
        $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        $featured = $existing['featured_image'] ?? '';
        $imageUrl = trim((string)($_POST['featured_image'] ?? ''));

        $errors = [];
        if ($title === '' || $content === '') {
            $errors[] = 'Le titre et le texte de l\'article sont obligatoires.';
        }

        // Image à la une : fichier envoyé, adresse https://, ou suppression
        if (!empty($_FILES['featured_image_file']['name'])) {
            $ext = uploaded_image_extension($_FILES['featured_image_file'], 5 * 1024 * 1024);
            if ($ext === null) {
                $errors[] = 'Image à la une refusée : JPG, PNG, GIF ou WEBP de 5 Mo maximum.';
            } elseif (!$errors) {
                $name = random_upload_name('blog', $ext);
                if (move_uploaded_file($_FILES['featured_image_file']['tmp_name'], MAT_ROOT . '/uploads/blog/' . $name)) {
                    if ($featured !== '') {
                        delete_upload($featured, 'uploads/blog/');
                    }
                    $featured = 'uploads/blog/' . $name;
                }
            }
        } elseif (!empty($_POST['remove_featured'])) {
            if ($featured !== '') {
                delete_upload($featured, 'uploads/blog/');
            }
            $featured = '';
        } elseif ($imageUrl !== '' && $imageUrl !== $featured) {
            if (preg_match('#^https://[^\s"\'<>]+$#', $imageUrl) && strlen($imageUrl) <= 255) {
                $featured = $imageUrl;
            } else {
                $errors[] = 'Adresse de l\'image invalide : elle doit commencer par https://';
            }
        }

        if ($errors) {
            $_SESSION['blog_draft'] = ['post_id' => $postId, 'title' => $title, 'content' => $content, 'excerpt' => $excerpt, 'is_published' => $isPublished];
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
            redirect('manage_blog.php?' . ($postId ? 'edit=' . $postId : 'new=1'));
        }

        $slug = blog_slug($pdo, $title, $postId);
        if ($existing) {
            $publishedAt = $isPublished ? ($existing['published_at'] ?: date('Y-m-d H:i:s')) : $existing['published_at'];
            $pdo->prepare("
                UPDATE blog_posts SET title = ?, slug = ?, content = ?, excerpt = ?, featured_image = ?, is_published = ?, published_at = ?
                WHERE id = ?
            ")->execute([$title, $slug, $content, $excerpt, $featured, $isPublished, $publishedAt, $postId]);
        } else {
            $pdo->prepare("
                INSERT INTO blog_posts (title, slug, content, excerpt, featured_image, author_id, is_published, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$title, $slug, $content, $excerpt, $featured, current_user_id(), $isPublished, $isPublished ? date('Y-m-d H:i:s') : null]);
            $postId = (int)$pdo->lastInsertId();
        }
        $added = blog_save_photos($pdo, $postId);
        unset($_SESSION['blog_draft']);
        flash_set('success', ($existing ? 'Article enregistré' : 'Article créé') . ($isPublished ? ' et publié' : ' (brouillon)')
            . ($added ? ' · ' . $added . ' photo(s) ajoutée(s)' : '') . '.');
        redirect('manage_blog.php?edit=' . $postId);
    }

    if ($action === 'caption') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $pdo->prepare("UPDATE blog_gallery SET caption = ? WHERE id = ? AND blog_post_id = ?")
            ->execute([mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 300), $imageId, $postId]);
        flash_set('success', 'Légende enregistrée.');
        redirect('manage_blog.php?edit=' . $postId . '#photos');
    }

    if ($action === 'delete_gallery_image') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT image_path FROM blog_gallery WHERE id = ? AND blog_post_id = ?");
        $stmt->execute([$imageId, $postId]);
        if ($image = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("DELETE FROM blog_gallery WHERE id = ?")->execute([$imageId]);
            delete_upload($image['image_path'], 'uploads/blog/');
            flash_set('success', 'Photo retirée de l\'article.');
        }
        redirect('manage_blog.php?edit=' . $postId . '#photos');
    }

    if ($action === 'delete' && ($post = blog_find($pdo, $postId))) {
        $stmt = $pdo->prepare("SELECT image_path FROM blog_gallery WHERE blog_post_id = ?");
        $stmt->execute([$postId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            delete_upload($path, 'uploads/blog/');
        }
        if (!empty($post['featured_image'])) {
            delete_upload($post['featured_image'], 'uploads/blog/');
        }
        $pdo->prepare("DELETE FROM blog_posts WHERE id = ?")->execute([$postId]); // les photos suivent (clé étrangère)
        flash_set('success', 'Article « ' . $post['title'] . ' » supprimé.');
    }
    redirect('manage_blog.php');
}

// =====================================================================
// Affichage
// =====================================================================
$editId = (int)($_GET['edit'] ?? 0);
$isEditor = $editId > 0 || isset($_GET['new']);
$post = $editId > 0 ? blog_find($pdo, $editId) : null;
if ($editId > 0 && !$post) {
    redirect('manage_blog.php');
}
$photos = [];
if ($post) {
    $stmt = $pdo->prepare("SELECT * FROM blog_gallery WHERE blog_post_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->execute([$post['id']]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Saisie gardée après une erreur
$draft = $_SESSION['blog_draft'] ?? null;
unset($_SESSION['blog_draft']);
$form = $post ?? ['title' => '', 'content' => '', 'excerpt' => '', 'featured_image' => '', 'is_published' => 1];
if ($draft && (int)$draft['post_id'] === (int)($post['id'] ?? 0)) {
    $form = array_merge($form, $draft);
}

$posts = $pdo->query("
    SELECT bp.*, u.pseudo AS author_name,
           (SELECT COUNT(*) FROM blog_gallery g WHERE g.blog_post_id = bp.id) AS photos
    FROM blog_posts bp LEFT JOIN users u ON bp.author_id = u.id
    ORDER BY bp.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$flashes = flash_get();
$cover = (string)($form['featured_image'] ?? '');
$coverSrc = $cover === '' ? '' : (str_starts_with($cover, 'https://') ? $cover : '../' . $cover);
?>
<?php site_head($isEditor ? ($post ? 'Modifier l\'article' : 'Nouvel article') : 'Blog', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <?php if (!$isEditor): ?>
            <div class="a-page-head">
                <div>
                    <h1>Blog</h1>
                    <div class="a-sub"><?= count($posts) ?> article(s) · <?= count(array_filter($posts, fn($p) => !empty($p['is_published']))) ?> publié(s)</div>
                </div>
                <div class="a-actions">
                    <a href="manage_blog.php?new=1" class="a-btn a-btn-primary"><?= ui_icon('plus', 18) ?> Nouvel article</a>
                </div>
            </div>
        <?php else: ?>
            <div class="a-page-head">
                <div>
                    <a class="a-link" href="manage_blog.php">← Tous les articles</a>
                    <h1><?= $post ? 'Modifier l\'article' : 'Nouvel article' ?></h1>
                    <?php if ($post): ?><div class="a-sub"><?= e($post['title']) ?></div><?php endif; ?>
                </div>
                <?php if ($post && !empty($post['is_published'])): ?>
                    <div class="a-actions">
                        <a href="../blog_post.php?slug=<?= e(urlencode($post['slug'])) ?>" class="a-btn" target="_blank" rel="noopener"><?= ui_icon('external', 18) ?> Voir sur le site</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if (!$isEditor): ?>
            <section class="a-card">
                <?php if (!$posts): ?>
                    <p class="a-empty">Aucun article pour l'instant. <a class="a-link" href="manage_blog.php?new=1">Écrire le premier</a></p>
                <?php else: ?>
                    <table class="rtable">
                        <thead>
                            <tr><th>Article</th><th>Statut</th><th>Date</th><th>Lectures</th><th style="text-align:right;">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $p): ?>
                                <tr>
                                    <td class="t-head">
                                        <a class="t-title" href="manage_blog.php?edit=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a>
                                        <span class="t-sub">par <?= e($p['author_name'] ?? '?') ?><?= (int)$p['photos'] ? ' · ' . (int)$p['photos'] . ' photo(s)' : '' ?></span>
                                    </td>
                                    <td data-label="Statut"><?= !empty($p['is_published']) ? '<span class="a-badge st-ok">Publié</span>' : '<span class="a-badge st-muted">Brouillon</span>' ?></td>
                                    <td data-label="Date"><span><?= e(date('d/m/Y', strtotime($p['published_at'] ?: $p['created_at']))) ?></span></td>
                                    <td data-label="Lectures"><span class="t-num"><?= (int)$p['views'] ?></span></td>
                                    <td class="t-actions-cell">
                                        <div class="t-actions">
                                            <a href="manage_blog.php?edit=<?= (int)$p['id'] ?>" class="a-btn a-btn-sm"><?= ui_icon('edit', 16) ?> Modifier</a>
                                            <?php if (!empty($p['is_published'])): ?>
                                                <a href="../blog_post.php?slug=<?= e(urlencode($p['slug'])) ?>" class="a-btn a-btn-sm a-btn-icon" target="_blank" rel="noopener" title="Voir sur le site"><?= ui_icon('eye', 16) ?></a>
                                            <?php endif; ?>
                                            <form method="POST" class="a-inline" data-confirm="<?= e('Supprimer l\'article « ' . $p['title'] . ' » et ses photos ?') ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="a-btn a-btn-sm a-btn-icon a-btn-danger" title="Supprimer"><?= ui_icon('trash', 16) ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" class="s-form" data-warn-unsaved>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="post_id" value="<?= (int)($post['id'] ?? 0) ?>">
                <div class="a-grid a-grid-main">
                    <div class="a-stack">
                        <section class="a-card">
                            <div class="a-card-head"><h2><?= ui_icon('file', 18) ?> Article</h2></div>
                            <div class="s-field">
                                <label for="title">Titre <span class="s-req">*</span></label>
                                <input type="text" id="title" name="title" required maxlength="200" value="<?= e($form['title']) ?>">
                            </div>
                            <div class="s-field">
                                <label for="excerpt">Résumé</label>
                                <textarea id="excerpt" name="excerpt" rows="2" maxlength="600" placeholder="1 ou 2 phrases affichées dans la liste du blog"><?= e($form['excerpt']) ?></textarea>
                            </div>
                            <div class="s-field" style="margin-bottom:0;">
                                <label for="content">Texte <span class="s-req">*</span></label>
                                <textarea id="content" name="content" rows="16" required><?= e($form['content']) ?></textarea>
                                <span class="s-hint">Laisse une ligne vide entre deux paragraphes.</span>
                            </div>
                        </section>

                        <section class="a-card" id="photos">
                            <div class="a-card-head"><h2><?= ui_icon('image', 18) ?> Photos de l'article</h2>
                                <span class="a-badge st-muted"><?= count($photos) ?></span></div>
                            <div class="s-field">
                                <label for="gallery_images">Ajouter des photos</label>
                                <input type="file" id="gallery_images" name="gallery_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                <span class="s-hint">Tu peux en choisir plusieurs d'un coup. Elles sont ajoutées en enregistrant l'article.</span>
                            </div>
                            <?php if ($photos): ?>
                                <div class="a-thumbs">
                                    <?php foreach ($photos as $ph): ?>
                                        <div class="a-thumb">
                                            <img src="../<?= e($ph['image_path']) ?>" alt="<?= e($ph['caption'] ?: 'Photo de l\'article') ?>" loading="lazy">
                                            <div class="a-thumb-body">
                                                <input type="text" form="cap<?= (int)$ph['id'] ?>" name="caption" maxlength="300" value="<?= e($ph['caption']) ?>" placeholder="Légende" aria-label="Légende de la photo">
                                                <div class="a-thumb-actions">
                                                    <button type="submit" form="cap<?= (int)$ph['id'] ?>" class="a-btn a-btn-sm" title="Enregistrer la légende"><?= ui_icon('check', 16) ?></button>
                                                    <button type="submit" form="del<?= (int)$ph['id'] ?>" class="a-btn a-btn-sm a-btn-danger" title="Retirer la photo"><?= ui_icon('trash', 16) ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="a-stack">
                        <section class="a-card">
                            <div class="a-card-head"><h2><?= ui_icon('settings', 18) ?> Publication</h2></div>
                            <div class="s-check">
                                <input type="checkbox" id="is_published" name="is_published" value="1" <?= !empty($form['is_published']) ? 'checked' : '' ?>>
                                <label for="is_published">Publié sur le site</label>
                            </div>
                            <p class="s-hint" style="margin-bottom:1rem;">Décoché : l'article reste un brouillon visible seulement ici.</p>
                            <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block"><?= ui_icon('check', 18) ?> Enregistrer</button>
                            <a href="manage_blog.php" class="a-btn a-btn-block" style="margin-top:0.5rem;">Annuler</a>
                        </section>

                        <section class="a-card">
                            <div class="a-card-head"><h2><?= ui_icon('image', 18) ?> Image à la une</h2></div>
                            <?php if ($coverSrc !== ''): ?>
                                <img class="a-cover" src="<?= e($coverSrc) ?>" alt="Image à la une actuelle">
                                <div class="s-check">
                                    <input type="checkbox" id="remove_featured" name="remove_featured" value="1">
                                    <label for="remove_featured">Retirer cette image</label>
                                </div>
                            <?php endif; ?>
                            <div class="s-field">
                                <label for="featured_image_file"><?= $coverSrc !== '' ? 'Remplacer par' : 'Choisir une image' ?></label>
                                <input type="file" id="featured_image_file" name="featured_image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                            </div>
                            <div class="s-field" style="margin-bottom:0;">
                                <label for="featured_image">Ou adresse d'une image</label>
                                <input type="url" id="featured_image" name="featured_image" maxlength="255" placeholder="https://…" value="<?= e(str_starts_with($cover, 'https://') ? $cover : '') ?>">
                            </div>
                        </section>
                    </aside>
                </div>
            </form>

            <?php foreach ($photos as $ph): ?>
                <form method="POST" id="cap<?= (int)$ph['id'] ?>" hidden>
                    <input type="hidden" name="action" value="caption">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="image_id" value="<?= (int)$ph['id'] ?>">
                </form>
                <form method="POST" id="del<?= (int)$ph['id'] ?>" hidden data-confirm="Retirer cette photo de l'article ?">
                    <input type="hidden" name="action" value="delete_gallery_image">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="image_id" value="<?= (int)$ph['id'] ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
