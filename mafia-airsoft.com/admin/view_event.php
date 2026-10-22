<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../config/team_helpers.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? 0);

// Récupérer les détails de la partie avec les équipes
$event = getEventWithTeams($pdo, $event_id);

if (!$event) {
    header('Location: index.php');
    exit;
}

// Gérer la suppression d'une inscription (place) : son billet est supprimé avec
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_registration'])) {
    require_csrf();
    $reg_id = (int)($_POST['registration_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM registrations WHERE id = ? AND event_id = ?");
    $stmt->execute([$reg_id, $event_id]);
    header('Location: view_event.php?id=' . $event_id . '&deleted=1');
    exit;
}

// Récupérer tous les inscrits (joueurs et invités) avec leur billet et leur paiement
$stmt = $pdo->prepare("
    SELECT r.*,
           COALESCE(r.pseudo, u.pseudo) AS pseudo,
           TRIM(CONCAT_WS(' ', COALESCE(r.first_name, u.first_name), COALESCE(r.last_name, u.last_name))) AS full_name,
           COALESCE(u.email, o.buyer_email) AS email,
           u.phone,
           o.id AS order_id, o.status AS order_status, o.buyer_name,
           t.scanned_at
    FROM registrations r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN orders o ON r.order_id = o.id
    LEFT JOIN tickets t ON t.registration_id = r.id
    WHERE r.event_id = ?
    ORDER BY r.team, r.registered_at
");
$stmt->execute([$event_id]);
$registrations = $stmt->fetchAll();

// Organiser les inscriptions par équipe
$teams = [];
foreach ($event['teams'] as $team_info) {
    $teams[$team_info['team_key']] = [];
}

foreach ($registrations as $reg) {
    if (isset($teams[$reg['team']])) {
        $teams[$reg['team']][] = $reg;
    }
}

// Recettes de la partie
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM orders WHERE event_id = ? AND status = 'paid'");
$stmt->execute([$event_id]);
$total_paid_cents = (int)$stmt->fetchColumn();
$present_count = count(array_filter($registrations, fn($r) => !empty($r['scanned_at'])));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails - <?= htmlspecialchars($event['title']) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="admin-view">
            <div class="page-header">
                <h1><?= icon('eye') ?> <?= htmlspecialchars($event['title']) ?></h1>
                <div class="header-actions">
                    <a href="edit_event.php?id=<?= $event['id'] ?>" class="btn btn-primary"><?= icon('edit') ?> Modifier</a>
                    <a href="index.php" class="btn btn-secondary">← Retour</a>
                </div>
            </div>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">Inscription supprimée avec succès.</div>
            <?php endif; ?>

            <!-- Informations de la partie -->
            <section class="info-section">
                <h2><?= icon('list') ?> Informations</h2>
                <div class="info-grid-large">
                    <div class="info-item">
                        <strong>Date et heure:</strong> <?= date('d/m/Y à H:i', strtotime($event['event_date'])) ?>
                    </div>
                    <div class="info-item">
                        <strong>Lieu:</strong> <?= htmlspecialchars($event['location']) ?>
                    </div>
                    <div class="info-item">
                        <strong>Statut:</strong> 
                        <?= $event['is_active'] ? '<span class="badge badge-success">Actif</span>' : '<span class="badge badge-danger">Inactif</span>' ?>
                        <?= $event['registration_open'] ? '<span class="badge badge-success">Inscriptions ouvertes</span>' : '<span class="badge badge-warning">Inscriptions fermées</span>' ?>
                    </div>
                    <div class="info-item">
                        <strong>Total inscrits:</strong> <?= count($registrations) ?>
                        (<?= $present_count ?> présent<?= $present_count > 1 ? 's' : '' ?>)
                    </div>
                    <div class="info-item">
                        <strong>Prix:</strong> <?= event_is_free($event) ? 'Gratuit' : e(format_price((int)$event['price_cents'])) . ' / place' ?>
                        · <strong>Encaissé:</strong> <?= e(format_price($total_paid_cents)) ?>
                    </div>
                </div>

                <?php if ($event['description']): ?>
                    <div class="info-block">
                        <strong>Description:</strong>
                        <p><?= nl2br(htmlspecialchars($event['description'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($event['scenario']): ?>
                    <div class="info-block">
                        <strong>Scénario:</strong>
                        <p><?= nl2br(htmlspecialchars($event['scenario'])) ?></p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Statistiques par camp -->
            <section class="stats-section">
                <h2><?= icon('stats') ?> Répartition des équipes</h2>
                <div class="team-stats-grid">
                    <?php foreach ($event['teams'] as $team): ?>
                        <div class="team-stat-card" style="border-top: 3px solid <?= htmlspecialchars($team['team_color']) ?>">
                            <h3><?= htmlspecialchars($team['team_name']) ?></h3>
                            <div class="stat-big"><?= $team['current_players'] ?> / <?= $team['max_players'] ?></div>
                            <div class="stat-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= ($team['max_players'] > 0) ? ($team['current_players'] / $team['max_players'] * 100) : 0 ?>%; background: <?= htmlspecialchars($team['team_color']) ?>"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Liste des inscrits par camp -->
            <section class="registrations-section">
                <h2><?= icon('users') ?> Liste des inscrits</h2>
                
                <?php foreach ($event['teams'] as $team_info): ?>
                    <?php $team_key = $team_info['team_key']; ?>
                    <div class="team-registration-block">
                        <h3 style="border-left: 4px solid <?= htmlspecialchars($team_info['team_color']) ?>">
                            <?= htmlspecialchars($team_info['team_name']) ?> 
                            (<?= isset($teams[$team_key]) ? count($teams[$team_key]) : 0 ?>)
                        </h3>
                        
                        <?php if (empty($teams[$team_key])): ?>
                            <p class="no-data">Aucun joueur inscrit</p>
                        <?php else: ?>
                                <table class="players-table">
                                    <thead>
                                        <tr>
                                            <th>Pseudo</th>
                                            <th>Nom</th>
                                            <th>Email</th>
                                            <th>Téléphone</th>
                                            <th>Paiement</th>
                                            <th>Présent</th>
                                            <th>Inscrit le</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teams[$team_key] as $reg): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($reg['pseudo'] ?: 'Invité') ?></strong></td>
                                                <td>
                                                    <?= htmlspecialchars($reg['full_name'] ?: 'À compléter') ?>
                                                    <?php if (!$reg['user_id']): ?>
                                                        <br><small>invité de <?= htmlspecialchars($reg['buyer_name'] ?? '?') ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($reg['email'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($reg['phone'] ?: '-') ?></td>
                                                <td>
                                                    <?php if ($reg['order_id']): ?>
                                                        <a href="../order.php?id=<?= (int)$reg['order_id'] ?>">n° <?= (int)$reg['order_id'] ?></a>
                                                        <small>(<?= e(order_status_label($reg['order_status'])) ?>)</small>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $reg['scanned_at'] ? '✅ ' . date('H:i', strtotime($reg['scanned_at'])) : '-' ?></td>
                                                <td><?= date('d/m/Y H:i', strtotime($reg['registered_at'])) ?></td>
                                                <td>
                                                    <form method="POST" style="display: inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="registration_id" value="<?= $reg['id'] ?>">
                                                        <button type="submit" name="delete_registration" class="btn-icon-danger" 
                                                                onclick="return confirm('<?= $reg['order_status'] === 'paid' ? 'Place payée : pense à rembourser dans Stripe (le remboursement total supprime aussi les places automatiquement). ' : '' ?>Supprimer cette inscription et son billet ?')" title="Supprimer">
                                                            <?= icon('trash') ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                <?php endforeach; ?>
            </section>

            <!-- Export -->
            <section class="export-section">
                <h2>📥 Exporter les données</h2>
                <p>Exportez la liste des inscrits pour impression ou traitement externe.</p>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ Imprimer</button>
            </section>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
