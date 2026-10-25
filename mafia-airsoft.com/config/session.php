<?php
// Configuration + bibliothèques (sécurité, emails, paiements, billets)
require_once __DIR__ . '/../includes/lib/bootstrap.php';

// Démarrage et configuration sécurisée de la session
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',   // protège aussi les anciens formulaires contre le CSRF
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// En-têtes de sécurité envoyés avec chaque page
// ---------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self'; media-src 'self' data: blob:; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; "
        . "form-action 'self' https://checkout.stripe.com"
        . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '; upgrade-insecure-requests' : ''));
    if ($isHttps ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------------------------------------------------------------------
// Protection CSRF de TOUS les formulaires du site
// ---------------------------------------------------------------------
// - chaque formulaire POST reçoit automatiquement un champ caché csrf_token
// - toute requête POST sans jeton valide est refusée
$csrfTokenForForms = csrf_token();
// (SKIP_CSRF : uniquement pour les pages protégées autrement, ex. lien signé de désabonnement)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !defined('SKIP_CSRF') && !csrf_valid()) {
    http_response_code(400);
    exit('Session expirée ou formulaire invalide. Recharge la page et réessaie.');
}
ob_start(function (string $buffer) use ($csrfTokenForForms): string {
    // On ne touche qu'aux pages HTML (pas aux PDF ni au JSON)
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
            return $buffer;
        }
    }
    $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfTokenForForms, ENT_QUOTES) . '">';
    return preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($field) {
        $tag = $m[0];
        return preg_match('/\bmethod\s*=\s*["\']?post\b/i', $tag) ? $tag . $field : $tag;
    }, $buffer);
});

// ---------------------------------------------------------------------
// Session : on revérifie le compte à chaque page (compte supprimé ou
// droits admin retirés = effet immédiat)
// ---------------------------------------------------------------------
if (isset($_SESSION['user_id'], $pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT pseudo, is_admin, password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $fingerprint = $sessionUser ? session_password_fingerprint((string)$sessionUser['password_hash']) : '';
        if ($sessionUser && empty($_SESSION['pwd_fp'])) {
            $_SESSION['pwd_fp'] = $fingerprint; // sessions ouvertes avant cette mise à jour
        }
        if (!$sessionUser || !hash_equals((string)$_SESSION['pwd_fp'], $fingerprint)) {
            // Compte supprimé, ou mot de passe changé ailleurs : cette session n'est plus valable
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['pseudo'] = $sessionUser['pseudo'];
            $_SESSION['is_admin'] = (int)$sessionUser['is_admin'];
            $_SESSION['role'] = ((int)$sessionUser['is_admin'] === 1) ? 'admin' : 'player';
        }
        unset($stmt, $sessionUser, $fingerprint);
    } catch (Throwable $e) {
        // Base pas encore migrée : on ne bloque pas le site
    }
}

// Session inactive depuis plus de 12 heures : on la ferme (ordinateur partagé, téléphone perdu…)
if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 12 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = time();
    }
}

// Pages vues une fois connecté : jamais gardées en cache (le bouton « retour » après
// une déconnexion ne réaffiche pas les informations du compte)
if (isset($_SESSION['user_id']) && !headers_sent()) {
    header('Cache-Control: private, no-store, max-age=0');
}

// Fonction pour vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Fonction pour vérifier si l'utilisateur est admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Fonction pour obtenir le chemin relatif vers la racine
function getBasePath() {
    $path = $_SERVER['PHP_SELF'];
    if (strpos($path, '/admin/') !== false || strpos($path, '/player/') !== false || strpos($path, '/qr-code/') !== false) {
        return '../';
    }
    return '';
}

// Fonction pour rediriger si non connecté (avec retour sur la page demandée)
function requireLogin() {
    if (!isLoggedIn()) {
        $basePath = getBasePath();
        // Page à rouvrir après la connexion (chemin relatif à la racine du site)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $return = ($basePath ? basename(dirname($script)) . '/' : '') . basename($script);
        if (!empty($_SERVER['QUERY_STRING'])) {
            $return .= '?' . $_SERVER['QUERY_STRING'];
        }
        header('Location: ' . $basePath . 'login.php?return=' . urlencode($return));
        exit;
    }
}

// Fonction pour rediriger si non admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $basePath = getBasePath();
        header('Location: ' . $basePath . 'index.php');
        exit;
    }
}

/** Lien de déconnexion protégé (un site tiers ne peut pas déconnecter un joueur à son insu). */
function logout_url(string $basePath = ''): string
{
    return $basePath . 'logout.php?t=' . urlencode(csrf_token());
}

// Fonction pour se déconnecter
function logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    $basePath = getBasePath();
    header('Location: ' . $basePath . 'index.php');
    exit;
}

// Inclure les fonctions d'icônes (anciennes icônes PNG + icônes SVG et éléments d'interface)
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/ui.php';
?>
