<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$event_id = (int)($_GET['event_id'] ?? 0);

// Récupérer l'événement
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Gérer les actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_team') {
        $team_key = strtoupper(trim($_POST['team_key'] ?? ''));
        $team_name = trim($_POST['team_name'] ?? '');
        $team_color = safe_color(trim($_POST['team_color'] ?? ''), '#dc2626');
        $team_name = mb_substr($team_name, 0, 100);
        $max_players = intval($_POST['max_players'] ?? 0);
        
        // Valider la clé (alphanumérique uniquement, pas d'espaces)
        if (!preg_match('/^[A-Z0-9_]{1,50}$/', $team_key)) {
            $error = 'La clé doit contenir uniquement des lettres majuscules, chiffres et underscores.';
        } elseif (empty($team_name) || $max_players < 0) {
            $error = 'Veuillez remplir tous les champs correctement.';
        } else {
            // Vérifier si la clé existe déjà
            $stmt = $pdo->prepare("SELECT id FROM event_teams WHERE event_id = ? AND team_key = ?");
            $stmt->execute([$event_id, $team_key]);
            
            if ($stmt->fetch()) {
                $error = 'Cette clé d\'équipe existe déjà pour cet événement.';
            } else {
                // Obtenir le prochain display_order
                $stmt = $pdo->prepare("SELECT MAX(display_order) FROM event_teams WHERE event_id = ?");
                $stmt->execute([$event_id]);
                $max_order = $stmt->fetchColumn() ?? 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO event_teams (event_id, team_key, team_name, team_color, max_players, display_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([$event_id, $team_key, $team_name, $team_color, $max_players, $max_order + 1])) {
                    $message = 'Équipe ajoutée avec succès !';
                } else {
                    $error = 'Erreur lors de l\'ajout de l\'équipe.';
                }
            }
        }
    } elseif ($action === 'update_team') {
        $team_id = intval($_POST['team_id'] ?? 0);
        $team_name = trim($_POST['team_name'] ?? '');
        $team_color = safe_color(trim($_POST['team_color'] ?? ''), '#dc2626');
        $team_name = mb_substr($team_name, 0, 100);
        $max_players = intval($_POST['max_players'] ?? 0);
        
        if (empty($team_name) || $max_players < 0) {
            $error = 'Veuillez remplir tous les champs correctement.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE event_teams 
                SET team_name = ?, team_color = ?, max_players = ?
                WHERE id = ? AND event_id = ?
            ");
            if ($stmt->execute([$team_name, $team_color, $max_players, $team_id, $event_id])) {
                $message = 'Équipe mise à jour avec succès !';
            } else {
                $error = 'Erreur lors de la mise à jour.';
            }
        }
    } elseif ($action === 'delete_team') {
        $team_id = intval($_POST['team_id'] ?? 0);
        
        // Vérifier s'il y a des inscriptions pour cette équipe
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM registrations r
            JOIN event_teams et ON r.team = et.team_key
            WHERE et.id = ? AND r.event_id = ?
        ");
        $stmt->execute([$team_id, $event_id]);
        $registration_count = $stmt->fetchColumn();
        
        if ($registration_count > 0) {
            $error = "Impossible de supprimer cette équipe car $registration_count joueur(s) y sont inscrits.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM event_teams WHERE id = ? AND event_id = ?");
            if ($stmt->execute([$team_id, $event_id])) {
                $message = 'Équipe supprimée avec succès !';
            } else {
                $error = 'Erreur lors de la suppression.';
            }
        }
    } elseif ($action === 'update_order') {
        $orders = $_POST['order'] ?? [];
        foreach ($orders as $team_id => $order) {
            $stmt = $pdo->prepare("UPDATE event_teams SET display_order = ? WHERE id = ? AND event_id = ?");
            $stmt->execute([intval($order), intval($team_id), $event_id]);
        }
        $message = 'Ordre des équipes mis à jour !';
    }
}

