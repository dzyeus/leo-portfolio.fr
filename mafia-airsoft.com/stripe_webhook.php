<?php
/**
 * Webhook Stripe : c'est lui qui confirme les paiements.
 *
 * À déclarer dans Stripe (Développeurs > Webhooks > Ajouter un endpoint) :
 *   URL : https://mafia-airsoft.com/stripe_webhook.php
 *   Événements : checkout.session.completed
 *                checkout.session.async_payment_succeeded
 *                checkout.session.expired
 *                charge.refunded
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/lib/bootstrap.php';

$payload = file_get_contents('php://input');
$event = stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if (!$event) {
    http_response_code(400);
    exit('Signature invalide');
}

$object = $event['data']['object'] ?? [];

try {
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            order_complete_from_stripe($pdo, $object);
            break;

        case 'checkout.session.expired':
            order_expire_by_session($pdo, (string)($object['id'] ?? ''));
            break;

        case 'charge.refunded':
            // Remboursement total uniquement (un remboursement partiel ne libère pas les places)
            if (!empty($object['refunded']) && !empty($object['payment_intent'])) {
                order_refund_by_payment_intent($pdo, (string)$object['payment_intent']);
            }
            break;
    }
} catch (Throwable $e) {
    error_log('Webhook Stripe (' . ($event['type'] ?? '?') . ') : ' . $e->getMessage());
    http_response_code(500); // Stripe réessaiera plus tard
    exit;
}

http_response_code(200);
echo 'ok';
