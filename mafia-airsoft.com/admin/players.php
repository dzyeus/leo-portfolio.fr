<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$message = '';
$error = '';

// Gestion de l'ajout d'un nouveau joueur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();
    $action = $_POST['action'];
    
    if ($action === 'add_player') {
        $pseudo = trim($_POST['pseudo'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($pseudo) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $error = 'Pseudo, prénom, nom, email et mot de passe sont obligatoires.';
        } elseif ($problem = pseudo_problem($pseudo)) {
            $error = $problem;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalide.';
        } elseif ($problem = password_problem($password)) {
            $error = $problem;
        } else {
            // Vérifier si l'email ou pseudo existe déjà
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR pseudo = ?");
            $stmt->execute([$email, $pseudo]);
            
            if ($stmt->fetch()) {
                $error = 'Cet email ou pseudo est déjà utilisé.';
            } else {
                // Gestion de l'upload de la photo
                $profile_picture = null;
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $filename = $_FILES['profile_picture']['name'];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $allowed) && @getimagesize($_FILES['profile_picture']['tmp_name']) !== false) {
                        $new_filename = bin2hex(random_bytes(12)) . '.' . $ext;
                        $upload_path = '../uploads/profiles/' . $new_filename;
                        
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                            $profile_picture = 'uploads/profiles/' . $new_filename;
                        }
                    }
                }
                
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                // Compte créé par un admin : email considéré comme vérifié
                $stmt = $pdo->prepare("INSERT INTO users (pseudo, first_name, last_name, email, email_verified_at, password_hash, phone, profile_picture) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)");
                
                if ($stmt->execute([$pseudo, $first_name, $last_name, $email, $password_hash, $phone ?: null, $profile_picture])) {
                    $message = 'Joueur ajouté avec succès !';
                } else {
                    $error = 'Erreur lors de l\'ajout du joueur.';
                }
            }
        }
    } elseif ($action === 'update_picture') {
        $user_id = $_POST['user_id'];
        
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_picture']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed) && @getimagesize($_FILES['profile_picture']['tmp_name']) !== false) {
                // Supprimer l'ancienne photo
                $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $old_picture = $stmt->fetchColumn();
                if ($old_picture && strpos($old_picture, 'uploads/profiles/') === 0 && file_exists('../' . $old_picture)) {
                    unlink('../' . $old_picture);
                }
                
                $new_filename = bin2hex(random_bytes(12)) . '.' . $ext;
                $upload_path = '../uploads/profiles/' . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                    $profile_picture = 'uploads/profiles/' . $new_filename;
                    $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                    if ($stmt->execute([$profile_picture, $user_id])) {
                        $message = 'Photo de profil mise à jour !';
                    }
                }
            } else {
                $error = 'Format de fichier non autorisé. Utilisez JPG, PNG ou GIF.';
            }
        }
    } elseif ($action === 'verify_email') {
        // Validation manuelle (le joueur n'a pas reçu ou trouvé l'email de confirmation)
        $stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL");
        $stmt->execute([(int)$_POST['user_id']]);
        $message = $stmt->rowCount() ? 'Email validé : le joueur peut se connecter.' : 'Ce compte était déjà validé.';
    } elseif ($action === 'resend_verification') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND email_verified_at IS NULL");
        $stmt->execute([(int)$_POST['user_id']]);
        if ($target = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (send_verification_email($pdo, $target)) {
                $message = 'Email de confirmation renvoyé à ' . $target['email'] . '.';
            } else {
                $error = 'L\'email n\'a pas pu partir. Va dans Admin › Diagnostic pour voir pourquoi.';
            }
        }
    } elseif ($action === 'delete_player') {
        $user_id = $_POST['user_id'];
        
        // Supprimer la photo de profil si elle existe
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $picture = $stmt->fetchColumn();
        if ($picture && strpos($picture, 'uploads/profiles/') === 0 && file_exists('../' . $picture)) {
            unlink('../' . $picture);
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0");
        if ($stmt->execute([$user_id])) {
            $message = 'Joueur supprimé.';
        }
    }
}

