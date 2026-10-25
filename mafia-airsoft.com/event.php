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
                flash_set('success', 'Inscription réussie ! Ton billet t\'a été envoyé par email.');
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
    SELECT r.team, COALESCE(r.pseudo, u.pseudo) AS pseudo, u.profile_picture, r.user_id
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
        'pseudo'  => $reg['pseudo'] ?: 'Invité',
        'picture' => $reg['profile_picture'],
        'guest'   => empty($reg['user_id']),
    ];
}

$max_places = (int)MAX_PLACES_PER_ORDER;
$price_label = $is_free ? 'Gratuit' : format_price((int)$event['price_cents']) . ' par place';
$teams_by_key = array_column($event['teams'], null, 'team_key');
$total_taken = (int)array_sum(array_column($event['teams'], 'current_players'));
$total_capacity = (int)array_sum(array_column($event['teams'], 'max_players'));
$is_gps = location_is_gps($event['location']);
$event_past = strtotime($event['event_date']) < time();
$my_team_name = $user_registration ? ($teams_by_key[$user_registration['team']]['team_name'] ?? $user_registration['team']) : '';
$can_unregister = $user_registration && !$event_started
    && (empty($user_registration['order_status']) || $user_registration['order_status'] === 'free');
$return_url = 'event.php?id=' . $event_id;

