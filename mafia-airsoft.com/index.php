<?php
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'config/team_helpers.php';

// Prochaines parties visibles (la première est mise en avant)
$upcoming_events = getEventsWithTeams($pdo, "e.is_active = 1 AND e.event_date >= NOW()");
$next_event = $upcoming_events[0] ?? null;
$other_events = array_slice($upcoming_events, 1, 3);

// Statistiques publiques
$total_events = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE is_active = 1")->fetchColumn();
$total_players = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 0")->fetchColumn();

// Photos de la galerie
$gallery_photos = $pdo->query("SELECT * FROM gallery WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$hero_photo = $gallery_photos[0]['image_path'] ?? '';

/** Places prises / capacité totale d'une partie. */
function event_fill(array $event): array
{
    return [
        (int)array_sum(array_column($event['teams'], 'current_players')),
        (int)array_sum(array_column($event['teams'], 'max_players')),
    ];
}

site_head('', [
    'description' => 'Mafia Airsoft Team, association d\'airsoft du Puy-de-Dôme : parties en forêt, scénarios tactiques et fair-play. Réserve ta place en ligne.',
    'image' => $hero_photo ?: 'images/logo.png',
]);
include 'includes/header.php';
?>
<main class="s-wrap">
    <!-- Bandeau d'accueil -->
    <section class="s-hero">
        <?php if ($hero_photo): ?>
            <img class="s-hero-img" src="<?= e($hero_photo) ?>" alt="" fetchpriority="high">
        <?php else: ?>
            <img class="s-hero-logo" src="images/logo.png" alt="">
        <?php endif; ?>
        <div class="s-hero-content">
            <span class="s-kicker"><?= ui_icon('target', 16) ?> Association d'airsoft · Puy-de-Dôme</span>
            <h1>Mafia Airsoft Team</h1>
            <p class="s-lead">Parties en forêt, scénarios tactiques et fair-play. Réserve ta place en ligne en quelques secondes et reçois ton billet par email.</p>
            <div class="s-hero-actions">
                <?php if ($next_event): ?>
                    <a href="event.php?id=<?= (int)$next_event['id'] ?>" class="a-btn a-btn-primary a-btn-lg"><?= ui_icon('ticket', 19) ?> Réserver la prochaine partie</a>
                <?php else: ?>
                    <a href="events.php" class="a-btn a-btn-primary a-btn-lg"><?= ui_icon('calendar', 19) ?> Voir les parties</a>
                <?php endif; ?>
                <?php if (!isLoggedIn()): ?>
                    <a href="register.php" class="a-btn a-btn-lg"><?= ui_icon('user', 19) ?> Créer un compte</a>
                <?php else: ?>
                    <a href="player/dashboard.php" class="a-btn a-btn-lg"><?= ui_icon('user', 19) ?> Mon espace</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Prochaine partie -->
    <section class="s-section">
        <div class="s-section-head">
            <h2><?= ui_icon('calendar', 20) ?> Prochaine partie</h2>
            <a class="a-link" href="events.php">Toutes les parties →</a>
        </div>

        <?php if (!$next_event): ?>
            <div class="a-card s-empty">
                <?= ui_icon('calendar', 40) ?>
                <p>Aucune partie programmée pour le moment. Crée ton compte et active les alertes : tu seras prévenu par email dès qu'une partie sera ouverte.</p>
                <?php if (!isLoggedIn()): ?><a href="register.php" class="a-btn a-btn-primary">Créer un compte</a><?php endif; ?>
            </div>
        <?php else:
            [$taken, $capacity] = event_fill($next_event);
            $left = max(0, $capacity - $taken);
            $open = event_registration_is_open($next_event); ?>
            <article class="a-card s-next">
                <div class="s-next-grid">
                    <div>
                        <span class="s-kicker"><?= ui_icon('clock', 15) ?> <?= e(days_until_fr($next_event['event_date'])) ?></span>
                        <h3 class="s-next-title"><a href="event.php?id=<?= (int)$next_event['id'] ?>"><?= e($next_event['title']) ?></a></h3>
                        <div class="a-meta">
                            <span><?= ui_icon('calendar', 16) ?> <?= e(ucfirst(date_fr($next_event['event_date'], false))) ?></span>
                            <span><?= ui_icon('clock', 16) ?> <?= e(time_fr($next_event['event_date'])) ?></span>
                            <span><?= ui_icon('pin', 16) ?> <?= location_is_gps($next_event['location']) ? 'Coordonnées GPS' : e($next_event['location']) ?></span>
                        </div>
                        <?php if (trim((string)$next_event['description']) !== ''): ?>
                            <p class="s-next-desc"><?= e(mb_strimwidth(trim($next_event['description']), 0, 220, '…')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="s-next-side">
                        <div class="s-book-price">
                            <span class="s-price"><?= event_is_free($next_event) ? 'Gratuit' : e(format_price((int)$next_event['price_cents'])) ?></span>
                            <?php if (!event_is_free($next_event)): ?><span class="s-muted">par place</span><?php endif; ?>
                        </div>
                        <div class="s-event-teams" style="margin-top:0;">
                            <?php foreach ($next_event['teams'] as $team): ?>
                                <div class="s-team-mini">
                                    <div class="s-team-mini-name">
                                        <span><i class="s-dot" style="background:<?= e(safe_color($team['team_color'])) ?>"></i><?= e($team['team_name']) ?></span>
                                        <span><?= (int)$team['current_players'] ?>/<?= (int)$team['max_players'] ?></span>
                                    </div>
                                    <?= ui_progress((int)$team['current_players'], (int)$team['max_players'], $team['team_color']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="event.php?id=<?= (int)$next_event['id'] ?>" class="a-btn a-btn-primary a-btn-lg a-btn-block">
                            <?= $open ? ui_icon('ticket', 19) . ' Réserver ma place' : ui_icon('eye', 19) . ' Voir la partie' ?></a>
                        <span class="s-places s-center"><?= $open ? ($left > 0 ? $left . ' place' . ($left > 1 ? 's' : '') . ' restante' . ($left > 1 ? 's' : '') : 'Complet') : 'Inscriptions fermées' ?></span>
                    </div>
                </div>
            </article>

            <?php if ($other_events): ?>
                <div class="s-events" style="margin-top:1rem;">
                    <?php foreach ($other_events as $event):
                        [$taken, $capacity] = event_fill($event); ?>
                        <article class="a-card s-event">
                            <a class="s-date" href="event.php?id=<?= (int)$event['id'] ?>" aria-hidden="true" tabindex="-1">
                                <span class="s-date-wd"><?= e(fr_weekday($event['event_date'], true)) ?></span>
                                <span class="s-date-day"><?= (int)date('j', strtotime($event['event_date'])) ?></span>
                                <span class="s-date-month"><?= e(fr_month($event['event_date'], true)) ?></span>
                            </a>
                            <div>
                                <h3 class="s-event-title"><a href="event.php?id=<?= (int)$event['id'] ?>"><?= e($event['title']) ?></a></h3>
                                <div class="a-meta">
                                    <span><?= ui_icon('clock', 16) ?> <?= e(time_fr($event['event_date'])) ?></span>
                                    <span><?= ui_icon('pin', 16) ?> <?= location_is_gps($event['location']) ? 'Coordonnées GPS' : e($event['location']) ?></span>
                                    <span><?= ui_icon('users', 16) ?> <?= $taken ?>/<?= $capacity ?> joueurs</span>
                                </div>
                            </div>
                            <div class="s-event-cta">
                                <span class="s-price"><?= event_is_free($event) ? 'Gratuit' : e(format_price((int)$event['price_cents'])) ?></span>
                                <a href="event.php?id=<?= (int)$event['id'] ?>" class="a-btn">Voir la partie</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Photos -->
    <?php if ($gallery_photos): ?>
        <section class="s-section" data-strip>
            <div class="s-section-head">
                <h2><?= ui_icon('camera', 20) ?> En images</h2>
                <?php if (count($gallery_photos) > 1): ?>
                    <div class="s-strip-nav">
                        <button type="button" class="a-btn a-btn-icon a-btn-sm" data-strip-dir="-1" aria-label="Photos précédentes"><?= ui_icon('chevronleft', 18) ?></button>
                        <button type="button" class="a-btn a-btn-icon a-btn-sm" data-strip-dir="1" aria-label="Photos suivantes"><?= ui_icon('chevron', 18) ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="s-strip">
                <?php foreach ($gallery_photos as $photo): ?>
                    <figure class="s-photo">
                        <img src="<?= e($photo['image_path']) ?>" alt="<?= e($photo['title'] ?: 'Photo de la Mafia Airsoft Team') ?>" loading="lazy">
                        <?php if ($photo['title'] || $photo['description']): ?>
                            <figcaption>
                                <?php if ($photo['title']): ?><strong><?= e($photo['title']) ?></strong><?php endif; ?>
                                <?php if ($photo['description']): ?><span><?= e($photo['description']) ?></span><?php endif; ?>
                            </figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- L'association -->
    <section class="s-section">
        <div class="s-section-head">
            <h2><?= ui_icon('users', 20) ?> L'association</h2>
        </div>
        <div class="s-features">
            <div class="a-card s-feature">
                <span class="s-feature-ico"><?= ui_icon('target', 22) ?></span>
                <h3>Jeu tactique</h3>
                <p>Des scénarios avec des objectifs pour chaque camp, en forêt, pour jouer en équipe et réfléchir avant de tirer.</p>
            </div>
            <div class="a-card s-feature">
                <span class="s-feature-ico"><?= ui_icon('shield', 22) ?></span>
                <h3>Sécurité d'abord</h3>
                <p>Briefing avant chaque partie, protections obligatoires et règles respectées par tous, organisateurs compris.</p>
            </div>
            <div class="a-card s-feature">
                <span class="s-feature-ico"><?= ui_icon('usercheck', 22) ?></span>
                <h3>Fair-play</h3>
                <p>Respect des adversaires, honnêteté sur les touches et bonne ambiance : plus qu'un groupe d'amis, une famille.</p>
            </div>
        </div>
        <div class="s-kpis">
            <div class="a-kpi">
                <span class="a-kpi-label"><?= ui_icon('calendar', 15) ?> Parties organisées</span>
                <span class="a-kpi-value"><?= $total_events ?></span>
            </div>
            <div class="a-kpi">
                <span class="a-kpi-label"><?= ui_icon('users', 15) ?> Joueurs inscrits</span>
                <span class="a-kpi-value"><?= $total_players ?></span>
            </div>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
