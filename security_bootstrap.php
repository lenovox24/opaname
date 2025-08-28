<?php
// Central security bootstrap: headers, session cookies, helpers

// Send security headers early
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    $csp = "default-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'";
    header("Content-Security-Policy: $csp");
}

// Harden session cookie
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443),
        'samesite' => 'Strict',
        'path' => '/',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params($cookieParams['lifetime'] ?? 0, $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'], '', $cookieParams['secure'], $cookieParams['httponly']);
    }
    session_start();
}

// Disable error display in production (keep linter quiet)
ini_set('display_errors', '0');
error_reporting(0);

// Helper to enforce authentication
function require_auth() {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo 'Unauthorized';
        exit();
    }
}

// Helper to get or create CSRF token
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}


