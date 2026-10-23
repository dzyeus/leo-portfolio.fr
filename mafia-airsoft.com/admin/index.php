<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
requireAdmin();

// Récupérer toutes les parties avec équipes
$events = getEventsWithTeams($pdo, "1=1");

// Statistiques globales
$total_events = count($events);
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 0");
$total_players = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM registrations");
$total_registrations = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Association Airsoft</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="../images/favicon.ico">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-panel">
            <h1><?= icon('settings') ?> Espace Administration</h1>

            <?php foreach (flash_get() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>

            <!-- Statistiques -->
            <section class="dashboard-section">
                <h2><?= icon('stats') ?> Statistiques générales</h2>
                <div class="stats-grid">
                    <div class="stat-card stat-primary">
                        <div class="stat-number"><?= $total_events ?></div>
                        <div class="stat-label">Parties créées</div>
                    </div>
                    <div class="stat-card stat-success">
                        <div class="stat-number"><?= $total_players ?></div>
                        <div class="stat-label">Joueurs inscrits</div>
                    </div>
                    <div class="stat-card stat-info">
                        <div class="stat-number"><?= $total_registrations ?></div>
                        <div class="stat-label">Inscriptions totales</div>
                    </div>
                </div>
            </section>

            <!-- Actions rapides -->
            <section class="dashboard-section">
                <div class="admin-actions">
                    <a href="create_event.php" class="btn btn-primary btn-lg">
                        <?= icon('plus') ?> Créer une nouvelle partie
                    </a>
                    <a href="players.php" class="btn btn-secondary btn-lg">
                        <?= icon('users') ?> Gérer les joueurs
                    </a>
                    <a href="../qr-code/dashboard.php" class="btn btn-secondary btn-lg">
                        <?= icon('ticket') ?> Gestion des billets
                    </a>
                    <a href="diagnostic.php" class="btn btn-secondary btn-lg">
                        🩺 Diagnostic (paiement, emails)
                    </a>
                    <a href="manage_gallery.php" class="btn btn-secondary btn-lg">
                        <?= icon('image') ?> Gérer la galerie photo
                    </a>
                </div>
            </section>

            <!-- Liste des parties -->
            <section class="dashboard-section">
                <h2><?= icon('calendar') ?> Gestion des parties</h2>
                
                <?php if (empty($events)): ?>
                    <p class="no-data">Aucune partie créée.</p>
                <?php else: ?>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Titre</th>
                                    <th>Date</th>
                                    <th>Lieu</th>
                                    <th>Inscrits</th>
                                    <th>Équipes</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?= $event['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($event['title']) ?></strong></td>
                                        <td><?= date('d/m/Y H:i', strtotime($event['event_date'])) ?></td>
                                        <td><?= htmlspecialchars(substr($event['location'], 0, 30)) ?></td>
                                        <td>
                                            <strong><?= $event['total_players'] ?></strong>
                                        </td>
                                        <td>
                                            <?php foreach ($event['teams'] as $team): ?>
                                                <span class="team-count-badge" style="border-left: 3px solid <?= htmlspecialchars($team['team_color']) ?>">
                                                    <?= $team['current_players'] ?>/<?= $team['max_players'] ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['is_active']): ?>
                                                <span class="badge badge-success">Actif</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Inactif</span>
                                            <?php endif; ?>
                                            <?php if (!$event['registration_open']): ?>
                                                <span class="badge badge-warning">Fermé</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-actions">
                                            <a href="view_event.php?id=<?= $event['id'] ?>" class="btn-icon" title="Voir"><?= icon('eye') ?></a>
                                            <a href="edit_event.php?id=<?= $event['id'] ?>" class="btn-icon" title="Modifier"><?= icon('edit') ?></a>
                                            <a href="delete_event.php?id=<?= $event['id'] ?>" class="btn-icon" title="Supprimer la partie"><?= icon('trash') ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