// Récupérer tous les joueurs
$stmt = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM registrations WHERE user_id = u.id) as total_registrations,
           (SELECT MAX(registered_at) FROM registrations WHERE user_id = u.id) as last_registration
    FROM users u
    WHERE u.is_admin = 0
    ORDER BY u.created_at DESC
");
$players = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des joueurs - Administration</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-panel">
            <div class="page-header">
                <h1><?= icon('users') ?> Gestion des joueurs</h1>
                <a href="index.php" class="btn btn-secondary">← Retour au dashboard</a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Formulaire d'ajout de joueur -->
            <section class="dashboard-section">
                <h2><?= icon('plus') ?> Ajouter un nouveau joueur</h2>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_player">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="pseudo">Pseudo *</label>
                            <input type="text" id="pseudo" name="pseudo" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">Prénom *</label>
                            <input type="text" id="first_name" name="first_name" required maxlength="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Nom *</label>
                            <input type="text" id="last_name" name="last_name" required maxlength="100">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Mot de passe *</label>
                            <input type="password" id="password" name="password" required minlength="8">
                            <small>Minimum 8 caractères (le joueur pourra le changer, ou utiliser « mot de passe oublié »)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="profile_picture">Photo de profil</label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                        <small>Formats acceptés : JPG, PNG, GIF (max 2MB)</small>
                    </div>

                    <button type="submit" class="btn btn-primary">Ajouter le joueur</button>
                </form>
            </section>

            <section class="dashboard-section">
                <h2>Liste des joueurs inscrits (<?= count($players) ?>)</h2>
                
                <?php if (empty($players)): ?>
                    <p class="no-data">Aucun joueur inscrit.</p>
                <?php else: ?>
                    <div class="players-grid">
                        <?php foreach ($players as $player): ?>
                            <div class="player-card">
                                <div class="player-avatar">
                                    <?php if ($player['profile_picture']): ?>
                                        <img src="../<?= htmlspecialchars($player['profile_picture']) ?>" alt="<?= htmlspecialchars($player['pseudo']) ?>">
                                    <?php else: ?>
                                        <div class="avatar-placeholder"><?= icon('user') ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="player-info">
                                    <h3><?= htmlspecialchars($player['pseudo']) ?></h3>
                                    <?php if (!empty($player['first_name']) || !empty($player['last_name'])): ?>
                                        <p class="player-name"><?= htmlspecialchars(full_name($player['first_name'], $player['last_name'])) ?></p>
                                    <?php endif; ?>
                                    <?php if (empty($player['email_verified_at'])): ?>
                                        <p class="player-activity">⏳ Email non confirmé</p>
                                    <?php endif; ?>
                                    <p class="player-email">📧 <?= htmlspecialchars($player['email']) ?></p>
                                    <?php if ($player['phone']): ?>
                                        <p class="player-phone">📱 <?= htmlspecialchars($player['phone']) ?></p>
                                    <?php endif; ?>
                                    <p class="player-stats">
                                        <?= icon('target') ?> <?= $player['total_registrations'] ?> inscriptions
                                    </p>
                                    <?php if ($player['last_registration']): ?>
                                        <p class="player-activity">
                                            Dernière activité: <?= date('d/m/Y', strtotime($player['last_registration'])) ?>
                                        </p>
                                    <?php endif; ?>
                                    <p class="player-date">
                                        Membre depuis <?= date('d/m/Y', strtotime($player['created_at'])) ?>
                                    </p>
                                </div>
                                
                                <div class="player-actions">
                                    <?php if (empty($player['email_verified_at'])): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="verify_email">
                                            <input type="hidden" name="user_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">✅ Valider l'email</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="resend_verification">
                                            <input type="hidden" name="user_id" value="<?= (int)$player['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm">✉️ Renvoyer</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" enctype="multipart/form-data" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_picture">
                                        <input type="hidden" name="user_id" value="<?= $player['id'] ?>">
                                        <label class="btn btn-outline btn-sm" style="cursor: pointer;">
                                            📷 Photo
                                            <input type="file" name="profile_picture" accept="image/*" style="display:none;" onchange="this.form.submit()">
                                        </label>
                                    </form>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce joueur ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_player">
                                        <input type="hidden" name="user_id" value="<?= $player['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
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
