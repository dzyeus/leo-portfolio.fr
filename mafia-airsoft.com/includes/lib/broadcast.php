<?php
/**
 * Messages de l'administrateur aux joueurs (admin/message.php) :
 * choix des destinataires, mise en forme, envoi un par un (personne ne voit
 * les adresses des autres), envoi par petits lots, historique.
 */

/** Nombre d'emails envoyés par requête (évite les délais dépassés chez l'hébergeur). */
const BROADCAST_BATCH = 15;
/** Nombre maximum d'adresses saisies à la main. */
const BROADCAST_MAX_CUSTOM = 500;

/** Libellés des destinataires possibles. */
function broadcast_target_labels(): array
{
    return [
        'all'         => 'Tous les joueurs',
        'subscribers' => 'Abonnés aux alertes',
        'event'       => 'Inscrits à une partie',
        'custom'      => 'Adresses précises',
    ];
}

/**
 * Découpe une liste d'adresses (séparées par des virgules, points-virgules,
 * espaces ou retours à la ligne). Accepte aussi « Nom <email> ».
 * Renvoie ['valid' => [...], 'invalid' => [...]] sans doublons.
 */
function broadcast_parse_emails(string $raw): array
{
    $valid = [];
    $invalid = [];
    // « Jean Dupont <jean@exemple.fr> » → « jean@exemple.fr »
    $raw = preg_replace('/[^,;\n<>]*<([^<>]*)>/u', ' $1 ', $raw);
    foreach (preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $part = trim($part, " \t<>\"'()");
        if ($part === '') {
            continue;
        }
        $email = mb_strtolower($part);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[$email] = true;
        } else {
            $invalid[$part] = true;
        }
    }
    return ['valid' => array_keys($valid), 'invalid' => array_keys($invalid)];
}

/**
 * Liste des destinataires : [['email', 'name', 'first_name', 'pseudo', 'user_id'], ...]
 * (une seule fois par adresse).
 */
