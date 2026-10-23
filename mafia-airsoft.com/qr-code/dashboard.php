<?php
/**
 * Tableau de bord de gestion des billets
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/icons.php';
requireAdmin();

// Récupérer l'événement sélectionné
$selectedEventId = isset($_GET['event']) ? (int)$_GET['event'] : null;

// Récupérer tous les événements
$stmt = $pdo->query("
    SELECT e.*, 
           COUNT(t.id) as total_tickets,
           SUM(CASE WHEN t.scanned_at IS NOT NULL THEN 1 ELSE 0 END) as scanned_tickets
    FROM events e
    LEFT JOIN tickets t ON e.id = t.event_id
    GROUP BY e.id
    ORDER BY e.event_date DESC
");
$events = $stmt->fetchAll();

// Statistiques pour l'événement sélectionné
$tickets = [];
$stats = [
    'total' => 0,
    'scanned' => 0,
    'pending' => 0,
    'rate' => 0
];

if ($selectedEventId) {
    $stmt = $pdo->prepare("
        SELECT t.id, t.code AS ticket_code, (t.scanned_at IS NOT NULL) AS is_scanned,
               t.scanned_at, t.created_at,
               e.title as event_title,
               e.event_date,
               CONCAT_WS(' ',
                   NULLIF(TRIM(CONCAT_WS(' ', COALESCE(r.first_name, u.first_name), COALESCE(r.last_name, u.last_name))), ''),
                   CONCAT('(', COALESCE(r.pseudo, u.pseudo, 'invité à compléter'), ')')
               ) as user_name,
               COALESCE(u.email, CONCAT(o.buyer_email, ' (acheteur)')) as user_email,
               scanner.pseudo as scanned_by_name
        FROM tickets t
        JOIN registrations r ON r.id = t.registration_id
        JOIN events e ON t.event_id = e.id
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN orders o ON r.order_id = o.id
        LEFT JOIN users scanner ON t.scanned_by = scanner.id
        WHERE t.event_id = ?
        ORDER BY is_scanned ASC, t.created_at DESC
    ");
    $stmt->execute([$selectedEventId]);
    $tickets = $stmt->fetchAll();
    
    $stats['total'] = count($tickets);
    $stats['scanned'] = array_reduce($tickets, fn($sum, $t) => $sum + ($t['is_scanned'] ? 1 : 0), 0);
    $stats['pending'] = $stats['total'] - $stats['scanned'];
    $stats['rate'] = $stats['total'] > 0 ? round(($stats['scanned'] / $stats['total']) * 100, 1) : 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Billets - MAT</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-content {
            padding-top: 120px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 30px;
            border-radius: var(--radius-lg);
            margin-bottom: 30px;
            box-shadow: var(--shadow-red);
        }
        
        .dashboard-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .quick-actions .btn {
            flex: 1;
            min-width: 200px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--tactical-surface);
            padding: 25px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            text-align: center;
            border-left: 4px solid var(--primary);
            border: 1px solid var(--tactical-border);
        }
        
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.primary { border-left-color: var(--primary); }
        
        .stat-number {
            font-size: 48px;
            font-weight: bold;
            color: var(--gray-900);
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .event-selector {
            background: var(--tactical-surface);
            padding: 25px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--tactical-border);
        }
        
        .event-selector h2 {
            margin: 0 0 15px 0;
            color: var(--gray-900);
        }
        
        .event-selector select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--tactical-border);
            border-radius: var(--radius-md);
            font-size: 16px;
            background: var(--tactical-bg);
            color: var(--gray-900);
        }
        
        .tickets-table-container {
            background: var(--tactical-surface);
            padding: 25px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow-x: auto;
            border: 1px solid var(--tactical-border);
        }
        
        .tickets-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .tickets-table th {
            background: var(--tactical-bg);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-900);
            border-bottom: 2px solid var(--tactical-border);
        }
        
        .tickets-table td {
            padding: 15px;
            border-bottom: 1px solid var(--tactical-border);
            color: var(--gray-700);
        }
        
        .tickets-table tr:hover {
            background: var(--tactical-bg);
        }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .ticket-code {
            font-family: 'Courier New', monospace;
            background: var(--tactical-bg);
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            color: var(--primary-light);
            border: 1px solid var(--tactical-border);
        }
        
        .no-data {
            text-align: center;
            padding: 50px;
            color: #999;
            font-size: 18px;
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 10px 20px;
            border: 2px solid var(--tactical-border);
            background: var(--tactical-bg);
            color: var(--gray-700);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .search-box {
            margin-bottom: 20px;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--tactical-border);
            border-radius: var(--radius-md);
            font-size: 16px;
            background: var(--tactical-bg);
            color: var(--gray-900);
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .tickets-table {
                font-size: 14px;
            }
            
            .tickets-table th,
            .tickets-table td {
                padding: 10px 8px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <!-- Header -->
        <div class="dashboard-header">
            <h1><?= icon('ticket', 'Billet') ?> Gestion des Billets</h1>
            <p>Contrôlez et suivez tous les billets d'entrée</p>
            
            <div class="quick-actions">
                <a href="scan.php" class="btn btn-secondary"><?= icon('camera', 'Scanner') ?> Scanner les billets</a>
                <a href="../admin/index.php" class="btn btn-secondary">← Retour admin</a>
            </div>
        </div>

        <!-- Sélecteur d'événement -->
        <div class="event-selector">
            <h2><?= icon('calendar', 'Calendrier') ?> Sélectionner un événement</h2>
            <select onchange="window.location.href='?event=' + this.value">
                <option value="">-- Choisir un événement --</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= $event['id'] ?>" <?= $selectedEventId == $event['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($event['title']) ?> 
                        (<?= date('d/m/Y', strtotime($event['event_date'])) ?>) 
                        - <?= $event['total_tickets'] ?> billets
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($selectedEventId): ?>
            <!-- Statistiques -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total billets</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-number"><?= $stats['scanned'] ?></div>
                    <div class="stat-label"><?= icon('check', 'Validé') ?> Présents</div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-number"><?= $stats['pending'] ?></div>
                    <div class="stat-label"><?= icon('pending', 'Attente') ?> En attente</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['rate'] ?>%</div>
                    <div class="stat-label">Taux de présence</div>
                </div>
            </div>

            <!-- Filtres -->
            <div class="tickets-table-container">
                <div class="filter-tabs">
                    <div class="filter-tab active" onclick="filterTickets('all')">
                        Tous (<?= $stats['total'] ?>)
                    </div>
                    <div class="filter-tab" onclick="filterTickets('scanned')">
                        ✅ Scannés (<?= $stats['scanned'] ?>)
                    </div>
                    <div class="filter-tab" onclick="filterTickets('pending')">
                        ⏳ En attente (<?= $stats['pending'] ?>)
                    </div>
                </div>

                <div class="search-box">
                    <input type="text" id="search" placeholder="Rechercher par nom, email ou code..." onkeyup="searchTickets()">
                </div>

                <!-- Tableau des billets -->
                <?php if (empty($tickets)): ?>
                    <div class="no-data">
                        <?= icon('empty', 'Vide') ?> Aucun billet pour cet événement
                    </div>
                <?php else: ?>
                    <table class="tickets-table" id="tickets-table">
                        <thead>
                            <tr>
                                <th>Statut</th>
                                <th>Code</th>
                                <th>Participant</th>
                                <th>Email</th>
                                <th>Créé le</th>
                                <th>Scanné le</th>
                                <th>Scanné par</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr data-status="<?= $ticket['is_scanned'] ? 'scanned' : 'pending' ?>">
                                    <td>
                                        <?php if ($ticket['is_scanned']): ?>
                                            <span class="badge badge-success"><?= icon('check', 'Validé') ?> Scanné</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><?= icon('pending', 'Attente') ?> En attente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ticket-code"><?= htmlspecialchars($ticket['ticket_code']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($ticket['user_name']) ?></td>
                                    <td><?= htmlspecialchars($ticket['user_email']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?></td>
                                    <td>
                                        <?php if ($ticket['is_scanned']): ?>
                                            <?= date('d/m/Y H:i', strtotime($ticket['scanned_at'])) ?>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ticket['scanned_by_name']): ?>
                                            <?= htmlspecialchars($ticket['scanned_by_name']) ?>
                                        <?php else: ?>
                                            <span style="color: #999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-data" style="background: var(--tactical-surface); padding: 50px; border-radius: var(--radius-lg); border: 1px solid var(--tactical-border);">
                <?= icon('calendar', 'Calendrier') ?> Sélectionnez un événement pour voir les billets
            </div>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script>
        function filterTickets(filter) {
            const rows = document.querySelectorAll('#tickets-table tbody tr');
            const tabs = document.querySelectorAll('.filter-tab');
            
            // Update active tab
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Filter rows
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    row.style.display = status === filter ? '' : 'none';
                }
            });
        }
        
        function searchTickets() {
            const search = document.getElementById('search').value.toLowerCase();
            const rows = document.querySelectorAll('#tickets-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(search) ? '' : 'none';
            });
        }
        
        // Auto-refresh toutes les 30 secondes
        setInterval(() => {
            if (window.location.search.includes('event=')) {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>
