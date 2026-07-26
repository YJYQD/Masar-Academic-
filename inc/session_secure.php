<?php
// Secure session bootstrap with a dedicated cookie name and root path.
// This avoids conflicts with older PHPSESSID cookies or path-specific sessions.
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    $cookieLifetime = defined('SESSION_COOKIE_LIFETIME') ? (int) SESSION_COOKIE_LIFETIME : 0;
    $cookieSecure = $is_https;
    $cookieHttpOnly = defined('SESSION_COOKIE_HTTPONLY') ? (bool) SESSION_COOKIE_HTTPONLY : true;
    $cookieSameSite = defined('SESSION_COOKIE_SAMESITE') ? (string) SESSION_COOKIE_SAMESITE : 'Lax';

    $sessionPath = defined('SESSION_SAVE_PATH') ? (string) SESSION_SAVE_PATH : __DIR__ . '/../sessions';
    if ($sessionPath !== '' && !is_dir($sessionPath)) {
        @mkdir($sessionPath, 0750, true);
    }
    if ($sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_lifetime', (string) $cookieLifetime);
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    ini_set('session.cookie_httponly', $cookieHttpOnly ? '1' : '0');
    ini_set('session.cookie_samesite', $cookieSameSite);
    ini_set('session.gc_maxlifetime', '1800');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    session_name('doctor_rating_session');
    session_set_cookie_params([
        'lifetime' => $cookieLifetime,
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => $cookieHttpOnly,
        'samesite' => $cookieSameSite,
    ]);

    if (headers_sent()) {
        return;
    }

    @session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $fallbackPath = __DIR__ . '/../logs/sessions';
        if (!is_dir($fallbackPath)) {
            @mkdir($fallbackPath, 0750, true);
        }
        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
            session_save_path($fallbackPath);
            @session_start();
        }
    }
}

if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
    $cookieValue = $_COOKIE['doctor_rating_auth'] ?? '';
    if ($cookieValue !== '') {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) === 2) {
            [$encodedPayload, $signature] = $parts;
            $secret = defined('APP_COOKIE_SECRET') ? (string) APP_COOKIE_SECRET : '';
            $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret !== '' ? $secret : 'doctor-rating-default-secret-change-me');
            if (hash_equals($expectedSignature, $signature)) {
                $decoded = json_decode(base64_decode($encodedPayload, true), true);
                if (is_array($decoded) && !empty($decoded['user_id']) && ((int) ($decoded['expires'] ?? 0) > time())) {
                    $userId = (int) $decoded['user_id'];
                    $userType = strtolower((string) ($decoded['type'] ?? 'user'));
                    $userName = (string) ($decoded['user_name'] ?? 'مستخدم');

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $userName;
                    $_SESSION['anonymous_user_id'] = $userId;
                    $_SESSION['role'] = $userType === 'admin' ? 'college_admin' : 'student';
                    $_SESSION['college_scope'] = '';
                    $_SESSION['is_admin'] = $userType === 'admin';
                    $_SESSION['admin_id'] = $userType === 'admin' ? $userId : 0;
                    $_SESSION['admin_role'] = $userType === 'admin' ? 'college_admin' : 'student';
                    $_SESSION['admin_college'] = '';
                }
            }
        }
    }
}
