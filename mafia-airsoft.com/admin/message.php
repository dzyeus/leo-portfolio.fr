<?php
/**
 * Panel admin – Message aux joueurs
 * Un email à tous les joueurs, aux abonnés aux alertes, aux inscrits d'une
 * partie ou à des adresses précises. Chaque personne reçoit son propre email
 * (personne ne voit les adresses des autres). Aperçu en direct, envoi de test,
 * modèles, envoi par lots avec progression, historique.
 */
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
require_once '../includes/admin_ui.php';
require_once '../includes/lib/broadcast.php';
requireAdmin();

$labels = broadcast_target_labels();
$isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$me = current_user($pdo);

function msg_json(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function msg_find_event(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, title, event_date, location FROM events WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function msg_draft_defaults(): array
{
    return ['target' => 'all', 'event_id' => 0, 'emails' => '', 'subject' => '', 'body' => ''];
}

function msg_draft_from_post(array $labels): array
{
    $target = (string)($_POST['target'] ?? 'all');
    return [
        'target'   => isset($labels[$target]) ? $target : 'all',
        'event_id' => (int)($_POST['event_id'] ?? 0),
        'emails'   => mb_substr((string)($_POST['emails'] ?? ''), 0, 30000),
        'subject'  => trim(mb_substr((string)($_POST['subject'] ?? ''), 0, 300)),
        'body'     => mb_substr(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? '')), 0, 30000),
    ];
}

/**
 * Analyse d'un brouillon : destinataires, champs de partie, libellé pour
 * l'historique et problèmes à corriger.
 * $full = vérifier aussi l'objet et le message ; $needRecipients = au moins un destinataire.
 */
function msg_analyse(PDO $pdo, array $draft, array $labels, bool $full, bool $needRecipients): array
{
    $errors = [];
    $extra = [];
    $label = $labels[$draft['target']];
    $targetOk = true;

    if ($draft['target'] === 'event') {
        $event = msg_find_event($pdo, (int)$draft['event_id']);
        if (!$event) {
            $errors[] = 'Choisis la partie dont tu veux contacter les inscrits.';
            $targetOk = false;
        } else {
            $extra = broadcast_event_vars($event);
            $label = 'Inscrits : ' . $event['title'] . ' (' . date('d/m/Y', strtotime($event['event_date'])) . ')';
        }
    } elseif (preg_match('/\{(partie|date|lieu)\}/i', $draft['subject'] . ' ' . $draft['body'])) {
        $errors[] = 'Le message contient {partie}, {date} ou {lieu} : choisis « Inscrits à une partie » pour que ces champs soient remplis.';
    }

    if ($draft['target'] === 'custom') {
        $parsed = broadcast_parse_emails($draft['emails']);
        if ($parsed['invalid']) {
            $errors[] = 'Adresse(s) invalide(s) : ' . implode(', ', array_slice($parsed['invalid'], 0, 5))
                . (count($parsed['invalid']) > 5 ? '…' : '') . '.';
        }
        if (count($parsed['valid']) > BROADCAST_MAX_CUSTOM) {
            $errors[] = 'Maximum ' . BROADCAST_MAX_CUSTOM . ' adresses à la fois.';
            $targetOk = false;
        }
        $label = 'Adresses précises : ' . implode(', ', array_slice($parsed['valid'], 0, 3)) . (count($parsed['valid']) > 3 ? '…' : '');
    }

    $recipients = $targetOk ? broadcast_recipients($pdo, $draft['target'], (int)$draft['event_id'], $draft['emails']) : [];

    if ($full) {
        if ($draft['subject'] === '') {
            $errors[] = 'Écris l\'objet du message.';
        } elseif (mb_strlen($draft['subject']) > 150) {
            $errors[] = 'L\'objet est trop long (150 caractères maximum).';
        }
        if (trim($draft['body']) === '') {
            $errors[] = 'Écris le message.';
        } elseif (mb_strlen($draft['body']) > 20000) {
            $errors[] = 'Le message est trop long.';
        }
    }
    if ($needRecipients && $targetOk && !$recipients) {
        $errors[] = $draft['target'] === 'custom' ? 'Ajoute au moins une adresse email.' : 'Aucun destinataire pour ce choix.';
    }

    return ['errors' => $errors, 'recipients' => $recipients, 'extra' => $extra, 'label' => $label];
}

/** Le compte de l'admin vu comme destinataire (test, aperçu sans destinataire). */
function msg_me_recipient(array $me): array
{
    return [
        'email'      => $me['email'],
        'user_id'    => (int)$me['id'],
        'pseudo'     => (string)$me['pseudo'],
        'first_name' => (string)($me['first_name'] ?? ''),
        'name'       => (string)$me['pseudo'],
    ];
}

