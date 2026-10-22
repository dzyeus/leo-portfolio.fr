<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$message = '';
$error = '';
$edit_post = null;

// Récupérer l'article à éditer
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_post = $stmt->fetch();
    
    // Récupérer les images de galerie de l'article
    if ($edit_post) {
        $stmt_gallery = $pdo->prepare("SELECT * FROM blog_gallery WHERE blog_post_id = ? ORDER BY display_order ASC");
        $stmt_gallery->execute([$edit_post['id']]);
        $gallery_images = $stmt_gallery->fetchAll();
    }
}

// Gérer la soumission du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $featured_image = trim($_POST['featured_image'] ?? '');
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        
        // Gérer l'upload de l'image
        if (isset($_FILES['featured_image_file']) && $_FILES['featured_image_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/blog/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = uploaded_image_extension($_FILES['featured_image_file']);
            
            if ($file_extension !== null) {
                $new_filename = random_upload_name('blog', $file_extension);
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['featured_image_file']['tmp_name'], $upload_path)) {
                    // Supprimer l'ancienne image si c'est une mise à jour et qu'elle existe
                    if ($action === 'update' && !empty($edit_post['featured_image'])) {
                        delete_upload($edit_post['featured_image'], 'uploads/blog/');
                    }
                    $featured_image = 'uploads/blog/' . $new_filename;
                } else {
                    $error = 'Erreur lors de l\'upload de l\'image.';
                }
            } else {
                $error = 'Format d\'image non autorisé. Utilisez JPG, PNG, GIF ou WEBP.';
            }
        }
        
        // Image à la une : uniquement un fichier du blog ou une adresse https
        if ($featured_image !== '' && !preg_match('#^uploads/blog/[A-Za-z0-9_.-]+$#', $featured_image)
            && !preg_match('#^https://[^\s"\'<>]+$#', $featured_image)) {
            $featured_image = '';
            $error = 'Image à la une invalide : utilise un fichier envoyé ou une adresse https://';
        }

        // Générer le slug (accents convertis : "Opération" → "operation") et le rendre unique
        $slug_base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $slug_base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($slug_base ?: $title)), '-') ?: 'article';
        $slug = $slug_base;
        $current_id = ($action === 'update') ? (int)($_POST['post_id'] ?? 0) : 0;
        for ($n = 2; ; $n++) {
            $stmt_slug = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ? AND id <> ?");
            $stmt_slug->execute([$slug, $current_id]);
            if (!$stmt_slug->fetch()) {
                break;
            }
            $slug = $slug_base . '-' . $n;
        }
        
        if (empty($title) || empty($content)) {
            $error = 'Le titre et le contenu sont obligatoires.';
        } else {
            if ($action === 'create') {
                $published_at = $is_published ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare("
                    INSERT INTO blog_posts (title, slug, content, excerpt, featured_image, author_id, is_published, published_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([$title, $slug, $content, $excerpt, $featured_image, $_SESSION['user_id'], $is_published, $published_at])) {
                    $post_id = $pdo->lastInsertId();
                    
                    // Gérer les images de galerie
                    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
                        $upload_dir = '../uploads/blog/';
                        $display_order = 0;
                        
                        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_extension = uploaded_image_extension([
                                    'error' => $_FILES['gallery_images']['error'][$key],
                                    'tmp_name' => $tmp_name,
                                    'size' => $_FILES['gallery_images']['size'][$key],
                                ]);
                                
                                if ($file_extension !== null) {
                                    $new_filename = random_upload_name('gallery', $file_extension);
                                    $upload_path = $upload_dir . $new_filename;
                                    
                                    if (move_uploaded_file($tmp_name, $upload_path)) {
                                        $caption = $_POST['gallery_captions'][$key] ?? '';
                                        $stmt_gallery = $pdo->prepare("
                                            INSERT INTO blog_gallery (blog_post_id, image_path, caption, display_order)
                                            VALUES (?, ?, ?, ?)
                                        ");
                                        $stmt_gallery->execute([$post_id, 'uploads/blog/' . $new_filename, $caption, $display_order]);
                                        $display_order++;
                                    }
                                }
                            }
                        }
                    }
                    
                    $message = 'Article créé avec succès !';
                } else {
                    $error = 'Erreur lors de la création.';
                }
            } else {
                $post_id = (int)$_POST['post_id'];
                $published_at = $is_published && !$edit_post['is_published'] ? date('Y-m-d H:i:s') : $edit_post['published_at'];
                
                $stmt = $pdo->prepare("
                    UPDATE blog_posts 
                    SET title = ?, slug = ?, content = ?, excerpt = ?, featured_image = ?, is_published = ?, published_at = ?
                    WHERE id = ?
                ");
                if ($stmt->execute([$title, $slug, $content, $excerpt, $featured_image, $is_published, $published_at, $post_id])) {
                    // Gérer les images de galerie
                    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
                        $upload_dir = '../uploads/blog/';
                        
                        // Récupérer l'ordre d'affichage maximal actuel
                        $stmt_max = $pdo->prepare("SELECT COALESCE(MAX(display_order), -1) as max_order FROM blog_gallery WHERE blog_post_id = ?");
                        $stmt_max->execute([$post_id]);
                        $display_order = $stmt_max->fetch()['max_order'] + 1;
                        
                        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
                            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_extension = uploaded_image_extension([
                                    'error' => $_FILES['gallery_images']['error'][$key],
                                    'tmp_name' => $tmp_name,
                                    'size' => $_FILES['gallery_images']['size'][$key],
                                ]);
                                
                                if ($file_extension !== null) {
                                    $new_filename = random_upload_name('gallery', $file_extension);
                                    $upload_path = $upload_dir . $new_filename;
                                    
                                    if (move_uploaded_file($tmp_name, $upload_path)) {
                                        $caption = $_POST['gallery_captions'][$key] ?? '';
                                        $stmt_gallery = $pdo->prepare("
                                            INSERT INTO blog_gallery (blog_post_id, image_path, caption, display_order)
                                            VALUES (?, ?, ?, ?)
                                        ");
                                        $stmt_gallery->execute([$post_id, 'uploads/blog/' . $new_filename, $caption, $display_order]);
                                        $display_order++;
                                    }
                                }
                            }
                        }
                    }
                    
                    $message = 'Article mis à jour !';
                    $edit_post = null;
                } else {
                    $error = 'Erreur lors de la mise à jour.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $post_id = $_POST['post_id'];
        $stmt = $pdo->prepare("DELETE FROM blog_posts WHERE id = ?");
        if ($stmt->execute([$post_id])) {
            $message = 'Article supprimé !';
        }
    } elseif ($action === 'delete_gallery_image') {
        $image_id = $_POST['image_id'];
        
        // Récupérer le chemin de l'image avant suppression
        $stmt = $pdo->prepare("SELECT image_path FROM blog_gallery WHERE id = ?");
        $stmt->execute([$image_id]);
        $image = $stmt->fetch();
        
        if ($image) {
            // Supprimer l'image de la base de données
            $stmt = $pdo->prepare("DELETE FROM blog_gallery WHERE id = ?");
            if ($stmt->execute([$image_id])) {
                // Supprimer le fichier physique (uniquement dans uploads/blog/)
                delete_upload($image['image_path'], 'uploads/blog/');
                $message = 'Image supprimée de la galerie !';
            }
        }
    }
}

