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

// Inclure les fonctions d'icônes
require_once __DIR__ . '/../includes/icons.php';
?>
