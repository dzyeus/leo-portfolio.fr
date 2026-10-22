<?php
/**
 * Reçu de paiement (PDF) d'une commande payée.
 */

function receipt_pdf(array $order, array $places = []): string
{
    if ($order['status'] !== 'paid' && $order['status'] !== 'refunded') {
        throw new RuntimeException('Pas de reçu pour une commande non payée.');
    }

    $pdf = pdf_new('Reçu ' . $order['receipt_number']);
    $pdf->AddPage();
    pdf_header_band($pdf, 'REÇU DE PAIEMENT');

    // Association (émetteur)
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetXY(15, 46);
    $pdf->Cell(90, 6, ASSO_NAME, 0, 2);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $assoLines = array_filter([
        ASSO_ADDRESS,
        ASSO_RNA !== '' ? 'RNA : ' . ASSO_RNA : '',
        ASSO_SIRET !== '' ? 'SIRET : ' . ASSO_SIRET : '',
        ASSO_EMAIL,
    ]);
    $pdf->MultiCell(90, 5, implode("\n", $assoLines), 0, 'L');
    $leftBottom = $pdf->GetY();

    // Numéro et date
    $pdf->SetXY(110, 46);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->Cell(85, 6, 'Reçu n° ' . $order['receipt_number'], 0, 2, 'R');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(85, 5, 'Date de paiement : ' . date('d/m/Y', strtotime($order['paid_at'] ?? $order['created_at'])), 0, 2, 'R');
    $pdf->Cell(85, 5, 'Réservation n° ' . $order['id'], 0, 2, 'R');

    // Client
    $y = max($leftBottom, $pdf->GetY()) + 8;
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Rect(15, $y, 180, 18, 'F');
    $pdf->SetXY(20, $y + 3);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->Cell(170, 4, 'PAYÉ PAR', 0, 2);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->Cell(170, 6, $order['buyer_name'] . '  ·  ' . $order['buyer_email'], 0, 2);

    // Détail
    $y += 26;
    $pdf->SetXY(15, $y);
    $pdf->SetFillColor(26, 26, 26);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->Cell(105, 8, '  Désignation', 0, 0, 'L', true);
    $pdf->Cell(20, 8, 'Qté', 0, 0, 'C', true);
    $pdf->Cell(27, 8, 'Prix unitaire', 0, 0, 'R', true);
    $pdf->Cell(28, 8, 'Total  ', 0, 1, 'R', true);

    $label = "Place – Partie d'airsoft\n" . $order['event_title'] . "\n"
        . ucfirst(date_fr($order['event_date'])) . ' · ' . ($order['team_name'] ?? $order['team_key']);

    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('dejavusans', '', 9);
    $rowY = $pdf->GetY() + 2;
    $pdf->SetXY(17, $rowY);
    $pdf->MultiCell(101, 5, $label, 0, 'L');
    $rowBottom = $pdf->GetY();
    $pdf->SetXY(120, $rowY);
    $pdf->Cell(20, 5, (string)$order['quantity'], 0, 0, 'C');
    $pdf->Cell(27, 5, format_price((int)$order['unit_price_cents']), 0, 0, 'R');
    $pdf->Cell(26, 5, format_price((int)$order['amount_cents']), 0, 0, 'R');

    $y = $rowBottom + 3;
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->Line(15, $y, 195, $y);

    $pdf->SetXY(110, $y + 3);
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(57, 8, 'Total payé', 0, 0, 'R');
    $pdf->Cell(28, 8, format_price((int)$order['amount_cents']), 0, 1, 'R');

    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->SetX(110);
    $pdf->Cell(85, 5, ASSO_VAT_MENTION, 0, 1, 'R');
    $pdf->SetX(110);
    $pdf->Cell(85, 5, 'Payé par carte bancaire (Stripe)', 0, 1, 'R');

    // Participants
    if ($places) {
        $pdf->SetXY(15, $pdf->GetY() + 8);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->Cell(180, 6, 'Participants', 0, 1);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        foreach ($places as $i => $place) {
            $name = place_is_complete($place)
                ? full_name($place['first_name'], $place['last_name']) . ' (' . $place['pseudo'] . ')'
                : 'Invité ' . ($i + 1) . ' – à compléter';
            $pdf->SetX(15);
            $pdf->Cell(180, 5, '• ' . $name, 0, 1);
        }
    }

    if ($order['status'] === 'refunded') {
        $pdf->SetXY(15, 240);
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(180, 10, 'COMMANDE REMBOURSÉE', 0, 1, 'C');
    }

    $pdf->SetDrawColor(220, 220, 220);
    $pdf->Line(15, 280, 195, 280);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(115, 115, 115);
    $pdf->SetXY(15, 282);
    $pdf->Cell(180, 5, ASSO_NAME . ' – association loi 1901 · Merci et à bientôt sur le terrain !', 0, 0, 'C');

    return $pdf->Output('recu.pdf', 'S');
}
