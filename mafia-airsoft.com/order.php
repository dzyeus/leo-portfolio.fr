<?php
/**
 * Réservation : confirmation après paiement, saisie des invités,
 * téléchargement des billets et du reçu.
 */
require_once 'config/database.php';
require_once 'config/session.php';
requireLogin();

$order_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$order = order_get($pdo, $order_id);

if (!$order || ((int)$order['user_id'] !== current_user_id() && !isAdmin())) {
    http_response_code(404);
    flash_set('error', 'Réservation introuvable.');
    redirect('player/dashboard.php');
}

// Le joueur a quitté la page de paiement sans payer : on libère tout de suite les places
if (isset($_GET['cancelled']) && $order['status'] === 'pending') {
    try {
        $order = order_sync_with_stripe($pdo, $order);
        if ($order['status'] === 'pending' && $order['stripe_session_id']) {
            stripe_request('POST', 'checkout/sessions/' . rawurlencode($order['stripe_session_id']) . '/expire');
            order_expire_by_session($pdo, $order['stripe_session_id']);
        }
    } catch (Throwable $e) {
        error_log('Annulation paiement commande ' . $order_id . ' : ' . $e->getMessage());
    }
    redirect('event.php?id=' . $order['event_id'] . '&payment=cancelled');
}

// Retour de Stripe (ou paiement encore en attente) : on vérifie le statut auprès de Stripe
if ($order['status'] === 'pending') {
    $order = order_sync_with_stripe($pdo, $order);
}

$event_started = strtotime($order['event_date']) <= time();
$is_active_order = in_array($order['status'], ['paid', 'free'], true);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'guests' && $is_active_order && !$event_started) {
        $places = order_places($pdo, $order_id);
        $updates = [];
        foreach ($places as $place) {
            if ($place['user_id']) {
                continue; // la place de l'acheteur suit son profil
            }
            $data = $_POST['guest'][$place['id']] ?? [];
            $first = trim($data['first_name'] ?? '');
            $last = trim($data['last_name'] ?? '');
            $pseudo = trim($data['pseudo'] ?? '');

            if ($first === '' && $last === '' && $pseudo === '') {
                continue; // invité laissé vide pour l'instant
            }
            if ($first === '' || $last === '' || $pseudo === '') {
                $errors[] = 'Pour chaque invité, remplis le prénom, le nom ET le pseudo.';
                break;
            }
            if (mb_strlen($first) > 100 || mb_strlen($last) > 100 || mb_strlen($pseudo) > 50) {
                $errors[] = 'Un des champs est trop long.';
                break;
            }
            $updates[$place['id']] = [$first, $last, $pseudo];
        }

        if (!$errors) {
            $stmt = $pdo->prepare("
                UPDATE registrations SET first_name = ?, last_name = ?, pseudo = ?
                WHERE id = ? AND order_id = ? AND user_id IS NULL
            ");
            foreach ($updates as $regId => [$first, $last, $pseudo]) {
                $stmt->execute([$first, $last, $pseudo, $regId, $order_id]);
            }

            $places = order_places($pdo, $order_id);
            $allComplete = count(array_filter($places, 'place_is_complete')) === count($places);
            if ($updates && $allComplete && order_send_tickets($pdo, $order_id)) {
                flash_set('success', 'Invités enregistrés. Tous les billets ont été envoyés par email !');
            } else {
                flash_set('success', 'Invités enregistrés.');
            }
            redirect('order.php?id=' . $order_id);
        }
    } elseif ($action === 'resend' && $is_active_order) {
        if (rate_limit_exceeded($pdo, 'resend_tickets', (string)$order_id, 3, 60)) {
            flash_set('error', 'Billets déjà renvoyés plusieurs fois, réessaie dans une heure.');
        } else {
            rate_limit_hit($pdo, 'resend_tickets', (string)$order_id);
            if (order_send_tickets($pdo, $order_id)) {
                flash_set('success', 'Billets renvoyés par email.');
            } else {
                flash_set('error', 'L\'email n\'a pas pu être envoyé. Tu peux télécharger les billets ci-dessous.');
            }
        }
        redirect('order.php?id=' . $order_id);
    } elseif ($action === 'cancel_free' && $order['status'] === 'free' && !$event_started) {
        order_cancel_free($pdo, $order_id);
        flash_set('success', 'Réservation annulée, les places ont été libérées.');
        redirect('event.php?id=' . $order['event_id']);
    } elseif ($action === 'resume' && $order['status'] === 'pending' && $order['stripe_session_id']) {
        try {
            $session = stripe_request('GET', 'checkout/sessions/' . rawurlencode($order['stripe_session_id']));
            if (($session['status'] ?? '') === 'open' && !empty($session['url'])) {
                header('Location: ' . $session['url'], true, 303);
                exit;
            }
        } catch (Throwable $e) {
            error_log('Reprise paiement commande ' . $order_id . ' : ' . $e->getMessage());
        }
        flash_set('error', 'Ce paiement a expiré. Refais une réservation depuis la page de la partie.');
        redirect('order.php?id=' . $order_id);
    }
}

