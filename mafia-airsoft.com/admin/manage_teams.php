<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
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

// Gérer les actions (après chaque action : message puis retour sur la page)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back = 'manage_teams.php?event_id=' . $event_id;

    if ($action === 'add_team') {
        $team_name = mb_substr(trim((string)($_POST['team_name'] ?? '')), 0, 100);
        $team_color = safe_color(trim((string)($_POST['team_color'] ?? '')), '#dc2626');
        $max_players = (int)($_POST['max_players'] ?? 0);
        $team_key = strtoupper(trim((string)($_POST['team_key'] ?? '')));
        if ($team_key === '') {
            // Clé créée à partir du nom : « Équipe Verte » → EQUIPE_VERTE
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $team_name) ?: $team_name;
            $team_key = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($ascii)), '_') ?: 'EQUIPE';
            $team_key = substr($team_key, 0, 40);
            $base = $team_key;
            $n = 2;
            $exists = $pdo->prepare("SELECT 1 FROM event_teams WHERE event_id = ? AND team_key = ?");
            while (true) {
                $exists->execute([$event_id, $team_key]);
                if (!$exists->fetchColumn()) {
                    break;
                }
                $team_key = $base . '_' . $n++;
            }
        }

        if (!preg_match('/^[A-Z0-9_]{1,50}$/', $team_key)) {
            flash_set('error', 'La clé doit contenir uniquement des lettres majuscules, des chiffres et _.');
        } elseif ($team_name === '' || $max_players < 0 || $max_players > 500) {
            flash_set('error', 'Indique un nom et un nombre de places valide.');
        } else {
            $stmt = $pdo->prepare("SELECT id FROM event_teams WHERE event_id = ? AND team_key = ?");
            $stmt->execute([$event_id, $team_key]);
            if ($stmt->fetch()) {
                flash_set('error', 'Cette clé d\'équipe existe déjà pour cette partie.');
            } else {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) FROM event_teams WHERE event_id = ?");
                $stmt->execute([$event_id]);
                $next = (int)$stmt->fetchColumn() + 1;
                $pdo->prepare("
                    INSERT INTO event_teams (event_id, team_key, team_name, team_color, max_players, display_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$event_id, $team_key, $team_name, $team_color, $max_players, $next]);
                flash_set('success', 'Équipe « ' . $team_name . ' » ajoutée.');
            }
        }
    } elseif ($action === 'update_team') {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $team_name = mb_substr(trim((string)($_POST['team_name'] ?? '')), 0, 100);
        $team_color = safe_color(trim((string)($_POST['team_color'] ?? '')), '#dc2626');
        $max_players = (int)($_POST['max_players'] ?? 0);
        $order = (int)($_POST['display_order'] ?? 0);

        if ($team_name === '' || $max_players < 0 || $max_players > 500) {
            flash_set('error', 'Indique un nom et un nombre de places valide.');
        } else {
            $pdo->prepare("
                UPDATE event_teams SET team_name = ?, team_color = ?, max_players = ?, display_order = ?
                WHERE id = ? AND event_id = ?
            ")->execute([$team_name, $team_color, $max_players, $order, $team_id, $event_id]);
            flash_set('success', 'Équipe « ' . $team_name . ' » enregistrée.');
        }
    } elseif ($action === 'delete_team') {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM registrations r
            JOIN event_teams et ON r.team = et.team_key AND et.event_id = r.event_id
            WHERE et.id = ? AND r.event_id = ?
        ");
        $stmt->execute([$team_id, $event_id]);
        $registration_count = (int)$stmt->fetchColumn();

        if ($registration_count > 0) {
            flash_set('error', 'Impossible de supprimer cette équipe : ' . $registration_count . ' joueur(s) y sont inscrits.');
        } else {
            $pdo->prepare("DELETE FROM event_teams WHERE id = ? AND event_id = ?")->execute([$team_id, $event_id]);
            flash_set('success', 'Équipe supprimée.');
        }
    }
    redirect($back);
}

