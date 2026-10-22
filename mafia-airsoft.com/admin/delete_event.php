<?php
require_once '../config/database.php';
require_once '../config/session.php';
requireAdmin();

$event_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    if ($stmt->execute([$event_id])) {
        header('Location: index.php?deleted=1');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supprimer - <?= htmlspecialchars($event['title']) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <main class="container page-content">
        <div class="auth-box" style="max-width: 600px;">
            <h1 style="color: var(--danger);"><?= icon('alert') ?> Confirmer la suppression</h1>
            
            <div class="alert alert-warning">
                <p><strong>Attention !</strong> Vous êtes sur le point de supprimer définitivement cette partie :</p>
                <h3 style="margin: 1rem 0;"><?= htmlspecialchars($event['title']) ?></h3>
                <p>Date: <?= date('d/m/Y à H:i', strtotime($event['event_date'])) ?></p>
                <p>Cette action est irréversible et supprimera également toutes les inscriptions associées.</p>
            </div>

            <form method="POST" style="margin-top: 2rem;">
                <div class="form-actions">
                    <button type="submit" name="confirm_delete" class="btn btn-danger btn-lg">
                        <?= icon('trash') ?> Oui, supprimer définitivement
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg">Annuler</a>
                </div>
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
