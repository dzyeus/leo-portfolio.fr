<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$message = '';
$error = '';

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
    $registration_open = isset($_POST['registration_open']) ? 1 : 0;
    // Prix par place en euros (0 = gratuit)
    $price_input = str_replace([',', '€', ' '], ['.', '', ''], trim($_POST['price'] ?? '0'));
    $price_cents = is_numeric($price_input) ? (int)round((float)$price_input * 100) : -1;

    // Validation
    if (empty($title) || empty($event_date) || empty($event_time) || empty($location)) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif ($price_cents < 0 || $price_cents > 100000) {
        $error = 'Prix invalide (ex : 10 ou 12,50 ; 0 pour une partie gratuite).';
    } else {
        $full_datetime = $event_date . ' ' . $event_time;
        
        $stmt = $pdo->prepare("
            INSERT INTO events (title, description, scenario, event_date, location, price_cents,
                               equipment_required, rules, registration_open)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$title, $description, $scenario, $full_datetime, $location, $price_cents,
                           $equipment, $rules, $registration_open])) {
            $new_event_id = $pdo->lastInsertId();
            
            // Créer les équipes par défaut
            $default_teams = [
                ['BLUE', 'Équipe Bleue', '#3b82f6', 15, 1],
                ['RED', 'Équipe Rouge', '#dc2626', 15, 2],
                ['ORGA', 'Organisation', '#a3a3a3', 3, 3]
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO event_teams (event_id, team_key, team_name, team_color, max_players, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($default_teams as $team) {
                $stmt->execute(array_merge([$new_event_id], $team));
            }

            // Alerte email aux joueurs abonnés (si demandé)
            if (!empty($_POST['announce'])) {
                $sent = announce_event($pdo, (int)$new_event_id);
                flash_set('success', 'Partie créée. Annonce envoyée à ' . $sent . ' joueur(s) abonné(s).');
            } else {
                $pdo->prepare("UPDATE events SET announced_at = NULL WHERE id = ?")->execute([$new_event_id]);
            }
            
            header('Location: manage_teams.php?event_id=' . $new_event_id . '&created=1');
            exit;
        } else {
            $error = 'Erreur lors de la création de la partie.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créer une partie - Administration</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-form-container">
            <a class="a-link" href="events.php">← Parties</a>
            <h1><?= icon('plus') ?> Créer une nouvelle partie</h1>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="create_event.php" class="admin-form">
                <?= csrf_field() ?>
                <div class="form-section">
                    <h3>Informations générales</h3>
                    
                    <div class="form-group">
                        <label for="title">Titre de la partie *</label>
                        <input type="text" id="title" name="title" required 
                               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" 
                               placeholder="Ex: Opération Aiguille Noire">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="event_date">Date *</label>
                            <input type="date" id="event_date" name="event_date" required 
                                   value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="event_time">Heure *</label>
                            <input type="time" id="event_time" name="event_time" required 
                                   value="<?= htmlspecialchars($_POST['event_time'] ?? '09:00') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="location">Lieu *</label>
                        <input type="text" id="location" name="location" required 
                               value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" 
                               placeholder="Ex: Forêt de Fontainebleau">
                    </div>

                    <div class="form-group">
                        <label for="price">Prix par place (€) *</label>
                        <input type="text" id="price" name="price" required inputmode="decimal"
                               value="<?= htmlspecialchars($_POST['price'] ?? number_format(DEFAULT_EVENT_PRICE_CENTS / 100, 2, ',', '')) ?>">
                        <small>Mettre 0 pour une partie gratuite (inscription sans paiement).</small>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3" 
                                  placeholder="Description courte de la partie"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Scénario et règles</h3>
                    
                    <div class="form-group">
                        <label for="scenario">Scénario détaillé</label>
                        <textarea id="scenario" name="scenario" rows="6" 
                                  placeholder="Décrivez le scénario, les objectifs de chaque camp, le contexte..."><?= htmlspecialchars($_POST['scenario'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="equipment_required">Matériel recommandé</label>
                        <textarea id="equipment_required" name="equipment_required" rows="3" 
                                  placeholder="Ex: Tenue camouflage, genouillères, chargeurs supplémentaires..."><?= htmlspecialchars($_POST['equipment_required'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="rules">Règles spéciales</label>
                        <textarea id="rules" name="rules" rows="3" 
                                  placeholder="Règles particulières pour cette partie..."><?= htmlspecialchars($_POST['rules'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Options</h3>
                    
                    <div class="form-group-checkbox">
                        <input type="checkbox" id="registration_open" name="registration_open" 
                               <?= (isset($_POST['registration_open']) || !isset($_POST['title'])) ? 'checked' : '' ?>>
                        <label for="registration_open">Inscriptions ouvertes</label>
                    </div>

                    <div class="form-group-checkbox">
                        <input type="checkbox" id="announce" name="announce" value="1"
                               <?= (isset($_POST['announce']) || !isset($_POST['title'])) ? 'checked' : '' ?>>
                        <label for="announce">Prévenir par email les joueurs abonnés aux alertes (<?= subscribers_count($pdo) ?> abonné(s))</label>
                    </div>
                    <small style="color:#a3a3a3;">Astuce : décoche si tu veux d'abord configurer les équipes, puis envoie l'annonce depuis « Modifier la partie ».</small>
                    
                    <div class="alert" style="background: rgba(220, 38, 38, 0.1); border-left: 3px solid var(--primary); padding: var(--spacing-md); margin-top: var(--spacing-md);">
                        <strong>ℹ️ Note :</strong> Après la création, vous pourrez configurer les équipes (noms, couleurs, limites) pour cette partie.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">Créer la partie</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">Annuler</a>
                </div>
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
