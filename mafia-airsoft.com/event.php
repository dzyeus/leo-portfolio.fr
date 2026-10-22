<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/team_helpers.php';

$event_id = (int)($_GET['id'] ?? 0);
$error = '';

// Récupérer les détails de la partie avec les équipes
$event = getEventWithTeams($pdo, $event_id);

if (!$event || (!$event['is_active'] && !isAdmin())) {
    header('Location: events.php');
    exit;
}

$is_free = event_is_free($event);
$registration_open = event_registration_is_open($event);
$event_started = event_has_started($event);

// Place du joueur connecté pour cette partie (et la commande associée)
$user_registration = null;
$user_orders = [];
if (isLoggedIn()) {
    $stmt = $pdo->prepare("
        SELECT r.*, o.status AS order_status, o.quantity AS order_quantity
        FROM registrations r
        LEFT JOIN orders o ON o.id = r.order_id
        WHERE r.user_id = ? AND r.event_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $event_id]);
    $user_registration = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare("
        SELECT * FROM orders
        WHERE user_id = ? AND event_id = ? AND status IN ('paid', 'free', 'pending')
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $event_id]);
    $user_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Actions : réserver / se désinscrire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isLoggedIn()) {
        redirect('login.php?return=' . urlencode('event.php?id=' . $event_id));
    }
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $user = current_user($pdo);
        if (empty($_POST['accept_terms'])) {
            $error = 'Coche la case d\'acceptation des conditions générales pour réserver.';
        }
        if (!profile_is_complete($user)) {
            redirect('player/profile.php?complete=1&return=' . urlencode('event.php?id=' . $event_id));
        }
        try {
            if ($error) {
                throw new OrderException($error);
            }
            $order = order_create($pdo, $user, $event, (string)($_POST['team'] ?? ''), (int)($_POST['quantity'] ?? 1));

            if ($order['status'] === 'free') {
                order_send_confirmation($pdo, (int)$order['id']);
                if ((int)$order['quantity'] > 1) {
                    flash_set('success', 'Places réservées ! Renseigne maintenant tes invités pour générer leurs billets.');
                    redirect('order.php?id=' . $order['id']);
                }
                flash_set('success', 'Inscription réussie ! 🎫 Ton billet t\'a été envoyé par email.');
                redirect('event.php?id=' . $event_id);
            }

            // Partie payante : direction la page de paiement Stripe
            header('Location: ' . order_start_checkout($pdo, $order, $event), true, 303);
            exit;
        } catch (OrderException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Réservation partie ' . $event_id . ' : ' . $e->getMessage());
            $error = 'Une erreur est survenue, réessaie dans un instant.';
        }
    } elseif ($action === 'unregister' && $user_registration) {
        if ($event_started) {
            $error = 'La partie a déjà commencé.';
        } elseif (!empty($user_registration['order_status']) && $user_registration['order_status'] !== 'free') {
            $error = 'Ta place a été payée : contacte un organisateur pour annuler et être remboursé.';
        } else {
            $pdo->prepare("DELETE FROM registrations WHERE id = ?")->execute([$user_registration['id']]);
            if ($user_registration['order_id'] && (int)$user_registration['order_quantity'] === 1) {
                $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'free'")
                    ->execute([$user_registration['order_id']]);
            }
            flash_set('success', 'Désinscription effectuée.');
            redirect('event.php?id=' . $event_id);
        }
    }
}

// Retour de la page de paiement abandonnée
if (isset($_GET['payment']) && $_GET['payment'] === 'cancelled') {
    flash_set('info', 'Paiement annulé : aucune place n\'a été réservée.');
}
$flashes = flash_get();