/** HTML complet de l'aperçu, tel que le premier destinataire le recevra. */
function msg_preview(array $draft, array $analysis, array $me): array
{
    $sample = $analysis['recipients'][0] ?? msg_me_recipient($me);
    $subject = $draft['subject'] !== '' ? $draft['subject'] : '(objet du message)';
    $body = trim($draft['body']) !== '' ? $draft['body'] : 'Ton message apparaîtra ici.';
    $msg = broadcast_build($sample, $subject, $body, $draft['target'], $analysis['extra']);
    $html = str_replace('<head>', '<head><base target="_blank">', mail_layout($msg['subject'], $msg['html']));
    return [
        'html'    => $html,
        'subject' => $msg['subject'],
        'sample'  => ($sample['pseudo'] !== '' ? $sample['pseudo'] . ' · ' : '') . $sample['email'],
    ];
}

// =====================================================================
// Actions
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'preview');

    // Lot suivant d'un envoi en cours
    if ($action === 'step') {
        $state = broadcast_job_step($pdo, (string)($_POST['job'] ?? ''));
        if ($state === null) {
            msg_json(['ok' => false, 'error' => 'Cet envoi est déjà terminé ou a été arrêté.']);
        }
        if ($state['finished']) {
            unset($_SESSION['broadcast_draft']);
        }
        msg_json(['ok' => true] + $state);
    }

    // Arrêter l'envoi en cours
    if ($action === 'cancel') {
        $job = broadcast_job_current();
        broadcast_job_cancel($pdo);
        if ($isFetch) {
            msg_json(['ok' => true]);
        }
        flash_set('success', $job ? 'Envoi arrêté : ' . $job['sent'] . ' email(s) étaient déjà partis.' : 'Aucun envoi en cours.');
        redirect('message.php');
    }

    $draft = msg_draft_from_post($labels);
    $_SESSION['broadcast_draft'] = $draft; // brouillon gardé si on quitte la page

    // Aperçu en direct (pendant la saisie)
    if ($action === 'render') {
        $a = msg_analyse($pdo, $draft, $labels, false, false);
        $p = msg_preview($draft, $a, $me);
        msg_json(['ok' => true, 'count' => count($a['recipients']), 'errors' => $a['errors'], 'html' => $p['html'], 'sample' => $p['sample']]);
    }

    // Email de test à l'admin connecté
    if ($action === 'test') {
        $a = msg_analyse($pdo, $draft, $labels, true, false);
        $message = '';
        if (!$a['errors']) {
            if (broadcast_send_one(msg_me_recipient($me), $draft['subject'], $draft['body'], $draft['target'], $a['extra'], '[TEST] ')) {
                $message = 'Email de test envoyé à ' . $me['email'] . ' : regarde ta boîte de réception (et les spams).';
            } else {
                $a['errors'][] = 'L\'email de test n\'est pas parti. Vérifie l\'envoi des emails dans Diagnostic.';
            }
        }
        if ($isFetch) {
            msg_json(['ok' => !$a['errors'], 'errors' => $a['errors'], 'message' => $message]);
        }
        foreach ($a['errors'] as $err) {
            flash_set('error', $err);
        }
        if ($message !== '') {
            flash_set('success', $message);
        }
        redirect('message.php#composer');
    }

    // Envoi à tous les destinataires
    if ($action === 'send') {
        $a = msg_analyse($pdo, $draft, $labels, true, true);
        if (!mail_configured()) {
            $a['errors'][] = 'L\'envoi d\'emails n\'est pas configuré sur le site (voir Diagnostic).';
        }
        if (broadcast_job_current()) {
            $a['errors'][] = 'Un envoi est déjà en cours : reprends-le ou arrête-le d\'abord.';
        }
        if ($a['errors']) {
            if ($isFetch) {
                msg_json(['ok' => false, 'errors' => $a['errors']]);
            }
            foreach ($a['errors'] as $err) {
                flash_set('error', $err);
            }
            redirect('message.php#composer');
        }

        $label = $a['label'] . ' – ' . count($a['recipients']) . ' destinataire(s)';
        $job = broadcast_job_start($pdo, $a['recipients'], $draft['subject'], $draft['body'], $draft['target'], $a['extra'], $label);
        if ($isFetch) {
            msg_json(['ok' => true, 'job' => $job['id'], 'total' => count($a['recipients'])]);
        }

        // Sans JavaScript : tout est envoyé maintenant
        @set_time_limit(0);
        ignore_user_abort(true);
        do {
            $state = broadcast_job_step($pdo, $job['id']);
        } while ($state && !$state['finished']);
        unset($_SESSION['broadcast_draft']);
        flash_set($state && !$state['failed'] ? 'success' : 'error', 'Message envoyé à ' . ($state['sent'] ?? 0) . ' personne(s)'
            . ($state && $state['failed'] ? ' – échec pour : ' . implode(', ', $state['failed']) : '') . '.');
        redirect('message.php');
    }

    // « Aperçu » sans JavaScript : la page se recharge avec l'aperçu
    redirect('message.php#apercu');
}

