<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/team_helpers.php';

// Parties visibles, séparées entre à venir et passées
$events = getEventsWithTeams($pdo, "e.is_active = 1");
$upcoming = [];
$past = [];
$now = new DateTime();
foreach ($events as $event) {
    if (new DateTime($event['event_date']) >= $now) {
        $upcoming[] = $event;
    } else {
        $past[] = $event;
    }
}
$past = array_slice(array_reverse($past), 0, 8); // les plus récentes d'abord

site_head('Parties', ['description' => 'Agenda des parties d\'airsoft de la Mafia Airsoft Team : dates, lieux, équipes et places restantes. Réserve ta place en ligne.']);
include 'includes/header.php';
?>
<main class="s-wrap">
    <div class="a-page-head">
        <div>
            <h1>Parties</h1>
            <div class="a-sub">Choisis ta partie, ton équipe, et réserve ta place en ligne.</div>
        </div>
    </div>

    <?php if (!isLoggedIn()): ?>
        <div class="alert alert-info" data-keep>
            <a href="login.php?return=events.php">Connecte-toi</a> ou <a href="register.php?return=events.php">crée ton compte</a> pour t'inscrire aux parties.
        </div>
    <?php endif; ?>

    <section>
        <div class="s-section-head">
            <h2><?= ui_icon('calendar', 20) ?> À venir</h2>
        </div>

        <?php if (!$upcoming): ?>
            <div class="a-card s-empty">
                <?= ui_icon('calendar', 40) ?>
                <p>Aucune partie programmée pour le moment.<br>Active les alertes dans ton espace pour être prévenu dès qu'une partie ouvre.</p>
            </div>
        <?php else: ?>
            <div class="s-events">
                <?php foreach ($upcoming as $event):
                    $taken = (int)array_sum(array_column($event['teams'], 'current_players'));
                    $capacity = (int)array_sum(array_column($event['teams'], 'max_players'));
                    $left = max(0, $capacity - $taken);
                    $open = event_registration_is_open($event);
                    [$statusLabel, $statusClass] = !$open ? ['Inscriptions fermées', 'st-warn'] : ($left === 0 ? ['Complet', 'st-warn'] : ['Inscriptions ouvertes', 'st-ok']); ?>
                    <article class="a-card s-event">
                        <a class="s-date" href="event.php?id=<?= (int)$event['id'] ?>" aria-hidden="true" tabindex="-1">
                            <span class="s-date-wd"><?= e(fr_weekday($event['event_date'], true)) ?></span>
                            <span class="s-date-day"><?= (int)date('j', strtotime($event['event_date'])) ?></span>
                            <span class="s-date-month"><?= e(fr_month($event['event_date'], true)) ?></span>
                        </a>
                        <div>
                            <div class="s-event-top">
                                <h3 class="s-event-title"><a href="event.php?id=<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a></h3>
                                <span class="a-badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
                            </div>
                            <div class="a-meta">
                                <span><?= ui_icon('calendar', 16) ?> <?= e(ucfirst(date_fr($event['event_date']))) ?></span>
                                <span><?= ui_icon('pin', 16) ?> <?= location_is_gps($event['location']) ? 'Coordonnées GPS' : e($event['location']) ?></span>
                            </div>
                            <?php if (trim((string)$event['description']) !== ''): ?>
                                <p class="s-event-desc"><?= e(mb_strimwidth(trim($event['description']), 0, 180, '…')) ?></p>
                            <?php endif; ?>
                            <div class="s-event-teams">
                                <?php foreach ($event['teams'] as $team): ?>
                                    <div class="s-team-mini">
                                        <div class="s-team-mini-name">
                                            <span><i class="s-dot" style="background:<?= e(safe_color($team['team_color'])) ?>"></i><?= e($team['team_name']) ?></span>
                                            <span><?= (int)$team['current_players'] ?>/<?= (int)$team['max_players'] ?></span>
                                        </div>
                                        <?= ui_progress((int)$team['current_players'], (int)$team['max_players'], $team['team_color']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="s-event-cta">
                            <span class="s-price"><?= event_is_free($event) ? 'Gratuit' : e(format_price((int)$event['price_cents'])) ?>
                                <?php if (!event_is_free($event)): ?><small>par place</small><?php endif; ?></span>
                            <a href="event.php?id=<?= (int)$event['id'] ?>" class="a-btn <?= $open && $left > 0 ? 'a-btn-primary' : '' ?>">
                                <?= $open && $left > 0 ? 'Voir et réserver' : 'Voir la partie' ?></a>
                            <?php if ($open && $left > 0): ?>
                                <span class="s-places"><?= $left ?> place<?= $left > 1 ? 's' : '' ?> restante<?= $left > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($past): ?>
        <section class="s-section">
            <div class="s-section-head">
                <h2><?= ui_icon('clock', 20) ?> Parties passées</h2>
            </div>
            <div class="a-card s-past">
                <div class="a-list">
                    <?php foreach ($past as $event): ?>
                        <div class="a-row">
                            <span class="s-past-date"><?= e(date('d/m/Y', strtotime($event['event_date']))) ?></span>
                            <div class="a-row-main">
                                <a class="a-row-title" href="event.php?id=<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a>
                            </div>
                            <a class="a-link" href="event.php?id=<?= (int)$event['id'] ?>">Détails →</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
