<?php
/**
 * API de validation des billets scannés à l'entrée (admins uniquement).
 * Appel en POST depuis scan.php, avec le jeton CSRF dans l'en-tête X-CSRF-Token.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn() || !isAdmin()) {
    respond(['success' => false, 'status' => 'error',
             'error' => 'Accès non autorisé. Seuls les administrateurs peuvent scanner les billets.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    respond(['success' => false, 'status' => 'error', 'error' => 'Session expirée : recharge la page du scanner.'], 400);
}

$code = strtoupper(trim((string)($_POST['code'] ?? '')));
// Anciens QR codes qui contenaient une URL : on extrait le code
if (preg_match('/CODE=([A-Z0-9\-]+)/', $code, $m)) {
    $code = $m[1];
}
if ($code === '' || strlen($code) > 64) {
    respond(['success' => false, 'status' => 'invalid', 'error' => 'Code du billet manquant ou invalide']);
}

try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.code, t.scanned_at,
               e.title AS event_title, e.event_date,
               COALESCE(r.first_name, u.first_name) AS first_name,
               COALESCE(r.last_name, u.last_name)   AS last_name,
               COALESCE(r.pseudo, u.pseudo)         AS pseudo,
               COALESCE(et.team_name, r.team) AS team_name, COALESCE(et.team_color, '#a3a3a3') AS team_color,
               s.pseudo AS scanned_by_name
        FROM tickets t
        JOIN registrations r ON r.id = t.registration_id
        JOIN events e ON e.id = t.event_id
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN event_teams et ON et.event_id = r.event_id AND et.team_key = r.team
        LEFT JOIN users s ON s.id = t.scanned_by
        WHERE t.code = ?
    ");
    $stmt->execute([$code]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        respond(['success' => false, 'status' => 'invalid', 'error' => 'Billet invalide ou introuvable (place annulée ou remboursée ?)']);
    }

    $name = full_name($ticket['first_name'], $ticket['last_name']);
    $info = [
        'code'        => $ticket['code'],
        'event'       => $ticket['event_title'],
        'event_date'  => date('d/m/Y à H:i', strtotime($ticket['event_date'])),
        'participant' => $name !== '' ? $name : 'Invité non renseigné',
        'pseudo'      => $ticket['pseudo'] ?: '-',
        'team'        => $ticket['team_name'],
        'team_color'  => $ticket['team_color'],
    ];

    if ($ticket['scanned_at']) {
        $info['scanned_at'] = date('d/m/Y à H:i', strtotime($ticket['scanned_at']));
        $info['scanned_by'] = $ticket['scanned_by_name'] ?? '-';
        respond(['success' => false, 'status' => 'already_scanned', 'error' => 'Ce billet a déjà été scanné', 'ticket' => $info]);
    }

    $eventTs = strtotime($ticket['event_date']);
    if ($eventTs < time() - 86400) {
        respond(['success' => false, 'status' => 'expired', 'error' => 'La partie est terminée depuis plus de 24 h', 'ticket' => $info]);
    }
    if ($eventTs > time() + 86400) {
        respond(['success' => false, 'status' => 'too_early', 'error' => 'Ce billet est pour une partie qui n\'a pas lieu aujourd\'hui', 'ticket' => $info]);
    }

    // Validation atomique : impossible de valider deux fois le même billet
    $stmt = $pdo->prepare("UPDATE tickets SET scanned_at = NOW(), scanned_by = ? WHERE id = ? AND scanned_at IS NULL");
    $stmt->execute([current_user_id(), $ticket['id']]);
    if ($stmt->rowCount() !== 1) {
        respond(['success' => false, 'status' => 'already_scanned', 'error' => 'Ce billet vient d\'être scanné', 'ticket' => $info]);
    }

    $info['scanned_at'] = date('d/m/Y à H:i');
    $info['scanned_by'] = $_SESSION['pseudo'] ?? '';
    $warning = $name === '' ? 'Invité non renseigné : vérifie son identité.' : null;

    respond(['success' => true, 'status' => 'valid', 'message' => 'Entrée validée !', 'warning' => $warning, 'ticket' => $info]);
} catch (Throwable $e) {
    error_log('validate_ticket.php : ' . $e->getMessage());
    respond(['success' => false, 'status' => 'error', 'error' => 'Erreur serveur, réessaie.'], 500);
}
