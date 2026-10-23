<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
requireLogin();

// Case « Me prévenir des nouvelles parties »
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notifications') {
    $notify = !empty($_POST['notify_new_events']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET notify_new_events = ? WHERE id = ?")->execute([$notify, $_SESSION['user_id']]);
    flash_set('success', $notify
        ? '🔔 C\'est noté : tu recevras un email à chaque nouvelle partie ouverte aux inscriptions.'
        : '🔕 Alertes désactivées : tu ne recevras plus d\'email pour les nouvelles parties.');
    header('Location: dashboard.php');
    exit;
}

// Récupérer les informations du joueur
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Récupérer les inscriptions du joueur
$stmt = $pdo->prepare("
    SELECT e.*, r.team, r.registered_at, r.id as registration_id,
           et.team_name, et.team_color
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN event_teams et ON r.event_id = et.event_id AND r.team = et.team_key
    WHERE r.user_id = ?
    ORDER BY e.event_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$registrations = $stmt->fetchAll();

// Enrichir avec les données d'équipe
foreach ($registrations as &$reg) {
    $team_info = getEventTeam($pdo, $reg['id'], $reg['team']);
    if ($team_info) {
        $reg['team_name'] = $team_info['team_name'];
        $reg['team_color'] = $team_info['team_color'];
    } else {
        $reg['team_name'] = $reg['team'];
        $reg['team_color'] = '#a3a3a3';
    }
}
unset($reg);

// Réservations (commandes) du joueur
$stmt = $pdo->prepare("
    SELECT o.*, e.title AS event_title, e.event_date
    FROM orders o
    JOIN events e ON e.id = o.event_id
    WHERE o.user_id = ? AND o.status IN ('paid', 'free', 'pending', 'refunded')
    ORDER BY o.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$orders = $stmt->fetchAll();

$flashes = flash_get();

// Séparer les inscriptions futures et passées
$upcoming_registrations = [];
$past_registrations = [];
$now = new DateTime();

foreach ($registrations as $reg) {
    $eventDate = new DateTime($reg['event_date']);
    if ($eventDate >= $now) {
        $upcoming_registrations[] = $reg;
    } else {
        $past_registrations[] = $reg;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Espace - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="dashboard">
            <h1><?= icon('user') ?> Mon espace joueur</h1>
            <p class="welcome-text">Bienvenue, <strong><?= htmlspecialchars($user['pseudo']) ?></strong> !</p>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>

            <?php if (!profile_is_complete($user)): ?>
                <div class="alert alert-warning">
                    Complète ton prénom et ton nom pour pouvoir réserver tes places.
                    <a href="profile.php?complete=1">Compléter mon profil</a>
                </div>
            <?php endif; ?>

            <!-- Informations personnelles -->
            <section class="dashboard-section">
                <h2><?= icon('edit') ?> Mes informations</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>Pseudo:</strong> <?= htmlspecialchars($user['pseudo']) ?>
                    </div>
                    <div class="info-item">
                        <strong>Nom:</strong> <?= htmlspecialchars(full_name($user['first_name'] ?? '', $user['last_name'] ?? '') ?: '-') ?>
                    </div>
                    <div class="info-item">
                        <strong>Email:</strong> <?= htmlspecialchars($user['email']) ?>
                    </div>
                    <?php if ($user['phone']): ?>
                    <div class="info-item">
                        <strong>Téléphone:</strong> <?= htmlspecialchars($user['phone']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <strong>Membre depuis:</strong> <?= date('d/m/Y', strtotime($user['created_at'])) ?>
                    </div>
                </div>
                <a href="profile.php" class="btn btn-secondary btn-sm" style="margin-top: var(--spacing-md);">Modifier mon profil / mot de passe</a>
            </section>

            <!-- Alertes nouvelles parties -->
            <section class="dashboard-section">
                <h2>🔔 Nouvelles parties</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="notifications">
                    <div class="form-group-checkbox">
                        <input type="checkbox" id="notify_new_events" name="notify_new_events" value="1"
                               <?= !empty($user['notify_new_events']) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label for="notify_new_events">Me prévenir par email dès qu'une nouvelle partie est ouverte aux inscriptions</label>
                    </div>
                    <noscript><button type="submit" class="btn btn-secondary btn-sm">Enregistrer</button></noscript>
                    <small style="color:#a3a3a3;">Tu peux changer d'avis à tout moment, et chaque email contient un lien de désabonnement.</small>
                </form>
            </section>

            <!-- Statistiques -->
            <section class="dashboard-section">
                <h2><?= icon('stats') ?> Mes statistiques</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= count($upcoming_registrations) ?></div>
                        <div class="stat-label">Parties à venir</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= count($past_registrations) ?></div>
                        <div class="stat-label">Parties jouées</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= count($registrations) ?></div>
                        <div class="stat-label">Total inscriptions</div>
                    </div>
                </div>
            </section>

            <!-- Prochaines parties -->
            <section class="dashboard-section">
                <h2><?= icon('target') ?> Mes prochaines parties</h2>
                
                <?php if (empty($upcoming_registrations)): ?>
                    <p class="no-data">Vous n'êtes inscrit à aucune partie pour le moment.</p>
                    <a href="../events.php" class="btn btn-primary">Voir les parties disponibles</a>
                <?php else: ?>
                    <div class="registrations-list">
                        <?php foreach ($upcoming_registrations as $reg): ?>
                            <div class="registration-card">
                                <div class="registration-header">
                                    <h3><?= htmlspecialchars($reg['title']) ?></h3>
                                    <span class="team-badge" style="background-color: <?= htmlspecialchars($reg['team_color']) ?>20; border-left: 3px solid <?= htmlspecialchars($reg['team_color']) ?>">
                                        <?= htmlspecialchars($reg['team_name']) ?>
                                    </span>
                                </div>
                                
                                <div class="registration-info">
                                    <div class="info-row">
                                        <span><?= icon('calendar') ?> <strong>Date:</strong></span>
                                        <span><?= date('d/m/Y', strtotime($reg['event_date'])) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span>🕐 <strong>Heure:</strong></span>
                                        <span><?= date('H:i', strtotime($reg['event_date'])) ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span>📍 <strong>Lieu:</strong></span>
                                        <span><?= htmlspecialchars($reg['location']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="registration-actions">
                                    <a href="../event.php?id=<?= $reg['id'] ?>" class="btn btn-primary btn-sm">
                                        Voir les détails
                                    </a>
                                    <a href="../ticket.php?registration=<?= (int)$reg['registration_id'] ?>" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">
                                        🎫 Mon billet
                                    </a>
                                    <small class="registration-date">
                                        Inscrit le <?= date('d/m/Y', strtotime($reg['registered_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Réservations et paiements -->
            <?php if (!empty($orders)): ?>
                <section class="dashboard-section">
                    <h2>🧾 Mes réservations</h2>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>N°</th>
                                    <th>Partie</th>
                                    <th>Places</th>
                                    <th>Montant</th>
                                    <th>Statut</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><?= (int)$order['id'] ?></td>
                                        <td><?= e($order['event_title']) ?><br><small><?= date('d/m/Y', strtotime($order['event_date'])) ?></small></td>
                                        <td><?= (int)$order['quantity'] ?></td>
                                        <td><?= (int)$order['amount_cents'] > 0 ? e(format_price((int)$order['amount_cents'])) : 'Gratuit' ?></td>
                                        <td><?= e(order_status_label($order['status'])) ?></td>
                                        <td>
                                            <a href="../order.php?id=<?= (int)$order['id'] ?>" class="btn-link">Billets / invités</a>
                                            <?php if (in_array($order['status'], ['paid', 'refunded'], true)): ?>
                                                · <a href="../receipt.php?order=<?= (int)$order['id'] ?>" class="btn-link" target="_blank" rel="noopener">Reçu</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Historique -->
            <?php if (!empty($past_registrations)): ?>
                <section class="dashboard-section">
                    <h2>📜 Historique des parties</h2>
                    <div class="history-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Partie</th>
                                    <th>Camp</th>
                                    <th>Lieu</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($past_registrations as $reg): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($reg['event_date'])) ?></td>
                                        <td><?= htmlspecialchars($reg['title']) ?></td>
                                        <td>
                                            <span class="team-badge-small team-badge-<?= strtolower($reg['team']) ?>">
                                                <?= $reg['team'] ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($reg['location']) ?></td>
                                        <td>
                                            <a href="../event.php?id=<?= $reg['id'] ?>" class="btn-link">Détails</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
