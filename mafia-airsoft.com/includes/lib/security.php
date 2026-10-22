<?php
/**
 * Sécurité : échappement, CSRF, limitation des tentatives, jetons email,
 * connexion des comptes.
 */

/** Échappe une valeur pour l'afficher dans du HTML. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Formate un montant en centimes : 1000 → "10,00 €". */
function format_price(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Messages "flash" affichés une seule fois après une redirection. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/** Arrête la requête si le jeton CSRF est absent ou faux. */
function require_csrf(): void
{
    if (!csrf_valid()) {
        http_response_code(400);
        exit('Session expirée ou formulaire invalide. Recharge la page et réessaie.');
    }
}

// ---------------------------------------------------------------------
// Limitation des tentatives (anti force brute / anti spam)
// ---------------------------------------------------------------------

function rate_limit_exceeded(PDO $pdo, string $action, string $identifier, int $max, int $minutes): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM rate_limits
        WHERE action = ? AND identifier = ? AND created_at > (NOW() - INTERVAL ? MINUTE)
    ");
    $stmt->execute([$action, mb_strtolower($identifier), $minutes]);
    return (int)$stmt->fetchColumn() >= $max;
}

function rate_limit_hit(PDO $pdo, string $action, string $identifier): void
{
    $pdo->prepare("INSERT INTO rate_limits (action, identifier, created_at) VALUES (?, ?, NOW())")
        ->execute([$action, mb_strtolower($identifier)]);

    // Nettoyage occasionnel des vieilles entrées
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

function rate_limit_clear(PDO $pdo, string $action, string $identifier): void
{
    $pdo->prepare("DELETE FROM rate_limits WHERE action = ? AND identifier = ?")
        ->execute([$action, mb_strtolower($identifier)]);
}

// ---------------------------------------------------------------------
// Jetons à usage unique envoyés par email
// ---------------------------------------------------------------------

/** Crée un jeton (le précédent du même type est invalidé) et renvoie sa valeur en clair. */
function user_token_create(PDO $pdo, int $userId, string $purpose, int $validMinutes): string
{
    $pdo->prepare("DELETE FROM user_tokens WHERE user_id = ? AND purpose = ?")
        ->execute([$userId, $purpose]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at)
        VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)
    ")->execute([$userId, $purpose, hash('sha256', $token), $validMinutes]);

    return $token;
}

/** Vérifie un jeton sans le consommer. Renvoie l'id du compte ou null. */
function user_token_check(PDO $pdo, string $token, string $purpose): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT user_id FROM user_tokens
        WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
    ");
    $stmt->execute([hash('sha256', $token), $purpose]);
    $userId = $stmt->fetchColumn();
    return $userId === false ? null : (int)$userId;
}

/** Vérifie et consomme un jeton. Renvoie l'id du compte ou null. */
function user_token_consume(PDO $pdo, string $token, string $purpose): ?int
{
    $userId = user_token_check($pdo, $token, $purpose);
    if ($userId === null) {
        return null;
    }
    $stmt = $pdo->prepare("UPDATE user_tokens SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->rowCount() === 1 ? $userId : null;
}

// ---------------------------------------------------------------------
// Comptes
// ---------------------------------------------------------------------

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function current_user(PDO $pdo): ?array
{
    $id = current_user_id();
    if (!$id) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/** Ouvre la session d'un compte (après vérification du mot de passe). */
function login_user(PDO $pdo, array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['pseudo']   = $user['pseudo'];
    $_SESSION['is_admin'] = (int)$user['is_admin'];
    $_SESSION['role']     = ((int)$user['is_admin'] === 1) ? 'admin' : 'player';
    unset($_SESSION['csrf_token']);

    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
}

/** Renvoie un message d'erreur si le mot de passe est trop faible, sinon null. */
function password_problem(string $password): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Le mot de passe doit faire au moins 8 caractères.';
    }
    if (mb_strlen($password) > 200) {
        return 'Le mot de passe est trop long.';
    }
    return null;
}

/** Renvoie un message d'erreur si le pseudo n'est pas valide, sinon null. */
function pseudo_problem(string $pseudo): ?string
{
    if (!preg_match('/^[\p{L}\p{N}_.\- ]{3,30}$/u', $pseudo)) {
        return 'Le pseudo doit faire 3 à 30 caractères (lettres, chiffres, espace, _ . -).';
    }
    return null;
}

/** Nom complet affichable d'un compte ou d'une place. */
function full_name(?string $firstName, ?string $lastName): string
{
    return trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
}

/** Le compte a-t-il renseigné nom et prénom ? (obligatoire pour réserver) */
function profile_is_complete(array $user): bool
{
    return trim((string)($user['first_name'] ?? '')) !== ''
        && trim((string)($user['last_name'] ?? '')) !== '';
}

/** Redirige vers une URL interne seulement (évite les redirections vers un autre site). */
function safe_return_url(?string $url, string $default): string
{
    if (!$url || !preg_match('#^[a-zA-Z0-9_\-/]+\.php(\?[a-zA-Z0-9_=&\-%.]*)?$#', $url) || str_contains($url, '//')) {
        return $default;
    }
    return $url;
}

// ---------------------------------------------------------------------
// Fichiers envoyés (images)
// ---------------------------------------------------------------------

/**
 * Vérifie qu'un fichier envoyé est une vraie image (contenu analysé, pas
 * seulement l'extension). Renvoie l'extension sûre à utiliser, ou null.
 */
function uploaded_image_extension(array $file, int $maxBytes = 5242880): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    return ($info && isset($types[$info[2]])) ? $types[$info[2]] : null;
}

/** Nom de fichier aléatoire et sûr pour un upload. */
function random_upload_name(string $prefix, string $extension): string
{
    return $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
}

/** Supprime un fichier uniquement s'il est bien dans le dossier d'uploads attendu. */
function delete_upload(string $relativePath, string $allowedDir): void
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (strpos($relativePath, $allowedDir) !== 0 || str_contains($relativePath, '..')) {
        return;
    }
    $full = MAT_ROOT . '/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Couleur hexadécimale valide (#rrggbb), sinon la couleur par défaut. */
function safe_color(?string $color, string $default = '#a3a3a3'): string
{
    return (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : $default;
}
