<?php
/**
 * Commandes de places : réservation, paiement Stripe, création des places et billets.
 *
 * Cycle de vie d'une commande :
 *   pending  → le joueur est sur la page de paiement Stripe (places bloquées CHECKOUT_HOLD_MINUTES)
 *   paid     → paiement confirmé par Stripe : les places et billets sont créés
 *   free     → partie gratuite : places et billets créés directement
 *   expired  → paiement abandonné : places libérées
 *   refunded → remboursé depuis Stripe : places et billets supprimés
 *   cancelled→ annulé (partie gratuite annulée par le joueur, ou erreur au démarrage du paiement)
 */

/** Erreur à afficher telle quelle au joueur. */
class OrderException extends RuntimeException
{
}

function event_is_free(array $event): bool
{
    return (int)($event['price_cents'] ?? 0) <= 0;
}

function event_has_started(array $event): bool
{
    return strtotime($event['event_date']) <= time();
}

function event_registration_is_open(array $event): bool
{
    return !empty($event['is_active']) && !empty($event['registration_open']) && !event_has_started($event);
}

/** Places prises dans une équipe : inscrits + paiements en cours non expirés. */
function team_taken_places(PDO $pdo, int $eventId, string $teamKey): int
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM registrations WHERE event_id = ? AND team = ?)
          + (SELECT COALESCE(SUM(quantity), 0) FROM orders
             WHERE event_id = ? AND team_key = ? AND status = 'pending' AND expires_at > NOW())
    ");
    $stmt->execute([$eventId, $teamKey, $eventId, $teamKey]);
    return (int)$stmt->fetchColumn();
}

function generate_receipt_number(int $orderId): string
{
    return sprintf('MAT-%s-%05d', date('Y'), $orderId);
}

/**
 * Crée une commande de $quantity places dans une équipe.
 * Partie gratuite : les places sont créées tout de suite.
 * Partie payante : les places sont bloquées le temps du paiement.
 */
