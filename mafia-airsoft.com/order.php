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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réservation n° <?= (int)$order['id'] ?> - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container page-content">
        <div class="dashboard">
            <h1><?= icon('ticket') ?> Réservation n° <?= (int)$order['id'] ?></h1>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if ($order['status'] === 'pending'): ?>
                <div class="alert alert-info">
                    ⏳ Paiement en cours de confirmation… Si tu viens de payer, recharge la page dans quelques secondes.
                    <form method="POST" style="display:inline-block;margin-left:10px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="resume">
                        <button type="submit" class="btn btn-primary btn-sm">Reprendre le paiement</button>
                    </form>
                </div>
            <?php elseif ($order['status'] === 'paid'): ?>
                <div class="alert alert-success">✅ Paiement reçu, merci ! Ta réservation est confirmée.</div>
            <?php elseif ($order['status'] === 'free'): ?>
                <div class="alert alert-success">✅ Inscription confirmée.</div>
            <?php else: ?>
                <div class="alert alert-warning">
                    Cette réservation n'est plus active (<?= e(order_status_label($order['status'])) ?>).
                    <a href="event.php?id=<?= (int)$order['event_id'] ?>">Retour à la partie</a>
                </div>
            <?php endif; ?>

            <!-- Récapitulatif -->
            <section class="dashboard-section">
                <h2><?= icon('list') ?> Récapitulatif</h2>
                <div class="info-grid">
                    <div class="info-item"><strong>Partie :</strong>
                        <a href="event.php?id=<?= (int)$order['event_id'] ?>"><?= e($order['event_title']) ?></a></div>
                    <div class="info-item"><strong>Date :</strong> <?= e(ucfirst(date_fr($order['event_date']))) ?></div>
                    <div class="info-item"><strong>Lieu :</strong> <?= e($order['event_location']) ?></div>
                    <div class="info-item"><strong>Équipe :</strong>
                        <span class="team-badge" style="background-color: <?= e($order['team_color'] ?? '#a3a3a3') ?>20; border-left: 3px solid <?= e($order['team_color'] ?? '#a3a3a3') ?>">
                            <?= e($order['team_name'] ?? $order['team_key']) ?>
                        </span>
                    </div>
                    <div class="info-item"><strong>Places :</strong> <?= (int)$order['quantity'] ?></div>
                    <div class="info-item"><strong>Montant :</strong>
                        <?= (int)$order['amount_cents'] > 0 ? e(format_price((int)$order['amount_cents'])) : 'Gratuit' ?>
                        (<?= e(order_status_label($order['status'])) ?>)</div>
                </div>

                <div class="registration-actions" style="margin-top: var(--spacing-md); display:flex; gap: var(--spacing-sm); flex-wrap: wrap;">
                    <?php if ($complete_count > 0): ?>
                        <a href="ticket.php?order=<?= (int)$order['id'] ?>" class="btn btn-primary" target="_blank" rel="noopener">
                            🎫 Télécharger les billets (<?= $complete_count ?>)
                        </a>
                    <?php endif; ?>
                    <?php if (in_array($order['status'], ['paid', 'refunded'], true)): ?>
                        <a href="receipt.php?order=<?= (int)$order['id'] ?>" class="btn btn-secondary" target="_blank" rel="noopener">
                            🧾 Télécharger le reçu
                        </a>
                    <?php endif; ?>
                    <?php if ($complete_count > 0): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="action" value="resend">
                            <button type="submit" class="btn btn-outline">✉️ Renvoyer par email</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($places): ?>
                <!-- Participants -->
                <section class="dashboard-section">
                    <h2><?= icon('users') ?> Participants</h2>
                    <p class="no-data" style="margin-bottom: var(--spacing-md);">
                        Chaque participant a son propre billet avec un QR code à présenter à l'entrée.
                        <?php if (!$event_started): ?>
                            Renseigne le prénom, le nom et le pseudo de jeu de tes invités pour générer leurs billets.
                        <?php endif; ?>
                    </p>

                    <form method="POST" class="admin-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="guests">

                        <?php $guest_number = 0; ?>
                        <?php foreach ($places as $place): ?>
                            <div class="registration-card" style="margin-bottom: var(--spacing-md);">
                                <div class="registration-header">
                                    <?php if ($place['user_id']): ?>
                                        <h3><?= e(full_name($place['first_name'], $place['last_name'])) ?> <small>(<?= e($place['pseudo']) ?>)</small></h3>
                                        <span class="team-badge">Toi</span>
                                    <?php else: ?>
                                        <?php $guest_number++; ?>
                                        <h3>Invité <?= $guest_number ?></h3>
                                        <span class="team-badge"><?= place_is_complete($place) ? '✅ Prêt' : '⏳ À compléter' ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$place['user_id'] && !$event_started): ?>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Prénom</label>
                                            <input type="text" name="guest[<?= (int)$place['id'] ?>][first_name]" maxlength="100"
                                                   value="<?= e($_POST['guest'][$place['id']]['first_name'] ?? $place['first_name']) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Nom</label>
                                            <input type="text" name="guest[<?= (int)$place['id'] ?>][last_name]" maxlength="100"
                                                   value="<?= e($_POST['guest'][$place['id']]['last_name'] ?? $place['last_name']) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Pseudo de jeu</label>
                                            <input type="text" name="guest[<?= (int)$place['id'] ?>][pseudo]" maxlength="50"
                                                   value="<?= e($_POST['guest'][$place['id']]['pseudo'] ?? $place['pseudo']) ?>">
                                        </div>
                                    </div>
                                <?php elseif (!$place['user_id']): ?>
                                    <p><?= place_is_complete($place) ? e(full_name($place['first_name'], $place['last_name']) . ' (' . $place['pseudo'] . ')') : 'Non renseigné' ?></p>
                                <?php endif; ?>

                                <div class="registration-actions">
                                    <?php if (place_is_complete($place)): ?>
                                        <a href="ticket.php?registration=<?= (int)$place['id'] ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">🎫 Billet</a>
                                    <?php endif; ?>
                                    <?php if ($place['scanned_at']): ?>
                                        <small class="registration-date">Entrée validée le <?= date('d/m/Y à H:i', strtotime($place['scanned_at'])) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($guest_number > 0 && !$event_started): ?>
                            <button type="submit" class="btn btn-primary">Enregistrer les invités</button>
                        <?php endif; ?>
                    </form>
                </section>
            <?php endif; ?>

            <div style="display:flex; gap: var(--spacing-sm); flex-wrap: wrap;">
                <a href="player/dashboard.php" class="btn btn-secondary">← Mon espace</a>
                <?php if ($order['status'] === 'free' && !$event_started): ?>
                    <form method="POST" onsubmit="return confirm('Annuler cette réservation et libérer toutes ses places ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                        <input type="hidden" name="action" value="cancel_free">
                        <button type="submit" class="btn btn-danger">Annuler la réservation</button>
                    </form>
                <?php elseif ($order['status'] === 'paid' && !$event_started): ?>
                    <p class="no-data">Pour annuler une place payée, contacte un organisateur (<?= e(ASSO_EMAIL) ?>).</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
