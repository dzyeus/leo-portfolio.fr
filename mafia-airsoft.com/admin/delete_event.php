<?php
/**
 * Suppression d'une partie (admin).
 * - Affiche ce qui va être supprimé (inscrits, réservations payées, montant).
 * - Si des places ont été payées : confirmation obligatoire que les paiements
 *   seront remboursés dans Stripe (le site ne rembourse pas tout seul).
 * - Option : prévenir les inscrits par email que la partie est annulée.
 * - Alternative sans perte : masquer la partie (désactivée, tout est conservé).
 */
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    flash_set('error', 'Partie introuvable.');
    header('Location: index.php');
    exit;
}

// ---------- Ce qui est rattaché à la partie
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ?");
$stmt->execute([$event_id]);
$registrationsCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS nb, COALESCE(SUM(amount_cents), 0) AS total
    FROM orders WHERE event_id = ? AND status = 'paid'
");
$stmt->execute([$event_id]);
$paid = $stmt->fetch(PDO::FETCH_ASSOC);
$paidCount = (int)$paid['nb'];
$paidTotal = (int)$paid['total'];

$isUpcoming = strtotime($event['event_date']) > time();

/** Adresses à prévenir : acheteurs des réservations actives + joueurs inscrits avec un compte. */
function event_contacts(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("
        SELECT buyer_email AS email, buyer_name AS name FROM orders
        WHERE event_id = ? AND status IN ('paid', 'free')
        UNION
        SELECT u.email, u.pseudo FROM registrations r JOIN users u ON u.id = r.user_id
        WHERE r.event_id = ?
    ");
    $stmt->execute([$eventId, $eventId]);
    $contacts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contacts[mb_strtolower($row['email'])] = $row;   // une seule fois par adresse
    }
    return array_values($contacts);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'hide') {
        // Alternative sans perte : la partie disparaît du site, tout est conservé
        $pdo->prepare("UPDATE events SET is_active = 0, registration_open = 0 WHERE id = ?")->execute([$event_id]);
        flash_set('success', 'Partie « ' . $event['title'] . ' » masquée : elle n\'apparaît plus sur le site, ses données sont conservées.');
        header('Location: index.php');
        exit;
    }

    if ($action === 'delete') {
        if ($paidCount > 0 && empty($_POST['refund_ack'])) {
            $error = 'Coche la case confirmant que les places payées seront remboursées dans Stripe.';
        } elseif (trim($_POST['confirm_title'] ?? '') !== 'SUPPRIMER') {
            $error = 'Tape SUPPRIMER (en majuscules) pour confirmer.';
        } else {
            // Contacts récupérés AVANT la suppression
            $contacts = (!empty($_POST['notify']) && $isUpcoming) ? event_contacts($pdo, $event_id) : [];

            // Supprime la partie : équipes, inscriptions, billets et réservations suivent (clés étrangères)
            $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);

            $sent = 0;
            foreach ($contacts as $c) {
                $html = '<h2 style="color:#ffffff;margin-top:0;">Partie annulée</h2>'
                    . '<p>Bonjour ' . e($c['name']) . ',</p>'
                    . '<p>La partie <strong>' . e($event['title']) . '</strong> prévue le '
                    . e(date_fr($event['event_date'])) . ' (' . e($event['location']) . ') est <strong>annulée</strong>. '
                    . 'Tes billets ne sont plus valables.</p>'
                    . ($paidCount > 0 ? '<p>Si tu avais payé ta place, tu vas être remboursé sur la carte utilisée '
                        . '(le délai d\'apparition sur ton compte dépend de ta banque).</p>' : '')
                    . '<p>Désolés pour le contretemps, on espère te revoir très vite sur le terrain !</p>'
                    . mail_button(SITE_URL . '/events.php', 'Voir les prochaines parties');
                if (send_mail($c['email'], $c['name'], 'Partie annulée : ' . $event['title'], $html)) {
                    $sent++;
                }
            }

            $msg = 'Partie « ' . $event['title'] . ' » supprimée.';
            if ($contacts) {
                $msg .= ' ' . $sent . ' inscrit(s) prévenu(s) par email.';
            }
            if ($paidCount > 0) {
                $msg .= ' N\'oublie pas de rembourser les ' . $paidCount . ' paiement(s) dans Stripe (Paiements › Rembourser).';
            }
            flash_set('success', $msg);
            header('Location: index.php');
            exit;
        }
    }
}
?>
<?php site_head('Supprimer · ' . $event['title'], ['admin' => true]); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <a class="a-link" href="view_event.php?id=<?= $event_id ?>">← Retour à la partie</a>
                <h1>Supprimer la partie</h1>
                <div class="a-sub"><?= e($event['title']) ?> · <?= e(ucfirst(date_fr($event['event_date']))) ?> · <?= e($event['location']) ?></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error a-flash" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="a-grid a-grid-main">
            <section class="a-card a-danger-card">
                <div class="a-card-head"><h2><?= ui_icon('alert', 18) ?> Suppression définitive</h2></div>
                <ul class="a-list-plain">
                    <li><?= ui_icon('users', 16) ?> <?= $registrationsCount ?> inscrit(s) / place(s) et leurs billets seront supprimés</li>
                    <?php if ($paidCount > 0): ?>
                        <li><?= ui_icon('euro', 16) ?> <span><strong><?= $paidCount ?> réservation(s) payée(s) pour <?= e(format_price($paidTotal)) ?></strong> :
                            le site ne rembourse pas automatiquement, il faudra le faire dans Stripe. Stripe garde l'historique de ces paiements.</span></li>
                    <?php endif; ?>
                    <li><?= ui_icon('alert', 16) ?> Cette action est <strong>irréversible</strong>.</li>
                </ul>

                <form method="POST" class="s-form" style="margin-top:1rem;">
                    <input type="hidden" name="id" value="<?= $event_id ?>">
                    <input type="hidden" name="action" value="delete">

                    <?php if ($isUpcoming && $registrationsCount > 0): ?>
                        <div class="s-check">
                            <input type="checkbox" id="notify" name="notify" value="1" checked>
                            <label for="notify">Prévenir les inscrits par email que la partie est annulée</label>
                        </div>
                    <?php endif; ?>

                    <?php if ($paidCount > 0): ?>
                        <div class="s-check">
                            <input type="checkbox" id="refund_ack" name="refund_ack" value="1" required>
                            <label for="refund_ack">Je rembourserai (ou j'ai déjà remboursé) les <?= $paidCount ?> paiement(s)
                                depuis le Dashboard Stripe (Paiements › Rembourser).</label>
                        </div>
                    <?php endif; ?>

                    <div class="s-field">
                        <label for="confirm_title">Pour confirmer, tape <strong>SUPPRIMER</strong></label>
                        <input type="text" id="confirm_title" name="confirm_title" required autocomplete="off" pattern="SUPPRIMER" placeholder="SUPPRIMER">
                    </div>

                    <div class="s-form-actions">
                        <button type="submit" class="a-btn a-btn-danger a-btn-lg"><?= ui_icon('trash', 18) ?> Supprimer définitivement</button>
                        <a href="view_event.php?id=<?= $event_id ?>" class="a-btn a-btn-lg">Annuler</a>
                    </div>
                </form>
            </section>

            <?php if ($event['is_active']): ?>
                <aside class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('eyeoff', 18) ?> Plutôt la masquer ?</h2></div>
                    <p class="s-muted" style="margin-bottom:1rem;">Elle disparaît du site et les inscriptions se ferment, mais tout est conservé. Tu peux la réactiver depuis « Modifier ».</p>
                    <form method="POST">
                        <input type="hidden" name="id" value="<?= $event_id ?>">
                        <input type="hidden" name="action" value="hide">
                        <button type="submit" class="a-btn a-btn-block"><?= ui_icon('eyeoff', 18) ?> Masquer la partie (réversible)</button>
                    </form>
                </aside>
            <?php endif; ?>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
