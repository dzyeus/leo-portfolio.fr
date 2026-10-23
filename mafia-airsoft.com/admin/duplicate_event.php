<?php
/**
 * Duplique une partie (même contenu, mêmes équipes) une semaine plus tard.
 * La copie est créée MASQUÉE et inscriptions fermées : l'admin vérifie la date
 * puis l'active depuis « Modifier ».
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    flash_set('error', 'Partie introuvable.');
    header('Location: events.php');
    exit;
}

// Même heure, 7 jours après la date d'origine (ou après aujourd'hui si elle est passée)
$base = max(strtotime($event['event_date']), strtotime(date('Y-m-d') . ' ' . date('H:i:s', strtotime($event['event_date']))));
$newDate = date('Y-m-d H:i:s', strtotime('+7 days', $base));

$pdo->beginTransaction();
try {
    $pdo->prepare("
        INSERT INTO events (title, description, scenario, event_date, location, price_cents,
                            equipment_required, rules, is_active, registration_open)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
    ")->execute([
        mb_substr($event['title'] . ' (copie)', 0, 150), $event['description'], $event['scenario'], $newDate,
        $event['location'], (int)($event['price_cents'] ?? 0), $event['equipment_required'], $event['rules'],
    ]);
    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO event_teams (event_id, team_key, team_name, team_color, max_players, display_order)
        SELECT ?, team_key, team_name, team_color, max_players, display_order FROM event_teams WHERE event_id = ?
    ")->execute([$newId, $id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Duplication partie ' . $id . ' : ' . $e->getMessage());
    flash_set('error', 'La copie n\'a pas pu être créée.');
    header('Location: events.php');
    exit;
}

flash_set('success', 'Copie créée le ' . date('d/m/Y', strtotime($newDate)) . ', masquée et inscriptions fermées. '
    . 'Vérifie le titre et la date, puis coche « Partie active » et « Inscriptions ouvertes ».');
header('Location: edit_event.php?id=' . $newId);
exit;
