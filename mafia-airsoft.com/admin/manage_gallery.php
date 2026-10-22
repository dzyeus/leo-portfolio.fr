<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$message = '';
$error = '';

// Gérer l'upload d'une nouvelle photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'upload' && isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $display_order = max(0, (int)($_POST['display_order'] ?? 0));
        
        // Validation : le contenu du fichier doit être une vraie image (JPG, PNG, WEBP, max 5 Mo)
        $extension = uploaded_image_extension($file, 5 * 1024 * 1024);
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Erreur lors de l\'upload du fichier.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Le fichier est trop volumineux. Maximum 5MB.';
        } elseif ($extension === null || $extension === 'gif') {
            $error = 'Type de fichier non autorisé. Utilisez JPG, PNG ou WEBP.';
        } else {
            // Générer un nom unique
            $filename = random_upload_name('gallery', $extension);
            $upload_path = '../uploads/gallery/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                // Enregistrer dans la base de données
                $stmt = $pdo->prepare("INSERT INTO gallery (image_path, title, description, display_order) VALUES (?, ?, ?, ?)");
                if ($stmt->execute(['uploads/gallery/' . $filename, $title, $description, $display_order])) {
                    $message = 'Photo ajoutée avec succès !';
                } else {
                    $error = 'Erreur lors de l\'enregistrement en base de données.';
                    unlink($upload_path); // Supprimer le fichier si échec BDD
                }
            } else {
                $error = 'Erreur lors de l\'upload du fichier.';
            }
        }
    }
    
    // Activer/désactiver une photo
    elseif ($action === 'toggle' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE gallery SET is_active = NOT is_active WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Statut mis à jour !';
        }
    }
    
    // Supprimer une photo
    elseif ($action === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        
        // Récupérer le chemin du fichier
        $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE id = ?");
        $stmt->execute([$id]);
        $photo = $stmt->fetch();
        
        if ($photo) {
            // Supprimer le fichier (uniquement dans uploads/gallery/)
            delete_upload($photo['image_path'], 'uploads/gallery/');
            
            // Supprimer de la BDD
            $stmt = $pdo->prepare("DELETE FROM gallery WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Photo supprimée avec succès !';
            }
        }
    }
    
    // Mettre à jour l'ordre
    elseif ($action === 'update_order' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $display_order = max(0, (int)($_POST['display_order'] ?? 0));
        
        $stmt = $pdo->prepare("UPDATE gallery SET display_order = ? WHERE id = ?");
        if ($stmt->execute([$display_order, $id])) {
            $message = 'Ordre mis à jour !';
        }
    }
}

// Récupérer toutes les photos
$stmt = $pdo->query("SELECT * FROM gallery ORDER BY display_order ASC, created_at DESC");
$photos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer la Galerie - Admin</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-panel">
            <div class="page-header">
                <h1><?= icon('image') ?> Gérer la Galerie Photo</h1>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-secondary">← Retour au Dashboard</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Formulaire d'upload -->
            <section class="admin-section">
                <h2><?= icon('upload') ?> Ajouter une nouvelle photo</h2>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="action" value="upload">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="image">Image * (JPG, PNG, WEBP - Max 5MB)</label>
                            <input type="file" id="image" name="image" accept="image/jpeg,image/jpg,image/png,image/webp" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="title">Titre (optionnel)</label>
                            <input type="text" id="title" name="title" placeholder="Ex: Partie du 15 octobre">
                        </div>
                        
                        <div class="form-group">
                            <label for="display_order">Ordre d'affichage</label>
                            <input type="number" id="display_order" name="display_order" value="0" min="0">
                            <small>Les photos sont affichées par ordre croissant (0, 1, 2...)</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description (optionnel)</label>
                        <textarea id="description" name="description" rows="3" placeholder="Description de la photo..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <?= icon('upload') ?> Ajouter la photo
                    </button>
                </form>
            </section>

            <!-- Liste des photos -->
            <section class="admin-section">
                <h2><?= icon('list') ?> Photos de la galerie (<?= count($photos) ?>)</h2>
                
                <?php if (empty($photos)): ?>
                    <p class="no-data">Aucune photo dans la galerie. Ajoutez-en une ci-dessus !</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($photos as $photo): ?>
                            <div class="gallery-admin-item <?= $photo['is_active'] ? '' : 'inactive' ?>">
                                <div class="gallery-admin-image">
                                    <img src="../<?= htmlspecialchars($photo['image_path']) ?>" alt="<?= htmlspecialchars($photo['title']) ?>">
                                    <?php if (!$photo['is_active']): ?>
                                        <div class="inactive-overlay">DÉSACTIVÉE</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="gallery-admin-info">
                                    <h3><?= htmlspecialchars($photo['title']) ?: 'Sans titre' ?></h3>
                                    <?php if ($photo['description']): ?>
                                        <p><?= htmlspecialchars($photo['description']) ?></p>
                                    <?php endif; ?>
                                    <div class="gallery-admin-meta">
                                        <span class="meta-badge">Ordre: <?= $photo['display_order'] ?></span>
                                        <span class="meta-badge"><?= date('d/m/Y', strtotime($photo['created_at'])) ?></span>
                                    </div>
                                </div>
                                
                                <div class="gallery-admin-actions">
                                    <!-- Toggle actif/inactif -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $photo['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $photo['is_active'] ? 'btn-secondary' : 'btn-primary' ?>">
                                            <?= $photo['is_active'] ? '👁️ Masquer' : '👁️ Afficher' ?>
                                        </button>
                                    </form>
                                    
                                    <!-- Modifier l'ordre -->
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="id" value="<?= $photo['id'] ?>">
                                        <input type="number" name="display_order" value="<?= $photo['display_order'] ?>" 
                                               style="width: 60px; padding: 0.25rem;" min="0">
                                        <button type="submit" class="btn btn-sm btn-primary">↕️</button>
                                    </form>
                                    
                                    <!-- Supprimer -->
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Supprimer cette photo définitivement ?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $photo['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">🗑️ Supprimer</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
