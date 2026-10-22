<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$event_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $scenario = trim($_POST['scenario'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $event_time = $_POST['event_time'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $equipment = trim($_POST['equipment_required'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $registration_open = isset($_POST['registration_open']) ? 1 : 0;
    // Prix par place en euros (0 = gratuit)
    $price_input = str_replace([',', '€', ' '], ['.', '', ''], trim($_POST['price'] ?? '0'));
    $price_cents = is_numeric($price_input) ? (int)round((float)$price_input * 100) : -1;

    if (empty($title) || empty($event_date) || empty($event_time) || empty($location)) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif ($price_cents < 0 || $price_cents > 100000) {
        $error = 'Prix invalide (ex : 10 ou 12,50 ; 0 pour une partie gratuite).';
    } else {
        $full_datetime = $event_date . ' ' . $event_time;
        
        $stmt = $pdo->prepare("
            UPDATE events SET 
                title = ?, description = ?, scenario = ?, event_date = ?, location = ?, price_cents = ?,
                equipment_required = ?, rules = ?, is_active = ?, registration_open = ?
            WHERE id = ?
        ");
        
        if ($stmt->execute([$title, $description, $scenario, $full_datetime, $location, $price_cents,
                           $equipment, $rules, $is_active, $registration_open, $event_id])) {
            $success = 'Partie mise à jour avec succès !';
            // Recharger les données
            $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            $event = $stmt->fetch();
        } else {
            $error = 'Erreur lors de la mise à jour.';
        }
    }
}

// Séparer la date et l'heure pour les champs du formulaire
$event_date_only = date('Y-m-d', strtotime($event['event_date']));
$event_time_only = date('H:i', strtotime($event['event_date']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier - <?= htmlspecialchars($event['title']) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-form-container">
            <h1><?= icon('edit') ?> Modifier la partie</h1>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" class="admin-form">
                <?= csrf_field() ?>
                <div class="form-section">
                    <h3>Informations générales</h3>
                    
                    <div class="form-group">
                        <label for="title">Titre de la partie *</label>
                        <input type="text" id="title" name="title" required 
                               value="<?= htmlspecialchars($event['title']) ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="event_date">Date *</label>
                            <input type="date" id="event_date" name="event_date" required 
                                   value="<?= $event_date_only ?>">
                        </div>
                        <div class="form-group">
                            <label for="event_time">Heure *</label>
                            <input type="time" id="event_time" name="event_time" required 
                                   value="<?= $event_time_only ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="location">Lieu *</label>
                        <input type="text" id="location" name="location" required 
                               value="<?= htmlspecialchars($event['location']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="price">Prix par place (€) *</label>
                        <input type="text" id="price" name="price" required inputmode="decimal"
                               value="<?= htmlspecialchars($_POST['price'] ?? number_format(($event['price_cents'] ?? 0) / 100, 2, ',', '')) ?>">
                        <small>0 = gratuit. Changer le prix ne modifie pas les places déjà payées.</small>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?= htmlspecialchars($event['description']) ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Scénario et règles</h3>
                    
                    <div class="form-group">
                        <label for="scenario">Scénario détaillé</label>
                        <textarea id="scenario" name="scenario" rows="6"><?= htmlspecialchars($event['scenario']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="equipment_required">Matériel recommandé</label>
                        <textarea id="equipment_required" name="equipment_required" rows="3"><?= htmlspecialchars($event['equipment_required']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="rules">Règles spéciales</label>
                        <textarea id="rules" name="rules" rows="3"><?= htmlspecialchars($event['rules']) ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Options</h3>
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" id="is_active" name="is_active" 
                               <?= $event['is_active'] ? 'checked' : '' ?>>
                        <label for="is_active">Partie active (visible sur le site)</label>
                    </div>
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" id="registration_open" name="registration_open" 
                               <?= $event['registration_open'] ? 'checked' : '' ?>>
                        <label for="registration_open">Inscriptions ouvertes</label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer les modifications</button>
                    <a href="manage_teams.php?event_id=<?= $event_id ?>" class="btn btn-outline btn-lg"><?= icon('users') ?> Gérer les équipes</a>
                    <a href="view_event.php?id=<?= $event_id ?>" class="btn btn-secondary btn-lg">Annuler</a>
                </div>
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
