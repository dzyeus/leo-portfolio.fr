<?php
// Session sécurisée sur toutes les pages qui incluent ce fichier
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Inclure la connexion à la base
require_once __DIR__ . '/db.php';

// Vérifie si l'utilisateur est connecté
function isLoggedIn(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Forcer la connexion pour accéder à une page
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// Jeton anti-CSRF pour les formulaires
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(): bool {
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Limite les tentatives de connexion (anti force brute) : $max échecs par IP sur $minutes.
 * Stockage dans un fichier temporaire, hors du site.
 */
function login_attempts_file(): string {
    return sys_get_temp_dir() . '/panel_login_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . __DIR__) . '.json';
}

function login_blocked(int $max = 5, int $minutes = 15): bool {
    $file = login_attempts_file();
    $attempts = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $recent = array_filter($attempts, fn($t) => $t > time() - $minutes * 60);
    return count($recent) >= $max;
}

function login_failed(): void {
    $file = login_attempts_file();
    $attempts = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $attempts = array_values(array_filter($attempts, fn($t) => $t > time() - 3600));
    $attempts[] = time();
    file_put_contents($file, json_encode($attempts), LOCK_EX);
}

function login_succeeded(): void {
    @unlink(login_attempts_file());
}
