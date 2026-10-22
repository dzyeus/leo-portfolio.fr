<?php
/**
 * Reçu de paiement d'une réservation (PDF), pour l'acheteur ou un admin.
 */
require_once 'config/database.php';
require_once 'config/session.php';
requireLogin();

$order = order_get($pdo, (int)($_GET['order'] ?? 0));

if (!$order || ((int)$order['user_id'] !== current_user_id() && !isAdmin())
    || !in_array($order['status'], ['paid', 'refunded'], true)) {
    http_response_code(404);
    exit('Reçu introuvable.');
}

try {
    $pdf = receipt_pdf($order, order_places($pdo, (int)$order['id']));
} catch (Throwable $e) {
    error_log('Génération reçu : ' . $e->getMessage());
    http_response_code(500);
    exit('Le reçu n\'a pas pu être généré. Réessaie plus tard.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="recu-' . $order['receipt_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