// Liste des inscrits (visible pour tous) : joueurs et invités
$stmt = $pdo->prepare("
    SELECT r.team, COALESCE(r.pseudo, u.pseudo) AS pseudo, u.profile_picture
    FROM registrations r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.event_id = ?
    ORDER BY r.team, r.registered_at
");
$stmt->execute([$event_id]);
$registrations = $stmt->fetchAll();

$teams = [];
foreach ($event['teams'] as $team_info) {
    $teams[$team_info['team_key']] = [];
}
foreach ($registrations as $reg) {
    $teams[$reg['team']][] = [
        'pseudo' => $reg['pseudo'] ?: 'Invité',
        'picture' => $reg['profile_picture']
    ];
}

$max_places = (int)MAX_PLACES_PER_ORDER;
$price_label = $is_free ? 'Gratuit' : format_price((int)$event['price_cents']) . ' par place';

/** Bloc d'inscription (affiché deux fois : version mobile et version ordinateur). */
function render_registration_box(array $event, ?array $user_registration, bool $registration_open, bool $is_free, int $max_places, string $price_label, string $suffix): void
{
    if (!isLoggedIn()) { ?>
        <div class="registration-box">
            <h3>Rejoindre la partie</h3>
            <p><strong><?= e($price_label) ?></strong></p>
            <p>Connecte-toi ou crée un compte pour réserver ta place.</p>
            <a href="login.php?return=<?= urlencode('event.php?id=' . $event['id']) ?>" class="btn btn-primary btn-block">Se connecter</a>
            <p style="text-align:center;margin-top:var(--spacing-sm);">
                <a href="register.php?return=<?= urlencode('event.php?id=' . $event['id']) ?>">Créer un compte</a>
            </p>
        </div>
    <?php return; }

    if (!$registration_open) { ?>
        <div class="alert alert-warning">Inscriptions fermées</div>
    <?php return; } ?>

    <div class="registration-box">
        <h3><?= $user_registration ? 'Ajouter des invités' : 'Rejoindre la partie' ?></h3>
        <p><strong><?= e($price_label) ?></strong></p>
        <form method="POST" class="registration-form" data-price="<?= $is_free ? 0 : (int)$event['price_cents'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book">

            <div class="form-group">
                <label for="quantity<?= $suffix ?>">
                    <?= $user_registration ? 'Nombre d\'invités' : 'Nombre de places (toi + tes invités)' ?>
                </label>
                <select id="quantity<?= $suffix ?>" name="quantity" class="quantity-select">
                    <?php for ($q = 1; $q <= $max_places; $q++): ?>
                        <option value="<?= $q ?>"><?= $q ?><?= $is_free ? '' : ' – ' . e(format_price($q * (int)$event['price_cents'])) ?></option>
                    <?php endfor; ?>
                </select>
                <?php if (!$is_free): ?>
                    <small>Paiement sécurisé par carte (Stripe). Tu renseigneras tes invités juste après.</small>
                <?php endif; ?>
            </div>

            <div class="form-group-checkbox">
                <input type="checkbox" id="accept_terms<?= $suffix ?>" name="accept_terms" value="1" required>
                <label for="accept_terms<?= $suffix ?>">
                    J'accepte les <a href="cgv.php" target="_blank">conditions générales</a><?= $is_free ? '' : ' et je reconnais que, s\'agissant d\'une activité de loisirs à date fixe, je ne dispose pas de droit de rétractation (art. L221-28 12° du Code de la consommation)' ?>.
                </label>
            </div>

            <p>Choisissez votre équipe :</p>
            <?php foreach ($event['teams'] as $team): ?>
                <?php $is_full = $team['current_players'] >= $team['max_players']; ?>
                <button type="submit" name="team" value="<?= e($team['team_key']) ?>"
                        class="btn-team"
                        style="border-left: 4px solid <?= e($team['team_color']) ?>"
                        <?= $is_full ? 'disabled' : '' ?>>
                    <?= e($team['team_name']) ?>
                    <span class="team-count"><?= $team['current_players'] ?>/<?= $team['max_players'] ?></span>
                    <?php if ($is_full): ?>
                        <span class="team-full">COMPLET</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </form>
    </div>
<?php }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($event['title']) ?> - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container page-content">
        <div class="event-detail">
            <!-- En-tête de l'événement -->
            <div class="event-detail-header">
                <h1><?= htmlspecialchars($event['title']) ?></h1>
                <div class="event-meta-large">
                    <span class="meta-item">
                        <strong><?= icon('calendar') ?> Date:</strong> <?= date('l d F Y', strtotime($event['event_date'])) ?>
                    </span>
                    <span class="meta-item">
                        <strong>🕐 Heure:</strong> <?= date('H:i', strtotime($event['event_date'])) ?>
                    </span>
                    <span class="meta-item">
                        <strong>📍 Lieu:</strong> <?= htmlspecialchars($event['location']) ?>
                    </span>
                    <span class="meta-item">
                        <strong>💶 Prix:</strong> <?= e($price_label) ?>
                    </span>
                </div>
            </div>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Statut d'inscription de l'utilisateur -->
            <?php if ($user_registration): ?>
                <div class="alert alert-info">
                    <?= icon('check') ?> Vous êtes inscrit au camp <strong><?= e($user_registration['team']) ?></strong>
                    <?php if (!$event_started && (empty($user_registration['order_status']) || $user_registration['order_status'] === 'free')): ?>
                        <form method="POST" style="display: inline-block; margin-left: 15px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="unregister">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Êtes-vous sûr de vouloir vous désinscrire ?')">
                                Se désinscrire
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($user_orders): ?>
                <div class="alert alert-info">
                    🎫 Mes réservations pour cette partie :
                    <?php foreach ($user_orders as $o): ?>
                        <a href="order.php?id=<?= (int)$o['id'] ?>" style="margin-left: 10px;">
                            n° <?= (int)$o['id'] ?> (<?= (int)$o['quantity'] ?> place<?= $o['quantity'] > 1 ? 's' : '' ?>, <?= e(order_status_label($o['status'])) ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="event-detail-grid">
                <!-- Colonne gauche: Informations -->
                <div class="event-detail-content">
                    <section class="event-section">
                        <h2><?= icon('list') ?> Description</h2>
                        <p><?= nl2br(htmlspecialchars($event['description'])) ?></p>
                    </section>

                    <section class="event-section">
                        <h2><?= icon('target') ?> Scénario</h2>
                        <div class="scenario-box">
                            <?= nl2br(htmlspecialchars($event['scenario'])) ?>
                        </div>
                    </section>

                    <?php if ($event['equipment_required']): ?>
                    <section class="event-section">
                        <h2>🎒 Matériel recommandé</h2>
                        <p><?= nl2br(htmlspecialchars($event['equipment_required'])) ?></p>
                    </section>
                    <?php endif; ?>

                    <?php if ($event['rules']): ?>
                    <section class="event-section">
                        <h2>📜 Règles spéciales</h2>
                        <p><?= nl2br(htmlspecialchars($event['rules'])) ?></p>
                    </section>
                    <?php endif; ?>
                    
                    <!-- Inscription et équipes en mobile -->
                    <div class="event-detail-sidebar-mobile">
                        <!-- Inscription -->
                        <?php render_registration_box($event, $user_registration, $registration_open, $is_free, $max_places, $price_label, '_m'); ?>

                        <!-- Liste des inscrits -->
                        <div class="players-list">
                            <h3><?= icon('users') ?> Joueurs inscrits</h3>
                            
                            <?php foreach ($event['teams'] as $team_info): ?>
                                <?php $team_key = $team_info['team_key']; ?>
                                <div class="team-list">
                                    <h4 class="team-title" style="border-left: 4px solid <?= htmlspecialchars($team_info['team_color']) ?>">
                                        <?= htmlspecialchars($team_info['team_name']) ?> 
                                        (<?= isset($teams[$team_key]) ? count($teams[$team_key]) : 0 ?>/<?= $team_info['max_players'] ?>)
                                    </h4>
                                    <?php if (empty($teams[$team_key])): ?>
                                        <p class="no-players">Aucun joueur inscrit</p>
                                    <?php else: ?>
                                        <div class="team-players">
                                            <?php foreach ($teams[$team_key] as $player): ?>
                                                <div class="player-item">
                                                    <?php if ($player['picture']): ?>
                                                        <img src="<?= htmlspecialchars($player['picture']) ?>" alt="<?= htmlspecialchars($player['pseudo']) ?>" class="player-mini-avatar">
                                                    <?php else: ?>
                                                        <div class="player-mini-avatar"><?= icon('user') ?></div>
                                                    <?php endif; ?>
                                                    <span><?= htmlspecialchars($player['pseudo']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite: Inscription et équipes (Desktop seulement) -->
                <div class="event-detail-sidebar">
                    <!-- Inscription -->
                    <?php render_registration_box($event, $user_registration, $registration_open, $is_free, $max_places, $price_label, '_d'); ?>

                    <!-- Liste des inscrits -->
                    <div class="players-list">
                        <h3><?= icon('users') ?> Joueurs inscrits</h3>
                        
                        <?php foreach ($event['teams'] as $team_info): ?>
                            <?php $team_key = $team_info['team_key']; ?>
                            <div class="team-list">
                                <h4 class="team-title" style="border-left: 4px solid <?= htmlspecialchars($team_info['team_color']) ?>">
                                    <?= htmlspecialchars($team_info['team_name']) ?> 
                                    (<?= isset($teams[$team_key]) ? count($teams[$team_key]) : 0 ?>/<?= $team_info['max_players'] ?>)
                                </h4>
                                <?php if (empty($teams[$team_key])): ?>
                                    <p class="no-players">Aucun joueur inscrit</p>
                                <?php else: ?>
                                    <div class="team-players">
                                        <?php foreach ($teams[$team_key] as $player): ?>
                                            <div class="player-item">
                                                <?php if ($player['picture']): ?>
                                                    <img src="<?= htmlspecialchars($player['picture']) ?>" alt="<?= htmlspecialchars($player['pseudo']) ?>" class="player-mini-avatar">
                                                <?php else: ?>
                                                    <div class="player-mini-avatar"><?= icon('user') ?></div>
                                                <?php endif; ?>
                                                <span><?= htmlspecialchars($player['pseudo']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="event-detail-footer">
                <a href="events.php" class="btn btn-secondary">← Retour à l'agenda</a>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
