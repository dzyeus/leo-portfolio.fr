<?php
/**
 * Téléchargement des billets (PDF généré à la demande).
 *   ticket.php?order=ID         → tous les billets prêts de la réservation
 *   ticket.php?registration=ID  → le billet d'une seule place
 * Accessible à l'acheteur, au joueur concerné, ou à un admin.
 */
require_once 'config/database.php';
require_once 'config/session.php';
requireLogin();

$me = current_user_id();
$registrationIds = [];

if (isset($_GET['order'])) {
    $order = order_get($pdo, (int)$_GET['order']);
    if ($order && ((int)$order['user_id'] === $me || isAdmin()) && in_array($order['status'], ['paid', 'free'], true)) {
        $registrationIds = array_column(array_filter(order_places($pdo, (int)$order['id']), 'place_is_complete'), 'id');
    }
    $filename = 'billets-' . (int)$_GET['order'] . '.pdf';
} else {
    $registrationId = (int)($_GET['registration'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, o.user_id AS buyer_id,
               COALESCE(r.first_name, u.first_name) AS first_name,
               COALESCE(r.last_name, u.last_name)   AS last_name,
               COALESCE(r.pseudo, u.pseudo)         AS pseudo
        FROM registrations r
        LEFT JOIN orders o ON o.id = r.order_id
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
    ");
    $stmt->execute([$registrationId]);
    $place = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($place && ((int)$place['user_id'] === $me || (int)$place['buyer_id'] === $me || isAdmin())
        && (place_is_complete($place) || $place['user_id'])) {
        $registrationIds = [$place['id']];
    }
    $filename = 'billet-' . $registrationId . '.pdf';
}

if (!$registrationIds) {
    http_response_code(404);
    exit('Billet introuvable ou pas encore prêt (les infos de l\'invité doivent être complétées).');
}

try {
    $pdf = tickets_pdf($pdo, $registrationIds);
} catch (Throwable $e) {
    error_log('Génération billets : ' . $e->getMessage());
    http_response_code(500);
    exit('Le billet n\'a pas pu être généré. Réessaie plus tard ou contacte un organisateur.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