// Récupérer tous les articles
$stmt = $pdo->query("
    SELECT bp.*, u.pseudo as author_name
    FROM blog_posts bp
    JOIN users u ON bp.author_id = u.id
    ORDER BY bp.created_at DESC
");
$posts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion du Blog - Administration</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <h1><?= icon('edit') ?> Gestion du Blog</h1>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Formulaire de création/édition -->
        <section class="admin-section">
            <h2><?= $edit_post ? icon('edit') . ' Modifier l\'article' : icon('plus') . ' Nouvel article' ?></h2>
            <form method="POST" class="admin-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?= $edit_post ? 'update' : 'create' ?>">
                <?php if ($edit_post): ?>
                    <input type="hidden" name="post_id" value="<?= $edit_post['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="title">Titre *</label>
                    <input type="text" id="title" name="title" required 
                           value="<?= htmlspecialchars($edit_post['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="excerpt">Extrait (résumé court)</label>
                    <textarea id="excerpt" name="excerpt" rows="3"><?= htmlspecialchars($edit_post['excerpt'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="content">Contenu *</label>
                    <textarea id="content" name="content" rows="15" required><?= htmlspecialchars($edit_post['content'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="featured_image">URL de l'image (optionnel)</label>
                    <input type="url" id="featured_image" name="featured_image" 
                           value="<?= htmlspecialchars($edit_post['featured_image'] ?? '') ?>"
                           placeholder="https://exemple.com/image.jpg">
                    <small style="display: block; margin-top: 5px; color: #666;">Ou utilisez le bouton ci-dessous pour uploader une image</small>
                </div>

                <div class="form-group">
                    <label for="featured_image_file">Uploader une image</label>
                    <input type="file" id="featured_image_file" name="featured_image_file" 
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           onchange="previewBlogImage(event)">
                    <small style="display: block; margin-top: 5px; color: #666;">Formats acceptés : JPG, PNG, GIF, WEBP</small>
                    
                    <?php if (!empty($edit_post['featured_image'])): ?>
                        <div id="current-image" style="margin-top: 15px;">
                            <p><strong>Image actuelle :</strong></p>
                            <img src="../<?= htmlspecialchars($edit_post['featured_image']) ?>" 
                                 alt="Image actuelle" 
                                 style="max-width: 300px; max-height: 200px; border-radius: 5px; border: 2px solid #ddd;">
                        </div>
                    <?php endif; ?>
                    
                    <div id="image-preview" style="margin-top: 15px; display: none;">
                        <p><strong>Aperçu de la nouvelle image :</strong></p>
                        <img id="preview-img" src="" alt="Aperçu" 
                             style="max-width: 300px; max-height: 200px; border-radius: 5px; border: 2px solid #4CAF50;">
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_published" 
                               <?= ($edit_post['is_published'] ?? 0) ? 'checked' : '' ?>>
                        Publier l'article
                    </label>
                </div>

                <!-- Section Galerie d'images -->
                <div class="form-group">
                    <label for="gallery_images">📷 Galerie d'images (plusieurs photos possibles)</label>
                    <input type="file" id="gallery_images" name="gallery_images[]" 
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           multiple
                           onchange="previewGalleryImages(event)">
                    <small style="display: block; margin-top: 5px; color: #666;">
                        Vous pouvez sélectionner plusieurs images en même temps (Ctrl + clic). Formats acceptés : JPG, PNG, GIF, WEBP
                    </small>
                    
                    <div id="gallery-preview" style="margin-top: 15px; display: none;">
                        <p><strong>Aperçu des nouvelles images :</strong></p>
                        <div id="gallery-preview-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                        </div>
                    </div>
                    
                    <?php if (!empty($gallery_images)): ?>
                        <div style="margin-top: 20px;">
                            <p><strong>Images actuelles de la galerie :</strong></p>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                                <?php foreach ($gallery_images as $gimage): ?>
                                    <div class="gallery-item" style="position: relative; border: 2px solid #ddd; border-radius: 5px; padding: 10px;">
                                        <img src="../<?= htmlspecialchars($gimage['image_path']) ?>" 
                                             alt="Image galerie" 
                                             style="width: 100%; height: 150px; object-fit: cover; border-radius: 5px;">
                                        <?php if ($gimage['caption']): ?>
                                            <p style="margin: 5px 0; font-size: 0.9em; color: #666;"><?= htmlspecialchars($gimage['caption']) ?></p>
                                        <?php endif; ?>
                                        <form method="POST" style="margin-top: 10px;" onsubmit="return confirm('Supprimer cette image ?');">
                                            <input type="hidden" name="action" value="delete_gallery_image">
                                            <input type="hidden" name="image_id" value="<?= $gimage['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="width: 100%; padding: 5px; font-size: 0.85em;">
                                                🗑️ Supprimer
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $edit_post ? 'Mettre à jour' : 'Créer l\'article' ?>
                    </button>
                    <?php if ($edit_post): ?>
                        <a href="manage_blog.php" class="btn btn-secondary">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Liste des articles -->
        <section class="admin-section">
            <h2>📚 Tous les articles</h2>
            <?php if (empty($posts)): ?>
                <p class="no-data">Aucun article créé.</p>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Titre</th>
                                <th>Auteur</th>
                                <th>Date</th>
                                <th>Vues</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td><?= $post['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($post['title']) ?></strong></td>
                                    <td><?= htmlspecialchars($post['author_name']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($post['created_at'])) ?></td>
                                    <td><?= $post['views'] ?></td>
                                    <td>
                                        <?php if ($post['is_published']): ?>
                                            <span class="badge badge-success">Publié</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Brouillon</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="table-actions">
                                        <?php if ($post['is_published']): ?>
                                            <a href="../blog_post.php?slug=<?= urlencode($post['slug']) ?>" class="btn-action" target="_blank" title="Voir"><?= icon('eye') ?></a>
                                        <?php endif; ?>
                                        <a href="?edit=<?= $post['id'] ?>" class="btn-action" title="Modifier"><?= icon('edit') ?></a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cet article ?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
                                            <button type="submit" class="btn-action" title="Supprimer"><?= icon('trash') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <?php include '../includes/footer.php'; ?>
    
    <script>
        function previewBlogImage(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('image-preview');
                    const previewImg = document.getElementById('preview-img');
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                    
                    // Masquer l'image actuelle si elle existe
                    const currentImage = document.getElementById('current-image');
                    if (currentImage) {
                        currentImage.style.opacity = '0.5';
                    }
                };
                reader.readAsDataURL(file);
            }
        }
        
        function previewGalleryImages(event) {
            const files = event.target.files;
            const container = document.getElementById('gallery-preview-container');
            const preview = document.getElementById('gallery-preview');
            
            if (files.length > 0) {
                container.innerHTML = '';
                preview.style.display = 'block';
                
                Array.from(files).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.style.cssText = 'position: relative; border: 2px solid #4CAF50; border-radius: 5px; padding: 10px;';
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.style.cssText = 'width: 100%; height: 150px; object-fit: cover; border-radius: 5px;';
                        
                        const captionLabel = document.createElement('label');
                        captionLabel.textContent = 'Légende (optionnel) :';
                        captionLabel.style.cssText = 'display: block; margin-top: 10px; font-size: 0.85em; color: #666;';
                        
                        const captionInput = document.createElement('input');
                        captionInput.type = 'text';
                        captionInput.name = 'gallery_captions[]';
                        captionInput.placeholder = 'Description de l\'image...';
                        captionInput.style.cssText = 'width: 100%; padding: 5px; margin-top: 5px; border: 1px solid #ddd; border-radius: 3px; font-size: 0.85em;';
                        
                        div.appendChild(img);
                        div.appendChild(captionLabel);
                        div.appendChild(captionInput);
                        container.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                });
            } else {
                preview.style.display = 'none';
            }
        }
    </script>
</body>
</html>