function broadcast_recipients(PDO $pdo, string $target, int $eventId = 0, string $rawEmails = ''): array
{
    $rows = [];
    switch ($target) {
        case 'all':
        case 'subscribers':
            $rows = $pdo->query("
                SELECT id AS user_id, email, pseudo, first_name
                FROM users
                WHERE email_verified_at IS NOT NULL" . ($target === 'subscribers' ? ' AND notify_new_events = 1' : '') . "
                ORDER BY id
            ")->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'event':
            // Joueurs inscrits + acheteurs des places de leurs invités (les invités n'ont pas d'email)
            $stmt = $pdo->prepare("
                SELECT u.id AS user_id, u.email, u.pseudo, u.first_name, r.id AS sort_key
                FROM registrations r JOIN users u ON u.id = r.user_id
                WHERE r.event_id = ?
                UNION ALL
                SELECT ub.id, COALESCE(ub.email, o.buyer_email), ub.pseudo, ub.first_name, r.id
                FROM registrations r
                JOIN orders o ON o.id = r.order_id
                LEFT JOIN users ub ON ub.id = o.user_id
                WHERE r.event_id = ? AND r.user_id IS NULL
                ORDER BY sort_key
            ");
            $stmt->execute([$eventId, $eventId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'custom':
            $emails = broadcast_parse_emails($rawEmails)['valid'];
            $known = [];
            if ($emails) {
                $in = implode(',', array_fill(0, count($emails), '?'));
                $stmt = $pdo->prepare("SELECT id AS user_id, email, pseudo, first_name FROM users WHERE LOWER(email) IN ($in)");
                $stmt->execute($emails);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $known[mb_strtolower($u['email'])] = $u;
                }
            }
            foreach ($emails as $email) {
                $rows[] = $known[$email] ?? ['user_id' => null, 'email' => $email, 'pseudo' => null, 'first_name' => null];
            }
            break;
    }

    $list = [];
    foreach ($rows as $row) {
        $email = mb_strtolower(trim((string)$row['email']));
        if ($email === '' || isset($list[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $list[$email] = [
            'email'      => $email,
            'user_id'    => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'pseudo'     => (string)($row['pseudo'] ?? ''),
            'first_name' => (string)($row['first_name'] ?? ''),
            'name'       => (string)($row['pseudo'] ?: ($row['first_name'] ?? '')),
        ];
    }
    return array_values($list);
}

/**
 * Valeurs des champs à remplacer pour un destinataire : {prenom}, {pseudo}
 * (+ {partie}, {date}, {lieu} quand le message concerne une partie, via $extra).
 */
function broadcast_vars(array $recipient, array $extra = []): array
{
    $first = trim($recipient['first_name'] ?? '');
    $pseudo = trim($recipient['pseudo'] ?? '');
    return [
        'prenom' => $first !== '' ? $first : $pseudo,
        'pseudo' => $pseudo !== '' ? $pseudo : $first,
    ] + $extra;
}

/** Champs de partie pour {partie}, {date}, {lieu}. */
function broadcast_event_vars(array $event): array
{
    return [
        'partie' => (string)$event['title'],
        'date'   => date_fr($event['event_date']),
        'lieu'   => (string)$event['location'],
    ];
}

/** Remplace {prenom} et {pseudo} (et retire proprement le champ s'il est vide : « Salut {prenom}, » → « Salut, »). */
function broadcast_fill(string $text, array $vars): string
{
    foreach ($vars as $key => $value) {
        if ($value === '') {
            $text = str_ireplace([' {' . $key . '}', '{' . $key . '}'], '', $text);
        } else {
            $text = str_ireplace('{' . $key . '}', $value, $text);
        }
    }
    return $text;
}

/** Texte saisi → HTML de l'email (paragraphes, **gras**, liens cliquables). */
function broadcast_format(string $body): string
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    $html = '';
    foreach (preg_split('/\n[ \t]*\n+/', $body) as $para) {
        $para = e(trim($para));
        if ($para === '') {
            continue;
        }
        $para = preg_replace('/\*\*(.+?)\*\*/su', '<strong style="color:#ffffff;">$1</strong>', $para);
        $para = preg_replace_callback('~https?://(?:(?!&quot;|&#039;|&lt;|&gt;)[^\s<])+~iu', function ($m) {
            $url = rtrim($m[0], '.,;:!?)');
            $rest = substr($m[0], strlen($url));
            return '<a href="' . $url . '" style="color:#ef4444;word-break:break-all;">' . $url . '</a>' . $rest;
        }, $para);
        $html .= '<p style="margin:0 0 14px;">' . nl2br($para, false) . '</p>';
    }
    return $html;
}

/** Mention en bas de l'email (pourquoi on le reçoit, désabonnement). */
function broadcast_footer(string $target, ?int $userId): string
{
    $site = e(preg_replace('#^https?://#', '', SITE_URL));
    if ($target === 'subscribers' && $userId) {
        return '<p style="font-size:12px;color:#737373;margin-top:24px;">Tu reçois ce message car tu es abonné aux nouvelles des parties. '
            . '<a href="' . e(unsubscribe_url($userId)) . '" style="color:#a3a3a3;">Ne plus recevoir ces emails</a>.</p>';
    }
    if ($userId) {
        return '<p style="font-size:12px;color:#737373;margin-top:24px;">Tu reçois ce message car tu as un compte sur ' . $site . '. '
            . 'Pour nous répondre, il suffit de répondre à cet email.</p>';
    }
    return '<p style="font-size:12px;color:#737373;margin-top:24px;">Message envoyé par ' . e(SITE_NAME) . ' (' . $site . '). '
        . 'Pour nous répondre, il suffit de répondre à cet email.</p>';
}

/** Objet + HTML d'un message pour un destinataire. */
function broadcast_build(array $recipient, string $subject, string $body, string $target, array $extra = []): array
{
    $vars = broadcast_vars($recipient, $extra);
    return [
        'subject' => trim(broadcast_fill($subject, $vars)),
        'html'    => broadcast_format(broadcast_fill($body, $vars)) . broadcast_footer($target, $recipient['user_id'] ?? null),
    ];
}

/** Envoie le message à un destinataire. */
function broadcast_send_one(array $recipient, string $subject, string $body, string $target, array $extra = [], string $subjectPrefix = ''): bool
{
    $msg = broadcast_build($recipient, $subject, $body, $target, $extra);
    $headers = [];
    if ($target === 'subscribers' && !empty($recipient['user_id'])) {
        $headers = [
            'List-Unsubscribe'      => '<' . unsubscribe_url((int)$recipient['user_id']) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
    return send_mail($recipient['email'], $recipient['name'], $subjectPrefix . $msg['subject'], $msg['html'], [], $headers);
}

// ---------------------------------------------------------------------
// Historique des envois (table créée automatiquement au premier passage)
// ---------------------------------------------------------------------

function broadcast_history_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS broadcasts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    subject VARCHAR(200) NOT NULL,
                    body TEXT NOT NULL,
                    target VARCHAR(20) NOT NULL,
                    target_label VARCHAR(255) NOT NULL,
                    recipients INT NOT NULL DEFAULT 0,
                    sent INT NOT NULL DEFAULT 0,
                    failed INT NOT NULL DEFAULT 0,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    finished_at DATETIME NULL,
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $ready = true;
        } catch (Throwable $e) {
            error_log('Historique des messages indisponible : ' . $e->getMessage());
            $ready = false;
        }
    }
    return $ready;
}

function broadcast_history(PDO $pdo, int $limit = 10): array
{
    if (!broadcast_history_ready($pdo)) {
        return [];
    }
    return $pdo->query("
        SELECT b.*, u.pseudo AS author
        FROM broadcasts b LEFT JOIN users u ON u.id = b.created_by
        ORDER BY b.created_at DESC, b.id DESC
        LIMIT " . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------
// Envoi par lots : la liste est figée en session au départ, chaque appel
// envoie les BROADCAST_BATCH suivants (jamais deux fois la même personne).
// ---------------------------------------------------------------------

function broadcast_job_start(PDO $pdo, array $recipients, string $subject, string $body, string $target, array $extra, string $targetLabel): array
{
    $historyId = null;
    if (broadcast_history_ready($pdo)) {
        $pdo->prepare("INSERT INTO broadcasts (subject, body, target, target_label, recipients, created_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([mb_substr($subject, 0, 200), $body, $target, mb_substr($targetLabel, 0, 255), count($recipients), current_user_id()]);
        $historyId = (int)$pdo->lastInsertId();
    }
    $job = [
        'id'         => bin2hex(random_bytes(8)),
        'subject'    => $subject,
        'body'       => $body,
        'target'     => $target,
        'extra'      => $extra,
        'recipients' => $recipients,
        'offset'     => 0,
        'sent'       => 0,
        'failed'     => [],
        'history_id' => $historyId,
    ];
    $_SESSION['broadcast_job'] = $job;
    return $job;
}

/** Envoie le lot suivant. Renvoie l'état : total, done (traités), sent, failed[], finished. */
function broadcast_job_step(PDO $pdo, string $jobId, int $limit = BROADCAST_BATCH): ?array
{
    $job = $_SESSION['broadcast_job'] ?? null;
    if (!$job || !hash_equals($job['id'], $jobId)) {
        return null;
    }
    @set_time_limit(120);
    $total = count($job['recipients']);
    $slice = array_slice($job['recipients'], $job['offset'], $limit);
    foreach ($slice as $recipient) {
        if (broadcast_send_one($recipient, $job['subject'], $job['body'], $job['target'], $job['extra'])) {
            $job['sent']++;
        } else {
            $job['failed'][] = $recipient['email'];
        }
        $job['offset']++;
        $_SESSION['broadcast_job'] = $job; // progression gardée même si la requête est coupée
    }
    $finished = $job['offset'] >= $total;

    if ($job['history_id']) {
        $pdo->prepare("UPDATE broadcasts SET sent = ?, failed = ?, finished_at = " . ($finished ? 'NOW()' : 'NULL') . " WHERE id = ?")
            ->execute([$job['sent'], count($job['failed']), $job['history_id']]);
    }
    if ($finished) {
        unset($_SESSION['broadcast_job']);
    }
    return [
        'total'    => $total,
        'done'     => $job['offset'],
        'sent'     => $job['sent'],
        'failed'   => $job['failed'],
        'finished' => $finished,
    ];
}

/** Envoi en cours (interrompu ou pas encore fini) : ['total', 'done', 'sent', 'id', 'subject'] ou null. */
function broadcast_job_current(): ?array
{
    $job = $_SESSION['broadcast_job'] ?? null;
    if (!$job) {
        return null;
    }
    return [
        'id'         => $job['id'],
        'history_id' => $job['history_id'],
        'subject'    => $job['subject'],
        'total'   => count($job['recipients']),
        'done'    => $job['offset'],
        'sent'    => $job['sent'],
    ];
}

/** Abandonne l'envoi en cours (les emails déjà partis restent partis). */
function broadcast_job_cancel(PDO $pdo): void
{
    $job = $_SESSION['broadcast_job'] ?? null;
    if ($job && $job['history_id']) {
        $pdo->prepare("UPDATE broadcasts SET sent = ?, failed = ?, finished_at = NOW() WHERE id = ?")
            ->execute([$job['sent'], count($job['failed']), $job['history_id']]);
    }
    unset($_SESSION['broadcast_job']);
}
