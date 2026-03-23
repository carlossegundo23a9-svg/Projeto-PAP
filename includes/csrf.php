<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

if (!function_exists('app_csrf_token')) {
    function app_csrf_token(string $sessionKey = 'app_csrf_token'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            app_session_start();
        }

        $current = $_SESSION[$sessionKey] ?? '';
        if (!is_string($current) || $current === '') {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[$sessionKey];
    }
}

if (!function_exists('app_csrf_field')) {
    function app_csrf_field(
        string $sessionKey = 'app_csrf_token',
        string $fieldName = 'csrf_token'
    ): string {
        $token = app_csrf_token($sessionKey);
        $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . $fieldName . '" value="' . $escaped . '">';
    }
}

if (!function_exists('app_csrf_is_valid')) {
    function app_csrf_is_valid(
        ?string $token,
        string $sessionKey = 'app_csrf_token'
    ): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            app_session_start();
        }

        $sessionToken = $_SESSION[$sessionKey] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $provided = trim((string) $token);
        if ($provided === '') {
            return false;
        }

        return hash_equals($sessionToken, $provided);
    }
}
