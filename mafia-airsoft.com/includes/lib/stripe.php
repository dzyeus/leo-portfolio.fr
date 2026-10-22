<?php
/**
 * Appels à l'API Stripe (sans SDK, avec cURL) et vérification des webhooks.
 */

function stripe_request(string $method, string $endpoint, array $params = []): array
{
    if (STRIPE_SECRET_KEY === '' || str_contains(STRIPE_SECRET_KEY, 'A_REMPLACER')) {
        throw new RuntimeException('Clé Stripe non configurée dans config/app.php');
    }

    $base = defined('STRIPE_API_BASE') ? STRIPE_API_BASE : 'https://api.stripe.com/v1/';
    $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
    if ($method === 'GET' && $params) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Erreur réseau Stripe : ' . $error);
    }
    $data = json_decode($body, true);
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException('Erreur Stripe (' . $status . ') : ' . ($data['error']['message'] ?? 'réponse invalide'));
    }
    return $data;
}

/**
 * Vérifie la signature d'un webhook Stripe.
 * Renvoie l'événement décodé, ou null si la signature est invalide.
 */
function stripe_verify_webhook(string $payload, string $signatureHeader, int $toleranceSeconds = 300): ?array
{
    if (STRIPE_WEBHOOK_SECRET === '') {
        return null;
    }
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = (int)$value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }
    if (!$timestamp || !$signatures || abs(time() - $timestamp) > $toleranceSeconds) {
        return null;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            $event = json_decode($payload, true);
            return is_array($event) ? $event : null;
        }
    }
    return null;
}
