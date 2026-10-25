<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_event_form.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? 0);

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
            if (!empty($_POST['announce'])) {
                $updated = getEventWithTeams($pdo, $event_id);
                if ($updated && event_can_be_announced($updated)) {
                    $success .= ' Annonce envoyée à ' . announce_event($pdo, $event_id) . ' joueur(s) abonné(s).';
                } else {
                    $error = 'Annonce non envoyée : la partie doit être active, à venir et avec les inscriptions ouvertes.';
                }
            }
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

$values = [
    'title'              => $event['title'],
    'event_date'         => $event_date_only,
    'event_time'         => $event_time_only,
    'location'           => $event['location'],
    'price'              => is_string($_POST['price'] ?? null) ? $_POST['price'] : admin_price_input((int)($event['price_cents'] ?? 0)),
    'description'        => $event['description'],
    'scenario'           => $event['scenario'],
    'equipment_required' => $event['equipment_required'],
    'rules'              => $event['rules'],
];
// En cas d'erreur, on garde ce que l'admin vient de saisir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && !$success) {
    foreach (array_keys($values) as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) {
            $values[$k] = $_POST[$k];
        }
    }
}
$flashes = flash_get();
?>
<?php site_head('Modifier · ' . $event['title'], ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <a class="a-link" href="view_event.php?id=<?= $event_id ?>">← Retour à la partie</a>
                <h1>Modifier la partie</h1>
                <div class="a-sub"><?= e($event['title']) ?> · <?= e(ucfirst(date_fr($event['event_date']))) ?></div>
            </div>
            <div class="a-actions">
                <a href="manage_teams.php?event_id=<?= $event_id ?>" class="a-btn"><?= ui_icon('shield', 18) ?> Équipes</a>
                <a href="../event.php?id=<?= $event_id ?>" class="a-btn" target="_blank" rel="noopener"><?= ui_icon('external', 18) ?> Voir sur le site</a>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
            <div class="alert alert-error a-flash" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success a-flash"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="edit_event.php?id=<?= $event_id ?>" class="s-form">
            <?= csrf_field() ?>
            <div class="a-grid a-grid-main">
                <div class="a-stack">
                    <?php admin_event_form_fields($values); ?>
                </div>
                <aside class="a-stack">
                    <section class="a-card">
                        <div class="a-card-head"><h2><?= ui_icon('settings', 18) ?> Publication</h2></div>
                        <div class="s-check">
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?= $event['is_active'] ? 'checked' : '' ?>>
                            <label for="is_active">Partie visible sur le site</label>
                        </div>
                        <div class="s-check">
                            <input type="checkbox" id="registration_open" name="registration_open" value="1" <?= $event['registration_open'] ? 'checked' : '' ?>>
                            <label for="registration_open">Inscriptions ouvertes</label>
                        </div>
                        <div class="s-check">
                            <input type="checkbox" id="announce" name="announce" value="1">
                            <label for="announce"><?= empty($event['announced_at']) ? 'Envoyer' : 'Renvoyer' ?> l'annonce par email aux joueurs abonnés (<?= (int)subscribers_count($pdo) ?>)</label>
                        </div>
                        <?php if (!empty($event['announced_at'])): ?>
                            <p class="s-hint" style="margin-bottom:1rem;">Dernière annonce le <?= e(date('d/m/Y à H\hi', strtotime($event['announced_at']))) ?>
                                (<?= (int)($event['announced_count'] ?? 0) ?> email(s) envoyé(s)).</p>
                        <?php else: ?>
                            <p class="s-hint" style="margin-bottom:1rem;">L'annonce n'a pas encore été envoyée.</p>
                        <?php endif; ?>
                        <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block"><?= ui_icon('check', 18) ?> Enregistrer</button>
                        <a href="view_event.php?id=<?= $event_id ?>" class="a-btn a-btn-block" style="margin-top:0.5rem;">Annuler</a>
                    </section>
                    <section class="a-card">
                        <div class="a-card-head"><h2><?= ui_icon('alert', 18) ?> Zone sensible</h2></div>
                        <p class="s-hint" style="margin-bottom:0.75rem;">Supprimer efface la partie, ses inscrits et leurs billets. Pour la retirer du site sans rien perdre, décoche « Partie visible ».</p>
                        <a href="delete_event.php?id=<?= $event_id ?>" class="a-btn a-btn-danger a-btn-block"><?= ui_icon('trash', 18) ?> Supprimer la partie</a>
                    </section>
                </aside>
            </div>
        </form>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
