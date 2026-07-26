<?php
require_once __DIR__ . '/inc/env_loader.php';
load_env_file();

// إعدادات قاعدة البيانات — تفضيل متغيرات البيئة ثم القيم الافتراضية الآمنة.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_SOCKET', getenv('DB_SOCKET') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'doctor_rating');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

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