// Toutes les équipes de la partie
$stmt = $pdo->prepare("
    SELECT et.*,
           (SELECT COUNT(*) FROM registrations WHERE event_id = et.event_id AND team = et.team_key) AS current_players
    FROM event_teams et
    WHERE et.event_id = ?
    ORDER BY et.display_order ASC, et.id ASC
");
$stmt->execute([$event_id]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
$capacity = (int)array_sum(array_column($teams, 'max_players'));
$taken = (int)array_sum(array_column($teams, 'current_players'));
$justCreated = isset($_GET['created']);
$flashes = flash_get();
?>
<?php site_head('Équipes · ' . $event['title'], ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <a class="a-link" href="view_event.php?id=<?= $event_id ?>">← Retour à la partie</a>
                <h1>Équipes</h1>
                <div class="a-sub"><?= e($event['title']) ?> · <?= e(ucfirst(date_fr($event['event_date']))) ?> · <?= $taken ?>/<?= $capacity ?> places prises</div>
            </div>
            <div class="a-actions">
                <a href="edit_event.php?id=<?= $event_id ?>" class="a-btn"><?= ui_icon('edit', 18) ?> Modifier la partie</a>
                <a href="../event.php?id=<?= $event_id ?>" class="a-btn" target="_blank" rel="noopener"><?= ui_icon('external', 18) ?> Voir sur le site</a>
            </div>
        </div>

        <?php if ($justCreated): ?>
            <div class="alert alert-success a-flash">Partie créée ! Vérifie les équipes ci-dessous (noms, couleurs, places), puis ouvre la fiche de la partie.
                <a href="view_event.php?id=<?= $event_id ?>">Terminé →</a></div>
        <?php endif; ?>
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <div class="a-grid a-grid-main">
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('shield', 18) ?> Équipes de la partie</h2>
                    <span class="a-badge st-muted"><?= count($teams) ?></span></div>
                <?php if (!$teams): ?>
                    <p class="a-empty">Aucune équipe : ajoute-en au moins une pour ouvrir les inscriptions.</p>
                <?php else: ?>
                    <div class="a-teamlist">
                        <?php foreach ($teams as $team):
                            $formId = 'team' . (int)$team['id']; ?>
                            <div class="a-teamrow">
                                <form method="POST" id="<?= $formId ?>" class="a-teamrow-form">
                                    <input type="hidden" name="action" value="update_team">
                                    <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                    <label class="a-color" title="Couleur de l'équipe">
                                        <input type="color" name="team_color" value="<?= e(safe_color($team['team_color'])) ?>" aria-label="Couleur de <?= e($team['team_name']) ?>">
                                    </label>
                                    <div class="s-field a-teamrow-name">
                                        <label for="<?= $formId ?>-name">Nom <span class="a-small a-muted">· <?= e($team['team_key']) ?></span></label>
                                        <input type="text" id="<?= $formId ?>-name" name="team_name" required maxlength="100" value="<?= e($team['team_name']) ?>">
                                    </div>
                                    <div class="s-field a-teamrow-num a-teamrow-max">
                                        <label for="<?= $formId ?>-max">Places</label>
                                        <input type="number" id="<?= $formId ?>-max" name="max_players" min="0" max="500" required value="<?= (int)$team['max_players'] ?>">
                                    </div>
                                    <div class="s-field a-teamrow-num a-teamrow-order">
                                        <label for="<?= $formId ?>-order">Ordre</label>
                                        <input type="number" id="<?= $formId ?>-order" name="display_order" min="0" max="99" value="<?= (int)$team['display_order'] ?>">
                                    </div>
                                </form>
                                <div class="a-teamrow-foot">
                                    <span class="a-teamrow-fill"><?= admin_progress((int)$team['current_players'], (int)$team['max_players'], $team['team_color']) ?>
                                        <span class="a-small a-muted"><?= (int)$team['current_players'] ?>/<?= (int)$team['max_players'] ?> inscrits</span></span>
                                    <div class="a-actions">
                                        <button type="submit" form="<?= $formId ?>" class="a-btn a-btn-sm a-btn-ok"><?= ui_icon('check', 16) ?> Enregistrer</button>
                                        <form method="POST" class="a-inline" data-confirm="<?= e('Supprimer l\'équipe « ' . $team['team_name'] . ' » ?') ?>">
                                            <input type="hidden" name="action" value="delete_team">
                                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                            <button type="submit" class="a-btn a-btn-sm a-btn-danger a-btn-icon" title="Supprimer l'équipe"
                                                <?= (int)$team['current_players'] > 0 ? 'disabled' : '' ?>><?= ui_icon('trash', 16) ?></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="s-hint" style="margin-top:0.75rem;">Une équipe avec des inscrits ne peut pas être supprimée. L'ordre décide de l'affichage sur le site (1 = en premier).</p>
                <?php endif; ?>
            </section>

            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('plus', 18) ?> Ajouter une équipe</h2></div>
                <form method="POST" class="s-form">
                    <input type="hidden" name="action" value="add_team">
                    <div class="s-field">
                        <label for="team_name">Nom de l'équipe <span class="s-req">*</span></label>
                        <input type="text" id="team_name" name="team_name" required maxlength="100" placeholder="Ex. : OTAN">
                    </div>
                    <div class="s-form-row">
                        <div class="s-field">
                            <label for="team_color">Couleur</label>
                            <input type="color" id="team_color" name="team_color" value="#3b82f6">
                        </div>
                        <div class="s-field">
                            <label for="max_players">Places <span class="s-req">*</span></label>
                            <input type="number" id="max_players" name="max_players" min="0" max="500" value="15" required>
                        </div>
                    </div>
                    <details class="a-collapse" style="margin-bottom:1rem;">
                        <summary><?= ui_icon('settings', 16) ?> Clé technique (facultatif)</summary>
                        <div class="a-collapse-body">
                            <div class="s-field" style="margin-bottom:0;">
                                <label for="team_key">Clé</label>
                                <input type="text" id="team_key" name="team_key" pattern="[A-Za-z0-9_]+" maxlength="50" placeholder="Créée automatiquement à partir du nom">
                                <span class="s-hint">Lettres, chiffres et _ uniquement. Elle ne se change plus ensuite.</span>
                            </div>
                        </div>
                    </details>
                    <button type="submit" class="a-btn a-btn-primary a-btn-block"><?= ui_icon('plus', 18) ?> Ajouter l'équipe</button>
                </form>
            </section>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
