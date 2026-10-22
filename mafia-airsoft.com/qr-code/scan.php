<?php
/**
 * Interface de scan des billets (optimisée pour mobile)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/icons.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Scanner les billets - MAT</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-content {
            padding-top: 120px;
            max-width: 700px;
            margin: 0 auto;
        }
        
        .scan-wrapper {
            background: var(--tactical-surface);
            border: 1px solid var(--tactical-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg), var(--shadow-red);
        }
        
        .scan-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 30px;
            text-align: center;
            border-bottom: 3px solid var(--primary-light);
        }
        
        .scan-header h1 {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-family: 'Orbitron', sans-serif;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .scan-header h1 .icon {
            width: 32px;
            height: 32px;
            filter: brightness(0) invert(1);
        }
        
        .scan-header p {
            color: rgba(255,255,255,0.9);
            font-size: 14px;
        }
        
        .scanner-box {
            background: var(--tactical-surface);
            padding: 25px;
        }
        
        #reader {
            width: 100%;
            border-radius: var(--radius-md);
            overflow: hidden;
            margin-bottom: 20px;
            border: 2px solid var(--tactical-border);
            background: var(--tactical-bg);
        }
        
        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .controls .btn {
            flex: 1;
            padding: 15px;
            border: 2px solid transparent;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .controls .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .controls .btn-primary:hover:not(:disabled) {
            background: var(--primary-light);
            box-shadow: 0 0 15px var(--primary);
        }
        
        .controls .btn-secondary {
            background: var(--gray-300);
            color: var(--gray-900);
            border-color: var(--gray-400);
        }
        
        .controls .btn-secondary:hover:not(:disabled) {
            background: var(--gray-400);
        }
        
        .controls .btn-success {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }
        
        .controls .btn-success:hover:not(:disabled) {
            background: #1ea03f;
            box-shadow: 0 0 15px var(--success);
        }
        
        .controls .btn .icon {
            width: 20px;
            height: 20px;
            filter: brightness(0) invert(1);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn:active:not(:disabled) {
            transform: scale(0.98);
        }
        
        .result {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .result-success {
            background: rgba(34, 197, 94, 0.1);
            border: 2px solid var(--success);
            color: var(--gray-900);
            border-left: 4px solid var(--success);
        }
        
        .result-error {
            background: rgba(220, 38, 38, 0.1);
            border: 2px solid var(--danger);
            color: var(--gray-900);
            border-left: 4px solid var(--danger);
        }
        
        .result-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 2px solid var(--warning);
            color: var(--gray-900);
            border-left: 4px solid var(--warning);
        }
        
        .result-icon {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .result-icon .icon {
            width: 48px;
            height: 48px;
        }
        
        .result-success .result-icon .icon {
            filter: brightness(0) saturate(100%) invert(56%) sepia(89%) saturate(435%) hue-rotate(83deg);
        }
        
        .result-error .result-icon .icon {
            filter: brightness(0) saturate(100%) invert(18%) sepia(98%) saturate(6943%) hue-rotate(355deg);
        }
        
        .result-warning .result-icon .icon {
            filter: brightness(0) saturate(100%) invert(75%) sepia(66%) saturate(1838%) hue-rotate(1deg);
        }
        
        .result h3 {
            text-align: center;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .ticket-info {
            background: var(--tactical-bg);
            padding: 15px;
            border-radius: var(--radius-md);
            margin-top: 15px;
            border: 1px solid var(--tactical-border);
        }
        
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid var(--tactical-border);
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            width: 130px;
            color: var(--gray-600);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            color: var(--gray-900);
            flex: 1;
            font-weight: 500;
        }
        
        .stats {
            background: var(--tactical-bg);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--tactical-border);
            border-radius: var(--radius-lg);
        }
        
        .stats h2 {
            color: var(--gray-900);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .stats h2 .icon {
            filter: brightness(0) saturate(100%) invert(18%) sepia(98%) saturate(6943%) hue-rotate(355deg);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .stat-card {
            background: var(--tactical-surface);
            padding: 20px;
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid var(--tactical-border);
            border-left: 3px solid var(--primary);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--primary-light);
            font-family: 'Orbitron', sans-serif;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .manual-input {
            display: none;
            margin-top: 15px;
        }
        
        .manual-input.active {
            display: block;
        }
        
        .input-group {
            display: flex;
            gap: 10px;
        }
        
        input[type="text"] {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--tactical-border);
            border-radius: var(--radius-md);
            font-size: 16px;
            background: var(--tactical-bg);
            color: var(--gray-900);
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: var(--gray-700);
        }
        
        .spinner {
            border: 4px solid var(--tactical-border);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .sound-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid var(--primary-light);
            box-shadow: var(--shadow-lg), var(--shadow-red);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sound-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 0 20px var(--primary);
        }
        
        .sound-toggle .icon {
            width: 28px;
            height: 28px;
            filter: brightness(0) invert(1);
        }
        
        .action-links {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            flex-wrap: wrap;
        }
        
        .action-links .btn {
            flex: 1;
            min-width: 150px;
            text-decoration: none;
        }
        
        .action-links .btn .icon {
            width: 20px;
            height: 20px;
            filter: brightness(0) invert(1);
        }
        
        @media (max-width: 768px) {
            .page-content {
                padding-top: 100px;
            }
            
            .scan-header h1 {
                font-size: 22px;
            }
            
            .controls {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <main class="page-content">
        <!-- Statistiques -->
        <div class="stats">
            <h2><?= icon('stats', 'Statistiques') ?> Session de scan</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" id="scan-count">0</div>
                    <div class="stat-label">Scannés</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="success-count">0</div>
                    <div class="stat-label">Validés</div>
                </div>
            </div>
        </div>
        
        <!-- Scanner -->
        <div class="scan-wrapper">
            <div class="scan-header">
                <h1><?= icon('ticket', 'Billet') ?> Scanner</h1>
                <p>Pointez la caméra vers le QR code du billet</p>
            </div>
        
        <div class="scanner-box">
            <!-- Résultat du scan -->
            <div id="result-container"></div>
            
            <!-- Scanner QR Code -->
            <div id="reader"></div>
            
            <!-- Contrôles -->
            <div class="controls">
                <button id="start-scan" class="btn btn-success" onclick="startScanning()">
                    <?= icon('camera', 'Caméra') ?> Démarrer
                </button>
                <button id="stop-scan" class="btn btn-secondary" onclick="stopScanning()" style="display: none;">
                    <?= icon('stop', 'Arrêter') ?> Arrêter
                </button>
                <button class="btn btn-primary" onclick="toggleManualInput()">
                    <?= icon('keyboard', 'Clavier') ?> Manuel
                </button>
            </div>
            
            <!-- Saisie manuelle -->
            <div id="manual-input" class="manual-input">
                <div class="input-group">
                    <input type="text" id="manual-code" placeholder="Code du billet (TKT-...)">
                    <button class="btn btn-primary" onclick="validateManualCode()">✓</button>
                </div>
            </div>
            
            <!-- Liens -->
            <div class="action-links">
                <a href="dashboard.php" class="btn btn-primary">
                    <?= icon('stats', 'Statistiques') ?> Tableau de bord
                </a>
                <a href="../admin/index.php" class="btn btn-secondary">
                    <?= icon('arrow-left', 'Retour') ?> Admin
                </a>
            </div>
        </div>
        </div>
    </main>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Toggle son -->
    <div class="sound-toggle" id="sound-toggle" onclick="toggleSound()" title="Activer/désactiver le son">
        <?= icon('sound-on', 'Son') ?>
    </div>
    
    <!-- Librairie html5-qrcode -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    
    <script>
        let html5QrcodeScanner = null;
        let soundEnabled = true;
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        let requestInFlight = false;
        let lastCode = '';
        let lastCodeTime = 0;

        // Échappe le texte venant du serveur avant de l'afficher (noms saisis par les joueurs)
        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }
        let scanCount = 0;
        let successCount = 0;
        
        // Sons de feedback
        const successSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBi6Dyvfqp+3w6O7k4eHg3t7d3Nvb2tnY19bV1NPS0M/Ozs3Lysm');
        const errorSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBi6Dyvfqp+3w6O7k4eHg3t7d3Nvb2tnY19bV1NPS0M/Ozs3Lysm');
        
        function startScanning() {
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            html5QrcodeScanner = new Html5Qrcode("reader");
            
            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanError
            ).then(() => {
                document.getElementById('start-scan').style.display = 'none';
                document.getElementById('stop-scan').style.display = 'block';
            }).catch(err => {
                showResult('error', 'Erreur', 'Impossible d\'accéder à la caméra: ' + err);
            });
        }
        
        function stopScanning() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    document.getElementById('start-scan').style.display = 'block';
                    document.getElementById('stop-scan').style.display = 'none';
                }).catch(err => {
                    console.error('Erreur lors de l\'arrêt:', err);
                });
            }
        }
        
        function onScanSuccess(decodedText, decodedResult) {
            // Le QR code contient juste le code du billet (ex: MAT-XXXXXXXX…)
            const ticketCode = decodedText.trim();

            // La caméra lit le même QR code plusieurs fois par seconde : on ne le traite qu'une fois
            const now = Date.now();
            if (requestInFlight || (ticketCode === lastCode && now - lastCodeTime < 4000)) {
                return;
            }
            lastCode = ticketCode;
            lastCodeTime = now;

            validateTicket(ticketCode);
        }
        
        function onScanError(errorMessage) {
            // Ne rien faire, c'est normal pendant le scan
        }
        
        function validateTicket(ticketCode) {
            scanCount++;
            updateStats();
            
            showResult('loading', 'Validation en cours...', '');
            
            requestInFlight = true;
            fetch('validate_ticket.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: 'code=' + encodeURIComponent(ticketCode) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN),
                credentials: 'same-origin'
            })
                .then(response => response.json().catch(() => {
                    throw new Error('HTTP ' + response.status);
                }))
                .then(data => {
                    requestInFlight = false;
                    if (data.success) {
                        successCount++;
                        updateStats();
                        playSound('success');
                        const warning = data.warning ? '<p><strong>⚠️ ' + escapeHtml(data.warning) + '</strong></p>' : '';
                        showResult('success', 'Entrée validée !', warning + formatTicketInfo(data.ticket));
                    } else {
                        playSound('error');
                        if (data.status === 'already_scanned') {
                            showResult('warning', 'Déjà scanné', 'Ce billet a déjà été utilisé<br>' + formatTicketInfo(data.ticket));
                        } else if (data.ticket) {
                            showResult('error', escapeHtml(data.error), formatTicketInfo(data.ticket));
                        } else {
                            showResult('error', 'Erreur', escapeHtml(data.error || 'Billet invalide'));
                        }
                    }
                })
                .catch(error => {
                    requestInFlight = false;
                    console.error('Erreur fetch:', error);
                    playSound('error');
                    showResult('error', 'Erreur', 'Erreur de connexion au serveur<br><small>' + escapeHtml(error.message) + '</small>');
                });
        }
        
        function formatTicketInfo(ticket) {
            return `
                <div class="ticket-info">
                    <div class="info-row">
                        <div class="info-label">Participant:</div>
                        <div class="info-value"><strong>${escapeHtml(ticket.participant)}</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Pseudo:</div>
                        <div class="info-value">${escapeHtml(ticket.pseudo)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Équipe:</div>
                        <div class="info-value" style="border-left: 4px solid ${/^#[0-9a-fA-F]{6}$/.test(ticket.team_color || '') ? ticket.team_color : '#a3a3a3'}; padding-left: 8px;">${escapeHtml(ticket.team)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Événement:</div>
                        <div class="info-value">${escapeHtml(ticket.event)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Code:</div>
                        <div class="info-value"><small>${escapeHtml(ticket.code)}</small></div>
                    </div>
                    ${ticket.scanned_at ? `
                    <div class="info-row">
                        <div class="info-label">Scanné le:</div>
                        <div class="info-value">${escapeHtml(ticket.scanned_at)}${ticket.scanned_by ? ' par ' + escapeHtml(ticket.scanned_by) : ''}</div>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        function showResult(type, title, content) {
            const container = document.getElementById('result-container');
            const iconMap = {
                'success': '<?= icon('check', 'Succès') ?>',
                'error': '<?= icon('close', 'Erreur') ?>',
                'warning': '<?= icon('warning', 'Attention') ?>',
                'loading': '<?= icon('pending', 'Chargement') ?>'
            };
            
            if (type === 'loading') {
                container.innerHTML = `
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>${title}</p>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="result result-${type}">
                        <div class="result-icon">${iconMap[type]}</div>
                        <h3>${title}</h3>
                        ${content}
                    </div>
                `;
                
                // Auto-hide après 3 secondes pour success
                if (type === 'success') {
                    setTimeout(() => {
                        container.innerHTML = '';
                    }, 3000);
                }
            }
        }
        
        function toggleManualInput() {
            const input = document.getElementById('manual-input');
            input.classList.toggle('active');
            if (input.classList.contains('active')) {
                document.getElementById('manual-code').focus();
            }
        }
        
        function validateManualCode() {
            const code = document.getElementById('manual-code').value.trim();
            if (code) {
                validateTicket(code);
                document.getElementById('manual-code').value = '';
            }
        }
        
        function updateStats() {
            document.getElementById('scan-count').textContent = scanCount;
            document.getElementById('success-count').textContent = successCount;
        }
        
        function toggleSound() {
            soundEnabled = !soundEnabled;
            document.getElementById('sound-toggle').innerHTML = soundEnabled ? '<?= icon('sound-on', 'Son activé') ?>' : '<?= icon('sound-off', 'Son désactivé') ?>';
        }
        
        function playSound(type) {
            if (!soundEnabled) return;
            
            if (type === 'success') {
                // Bip de succès
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = ctx.createOscillator();
                const gainNode = ctx.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.3);
            } else {
                // Bip d'erreur
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = ctx.createOscillator();
                const gainNode = ctx.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);
                oscillator.frequency.value = 300;
                oscillator.type = 'sine';
                gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.5);
            }
        }
        
        // Démarrer automatiquement au chargement
        window.addEventListener('load', () => {
            // Attendre un peu pour que l'utilisateur soit prêt
            setTimeout(startScanning, 500);
        });
        
        // Empêcher le zoom sur iOS
        document.addEventListener('gesturestart', function (e) {
            e.preventDefault();
        });
    </script>
</body>
</html>
