<?php
/**
 * Billets d'entrée : un billet (QR code unique) par place.
 * Les PDF sont générés à la demande (rien n'est stocké en fichier public).
 */

function ticket_generate_code(): string
{
    return 'MAT-' . strtoupper(bin2hex(random_bytes(12)));
}

/** Date en français : "samedi 15 novembre 2026 à 09h00". */
function date_fr(string $datetime, bool $withTime = true): string
{
    $ts = strtotime($datetime);
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
               'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $text = $days[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    return $withTime ? $text . ' à ' . date('H\hi', $ts) : $text;
}

/** Crée le billet d'une place s'il n'existe pas encore. */
function ticket_ensure(PDO $pdo, int $registrationId): void
{
    $pdo->prepare("
        INSERT IGNORE INTO tickets (registration_id, event_id, code)
        SELECT r.id, r.event_id, ? FROM registrations r WHERE r.id = ?
    ")->execute([ticket_generate_code(), $registrationId]);
}

/** Données complètes des billets pour une liste de places. */
function tickets_data(PDO $pdo, array $registrationIds): array
{
    $registrationIds = array_values(array_unique(array_map('intval', $registrationIds)));
    if (!$registrationIds) {
        return [];
    }
    foreach ($registrationIds as $id) {
        ticket_ensure($pdo, $id);
    }
    $in = implode(',', array_fill(0, count($registrationIds), '?'));
    $stmt = $pdo->prepare("
        SELECT t.code, t.scanned_at, r.id AS registration_id, r.order_id, r.team,
               COALESCE(r.first_name, u.first_name) AS first_name,
               COALESCE(r.last_name, u.last_name)   AS last_name,
               COALESCE(r.pseudo, u.pseudo)         AS pseudo,
               e.title AS event_title, e.event_date, e.location AS event_location,
               COALESCE(et.team_name, r.team) AS team_name, COALESCE(et.team_color, '#a3a3a3') AS team_color
        FROM registrations r
        JOIN tickets t ON t.registration_id = r.id
        JOIN events e ON e.id = r.event_id
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN event_teams et ON et.event_id = r.event_id AND et.team_key = r.team
        WHERE r.id IN ($in)
        ORDER BY (r.user_id IS NULL), r.id
    ");
    $stmt->execute($registrationIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return [163, 163, 163];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Crée un document TCPDF vierge, configuré pour le site. */
function pdf_new(string $title): TCPDF
{
    require_vendor();
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(SITE_NAME);
    $pdf->SetAuthor(ASSO_NAME);
    $pdf->SetTitle($title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(false);
    return $pdf;
}

/** Bandeau du haut (logo + nom de l'asso) commun aux billets et reçus. */
function pdf_header_band(TCPDF $pdf, string $label): void
{
    $pdf->SetFillColor(15, 15, 15);
    $pdf->Rect(0, 0, 210, 34, 'F');
    $pdf->SetFillColor(220, 38, 38);
    $pdf->Rect(0, 34, 210, 1.5, 'F');

    $logo = MAT_ROOT . '/images/logo.png';
    $textX = 15;
    if (is_file($logo)) {
        try {
            // Image passée en mémoire ('@') : TCPDF ne touche jamais au fichier d'origine
            $pdf->Image('@' . file_get_contents($logo), 15, 6, 22, 22, '', '', '', true, 300, '', false, false, 0, 'CM');
            $textX = 42;
        } catch (Throwable $e) {
            // Logo illisible : on continue sans
        }
    }
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetXY($textX, 9);
    $pdf->Cell(100, 8, 'MAFIA AIRSOFT TEAM', 0, 2);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(239, 68, 68);
    $pdf->Cell(100, 6, 'Memento Mori', 0, 0);

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetXY(110, 12);
    $pdf->Cell(85, 8, $label, 0, 0, 'R');
}

/** PDF des billets (une page par place). Renvoie le contenu binaire du PDF. */
function tickets_pdf(PDO $pdo, array $registrationIds): string
{
    $tickets = tickets_data($pdo, $registrationIds);
    if (!$tickets) {
        throw new RuntimeException('Aucun billet à générer.');
    }

    $pdf = pdf_new('Billets – ' . $tickets[0]['event_title']);
    foreach ($tickets as $t) {
        $pdf->AddPage();
        pdf_header_band($pdf, "BILLET D'ENTRÉE");

        // Partie
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('dejavusans', 'B', 20);
        $pdf->SetXY(15, 46);
        $pdf->MultiCell(180, 10, $t['event_title'], 0, 'L');

        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetX(15);
        $pdf->Cell(180, 7, ucfirst(date_fr($t['event_date'])), 0, 1);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 6, 'Lieu : ' . $t['event_location'], 0, 'L');

        // Bloc participant
        $y = $pdf->GetY() + 6;
        [$r, $g, $b] = hex_to_rgb($t['team_color']);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Rect(15, $y, 180, 34, 'F');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect(15, $y, 3, 34, 'F');

        $pdf->SetTextColor(115, 115, 115);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(23, $y + 4);
        $pdf->Cell(90, 5, 'PARTICIPANT', 0, 0);
        $pdf->SetXY(120, $y + 4);
        $pdf->Cell(70, 5, 'ÉQUIPE', 0, 0);

        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetXY(23, $y + 10);
        $participant = full_name($t['first_name'], mb_strtoupper((string)$t['last_name']));
        $pdf->Cell(95, 8, $participant !== '' ? $participant : (string)$t['pseudo'], 0, 0);
        $pdf->SetXY(120, $y + 10);
        $pdf->Cell(70, 8, $t['team_name'], 0, 0);

        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY(23, $y + 19);
        $pdf->Cell(95, 7, 'Pseudo : ' . $t['pseudo'], 0, 0);

        // QR code
        $qrSize = 80;
        $qrY = $y + 46;
        $pdf->write2DBarcode($t['code'], 'QRCODE,M', (210 - $qrSize) / 2, $qrY, $qrSize, $qrSize, [
            'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false,
        ], 'N');

        $pdf->SetFont('dejavusansmono', 'B', 12);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetXY(15, $qrY + $qrSize + 4);
        $pdf->Cell(180, 7, $t['code'], 0, 1, 'C');

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(115, 115, 115);
        $pdf->SetX(30);
        $pdf->MultiCell(150, 5,
            "Présente ce QR code à l'entrée (sur ton téléphone ou imprimé).\n"
            . "Billet nominatif, valable une seule fois. Protection oculaire obligatoire.", 0, 'C');

        // Pied de page
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line(15, 280, 195, 280);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(15, 282);
        $pdf->Cell(90, 5, ASSO_NAME . ' · ' . ASSO_EMAIL, 0, 0);
        $pdf->Cell(90, 5, $t['order_id'] ? 'Réservation n° ' . $t['order_id'] : '', 0, 0, 'R');
    }
    return $pdf->Output('billets.pdf', 'S');
}
