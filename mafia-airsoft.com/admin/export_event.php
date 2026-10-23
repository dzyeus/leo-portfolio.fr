<?php
/**
 * Export de la liste des inscrits d'une partie (fichier CSV qui s'ouvre dans Excel / LibreOffice).
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(404);
    exit('Partie introuvable.');
}

$stmt = $pdo->prepare("
    SELECT COALESCE(et.team_name, r.team) AS equipe,
           COALESCE(r.pseudo, u.pseudo) AS pseudo,
           COALESCE(r.first_name, u.first_name) AS prenom,
           COALESCE(r.last_name, u.last_name) AS nom,
           COALESCE(u.email, o.buyer_email) AS email,
           u.phone AS telephone,
           IF(r.user_id IS NULL, CONCAT('Invité de ', COALESCE(o.buyer_name, '?')), 'Compte joueur') AS type,
           o.id AS reservation, o.status AS paiement,
           t.code AS billet, t.scanned_at AS present_a
    FROM registrations r
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN orders o ON o.id = r.order_id
    LEFT JOIN tickets t ON t.registration_id = r.id
    LEFT JOIN event_teams et ON et.event_id = r.event_id AND et.team_key = r.team
    WHERE r.event_id = ?
    ORDER BY et.display_order, pseudo
");
$stmt->execute([$event_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $event['title']) ?: 'partie')), '-');
$filename = 'inscrits-' . date('Y-m-d', strtotime($event['event_date'])) . '-' . ($slug ?: 'partie') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel

/** Neutralise les formules (=, +, -, @) qu'Excel exécuterait. */
function csv_safe($value): string
{
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

fputcsv($out, ['Équipe', 'Pseudo', 'Prénom', 'Nom', 'Email', 'Téléphone', 'Type', 'Réservation', 'Paiement', 'Billet', 'Présent'], ';');
foreach ($rows as $r) {
    fputcsv($out, array_map('csv_safe', [
        $r['equipe'], $r['pseudo'] ?: 'Invité (à compléter)', $r['prenom'], $r['nom'], $r['email'], $r['telephone'],
        $r['type'], $r['reservation'] ? 'n° ' . $r['reservation'] : '', $r['paiement'] ? order_status_label($r['paiement']) : '',
        $r['billet'], $r['present_a'] ? 'Oui (' . date('H:i', strtotime($r['present_a'])) . ')' : 'Non',
    ]), ';');
}
fclose($out);