// =====================================================================
// Affichage
// =====================================================================
if (isset($_GET['reset'])) {
    unset($_SESSION['broadcast_draft']);
    redirect('message.php');
}

$draft = array_merge(msg_draft_defaults(), $_SESSION['broadcast_draft'] ?? []);
if (isset($_GET['to'])) {
    $draft['target'] = 'custom';
    $draft['emails'] = implode("\n", broadcast_parse_emails((string)$_GET['to'])['valid']);
}
if (isset($_GET['event'])) {
    $draft['target'] = 'event';
    $draft['event_id'] = (int)$_GET['event'];
}
if (isset($_GET['target'], $labels[$_GET['target']])) {
    $draft['target'] = (string)$_GET['target'];
}
if (isset($_GET['reuse']) && broadcast_history_ready($pdo)) {
    $stmt = $pdo->prepare("SELECT subject, body FROM broadcasts WHERE id = ?");
    $stmt->execute([(int)$_GET['reuse']]);
    if ($old = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $draft['subject'] = $old['subject'];
        $draft['body'] = $old['body'];
        flash_set('success', 'Message repris : vérifie les destinataires avant d\'envoyer.');
    }
}

// Parties proposées : à venir (la plus proche d'abord), puis passées depuis moins de 60 jours
$stmt = $pdo->prepare("
    SELECT id, title, event_date, location, event_date >= NOW() AS upcoming FROM events
    WHERE event_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) OR id = ?
    ORDER BY upcoming DESC, ABS(TIMESTAMPDIFF(SECOND, NOW(), event_date))