site_head($event['title'], [
    'description' => ucfirst(date_fr($event['event_date'])) . ' · ' . ($is_gps ? 'Partie d\'airsoft en forêt' : $event['location'])
        . ' · ' . $price_label . '. ' . trim((string)$event['description']),
]);
include 'includes/header.php';
?>
<main class="s-wrap">
    <a class="s-back" href="events.php"><?= ui_icon('chevronleft', 18) ?> Toutes les parties</a>

    <!-- En-tête de la partie -->
    <section class="a-card s-event-hero">
        <span class="s-kicker"><?= ui_icon('target', 15) ?>
            <?= $event_past ? 'Partie terminée' : e(ucfirst(days_until_fr($event['event_date']))) ?></span>
        <h1><?= e($event['title']) ?></h1>
        <div class="s-facts">
            <div class="s-fact"><?= ui_icon('calendar', 20) ?><div><span class="s-fact-label">Date</span>
                <span class="s-fact-value"><?= e(ucfirst(date_fr($event['event_date'], false))) ?></span></div></div>
            <div class="s-fact"><?= ui_icon('clock', 20) ?><div><span class="s-fact-label">Heure</span>
                <span class="s-fact-value"><?= e(time_fr($event['event_date'])) ?></span></div></div>
            <div class="s-fact"><?= ui_icon('pin', 20) ?><div><span class="s-fact-label">Lieu</span>
                <span class="s-fact-value"><?= $is_gps ? 'Coordonnées GPS' : e($event['location']) ?></span>
                <a class="s-small" href="<?= e(maps_url($event['location'])) ?>" target="_blank" rel="noopener"><?= $is_gps ? e($event['location']) . ' · ' : '' ?>Itinéraire ↗</a></div></div>
            <div class="s-fact"><?= ui_icon('euro', 20) ?><div><span class="s-fact-label">Prix</span>
                <span class="s-fact-value"><?= e($price_label) ?></span></div></div>
        </div>
    </section>

    <div style="margin-top:1rem;">
        <?php render_flashes($flashes); ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
    </div>

    <div class="s-event-layout">
        <!-- Réservation -->
        <aside class="s-area-book">
            <div class="a-card s-book" id="reserver">
                <div class="a-card-head"><h2><?= ui_icon('ticket', 18) ?> <?= $user_registration && $registration_open ? 'Ta place' : 'Réserver' ?></h2></div>

                <?php if ($user_registration): ?>
                    <div class="s-status">
                        <?= ui_icon('check', 20) ?>
                        <div>
                            <strong>Tu es inscrit · <?= e($my_team_name) ?></strong>
                            <span class="s-small s-muted">Ton billet (QR code) est dans tes emails et dans ton espace.</span>
                            <div class="s-status-actions">
                                <?php foreach ($user_orders as $o): ?>
                                    <a class="a-btn a-btn-sm" href="order.php?id=<?= (int)$o['id'] ?>"><?= ui_icon('ticket', 16) ?> Réservation n° <?= (int)$o['id'] ?>
                                        (<?= (int)$o['quantity'] ?> place<?= $o['quantity'] > 1 ? 's' : '' ?>)</a>
                                <?php endforeach; ?>
                                <?php if ($can_unregister): ?>
                                    <form method="POST" class="a-inline" data-confirm="Te désinscrire de cette partie ?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unregister">
                                        <button type="submit" class="a-btn a-btn-sm a-btn-danger">Se désinscrire</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($user_orders): ?>
                    <div class="s-status">
                        <?= ui_icon('ticket', 20) ?>
                        <div><strong>Tes réservations</strong>
                            <div class="s-status-actions">
                                <?php foreach ($user_orders as $o): ?>
                                    <a class="a-btn a-btn-sm" href="order.php?id=<?= (int)$o['id'] ?>">n° <?= (int)$o['id'] ?> · <?= (int)$o['quantity'] ?> place<?= $o['quantity'] > 1 ? 's' : '' ?> · <?= e(order_status_label($o['status'])) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$registration_open): ?>
                    <div class="s-status is-closed">
                        <?= ui_icon('lock', 20) ?>
                        <div><strong><?= $event_past ? 'Cette partie est terminée' : 'Inscriptions fermées' ?></strong>
                            <span class="s-small s-muted"><?= $event_past ? 'Retrouve les prochaines parties dans l\'agenda.' : 'Les réservations ne sont pas ouvertes pour cette partie.' ?></span></div>
                    </div>
                <?php elseif (!isLoggedIn()): ?>
                    <div class="s-book-price"><span class="s-price"><?= $is_free ? 'Gratuit' : e(format_price((int)$event['price_cents'])) ?></span>
                        <?php if (!$is_free): ?><span class="s-muted">par place</span><?php endif; ?></div>
                    <p class="s-muted" style="margin-bottom:1rem;">Connecte-toi ou crée ton compte pour réserver ta place et recevoir ton billet.</p>
                    <div class="s-stack" style="display:grid;gap:0.5rem;">
                        <a href="login.php?return=<?= urlencode($return_url) ?>" class="a-btn a-btn-primary a-btn-lg a-btn-block"><?= ui_icon('login', 18) ?> Se connecter</a>
                        <a href="register.php?return=<?= urlencode($return_url) ?>" class="a-btn a-btn-lg a-btn-block">Créer un compte</a>
                    </div>
                <?php else: ?>
                    <div class="s-book-price"><span class="s-price"><?= $is_free ? 'Gratuit' : e(format_price((int)$event['price_cents'])) ?></span>
                        <?php if (!$is_free): ?><span class="s-muted">par place</span><?php endif; ?></div>
                    <form method="POST" class="s-form" data-booking data-price="<?= $is_free ? 0 : (int)$event['price_cents'] ?>"<?= $user_registration ? ' data-guests="1"' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="book">

                        <div class="s-field" style="margin-top:0.75rem;">
                            <label for="quantity"><?= $user_registration ? 'Nombre d\'invités à ajouter' : 'Nombre de places (toi + tes invités)' ?></label>
                            <select id="quantity" name="quantity">
                                <?php for ($q = 1; $q <= $max_places; $q++): ?>
                                    <option value="<?= $q ?>"><?= $q ?> place<?= $q > 1 ? 's' : '' ?><?= $is_free ? '' : ' – ' . e(format_price($q * (int)$event['price_cents'])) ?></option>
                                <?php endfor; ?>
                            </select>
                            <?php if (!$is_free): ?><span class="s-hint">Tu renseigneras le nom de tes invités juste après le paiement.</span><?php endif; ?>
                        </div>

                        <div class="s-check">
                            <input type="checkbox" id="accept_terms" name="accept_terms" value="1" required>
                            <label for="accept_terms">J'accepte les <a href="cgv.php" target="_blank">conditions générales</a><?= $is_free ? '' : ' et je reconnais que, s\'agissant d\'une activité de loisirs à date fixe, je ne dispose pas de droit de rétractation (art. L221-28 12° du Code de la consommation)' ?>.</label>
                        </div>

                        <span class="s-label"><?= $user_registration ? 'Équipe de tes invités' : 'Choisis ton équipe' ?></span>
                        <div class="s-team-pick" style="margin-top:0.5rem;">
                            <?php foreach ($event['teams'] as $team):
                                $is_full = $team['current_players'] >= $team['max_players']; ?>
                                <button type="submit" name="team" value="<?= e($team['team_key']) ?>" class="s-team-btn"
                                        data-team-name="<?= e($team['team_name']) ?>" <?= $is_full ? 'disabled' : '' ?>>
                                    <span class="s-team-stripe" style="background:<?= e(safe_color($team['team_color'])) ?>"></span>
                                    <span class="s-team-name"><?= e($team['team_name']) ?></span>
                                    <span class="s-team-count"><?= $is_full ? '<span class="s-team-full">Complet</span> ' : '' ?><?= (int)$team['current_players'] ?>/<?= (int)$team['max_players'] ?></span>
                                    <?= ui_progress((int)$team['current_players'], (int)$team['max_players'], $team['team_color']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="s-book-note"><?= ui_icon('lock', 16) ?>
                            <span><?= $is_free ? 'Inscription gratuite : ton billet t\'est envoyé par email tout de suite.' : 'Paiement sécurisé par carte avec Stripe. Reçu et billets envoyés par email.' ?></span></p>
                    </form>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Informations -->
        <div class="s-area-main s-stack">
            <?php if (trim((string)$event['description']) !== ''): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('info', 18) ?> Présentation</h2></div>
                    <div class="s-prose"><?= nl2br(e($event['description'])) ?></div>
                </section>
            <?php endif; ?>
            <?php if (trim((string)$event['scenario']) !== ''): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('target', 18) ?> Scénario</h2></div>
                    <div class="s-prose s-quote"><?= nl2br(e($event['scenario'])) ?></div>
                </section>
            <?php endif; ?>
            <?php if (trim((string)$event['equipment_required']) !== ''): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('backpack', 18) ?> Matériel recommandé</h2></div>
                    <div class="s-prose"><?= nl2br(e($event['equipment_required'])) ?></div>
                </section>
            <?php endif; ?>
            <?php if (trim((string)$event['rules']) !== ''): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('shield', 18) ?> Règles spéciales</h2></div>
                    <div class="s-prose"><?= nl2br(e($event['rules'])) ?></div>
                </section>
            <?php endif; ?>
        </div>

        <!-- Joueurs inscrits -->
        <aside class="s-area-roster">
            <section class="a-card">
                <div class="a-card-head">
                    <h2><?= ui_icon('users', 18) ?> Joueurs inscrits</h2>
                    <span class="a-badge st-muted"><?= $total_taken ?>/<?= $total_capacity ?></span>
                </div>
                <?php foreach ($event['teams'] as $team_info):
                    $team_key = $team_info['team_key'];
                    $players = $teams[$team_key] ?? []; ?>
                    <div class="s-roster-team">
                        <div class="s-roster-head">
                            <span><i class="s-dot" style="background:<?= e(safe_color($team_info['team_color'])) ?>"></i><?= e($team_info['team_name']) ?></span>
                            <span class="s-roster-count">(<?= count($players) ?>/<?= (int)$team_info['max_players'] ?>)</span>
                        </div>
                        <?php if (!$players): ?>
                            <p class="s-roster-empty">Aucun joueur pour l'instant.</p>
                        <?php else: ?>
                            <div class="s-players">
                                <?php foreach ($players as $player): ?>
                                    <span class="s-player<?= $player['guest'] ? ' s-player-guest' : '' ?>">
                                        <span class="s-avatar"><?php if ($player['picture']): ?><img src="<?= e($player['picture']) ?>" alt="" loading="lazy"><?php else: ?><?= e(initial($player['pseudo'])) ?><?php endif; ?></span>
                                        <?= e($player['pseudo']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        </aside>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