$places = $is_active_order ? order_places($pdo, $order_id) : [];
$complete_count = count(array_filter($places, 'place_is_complete'));
$flashes = flash_get();
$team_color = safe_color($order['team_color'] ?? '');
$is_gps = location_is_gps((string)$order['event_location']);

site_head('Réservation n° ' . (int)$order['id'], ['noindex' => true]);
include 'includes/header.php';
?>
<main class="s-wrap">
    <a class="s-back" href="player/dashboard.php"><?= ui_icon('chevronleft', 18) ?> Mon espace</a>
    <div class="a-page-head">
        <div>
            <h1>Réservation n° <?= (int)$order['id'] ?></h1>
            <div class="a-sub"><?= e($order['event_title']) ?> · <?= e(date_fr_short($order['event_date'])) ?></div>
        </div>
        <?= order_badge($order['status']) ?>
    </div>

    <?php render_flashes($flashes); ?>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($order['status'] === 'pending'): ?>
        <div class="alert alert-info" data-keep>
            Paiement en cours de confirmation… Si tu viens de payer, recharge la page dans quelques secondes.
            <form method="POST" class="a-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                <input type="hidden" name="action" value="resume">
                <button type="submit" class="a-btn a-btn-sm a-btn-primary" style="margin-left:0.5rem;">Reprendre le paiement</button>
            </form>
        </div>
    <?php elseif ($order['status'] === 'paid'): ?>
        <div class="alert alert-success" data-keep>Paiement reçu, merci ! Ta réservation est confirmée.</div>
    <?php elseif ($order['status'] === 'free'): ?>
        <div class="alert alert-success" data-keep>Inscription confirmée.</div>
    <?php else: ?>
        <div class="alert alert-warning" data-keep>
            Cette réservation n'est plus active (<?= e(order_status_label($order['status'])) ?>).
            <a href="event.php?id=<?= (int)$order['event_id'] ?>">Retour à la partie</a>
        </div>
    <?php endif; ?>

    <div class="s-order-layout">
        <!-- Récapitulatif -->
        <aside class="s-area-side s-stack">
            <section class="a-card">
                <div class="a-card-head"><h2><?= ui_icon('list', 18) ?> Récapitulatif</h2></div>
                <div>
                    <div class="s-kv"><span>Partie</span><strong><a href="event.php?id=<?= (int)$order['event_id'] ?>"><?= e($order['event_title']) ?></a></strong></div>
                    <div class="s-kv"><span>Date</span><strong><?= e(ucfirst(date_fr($order['event_date']))) ?></strong></div>
                    <div class="s-kv"><span>Lieu</span><strong><?= $is_gps ? 'Coordonnées GPS' : e($order['event_location']) ?>
                        <a class="s-small" style="display:block;font-weight:600;" href="<?= e(maps_url((string)$order['event_location'])) ?>" target="_blank" rel="noopener">Itinéraire ↗</a></strong></div>
                    <div class="s-kv"><span>Équipe</span><strong><i class="s-dot" style="background:<?= e($team_color) ?>"></i> <?= e($order['team_name'] ?? $order['team_key']) ?></strong></div>
                    <div class="s-kv"><span>Places</span><strong><?= (int)$order['quantity'] ?></strong></div>
                    <div class="s-kv"><span>Montant</span><strong><?= (int)$order['amount_cents'] > 0 ? e(format_price((int)$order['amount_cents'])) : 'Gratuit' ?></strong></div>
                </div>

                <?php if ($complete_count > 0 || in_array($order['status'], ['paid', 'refunded'], true)): ?>
                    <div class="s-stack" style="display:grid;gap:0.5rem;margin-top:1.1rem;">
                        <?php if ($complete_count > 0): ?>
                            <a href="ticket.php?order=<?= (int)$order['id'] ?>" class="a-btn a-btn-primary a-btn-block" target="_blank" rel="noopener">
                                <?= ui_icon('ticket', 18) ?> Télécharger les billets (<?= $complete_count ?>)</a>
                        <?php endif; ?>
                        <?php if (in_array($order['status'], ['paid', 'refunded'], true)): ?>
                            <a href="receipt.php?order=<?= (int)$order['id'] ?>" class="a-btn a-btn-block" target="_blank" rel="noopener"><?= ui_icon('file', 18) ?> Télécharger le reçu</a>
                        <?php endif; ?>
                        <?php if ($complete_count > 0): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                <input type="hidden" name="action" value="resend">
                                <button type="submit" class="a-btn a-btn-block"><?= ui_icon('mail', 18) ?> Renvoyer par email</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($order['status'] === 'free' && !$event_started): ?>
                <section class="a-card">
                    <form method="POST" data-confirm="Annuler cette réservation et libérer toutes ses places ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="cancel_free">
                        <button type="submit" class="a-btn a-btn-danger a-btn-block"><?= ui_icon('x', 17) ?> Annuler la réservation</button>
                    </form>
                </section>
            <?php elseif ($order['status'] === 'paid' && !$event_started): ?>
                <p class="s-hint" style="padding:0 0.25rem;">Pour annuler une place payée, contacte un organisateur : <a href="mailto:<?= e(ASSO_EMAIL) ?>"><?= e(ASSO_EMAIL) ?></a>.</p>
            <?php endif; ?>
        </aside>

        <!-- Participants -->
        <div class="s-area-main">
            <?php if ($places): ?>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('users', 18) ?> Participants</h2><span class="a-badge st-muted"><?= $complete_count ?>/<?= count($places) ?> billet<?= count($places) > 1 ? 's' : '' ?></span></div>
                    <p class="s-muted" style="margin-bottom:1rem;">
                        Chaque participant a son propre billet avec un QR code à présenter à l'entrée.
                        <?php if (!$event_started): ?>Renseigne le prénom, le nom et le pseudo de jeu de tes invités pour générer leurs billets.<?php endif; ?>
                    </p>

                    <form method="POST" class="s-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="guests">

                        <?php $guest_number = 0; ?>
                        <?php foreach ($places as $place):
                            $complete = place_is_complete($place); ?>
                            <div class="s-guest">
                                <div class="s-guest-head">
                                    <?php if ($place['user_id']): ?>
                                        <h3><?= ui_icon('user', 17) ?> <?= e(full_name($place['first_name'], $place['last_name'])) ?> <span class="s-muted">(<?= e($place['pseudo']) ?>)</span></h3>
                                        <span class="a-badge st-info">Toi</span>
                                    <?php else: ?>
                                        <?php $guest_number++; ?>
                                        <h3><?= ui_icon('users', 17) ?> Invité <?= $guest_number ?></h3>
                                        <span class="a-badge <?= $complete ? 'st-ok' : 'st-warn' ?>"><?= $complete ? 'Billet prêt' : 'À compléter' ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$place['user_id'] && !$event_started): ?>
                                    <div class="s-form-row-3">
                                        <div class="s-field">
                                            <label for="g<?= (int)$place['id'] ?>f">Prénom</label>
                                            <input type="text" id="g<?= (int)$place['id'] ?>f" name="guest[<?= (int)$place['id'] ?>][first_name]" maxlength="100"
                                                   value="<?= e($_POST['guest'][$place['id']]['first_name'] ?? $place['first_name']) ?>">
                                        </div>
                                        <div class="s-field">
                                            <label for="g<?= (int)$place['id'] ?>l">Nom</label>
                                            <input type="text" id="g<?= (int)$place['id'] ?>l" name="guest[<?= (int)$place['id'] ?>][last_name]" maxlength="100"
                                                   value="<?= e($_POST['guest'][$place['id']]['last_name'] ?? $place['last_name']) ?>">
                                        </div>
                                        <div class="s-field">
                                            <label for="g<?= (int)$place['id'] ?>p">Pseudo de jeu</label>
                                            <input type="text" id="g<?= (int)$place['id'] ?>p" name="guest[<?= (int)$place['id'] ?>][pseudo]" maxlength="50"
                                                   value="<?= e($_POST['guest'][$place['id']]['pseudo'] ?? $place['pseudo']) ?>">
                                        </div>
                                    </div>
                                <?php elseif (!$place['user_id']): ?>
                                    <p><?= $complete ? e(full_name($place['first_name'], $place['last_name']) . ' (' . $place['pseudo'] . ')') : 'Non renseigné' ?></p>
                                <?php endif; ?>

                                <?php if ($complete || $place['scanned_at']): ?>
                                    <div class="s-actions-row">
                                        <?php if ($complete): ?>
                                            <a href="ticket.php?registration=<?= (int)$place['id'] ?>" class="a-btn a-btn-sm" target="_blank" rel="noopener"><?= ui_icon('ticket', 16) ?> Billet</a>
                                        <?php endif; ?>
                                        <?php if ($place['scanned_at']): ?>
                                            <span class="a-badge st-ok"><?= ui_icon('check', 13) ?> Entrée validée le <?= e(date('d/m/Y à H\hi', strtotime($place['scanned_at']))) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($guest_number > 0 && !$event_started): ?>
                            <button type="submit" class="a-btn a-btn-primary" style="margin-top:1rem;"><?= ui_icon('check', 17) ?> Enregistrer les invités</button>
                        <?php endif; ?>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
