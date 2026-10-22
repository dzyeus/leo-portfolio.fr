<?php
/**
 * Alertes email : prévenir les joueurs abonnés quand une nouvelle partie
 * est ouverte aux inscriptions. Abonnement facultatif (case à cocher),
 * désabonnement en un clic depuis chaque email.
 */

/** Clé secrète du site (signature des liens de désabonnement). */
function app_secret(): string
{
    if (APP_SECRET !== '' && !str_contains(APP_SECRET, 'A_REMPLACER')) {
        return APP_SECRET;
    }
    // Repli si la clé n'est pas configurée : dérivée d'éléments propres au serveur
    return hash('sha256', 'mat|' . STRIPE_WEBHOOK_SECRET . '|' . STRIPE_SECRET_KEY . '|' . MAT_ROOT);
}

function unsubscribe_token(int $userId): string
{
    return hash_hmac('sha256', 'unsubscribe|' . $userId, app_secret());
}

function unsubscribe_url(int $userId): string
{
    return SITE_URL . '/unsubscribe.php?u=' . $userId . '&t=' . unsubscribe_token($userId);
}

/** Une partie peut-elle être annoncée ? (active, inscriptions ouvertes, à venir) */
function event_can_be_announced(array $event): bool
{
    return event_registration_is_open($event);
}

/**
 * Envoie l'annonce d'une partie à tous les joueurs abonnés (email confirmé).
 * Renvoie le nombre d'emails envoyés.
 */
function announce_event(PDO $pdo, int $eventId): int
{
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event || !event_can_be_announced($event)) {
        return 0;
    }

    // Marqué tout de suite : évite un double envoi si l'admin clique deux fois
    $pdo->prepare("UPDATE events SET announced_at = NOW() WHERE id = ?")->execute([$eventId]);

    $users = $pdo->query("
        SELECT id, pseudo, email FROM users
        WHERE notify_new_events = 1 AND email_verified_at IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    @set_time_limit(300);
    $eventUrl = SITE_URL . '/event.php?id=' . $eventId;
    $price = event_is_free($event) ? 'Gratuit' : format_price((int)$event['price_cents']) . ' par place';
    $description = trim((string)$event['description']);
    if (mb_strlen($description) > 300) {
        $description = mb_substr($description, 0, 300) . '…';
    }

    $sent = 0;
    foreach ($users as $user) {
        $unsubscribe = unsubscribe_url((int)$user['id']);
        $html = '<h2 style="color:#ffffff;margin-top:0;">Nouvelle partie : ' . e($event['title']) . '</h2>'
            . '<p>Salut ' . e($user['pseudo']) . ', une nouvelle partie est ouverte aux inscriptions !</p>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:16px 0;font-size:15px;">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#a3a3a3;">📅 Date</td><td>' . e(ucfirst(date_fr($event['event_date']))) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#a3a3a3;">📍 Lieu</td><td>' . e($event['location']) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#a3a3a3;">💶 Prix</td><td>' . e($price) . '</td></tr>'
            . '</table>'
            . ($description !== '' ? '<p>' . nl2br(e($description)) . '</p>' : '')
            . mail_button($eventUrl, 'Voir la partie et m\'inscrire')
            . '<p style="font-size:12px;color:#737373;">Tu reçois cet email car tu as demandé à être prévenu des nouvelles parties. '
            . '<a href="' . e($unsubscribe) . '" style="color:#a3a3a3;">Ne plus recevoir ces alertes</a>.</p>';

        $ok = send_mail($user['email'], $user['pseudo'], 'Nouvelle partie : ' . $event['title'], $html, [], [
            'List-Unsubscribe'      => '<' . $unsubscribe . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
        if ($ok) {
            $sent++;
        }
    }

    $pdo->prepare("UPDATE events SET announced_count = ? WHERE id = ?")->execute([$sent, $eventId]);
    return $sent;
}

/** Nombre de joueurs abonnés aux alertes (affiché dans l'admin). */
function subscribers_count(PDO $pdo): int
{
    return (int)$pdo->query("
        SELECT COUNT(*) FROM users WHERE notify_new_events = 1 AND email_verified_at IS NOT NULL
    ")->fetchColumn();
}