");
$stmt->execute([(int)$draft['event_id']]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($events as &$ev) {
    $ev['count'] = count(broadcast_recipients($pdo, 'event', (int)$ev['id']));
}
unset($ev);
if (!$draft['event_id'] && $events) {
    $draft['event_id'] = (int)$events[0]['id'];
}

$countAll = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL")->fetchColumn();
$countSubs = subscribers_count($pdo);
$players = $pdo->query("
    SELECT pseudo, first_name, last_name, email FROM users
    WHERE email_verified_at IS NOT NULL ORDER BY pseudo
")->fetchAll(PDO::FETCH_ASSOC);

$analysis = msg_analyse($pdo, $draft, $labels, false, false);
$preview = msg_preview($draft, $analysis, $me);
$count = count($analysis['recipients']);
$history = broadcast_history($pdo, 8);
$historyOk = broadcast_history_ready($pdo);
$job = broadcast_job_current();
$hasDraft = $draft['subject'] !== '' || trim($draft['body']) !== '';
$flashes = flash_get();

// Modèles de message
$dash = SITE_URL . '/player/dashboard.php';
$templates = [
    'news' => [
        'label'   => 'Nouveautés du site (paiement en ligne, alertes)',
        'target'  => 'all',
        'subject' => 'Du nouveau sur le site : paiement en ligne et alertes des parties',
        'body'    => "Salut {prenom},\n\n"
            . "Le site de la Mafia Airsoft Team évolue, voici ce qui change pour toi.\n\n"
            . "**🔔 Sois prévenu des nouvelles parties**\n"
            . "Tu peux maintenant recevoir un email dès qu'une nouvelle partie est ouverte aux inscriptions. "
            . "Pour l'activer, connecte-toi, va dans « Mon espace » et coche la case « Me prévenir par email ». Tu peux la décocher à tout moment.\n"
            . $dash . "\n\n"
            . "**💳 Parties payantes et paiement en ligne**\n"
            . "Certaines parties peuvent désormais être payantes : le prix est indiqué sur la page de chaque partie. "
            . "Le paiement se fait directement sur le site par carte bancaire via Stripe, une plateforme de paiement sécurisée. "
            . "L'association n'a jamais accès à tes coordonnées bancaires.\n\n"
            . "**🎟️ Reçu et billets automatiques**\n"
            . "Après le paiement, tu reçois par email ton reçu et tes billets avec un QR code. Ils restent aussi disponibles dans « Mon espace ». "
            . "Il suffit de présenter le QR code à l'entrée le jour de la partie.\n\n"
            . "**👥 Venir avec des amis**\n"
            . "Tu peux réserver plusieurs places en une fois, en indiquant le nom, le prénom et le pseudo de chaque invité.\n\n"
            . "Les conditions générales sont disponibles en bas de chaque page du site.\n\n"
            . "À très vite sur le terrain !\nLe bureau de la Mafia Airsoft Team",
    ],
    'reminder' => [
        'label'   => 'Rappel avant une partie',
        'target'  => 'event',
        'subject' => 'Rappel : {partie} – {date}',
        'body'    => "Salut {prenom},\n\n"
            . "Petit rappel : la partie « {partie} » a lieu le {date}.\n📍 Lieu : {lieu}\n\n"
            . "Pense à avoir ton billet (QR code) sur ton téléphone ou imprimé. Tu le trouves dans tes emails et dans « Mon espace » :\n"
            . $dash . "\n\n"
            . "À très vite sur le terrain !\nLe bureau de la Mafia Airsoft Team",
    ],
    'cancel' => [
        'label'   => 'Annulation d\'une partie',
        'target'  => 'event',
        'hint'    => 'Pense à rembourser les paiements dans Stripe (Paiements › choisir le paiement › Rembourser) : les places sont libérées automatiquement.',
        'subject' => 'Partie annulée : {partie}',
        'body'    => "Salut {prenom},\n\n"
            . "Nous sommes désolés : la partie « {partie} » prévue le {date} est annulée.\n\n"
            . "Si tu as payé ta place, tu seras remboursé intégralement sur la carte utilisée pour le paiement. "
            . "Le remboursement apparaît en général sous 5 à 10 jours.\n\n"
            . "On revient vite vers toi avec une nouvelle date.\nLe bureau de la Mafia Airsoft Team",
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message aux joueurs - Administration</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="a-page-head">
            <div>
                <h1>Message aux joueurs</h1>
                <div class="a-sub">Chaque personne reçoit son propre email : personne ne voit les adresses des autres.</div>
            </div>
            <?php if ($hasDraft): ?>
                <div class="a-actions"><a class="a-btn" href="message.php?reset=1"><?= admin_svg('plus', 18) ?> Nouveau message</a></div>
            <?php endif; ?>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> a-flash"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <?php if (!mail_configured()): ?>
            <div class="alert alert-error a-flash">L'envoi d'emails n'est pas encore configuré sur le site : <a href="diagnostic.php" class="a-link">voir Diagnostic</a>.</div>
        <?php endif; ?>

        <?php if ($job): ?>
            <div class="a-card a-resume" id="resumeBox" data-job="<?= e($job['id']) ?>" data-total="<?= (int)$job['total'] ?>"
                 data-done="<?= (int)$job['done'] ?>" data-sent="<?= (int)$job['sent'] ?>">
                <div>
                    <strong>Envoi interrompu : « <?= e($job['subject']) ?> »</strong>
                    <div class="a-small a-muted"><?= (int)$job['sent'] ?> sur <?= (int)$job['total'] ?> email(s) déjà envoyés. Les personnes déjà servies ne le recevront pas deux fois.</div>
                </div>
                <div class="a-actions">
                    <button type="button" class="a-btn a-btn-primary" id="resumeBtn"><?= admin_svg('send', 18) ?> Reprendre l'envoi</button>
                    <form class="a-inline" method="POST" action="message.php" onsubmit="return confirm('Arrêter cet envoi ? Les personnes restantes ne recevront pas le message.');">
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="a-btn a-btn-danger"><?= admin_svg('x', 18) ?> Arrêter</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="a-msg-grid">
            <!-- Rédaction -->
            <section class="a-card" id="composer">
                <form id="msgForm" class="a-msg" method="POST" action="message.php">
                    <div class="a-card-head"><h2><?= admin_svg('users', 18) ?> Destinataires</h2></div>
                    <div class="a-choices" role="radiogroup" aria-label="Destinataires">
                        <?php
                        $choiceSubs = [
                            'all'         => $countAll . ' compte(s) confirmé(s)',
                            'subscribers' => $countSubs . ' abonné(s)',
                            'event'       => 'Une partie au choix',
                            'custom'      => 'Une ou plusieurs adresses',
                        ];
                        $choiceIcons = ['all' => 'users', 'subscribers' => 'bell', 'event' => 'calendar', 'custom' => 'mail'];
                        foreach ($labels as $key => $label): ?>
                            <label class="a-choice">
                                <input type="radio" name="target" value="<?= e($key) ?>" <?= $draft['target'] === $key ? 'checked' : '' ?>>
                                <span class="a-choice-title"><?= admin_svg($choiceIcons[$key], 16) ?> <?= e($label) ?></span>
                                <span class="a-choice-sub"><?= e($choiceSubs[$key]) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="a-target-extra form-group" data-for="event">
                        <label for="event_id">Partie</label>
                        <?php if (!$events): ?>
                            <p class="a-small a-muted">Aucune partie récente ou à venir.</p>
                        <?php else: ?>
                            <select id="event_id" name="event_id">
                                <?php $group = null; foreach ($events as $ev):
                                    $g = $ev['upcoming'] ? 'À venir' : 'Passées';
                                    if ($g !== $group): if ($group !== null) echo '</optgroup>'; $group = $g; ?>
                                        <optgroup label="<?= e($g) ?>">
                                    <?php endif; ?>
                                    <option value="<?= (int)$ev['id'] ?>" data-count="<?= (int)$ev['count'] ?>" <?= (int)$draft['event_id'] === (int)$ev['id'] ? 'selected' : '' ?>>
                                        <?= e(date('d/m', strtotime($ev['event_date'])) . ' – ' . $ev['title'] . ' (' . $ev['count'] . ')') ?></option>
                                <?php endforeach; if ($group !== null) echo '</optgroup>'; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="a-target-extra form-group" data-for="custom">
                        <label for="emails">Adresses email</label>
                        <textarea id="emails" name="emails" rows="3" placeholder="joueur@exemple.fr, autre@exemple.fr"><?= e($draft['emails']) ?></textarea>
                        <div class="a-pick">
                            <input type="text" id="pickPlayer" list="playersList" placeholder="Ajouter un joueur (pseudo ou nom)…" autocomplete="off" aria-label="Ajouter un joueur">
                            <button type="button" class="a-btn a-btn-sm" id="pickAdd"><?= admin_svg('plus', 16) ?> Ajouter</button>
                        </div>
                        <datalist id="playersList">
                            <?php foreach ($players as $pl): ?>
                                <option value="<?= e($pl['email']) ?>"><?= e($pl['pseudo'] . ' – ' . trim(($pl['first_name'] ?? '') . ' ' . ($pl['last_name'] ?? ''))) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small>Sépare les adresses par une virgule ou un retour à la ligne. Elles peuvent aussi être hors du site.</small>
                    </div>

                    <div class="a-card-head a-msg-head">
                        <h2><?= admin_svg('edit', 18) ?> Message</h2>
                        <select id="tpl" class="a-tpl" aria-label="Partir d'un modèle">
                            <option value="">Partir d'un modèle…</option>
                            <?php foreach ($templates as $key => $t): ?>
                                <option value="<?= e($key) ?>"><?= e($t['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="a-hint" id="tplHint" hidden></p>

                    <div class="form-group">
                        <label for="subject">Objet</label>
                        <input type="text" id="subject" name="subject" maxlength="150" value="<?= e($draft['subject']) ?>" placeholder="Ex. : Nouvelle saison, nouvelles règles">
                    </div>
                    <div class="form-group">
                        <label for="body">Message</label>
                        <textarea id="body" name="body" rows="12" placeholder="Salut {prenom},&#10;&#10;…"><?= e($draft['body']) ?></textarea>
                        <div class="a-chips">
                            <span class="a-small a-muted">Insérer :</span>
                            <button type="button" class="a-chip" data-insert="{prenom}">Prénom</button>
                            <button type="button" class="a-chip" data-insert="{pseudo}">Pseudo</button>
                            <button type="button" class="a-chip" data-insert="{partie}" data-event-only>Partie</button>
                            <button type="button" class="a-chip" data-insert="{date}" data-event-only>Date</button>
                            <button type="button" class="a-chip" data-insert="{lieu}" data-event-only>Lieu</button>
                        </div>
                        <small>Laisse une ligne vide entre deux paragraphes · **texte** pour mettre en gras · les liens deviennent cliquables.</small>
                    </div>

                    <div class="alert alert-error a-flash" id="msgErrors" <?= $analysis['errors'] ? '' : 'hidden' ?>>
                        <?= implode('<br>', array_map('e', $analysis['errors'])) ?></div>
                    <div class="alert alert-success a-flash" id="msgNotice" hidden></div>

                    <div class="a-msg-actions" id="msgActions">
                        <button type="submit" name="action" value="preview" class="a-btn" id="btnPreview"><?= admin_svg('eye', 18) ?> Aperçu</button>
                        <button type="submit" name="action" value="test" class="a-btn" id="btnTest"><?= admin_svg('mail', 18) ?> M'envoyer un test</button>
                        <button type="submit" name="action" value="send" class="a-btn a-btn-primary" id="btnSend"><?= admin_svg('send', 18) ?>
                            <span id="sendLabel">Envoyer à <?= $count ?> personne<?= $count > 1 ? 's' : '' ?></span></button>
                    </div>

                    <div class="a-sendbox" id="sendBox" hidden>
                        <div class="a-sendbox-title" id="sendTitle">Envoi en cours… garde cette page ouverte</div>
                        <div class="a-progress"><div class="a-progress-bar" id="sendBar" style="width:0%"></div></div>
                        <div class="a-sendbox-count" id="sendCount">0 / 0</div>
                        <div class="a-small" id="sendFailed" hidden></div>
                        <div class="a-sendbox-actions">
                            <button type="button" class="a-btn a-btn-sm a-btn-danger" id="sendStop"><?= admin_svg('x', 16) ?> Arrêter</button>
                            <a href="message.php?reset=1" class="a-btn a-btn-sm" id="sendNew" hidden><?= admin_svg('plus', 16) ?> Nouveau message</a>
                        </div>
                    </div>
                </form>
            </section>

            <div class="a-stack">
                <!-- Aperçu -->
                <section class="a-card" id="apercu">
                    <div class="a-card-head">
                        <h2><?= admin_svg('eye', 18) ?> Aperçu</h2>
                        <span class="a-small a-muted a-ellipsis" id="previewFor">pour <?= e($preview['sample']) ?></span>
                    </div>
                    <iframe id="previewFrame" class="a-preview-frame" title="Aperçu de l'email"
                            sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                            srcdoc="<?= e($preview['html']) ?>"></iframe>
                </section>

                <!-- Expéditeur -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= admin_svg('mail', 18) ?> Expéditeur</h2></div>
                    <div class="a-list">
                        <div class="a-kv"><span>De</span><strong><?= e(FROM_NAME) ?> &lt;<?= e(FROM_EMAIL) ?>&gt;</strong></div>
                        <div class="a-kv"><span>Réponses</span><strong><?= e(REPLY_TO_EMAIL) ?></strong></div>
                    </div>
                    <p class="a-small a-muted" style="margin:0.6rem 0 0;">Les réponses des joueurs arrivent dans la boîte <?= e(REPLY_TO_EMAIL) ?> (webmail o2switch).</p>
                </section>

                <!-- Historique -->
                <section class="a-card">
                    <div class="a-card-head"><h2><?= admin_svg('clock', 18) ?> Derniers envois</h2></div>
                    <?php if (!$historyOk): ?>
                        <p class="a-small a-muted">Historique indisponible : exécute <code>database/update_v5_messages.sql</code> dans phpMyAdmin.</p>
                    <?php elseif (!$history): ?>
                        <p class="a-empty">Aucun message envoyé pour l'instant.</p>
                    <?php else: ?>
                        <div class="a-list">
                            <?php foreach ($history as $h):
                                $running = !$h['finished_at'] && $job && (int)$job['history_id'] === (int)$h['id']; ?>
                                <div class="a-row">
                                    <div class="a-row-main">
                                        <span class="a-row-title"><?= e($h['subject']) ?></span>
                                        <span class="a-row-sub"><?= e($h['target_label']) ?></span>
                                        <span class="a-row-sub"><?= e(admin_ago($h['created_at'])) ?><?= $h['author'] ? ' · par ' . e($h['author']) : '' ?></span>
                                    </div>
                                    <div class="a-row-side">
                                        <?php if ($running): ?>
                                            <span class="a-badge st-warn">En cours</span>
                                        <?php elseif (!$h['finished_at']): ?>
                                            <span class="a-badge st-warn" title="Envoi interrompu"><?= (int)$h['sent'] ?>/<?= (int)$h['recipients'] ?></span>
                                        <?php else: ?>
                                            <span class="a-badge <?= (int)$h['failed'] ? 'st-warn' : 'st-ok' ?>"><?= (int)$h['sent'] ?>/<?= (int)$h['recipients'] ?> envoyés</span>
                                        <?php endif; ?>
                                        <a class="a-btn a-btn-sm" href="message.php?reuse=<?= (int)$h['id'] ?>" title="Reprendre ce texte"><?= admin_svg('copy', 16) ?> Réutiliser</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script>
    (function () {
        var form = document.getElementById('msgForm');
        if (!form || !window.fetch || !window.FormData) { return; }
        var templates = <?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var counts = { all: <?= $countAll ?>, subscribers: <?= $countSubs ?> };
        var $ = function (id) { return document.getElementById(id); };
        var subject = $('subject'), body = $('body'), emails = $('emails'), eventSel = $('event_id');
        var frame = $('previewFrame'), errorsBox = $('msgErrors'), noticeBox = $('msgNotice');
        var btnSend = $('btnSend'), sendLabel = $('sendLabel');
        var count = <?= $count ?>, sending = false, lastField = body;

        function post(action, extra) {
            var data = new FormData(form);
            data.set('action', action);
            Object.keys(extra || {}).forEach(function (k) { data.set(k, extra[k]); });
            return fetch('message.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
        }
        function target() { var c = form.querySelector('input[name="target"]:checked'); return c ? c.value : 'all'; }
        function showErrors(list) {
            errorsBox.hidden = !list || !list.length;
            errorsBox.textContent = '';
            (list || []).forEach(function (msg, i) { if (i) { errorsBox.appendChild(document.createElement('br')); } errorsBox.appendChild(document.createTextNode(msg)); });
        }
        function showNotice(msg) { noticeBox.hidden = !msg; noticeBox.textContent = msg || ''; }
        function setCount(n) {
            count = n;
            sendLabel.textContent = 'Envoyer à ' + n + ' personne' + (n > 1 ? 's' : '');
            btnSend.disabled = n === 0;
        }

        // Destinataires : afficher le bon champ
        function syncTarget() {
            var t = target();
            form.querySelectorAll('.a-target-extra').forEach(function (el) { el.hidden = el.getAttribute('data-for') !== t; });
            form.querySelectorAll('[data-event-only]').forEach(function (el) { el.hidden = t !== 'event'; });
        }
        form.querySelectorAll('input[name="target"]').forEach(function (r) { r.addEventListener('change', function () { syncTarget(); refresh(); }); });
        syncTarget();

        // Aperçu en direct
        var timer = null, seq = 0;
        function refresh(now) {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var mine = ++seq;
                post('render').then(function (res) {
                    if (mine !== seq) { return; }
                    frame.srcdoc = res.html;
                    $('previewFor').textContent = 'pour ' + res.sample;
                    setCount(res.count);
                    showErrors(res.errors);
                }).catch(function () {});
            }, now ? 0 : 600);
        }
        [subject, body, emails].forEach(function (el) { if (el) { el.addEventListener('input', function () { refresh(); }); } });
        if (eventSel) { eventSel.addEventListener('change', function () { refresh(true); }); }
        [subject, body].forEach(function (el) { el.addEventListener('focus', function () { lastField = el; }); });

        // Hauteur de l'aperçu = hauteur de l'email
        frame.addEventListener('load', function () {
            try { frame.style.height = Math.max(320, frame.contentDocument.documentElement.scrollHeight + 8) + 'px'; } catch (e) {}
        });

        // Insérer {prenom}, {pseudo}…
        form.querySelectorAll('[data-insert]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var el = lastField, text = chip.getAttribute('data-insert');
                var start = el.selectionStart != null ? el.selectionStart : el.value.length, end = el.selectionEnd != null ? el.selectionEnd : start;
                el.value = el.value.slice(0, start) + text + el.value.slice(end);
                el.focus();
                el.selectionStart = el.selectionEnd = start + text.length;
                refresh();
            });
        });

        // Modèles
        $('tpl').addEventListener('change', function () {
            var t = templates[this.value];
            this.value = '';
            if (!t) { return; }
            if ((subject.value.trim() || body.value.trim()) && !confirm('Remplacer l\'objet et le message actuels par ce modèle ?')) { return; }
            subject.value = t.subject;
            body.value = t.body;
            var radio = form.querySelector('input[name="target"][value="' + t.target + '"]');
            if (radio && target() !== t.target && !(t.target === 'all' && target() !== 'event')) { radio.checked = true; syncTarget(); }
            $('tplHint').hidden = !t.hint;
            $('tplHint').textContent = t.hint || '';
            refresh(true);
        });

        // Ajouter un joueur à la liste d'adresses
        function addPicked() {
            var input = $('pickPlayer'), v = input.value.trim();
            if (v.indexOf('@') === -1) { return; }
            var list = emails.value.trim();
            if ((',' + list.replace(/\s+/g, ',') + ',').toLowerCase().indexOf(',' + v.toLowerCase() + ',') === -1) {
                emails.value = list ? list + '\n' + v : v;
            }
            input.value = '';
            refresh();
        }
        $('pickAdd').addEventListener('click', addPicked);
        $('pickPlayer').addEventListener('change', addPicked);
        $('pickPlayer').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addPicked(); } });

        // Boutons
        var lastAction = 'preview';
        form.querySelectorAll('button[name="action"]').forEach(function (b) { b.addEventListener('click', function () { lastAction = b.value; }); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (sending) { return; }
            var action = e.submitter && e.submitter.value ? e.submitter.value : lastAction;
            showNotice('');
            if (action === 'preview') {
                refresh(true);
                $('apercu').scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            if (action === 'test') {
                var b = $('btnTest');
                b.disabled = true;
                post('test').then(function (res) {
                    showErrors(res.errors);
                    showNotice(res.message);
                }).catch(function () { showErrors(['Erreur de connexion, réessaie.']); })
                  .then(function () { b.disabled = false; });
                return;
            }
            if (action === 'send') {
                if (!confirm('Envoyer « ' + subject.value.trim() + ' » à ' + count + ' personne' + (count > 1 ? 's' : '') + ' ?\n\nChaque personne reçoit son propre email.')) { return; }
                btnSend.disabled = true;
                post('send').then(function (res) {
                    if (!res.ok) { showErrors(res.errors); btnSend.disabled = false; return; }
                    showErrors([]);
                    run(res.job, res.total, 0, 0);
                }).catch(function () { showErrors(['Erreur de connexion, réessaie.']); btnSend.disabled = false; });
            }
        });

        // Envoi par lots avec progression
        var stopped = false;
        function run(job, total, done, sent) {
            sending = true; stopped = false;
            $('msgActions').hidden = true;
            $('sendBox').hidden = false;
            $('sendStop').hidden = false;
            $('sendNew').hidden = true;
            $('sendTitle').textContent = 'Envoi en cours… garde cette page ouverte';
            form.querySelectorAll('input:not([type="hidden"]), textarea, select, .a-chip').forEach(function (el) { el.disabled = true; });
            $('sendBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
            progress(done, total, sent, []);
            var retries = 0;
            (function next() {
                if (stopped) { return; }
                post('step', { job: job }).then(function (res) {
                    retries = 0;
                    if (!res.ok) { finish(res.error, true); return; }
                    progress(res.done, res.total, res.sent, res.failed);
                    if (res.finished) {
                        finish('✅ Message envoyé à ' + res.sent + ' personne' + (res.sent > 1 ? 's' : '') + (res.failed.length ? ' (' + res.failed.length + ' échec)' : '') + '.', false);
                    } else { next(); }
                }).catch(function () {
                    if (++retries <= 3) { setTimeout(next, 2000 * retries); return; }
                    finish('Connexion perdue. Recharge la page : tu pourras reprendre l\'envoi là où il s\'est arrêté.', true);
                });
            })();
        }
        function progress(done, total, sent, failed) {
            $('sendBar').style.width = (total ? Math.round(done * 100 / total) : 100) + '%';
            $('sendCount').textContent = sent + ' / ' + total + ' envoyé' + (sent > 1 ? 's' : '');
            var f = $('sendFailed');
            f.hidden = !failed.length;
            f.textContent = failed.length ? 'Non envoyé à : ' + failed.join(', ') : '';
        }
        function finish(message, isError) {
            sending = false;
            $('sendTitle').textContent = message;
            $('sendTitle').classList.toggle('is-error', !!isError);
            $('sendStop').hidden = true;
            $('sendNew').hidden = false;
            var resume = $('resumeBox');
            if (resume && !isError) { resume.remove(); }
        }
        $('sendStop').addEventListener('click', function () {
            if (!confirm('Arrêter l\'envoi ? Les personnes restantes ne recevront pas le message.')) { return; }
            stopped = true;
            post('cancel').then(function () { finish('Envoi arrêté.', true); });
        });
        window.addEventListener('beforeunload', function (e) { if (sending) { e.preventDefault(); e.returnValue = ''; } });

        // Reprendre un envoi interrompu
        var resumeBtn = $('resumeBtn');
        if (resumeBtn) {
            resumeBtn.addEventListener('click', function () {
                var box = $('resumeBox');
                box.querySelector('.a-actions').hidden = true;
                run(box.getAttribute('data-job'), +box.getAttribute('data-total'), +box.getAttribute('data-done'), +box.getAttribute('data-sent'));
            });
        }
    })();
    </script>
</body>
</html>
