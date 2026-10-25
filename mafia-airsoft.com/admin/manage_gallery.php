<?php
/**
 * Panel admin – Galerie photo (carrousel de l'accueil)
 * Ajout de photos, légende modifiable, ordre, visibilité, suppression.
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 600);
    $display_order = max(0, min(999, (int)($_POST['display_order'] ?? 0)));

    if ($action === 'upload') {
        $file = $_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        // Le contenu du fichier doit être une vraie image (JPG, PNG ou WEBP, 5 Mo max)
        $extension = uploaded_image_extension($file, 5 * 1024 * 1024);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Choisis une photo à ajouter.');
        } elseif ($extension === null || $extension === 'gif') {
            flash_set('error', 'Photo refusée : JPG, PNG ou WEBP de 5 Mo maximum.');
        } else {
            $filename = random_upload_name('gallery', $extension);
            $upload_path = MAT_ROOT . '/uploads/gallery/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $pdo->prepare("INSERT INTO gallery (image_path, title, description, display_order) VALUES (?, ?, ?, ?)")
                    ->execute(['uploads/gallery/' . $filename, $title, $description, $display_order]);
                flash_set('success', 'Photo ajoutée à la galerie.');
            } else {
                flash_set('error', 'La photo n\'a pas pu être enregistrée sur le serveur.');
            }
        }
    } elseif ($action === 'update' && $id > 0) {
        $pdo->prepare("UPDATE gallery SET title = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?")
            ->execute([$title, $description, $display_order, !empty($_POST['is_active']) ? 1 : 0, $id]);
        flash_set('success', 'Photo enregistrée.');
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE id = ?");
        $stmt->execute([$id]);
        if ($photo = $stmt->fetch(PDO::FETCH_ASSOC)) {
            delete_upload($photo['image_path'], 'uploads/gallery/'); // uniquement dans uploads/gallery/
            $pdo->prepare("DELETE FROM gallery WHERE id = ?")->execute([$id]);
            flash_set('success', 'Photo supprimée.');
        }
    }
    redirect('manage_gallery.php');
}

$photos = $pdo->query("SELECT * FROM gallery ORDER BY display_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$visible = count(array_filter($photos, fn($p) => !empty($p['is_active'])));
$flashes = flash_get();
?>
<?php site_head('Galerie', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Galerie photo</h1>
                <div class="a-sub"><?= count($photos) ?> photo(s) · <?= $visible ?> visible(s) dans « En images » sur l'accueil</div>
            </div>
            <div class="a-actions">
                <a href="../index.php" class="a-btn" target="_blank" rel="noopener"><?= ui_icon('external', 18) ?> Voir l'accueil</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <details class="a-collapse" <?= $photos ? '' : 'open' ?>>
            <summary><?= ui_icon('plus', 18) ?> Ajouter une photo</summary>
            <div class="a-collapse-body">
                <form method="POST" enctype="multipart/form-data" class="s-form">
                    <input type="hidden" name="action" value="upload">
                    <div class="s-field">
                        <label for="image">Photo <span class="s-req">*</span></label>
                        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required>
                        <span class="s-hint">JPG, PNG ou WEBP, 5 Mo maximum. Une photo en largeur rend mieux.</span>
                    </div>
                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="title">Titre</label>
                            <input type="text" id="title" name="title" maxlength="150" placeholder="Ex. : Partie du 15 octobre">
                        </div>
                        <div class="s-field">
                            <label for="display_order">Ordre</label>
                            <input type="number" id="display_order" name="display_order" min="0" max="999" value="0">
                            <span class="s-hint">0 = en premier.</span>
                        </div>
                    </div>
                    <div class="s-field">
                        <label for="description">Légende</label>
                        <textarea id="description" name="description" rows="2" maxlength="600" placeholder="Une phrase pour raconter la photo"></textarea>
                    </div>
                    <button type="submit" class="a-btn a-btn-primary"><?= ui_icon('plus', 18) ?> Ajouter la photo</button>
                </form>
            </div>
        </details>

        <?php if (!$photos): ?>
            <section class="a-card"><p class="a-empty">Aucune photo pour l'instant.</p></section>
        <?php else: ?>
            <div class="a-photos">
                <?php foreach ($photos as $photo):
                    $pid = (int)$photo['id']; ?>
                    <article class="a-card a-photo<?= empty($photo['is_active']) ? ' is-hidden' : '' ?>">
                        <div class="a-photo-img">
                            <img src="../<?= e($photo['image_path']) ?>" alt="<?= e($photo['title'] ?: 'Photo de la galerie') ?>" loading="lazy">
                            <span class="a-badge <?= !empty($photo['is_active']) ? 'st-ok' : 'st-muted' ?>"><?= !empty($photo['is_active']) ? 'Visible' : 'Masquée' ?></span>
                        </div>
                        <form method="POST" class="s-form" id="photo<?= $pid ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $pid ?>">
                            <div class="s-field">
                                <label for="t<?= $pid ?>">Titre</label>
                                <input type="text" id="t<?= $pid ?>" name="title" maxlength="150" value="<?= e($photo['title']) ?>">
                            </div>
                            <div class="s-field">
                                <label for="d<?= $pid ?>">Légende</label>
                                <textarea id="d<?= $pid ?>" name="description" rows="2" maxlength="600"><?= e($photo['description']) ?></textarea>
                            </div>
                            <div class="a-photo-opts">
                                <div class="s-field">
                                    <label for="o<?= $pid ?>">Ordre</label>
                                    <input type="number" id="o<?= $pid ?>" name="display_order" min="0" max="999" value="<?= (int)$photo['display_order'] ?>">
                                </div>
                                <div class="s-check">
                                    <input type="checkbox" id="v<?= $pid ?>" name="is_active" value="1" <?= !empty($photo['is_active']) ? 'checked' : '' ?>>
                                    <label for="v<?= $pid ?>">Visible sur l'accueil</label>
                                </div>
                            </div>
                        </form>
                        <div class="a-photo-actions">
                            <button type="submit" form="photo<?= $pid ?>" class="a-btn a-btn-sm a-btn-ok"><?= ui_icon('check', 16) ?> Enregistrer</button>
                            <form method="POST" class="a-inline" data-confirm="Supprimer définitivement cette photo ?">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $pid ?>">
                                <button type="submit" class="a-btn a-btn-sm a-btn-danger"><?= ui_icon('trash', 16) ?> Supprimer</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