// Récupérer toutes les équipes de cet événement
$stmt = $pdo->prepare("
    SELECT et.*,
           (SELECT COUNT(*) FROM registrations WHERE event_id = et.event_id AND team = et.team_key) as current_players
    FROM event_teams et
    WHERE et.event_id = ?
    ORDER BY et.display_order ASC
");
$stmt->execute([$event_id]);
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer les équipes - <?= htmlspecialchars($event['title']) ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: inline-block;
            vertical-align: middle;
        }
        
        .team-card {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            padding: var(--spacing-md);
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.2);
            border-radius: var(--radius-md);
            margin-bottom: var(--spacing-md);
        }
        
        .team-card-info {
            flex: 1;
        }
        
        .team-card-actions {
            display: flex;
            gap: var(--spacing-xs);
        }
        
        .form-inline {
            display: flex;
            gap: var(--spacing-sm);
            align-items: center;
            flex-wrap: wrap;
        }
        
        .form-inline input[type="text"],
        .form-inline input[type="number"],
        .form-inline input[type="color"] {
            flex: 0 0 auto;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-panel">
            <div class="page-header">
                <h1><?= icon('users') ?> Gérer les équipes - <?= htmlspecialchars($event['title']) ?></h1>
                <div class="header-actions">
                    <a href="edit_event.php?id=<?= $event_id ?>" class="btn btn-secondary">← Retour à l'événement</a>
                </div>
            </div>

            <?php foreach (flash_get() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Ajouter une nouvelle équipe -->
            <section class="dashboard-section">
                <h2><?= icon('plus') ?> Ajouter une équipe</h2>
                <form method="POST" class="admin-form">
                    <input type="hidden" name="action" value="add_team">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="team_key">Clé de l'équipe * (ex: BLUE, RED, GREEN)</label>
                            <input type="text" id="team_key" name="team_key" required 
                                   pattern="[A-Z0-9_]+" 
                                   placeholder="BLUE"
                                   style="text-transform: uppercase;">
                            <small>Lettres majuscules, chiffres et underscore uniquement</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="team_name">Nom de l'équipe *</label>
                            <input type="text" id="team_name" name="team_name" required 
                                   placeholder="Équipe Bleue">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="team_color">Couleur *</label>
                            <input type="color" id="team_color" name="team_color" value="#dc2626" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_players">Nombre maximum de joueurs *</label>
                            <input type="number" id="max_players" name="max_players" min="0" value="15" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Ajouter l'équipe</button>
                </form>
            </section>

            <!-- Liste des équipes existantes -->
            <section class="dashboard-section">
                <h2><?= icon('list') ?> Équipes configurées (<?= count($teams) ?>)</h2>
                
                <?php if (empty($teams)): ?>
                    <p class="no-data">Aucune équipe configurée pour cet événement.</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_order">
                        
                        <?php foreach ($teams as $index => $team): ?>
                            <div class="team-card">
                                <div class="color-preview" style="background-color: <?= e(safe_color($team['team_color'])) ?>"></div>
                                
                                <div class="team-card-info">
                                    <h3 style="margin: 0; color: #ffffff;">
                                        <?= htmlspecialchars($team['team_name']) ?>
                                        <small style="color: #a3a3a3; font-family: 'Orbitron', monospace;">[<?= htmlspecialchars($team['team_key']) ?>]</small>
                                    </h3>
                                    <p style="margin: 4px 0 0 0; color: #e5e5e5; font-size: 0.9rem;">
                                        <?= $team['current_players'] ?> / <?= $team['max_players'] ?> joueurs inscrits
                                    </p>
                                </div>
                                
                                <div class="team-card-actions">
                                    <input type="number" name="order[<?= $team['id'] ?>]" value="<?= $team['display_order'] ?>" 
                                           min="0" style="width: 70px;" title="Ordre d'affichage">
                                    
                                    <button type="button" class="btn btn-outline btn-sm" 
                                            onclick="showEditForm(<?= (int)$team['id'] ?>, <?= e(json_encode($team['team_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>, <?= e(json_encode(safe_color($team['team_color']))) ?>, <?= (int)$team['max_players'] ?>)">
                                        <?= icon('edit') ?> Modifier
                                    </button>
                                    
                                    <button type="button" class="btn btn-danger btn-sm"
                                            onclick="deleteTeam(<?= (int)$team['id'] ?>, <?= e(json_encode($team['team_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
                                        <?= icon('trash') ?> Supprimer
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="btn btn-secondary">💾 Enregistrer l'ordre d'affichage</button>
                    </form>
                <?php endif; ?>
            </section>

            <!-- Formulaire de modification (caché par défaut) -->
            <div id="editFormContainer" style="display: none;">
                <section class="dashboard-section">
                    <h2><?= icon('edit') ?> Modifier l'équipe</h2>
                    <form method="POST" class="admin-form">
                        <input type="hidden" name="action" value="update_team">
                        <input type="hidden" name="team_id" id="edit_team_id">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_team_name">Nom de l'équipe *</label>
                                <input type="text" id="edit_team_name" name="team_name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_team_color">Couleur *</label>
                                <input type="color" id="edit_team_color" name="team_color" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_max_players">Nombre maximum *</label>
                                <input type="number" id="edit_max_players" name="max_players" min="0" required>
                            </div>
                        </div>

                        <div style="display: flex; gap: var(--spacing-sm);">
                            <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
                            <button type="button" class="btn btn-secondary" onclick="hideEditForm()">Annuler</button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Formulaire de suppression caché -->
            <form id="deleteForm" method="POST" style="display: none;">
                <input type="hidden" name="action" value="delete_team">
                <input type="hidden" name="team_id" id="delete_team_id">
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script>
        function showEditForm(teamId, teamName, teamColor, maxPlayers) {
            document.getElementById('edit_team_id').value = teamId;
            document.getElementById('edit_team_name').value = teamName;
            document.getElementById('edit_team_color').value = teamColor;
            document.getElementById('edit_max_players').value = maxPlayers;
            document.getElementById('editFormContainer').style.display = 'block';
            document.getElementById('editFormContainer').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideEditForm() {
            document.getElementById('editFormContainer').style.display = 'none';
        }
        
        function deleteTeam(teamId, teamName) {
            if (confirm('Êtes-vous sûr de vouloir supprimer l\'équipe "' + teamName + '" ?\n\nCette action est irréversible si aucun joueur n\'y est inscrit.')) {
                document.getElementById('delete_team_id').value = teamId;
                document.getElementById('deleteForm').submit();
            }
        }
    </script>
</body>
</html>
