<?php
require_once __DIR__ . '/inc/env_loader.php';
load_env_file();

// إعدادات قاعدة البيانات — تفضيل رابط JawsDB من Heroku ثم متغيرات البيئة ثم القيم الافتراضية المحلية.
$jawsdb_url = getenv('JAWSDB_URL');
$app_env = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
$prefer_local_defaults = $jawsdb_url === false || $jawsdb_url === '';
$prefer_local_defaults = $prefer_local_defaults && ($app_env === '' || in_array($app_env, ['local', 'development', 'dev'], true));
if ($jawsdb_url) {
    $dbparts = parse_url($jawsdb_url);
    $db_host = $dbparts['host'] ?? '127.0.0.1';
    $db_user = $dbparts['user'] ?? 'root';
    $db_pass = $dbparts['pass'] ?? (getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '');
    $db_name = isset($dbparts['path']) ? ltrim($dbparts['path'], '/') : (getenv('DB_NAME') ?: 'doctor_rating');
    $db_port = isset($dbparts['port']) ? (int) $dbparts['port'] : 3306;
} else {
    $env_db_host = getenv('DB_HOST') ?: '';
    $env_db_port = (int) (getenv('DB_PORT') ?: 3306);
    $env_db_socket = getenv('DB_SOCKET') ?: '';
    $env_db_name = getenv('DB_NAME') ?: '';
    $env_db_user = getenv('DB_USER') ?: '';
    $env_db_pass = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';

    if ($prefer_local_defaults) {
        $db_host = '127.0.0.1';
        $db_port = 3307;
        $db_socket = '';
        $db_name = 'doctor_rating';
        $db_user = 'root';
        $db_pass = '';
    } else {
        $db_host = $env_db_host ?: '127.0.0.1';
        $db_port = $env_db_port ?: 3307;
        $db_socket = $env_db_socket;
        $db_name = $env_db_name ?: 'doctor_rating';
        $db_user = $env_db_user ?: 'root';
        $db_pass = $env_db_pass;
    }
}

if (!defined('DB_HOST')) define('DB_HOST', $db_host);
if (!defined('DB_PORT')) define('DB_PORT', $db_port);
if (!defined('DB_SOCKET')) define('DB_SOCKET', $db_socket ?? '');
if (!defined('DB_NAME')) define('DB_NAME', $db_name);
if (!defined('DB_USER')) define('DB_USER', $db_user);
if (!defined('DB_PASS')) define('DB_PASS', $db_pass);
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Backward compatibility for any legacy code that still reads variables.
$DB_HOST = DB_HOST;
$DB_PORT = DB_PORT;
$DB_SOCKET = DB_SOCKET;
$DB_NAME = DB_NAME;
$DB_USER = DB_USER;
$DB_PASS = DB_PASS;
$DB_CHARSET = DB_CHARSET;

// إعدادات الأمان للجلسة
if (!defined('SESSION_COOKIE_SECURE')) define('SESSION_COOKIE_SECURE', getenv('SESSION_COOKIE_SECURE') === '1');
if (!defined('SESSION_COOKIE_LIFETIME')) define('SESSION_COOKIE_LIFETIME', (int) (getenv('SESSION_COOKIE_LIFETIME') ?: 0));
if (!defined('SESSION_COOKIE_HTTPONLY')) define('SESSION_COOKIE_HTTPONLY', true);
if (!defined('SESSION_COOKIE_SAMESITE')) define('SESSION_COOKIE_SAMESITE', getenv('SESSION_COOKIE_SAMESITE') ?: 'Lax');

// مكان سجل الأخطاء الخاص بالتطبيق (قابل للتعديل عبر البيئة)
define('APP_LOG_DIR', __DIR__ . '/logs');
define('APP_ERROR_LOG', APP_LOG_DIR . '/errors.log');
define('APP_COOKIE_SECRET', getenv('APP_COOKIE_SECRET') ?: getenv('APP_SECRET') ?: 'doctor-rating-default-secret-change-me');

$displayErrors = getenv('DISPLAY_ERRORS') === '1' || getenv('APP_ENV') === 'local' || getenv('APP_ENV') === 'development';
define('DISPLAY_ERRORS', $displayErrors);
error_reporting(E_ALL);
ini_set('display_errors', DISPLAY_ERRORS ? '1' : '0');
ini_set('display_startup_errors', DISPLAY_ERRORS ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ERROR_LOG);

// --- SMTP / Email placeholders (configure via environment in production) ---
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: getenv('SMTP_PASSWORD') ?: '');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('MAIL_FROM', getenv('MAIL_FROM') ?: '');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'منصة مسار الأكاديمية');
define('OTP_TTL_MINUTES', (int) (getenv('OTP_TTL_MINUTES') ?: 10));
define('SMTP_DEBUG', getenv('SMTP_DEBUG') === '1');
define('SMTP_TIMEOUT_SECONDS', (int) (getenv('SMTP_TIMEOUT_SECONDS') ?: 6));
define('SMTP_CONNECT_TIMEOUT_SECONDS', (int) (getenv('SMTP_CONNECT_TIMEOUT_SECONDS') ?: 6));
// Mailjet API settings
define('MAILJET_API_KEY', getenv('MAILJET_API_KEY') ?: '');
define('MAILJET_SECRET_KEY', getenv('MAILJET_SECRET_KEY') ?: '');
define('MAILJET_FROM_EMAIL', getenv('MAILJET_FROM_EMAIL') ?: '');
define('MAILJET_FROM_NAME', getenv('MAILJET_FROM_NAME') ?: 'منصة مسار الأكاديمية');

define('DAILY_EMAIL_LIMIT', (int) (getenv('DAILY_EMAIL_LIMIT') ?: 100));
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash');
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: getenv('TELEGRAM_TOKEN') ?: '');
define('TELEGRAM_BOT_USERNAME', getenv('TELEGRAM_BOT_USERNAME') ?: '');
define('TELEGRAM_ADMIN_CHAT_ID', getenv('TELEGRAM_ADMIN_CHAT_ID') ?: '');
// Composer PHPMailer path hint (run `composer require phpmailer/phpmailer` in project root)
define('COMPOSER_VENDOR_DIR', __DIR__ . '/vendor');
?>