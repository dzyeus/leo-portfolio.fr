<?php
/**
 * Scanner des billets à l'entrée (pensé pour le téléphone).
 * La caméra lit le QR code, le billet est validé par qr-code/validate_ticket.php.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
requireAdmin();

$icons = [
    'success' => ui_icon('check', 30),
    'warning' => ui_icon('alert', 30),
    'error'   => ui_icon('x', 30),
    'soundOn' => ui_icon('bell', 18),
    'soundOff'=> ui_icon('eyeoff', 18),
];
?>
<?php site_head('Scanner', ['admin' => true, 'body_class' => 's-scan-page']); ?>
    <?php include '../includes/header.php'; ?>

    <main class="s-wrap">
        <div class="a-page-head">
            <div>
                <h1>Scanner les billets</h1>
                <div class="a-sub">Pointe la caméra vers le QR code du billet.</div>
            </div>
            <div class="a-actions">
                <button type="button" class="a-btn" id="soundBtn" aria-pressed="true"><?= ui_icon('bell', 18) ?> <span>Son activé</span></button>
                <a href="dashboard.php" class="a-btn"><?= ui_icon('ticket', 18) ?> Billets</a>
            </div>
        </div>

        <div class="a-grid a-grid-main">
            <div class="a-stack">
                <section class="a-card a-scan-card">
                    <div id="result" class="a-scan-result" hidden aria-live="assertive"></div>
                    <div id="reader" class="a-scan-reader"></div>
                    <p id="cameraHint" class="a-scan-hint">La caméra démarre… Autorise son accès si ton téléphone le demande.</p>
                    <div class="a-scan-controls">
                        <button type="button" id="startBtn" class="a-btn a-btn-primary a-btn-lg" hidden><?= ui_icon('camera', 18) ?> Démarrer la caméra</button>
                        <button type="button" id="stopBtn" class="a-btn a-btn-lg" hidden><?= ui_icon('x', 18) ?> Arrêter</button>
                    </div>
                    <form id="manualForm" class="a-scan-manual" autocomplete="off">
                        <label for="manualCode">QR code illisible ? Saisis le code du billet</label>
                        <div class="a-search">
                            <input type="text" id="manualCode" placeholder="MAT-…" autocapitalize="characters" spellcheck="false">
                            <button type="submit" class="a-btn a-btn-primary"><?= ui_icon('check', 18) ?> Valider</button>
                        </div>
                    </form>
                </section>
            </div>

            <aside class="a-stack">
                <div class="a-grid a-grid-2">
                    <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('scan', 15) ?> Scannés</span><span class="a-kpi-value" id="scanCount">0</span></div>
                    <div class="a-kpi"><span class="a-kpi-label"><?= ui_icon('usercheck', 15) ?> Entrées validées</span><span class="a-kpi-value" id="okCount">0</span></div>
                </div>
                <section class="a-card">
                    <div class="a-card-head"><h2><?= ui_icon('clock', 18) ?> Derniers scans</h2></div>
                    <ul class="a-scan-history" id="history"><li class="a-empty">Aucun billet scanné pour l'instant.</li></ul>
                </section>
            </aside>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>

    <!-- Lecteur de QR code (html5-qrcode, hébergé sur le site) -->
    <script src="<?= e(asset_url('js/lib/html5-qrcode.min.js', '../')) ?>"></script>
    <script>
    (function () {
        var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        var ICONS = <?= json_encode($icons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var $ = function (id) { return document.getElementById(id); };
        var scanner = null, busy = false, lastCode = '', lastTime = 0, scans = 0, oks = 0, sound = true, hideTimer = null;

        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function color(c) { return /^#[0-9a-fA-F]{6}$/.test(c || '') ? c : '#a3a3a3'; }

        function beep(ok) {
            if (!sound) { return; }
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.type = 'sine'; osc.frequency.value = ok ? 880 : 280;
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + (ok ? 0.25 : 0.5));
                osc.start(); osc.stop(ctx.currentTime + (ok ? 0.25 : 0.5));
            } catch (e) {}
            if (navigator.vibrate) { navigator.vibrate(ok ? 80 : [120, 80, 120]); }
        }

        function details(t) {
            if (!t) { return ''; }
            var rows = [
                ['Participant', '<strong>' + esc(t.participant) + '</strong>'],
                ['Pseudo', esc(t.pseudo)],
                ['Équipe', '<span class="a-scan-team" style="border-color:' + color(t.team_color) + '">' + esc(t.team) + '</span>'],
                ['Partie', esc(t.event)],
                ['Code', '<code class="a-code">' + esc(t.code) + '</code>']
            ];
            if (t.scanned_at) { rows.push(['Déjà scanné', esc(t.scanned_at) + (t.scanned_by ? ' par ' + esc(t.scanned_by) : '')]); }
            return '<dl class="a-scan-details">' + rows.map(function (r) { return '<dt>' + r[0] + '</dt><dd>' + r[1] + '</dd>'; }).join('') + '</dl>';
        }

        function show(type, title, html) {
            var box = $('result');
            clearTimeout(hideTimer);
            box.className = 'a-scan-result is-' + type;
            box.innerHTML = type === 'loading'
                ? '<div class="a-scan-spinner"></div><strong>Vérification…</strong>'
                : '<div class="a-scan-status">' + (ICONS[type] || '') + '<strong>' + esc(title) + '</strong></div>' + (html || '');
            box.hidden = false;
            if (type === 'success') { hideTimer = setTimeout(function () { box.hidden = true; }, 4000); }
        }

        function remember(type, label, sub) {
            var list = $('history');
            var empty = list.querySelector('.a-empty');
            if (empty) { empty.remove(); }
            var li = document.createElement('li');
            li.className = 'is-' + type;
            var time = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            li.innerHTML = '<span class="a-scan-dot"></span><span><strong>' + esc(label) + '</strong><small>' + esc(sub) + ' · ' + time + '</small></span>';
            list.insertBefore(li, list.firstChild);
            while (list.children.length > 12) { list.removeChild(list.lastChild); }
        }

        function validate(code) {
            code = String(code || '').trim();
            if (!code || busy) { return; }
            busy = true;
            scans++; $('scanCount').textContent = scans;
            show('loading');
            fetch('validate_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                body: 'code=' + encodeURIComponent(code) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN),
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
            }).then(function (data) {
                var t = data.ticket || null;
                var who = t ? (t.participant || t.pseudo || code) : code;
                if (data.success) {
                    oks++; $('okCount').textContent = oks;
                    beep(true);
                    show('success', 'Entrée validée', (data.warning ? '<p class="a-scan-warn">' + esc(data.warning) + '</p>' : '') + details(t));
                    remember('success', who, 'validé');
                } else if (data.status === 'already_scanned') {
                    beep(false);
                    show('warning', 'Billet déjà utilisé', details(t));
                    remember('warning', who, 'déjà scanné');
                } else {
                    beep(false);
                    show('error', data.error || 'Billet invalide', details(t));
                    remember('error', who, data.error || 'refusé');
                }
            }).catch(function (err) {
                beep(false);
                show('error', 'Connexion au serveur impossible', '<p class="a-scan-warn">Vérifie le réseau puis réessaie (' + esc(err.message) + ').</p>');
            }).then(function () { busy = false; });
        }

        function setRunning(running) {
            $('startBtn').hidden = running;
            $('stopBtn').hidden = !running;
            $('cameraHint').hidden = running;
        }

        function start() {
            if (typeof Html5Qrcode === 'undefined') {
                $('cameraHint').textContent = 'Le lecteur de QR code n\'a pas pu se charger : utilise la saisie du code.';
                return;
            }
            scanner = scanner || new Html5Qrcode('reader');
            scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 1.0 }, function (text) {
                var now = Date.now();
                text = text.trim();
                // La caméra lit le même QR code plusieurs fois par seconde : un seul envoi
                if (busy || (text === lastCode && now - lastTime < 4000)) { return; }
                lastCode = text; lastTime = now;
                validate(text);
            }, function () {}).then(function () {
                setRunning(true);
            }).catch(function (err) {
                setRunning(false);
                $('cameraHint').hidden = false;
                $('cameraHint').textContent = 'Caméra inaccessible : autorise-la dans le navigateur, ou saisis le code du billet. (' + err + ')';
            });
        }

        function stop() {
            if (scanner) { scanner.stop().then(function () { setRunning(false); }).catch(function () {}); }
        }

        $('startBtn').addEventListener('click', start);
        $('stopBtn').addEventListener('click', stop);
        $('manualForm').addEventListener('submit', function (e) {
            e.preventDefault();
            validate($('manualCode').value);
            $('manualCode').value = '';
        });
        $('soundBtn').addEventListener('click', function () {
            sound = !sound;
            this.setAttribute('aria-pressed', sound ? 'true' : 'false');
            this.innerHTML = (sound ? ICONS.soundOn : ICONS.soundOff) + ' <span>' + (sound ? 'Son activé' : 'Son coupé') + '</span>';
        });
        // Caméra coupée quand l'écran est caché (batterie), relancée au retour
        var resume = false;
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { resume = !$('stopBtn').hidden; stop(); }
            else if (resume) { resume = false; start(); }
        });
        window.addEventListener('load', function () { setTimeout(start, 300); });
    })();
    </script>
</body>
</html>
