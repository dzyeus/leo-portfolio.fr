<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/admin_event_form.php';
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

$values = [
    'title'              => $_POST['title'] ?? '',
    'event_date'         => $_POST['event_date'] ?? '',
    'event_time'         => $_POST['event_time'] ?? '09:00',
    'location'           => $_POST['location'] ?? '',
    'price'              => $_POST['price'] ?? admin_price_input((int)DEFAULT_EVENT_PRICE_CENTS),
    'description'        => $_POST['description'] ?? '',
    'scenario'           => $_POST['scenario'] ?? '',
    'equipment_required' => $_POST['equipment_required'] ?? '',
    'rules'              => $_POST['rules'] ?? '',
];
$values = array_map(fn($x) => is_string($x) ? $x : '', $values);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<?php site_head('Créer une partie', ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <a class="a-link" href="events.php">← Parties</a>
                <h1>Créer une partie</h1>
                <div class="a-sub">Après la création, tu règles les équipes (noms, couleurs, places).</div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="create_event.php" class="s-form">
            <?= csrf_field() ?>
            <div class="a-grid a-grid-main">
                <div class="a-stack">
                    <?php admin_event_form_fields($values); ?>
                </div>
                <aside class="a-stack">
                    <section class="a-card">
                        <div class="a-card-head"><h2><?= ui_icon('settings', 18) ?> Publication</h2></div>
                        <div class="s-check">
                            <input type="checkbox" id="registration_open" name="registration_open" value="1" <?= !$posted || isset($_POST['registration_open']) ? 'checked' : '' ?>>
                            <label for="registration_open">Inscriptions ouvertes</label>
                        </div>
                        <div class="s-check">
                            <input type="checkbox" id="announce" name="announce" value="1" <?= !$posted || isset($_POST['announce']) ? 'checked' : '' ?>>
                            <label for="announce">Prévenir par email les joueurs abonnés aux alertes (<?= (int)subscribers_count($pdo) ?>)</label>
                        </div>
                        <p class="s-hint" style="margin-bottom:1rem;">Astuce : décoche l'annonce pour régler d'abord les équipes, puis envoie-la depuis « Modifier la partie ».</p>
                        <button type="submit" class="a-btn a-btn-primary a-btn-lg a-btn-block"><?= ui_icon('plus', 18) ?> Créer la partie</button>
                        <a href="events.php" class="a-btn a-btn-block" style="margin-top:0.5rem;">Annuler</a>
                    </section>
                </aside>
            </div>
        </form>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