function order_create(PDO $pdo, array $user, array $event, string $teamKey, int $quantity): array
{
    if ($quantity < 1 || $quantity > MAX_PLACES_PER_ORDER) {
        throw new OrderException('Nombre de places invalide (1 à ' . MAX_PLACES_PER_ORDER . ').');
    }
    if (!event_registration_is_open($event)) {
        throw new OrderException('Les inscriptions sont fermées pour cette partie.');
    }
    if (!profile_is_complete($user)) {
        throw new OrderException('Renseigne ton nom et ton prénom dans ton profil avant de réserver.');
    }

    $isFree = event_is_free($event);
    $unitPrice = $isFree ? 0 : (int)$event['price_cents'];

    $pdo->beginTransaction();
    try {
        // Verrou sur l'équipe : deux joueurs ne peuvent pas prendre la dernière place en même temps
        $stmt = $pdo->prepare("SELECT * FROM event_teams WHERE event_id = ? AND team_key = ? FOR UPDATE");
        $stmt->execute([$event['id'], $teamKey]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$team) {
            throw new OrderException('Cette équipe n\'existe pas.');
        }

        $free = (int)$team['max_players'] - team_taken_places($pdo, (int)$event['id'], $teamKey);
        if ($free <= 0) {
            throw new OrderException('Cette équipe est complète.');
        }
        if ($quantity > $free) {
            throw new OrderException('Il ne reste que ' . $free . ' place(s) dans cette équipe.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO orders (user_id, event_id, team_key, quantity, unit_price_cents, amount_cents,
                                status, buyer_name, buyer_email, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($isFree ? 'NULL' : 'NOW() + INTERVAL ? MINUTE') . ")
        ");
        $params = [
            $user['id'], $event['id'], $teamKey, $quantity, $unitPrice, $unitPrice * $quantity,
            $isFree ? 'free' : 'pending',
            full_name($user['first_name'], $user['last_name']), $user['email'],
        ];
        if (!$isFree) {
            // Un peu plus que la session Stripe, pour ne jamais libérer une place encore payable
            $params[] = CHECKOUT_HOLD_MINUTES + 5;
        }
        $stmt->execute($params);
        $orderId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($isFree) {
        order_fulfill($pdo, $orderId);
    }
    return order_get($pdo, $orderId);
}

/** Lance le paiement Stripe d'une commande et renvoie l'URL de la page de paiement. */
function order_start_checkout(PDO $pdo, array $order, array $event): string
{
    $lineItem = [
        'quantity'   => (int)$order['quantity'],
        'price_data' => [
            'currency'    => 'eur',
            'unit_amount' => (int)$order['unit_price_cents'],
        ],
    ];
    if (STRIPE_PRODUCT_ID !== '') {
        $lineItem['price_data']['product'] = STRIPE_PRODUCT_ID;
    } else {
        $lineItem['price_data']['product_data'] = ['name' => 'Place – Partie d\'airsoft'];
    }

    $description = mb_substr('Partie : ' . $event['title'] . ' (' . date('d/m/Y', strtotime($event['event_date'])) . ')', 0, 250);

    try {
        $session = stripe_request('POST', 'checkout/sessions', [
            'mode'                => 'payment',
            'line_items'          => [$lineItem],
            'client_reference_id' => (string)$order['id'],
            'customer_email'      => $order['buyer_email'],
            'metadata'            => ['order_id' => $order['id'], 'event_id' => $event['id']],
            'payment_intent_data' => [
                'description' => $description,
                'metadata'    => ['order_id' => $order['id']],
            ],
            'custom_text'         => [
                'submit' => ['message' => $description . ' – ' . $order['quantity'] . ' place(s)'],
            ],
            'expires_at'          => time() + CHECKOUT_HOLD_MINUTES * 60,
            'locale'              => 'fr',
            'success_url'         => SITE_URL . '/order.php?id=' . $order['id'] . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => SITE_URL . '/order.php?id=' . $order['id'] . '&cancelled=1',
        ]);
    } catch (Throwable $e) {
        // Le paiement n'a pas pu démarrer : on libère les places
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
            ->execute([$order['id']]);
        error_log('Création session Stripe (commande ' . $order['id'] . ') : ' . $e->getMessage());
        throw new OrderException('Le paiement n\'a pas pu démarrer. Réessaie dans un instant.');
    }

    $pdo->prepare("UPDATE orders SET stripe_session_id = ? WHERE id = ?")
        ->execute([$session['id'], $order['id']]);

    return $session['url'];
}

/**
 * Valide une commande : passe en payée (si besoin) et crée les places + billets.
 * Sans effet si c'est déjà fait. Renvoie true uniquement la première fois
 * (pour n'envoyer l'email de confirmation qu'une fois).
 */
function order_fulfill(PDO $pdo, int $orderId, ?string $paymentIntent = null): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $alreadyDone = (int)$stmt->fetchColumn() > 0;

        if ($alreadyDone || in_array($order['status'], ['refunded', 'cancelled'], true)) {
            $pdo->commit();
            return false;
        }

        if ($order['status'] !== 'free') {
            $pdo->prepare("
                UPDATE orders
                SET status = 'paid', paid_at = NOW(), stripe_payment_intent = COALESCE(?, stripe_payment_intent),
                    receipt_number = ?
                WHERE id = ?
            ")->execute([$paymentIntent, generate_receipt_number($orderId), $orderId]);
        }

        // La 1re place est pour l'acheteur, s'il n'est pas déjà inscrit à cette partie
        $buyer = null;
        if ($order['user_id']) {
            $stmt = $pdo->prepare("
                SELECT u.* FROM users u
                WHERE u.id = ? AND NOT EXISTS (
                    SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.event_id = ?
                )
            ");
            $stmt->execute([$order['user_id'], $order['event_id']]);
            $buyer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $insertPlace = $pdo->prepare("
            INSERT INTO registrations (user_id, event_id, order_id, team, first_name, last_name, pseudo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertTicket = $pdo->prepare("INSERT INTO tickets (registration_id, event_id, code) VALUES (?, ?, ?)");

        for ($i = 0; $i < (int)$order['quantity']; $i++) {
            if ($i === 0 && $buyer) {
                $insertPlace->execute([$buyer['id'], $order['event_id'], $orderId, $order['team_key'],
                                       $buyer['first_name'], $buyer['last_name'], $buyer['pseudo']]);
            } else {
                // Invité : nom, prénom et pseudo à compléter par l'acheteur
                $insertPlace->execute([null, $order['event_id'], $orderId, $order['team_key'], null, null, null]);
            }
            $insertTicket->execute([(int)$pdo->lastInsertId(), $order['event_id'], ticket_generate_code()]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Vérifie directement auprès de Stripe si une commande en attente a été payée
 * (utile quand le joueur revient avant que le webhook soit arrivé).
 */
function order_sync_with_stripe(PDO $pdo, array $order): array
{
    if ($order['status'] !== 'pending' || empty($order['stripe_session_id'])) {
        return $order;
    }
    try {
        $session = stripe_request('GET', 'checkout/sessions/' . rawurlencode($order['stripe_session_id']));
        if (($session['payment_status'] ?? '') === 'paid') {
            order_complete_from_stripe($pdo, $session);
        } elseif (($session['status'] ?? '') === 'expired') {
            order_expire_by_session($pdo, $session['id']);
        }
    } catch (Throwable $e) {
        error_log('Synchro Stripe commande ' . $order['id'] . ' : ' . $e->getMessage());
    }
    return order_get($pdo, (int)$order['id']);
}

/** Traite une session Checkout payée (webhook ou retour du joueur). */
function order_complete_from_stripe(PDO $pdo, array $session): void
{
    $orderId = (int)($session['client_reference_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND stripe_session_id = ?");
    $stmt->execute([$orderId, $session['id'] ?? '']);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        error_log('Stripe : commande introuvable pour la session ' . ($session['id'] ?? '?'));
        return;
    }
    if ((int)($session['amount_total'] ?? -1) !== (int)$order['amount_cents']
        || strtolower($session['currency'] ?? '') !== 'eur') {
        error_log('Stripe : montant incohérent pour la commande ' . $orderId);
        return;
    }
    if (($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    $paymentIntent = is_array($session['payment_intent'] ?? null)
        ? ($session['payment_intent']['id'] ?? null)
        : ($session['payment_intent'] ?? null);

    if (order_fulfill($pdo, $orderId, $paymentIntent)) {
        order_send_confirmation($pdo, $orderId);
    }
}

function order_expire_by_session(PDO $pdo, string $sessionId): void
{
    $pdo->prepare("UPDATE orders SET status = 'expired' WHERE stripe_session_id = ? AND status = 'pending'")
        ->execute([$sessionId]);
}

/** Remboursement total fait depuis Stripe : on supprime les places (et leurs billets). */
function order_refund_by_payment_intent(PDO $pdo, string $paymentIntent): void
{
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE stripe_payment_intent = ? AND status = 'paid'");
    $stmt->execute([$paymentIntent]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM registrations WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE orders SET status = 'refunded' WHERE id = ?")->execute([$orderId]);
        $pdo->commit();
    }
}

/** Annule une commande gratuite (le joueur se désinscrit avec ses invités). */
function order_cancel_free(PDO $pdo, int $orderId): void
{
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM registrations WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'free'")->execute([$orderId]);
    $pdo->commit();
}

// ---------------------------------------------------------------------
// Lecture
// ---------------------------------------------------------------------

function order_get(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare("
        SELECT o.*, e.title AS event_title, e.event_date, e.location AS event_location,
               et.team_name, et.team_color
        FROM orders o
        JOIN events e ON e.id = o.event_id
        LEFT JOIN event_teams et ON et.event_id = o.event_id AND et.team_key = o.team_key
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

/** Les places d'une commande, avec le billet de chacune. */
function order_places(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.event_id, r.team, r.registered_at,
               COALESCE(r.first_name, u.first_name) AS first_name,
               COALESCE(r.last_name, u.last_name)   AS last_name,
               COALESCE(r.pseudo, u.pseudo)         AS pseudo,
               t.code AS ticket_code, t.scanned_at
        FROM registrations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN tickets t ON t.registration_id = r.id
        WHERE r.order_id = ?
        ORDER BY (r.user_id IS NULL), r.id
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function place_is_complete(array $place): bool
{
    return trim((string)$place['first_name']) !== ''
        && trim((string)$place['last_name']) !== ''
        && trim((string)$place['pseudo']) !== '';
}

/** Libellé lisible d'un statut de commande. */
function order_status_label(string $status): string
{
    return [
        'pending'   => 'Paiement en attente',
        'paid'      => 'Payée',
        'free'      => 'Gratuite',
        'expired'   => 'Paiement abandonné',
        'refunded'  => 'Remboursée',
        'cancelled' => 'Annulée',
    ][$status] ?? $status;
}

// ---------------------------------------------------------------------
// Emails de commande
// ---------------------------------------------------------------------

/** Email après paiement / inscription : reçu + billets prêts + lien vers la commande. */
function order_send_confirmation(PDO $pdo, int $orderId): bool
{
    $order = order_get($pdo, $orderId);
    if (!$order) {
        return false;
    }
    $places = order_places($pdo, $orderId);
    $complete = array_values(array_filter($places, 'place_is_complete'));
    $missing = count($places) - count($complete);
    $url = SITE_URL . '/order.php?id=' . $orderId;

    $html = '<h2 style="color:#ffffff;margin-top:0;">' . ($order['status'] === 'paid' ? 'Paiement confirmé' : 'Inscription confirmée') . '</h2>'
        . '<p>Tu as réservé <strong>' . (int)$order['quantity'] . ' place(s)</strong> pour <strong>'
        . e($order['event_title']) . '</strong>, le ' . date('d/m/Y à H\hi', strtotime($order['event_date']))
        . ' (' . e($order['event_location']) . '), équipe <strong>' . e($order['team_name'] ?? $order['team_key']) . '</strong>.</p>';

    if ($order['status'] === 'paid') {
        $html .= '<p>Montant payé : <strong>' . format_price((int)$order['amount_cents']) . '</strong>. Ton reçu est joint à cet email.</p>';
    }
    if ($complete) {
        $html .= '<p>Les billets avec QR code sont joints : présente-les à l\'entrée (sur ton téléphone ou imprimés).</p>';
    }
    if ($missing > 0) {
        $html .= '<p style="color:#f59e0b;"><strong>Il reste ' . $missing . ' invité(s) à compléter</strong> (nom, prénom, pseudo) '
            . 'pour générer leurs billets.</p>';
    }
    $html .= mail_button($url, $missing > 0 ? 'Compléter mes invités' : 'Voir ma réservation');

    $attachments = [];
    try {
        if ($order['status'] === 'paid') {
            $attachments['recu-' . $order['receipt_number'] . '.pdf'] = receipt_pdf($order, $places);
        }
        if ($complete) {
            $attachments['billets-' . $orderId . '.pdf'] = tickets_pdf($pdo, array_column($complete, 'id'));
        }
    } catch (Throwable $e) {
        error_log('PDF commande ' . $orderId . ' : ' . $e->getMessage());
    }

    return send_mail($order['buyer_email'], $order['buyer_name'],
        'Ta réservation – ' . $order['event_title'], $html, $attachments);
}

/** Email avec les billets (après avoir complété les invités, ou à la demande). */
function order_send_tickets(PDO $pdo, int $orderId): bool
{
    $order = order_get($pdo, $orderId);
    $complete = array_values(array_filter(order_places($pdo, $orderId), 'place_is_complete'));
    if (!$order || !$complete) {
        return false;
    }
    $html = '<h2 style="color:#ffffff;margin-top:0;">Tes billets</h2>'
        . '<p>Voici les billets pour <strong>' . e($order['event_title']) . '</strong>, le '
        . date('d/m/Y à H\hi', strtotime($order['event_date'])) . '. Chaque participant présente son QR code à l\'entrée.</p>'
        . mail_button(SITE_URL . '/order.php?id=' . $orderId, 'Voir ma réservation');

    try {
        $pdf = tickets_pdf($pdo, array_column($complete, 'id'));
    } catch (Throwable $e) {
        error_log('PDF billets commande ' . $orderId . ' : ' . $e->getMessage());
        return false;
    }
    return send_mail($order['buyer_email'], $order['buyer_name'],
        'Tes billets – ' . $order['event_title'], $html, ['billets-' . $orderId . '.pdf' => $pdf]);
}
