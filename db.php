<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/privacy.php';
require_once __DIR__ . '/inc/anonymous_identity.php';
require_once __DIR__ . '/inc/attendance_engine.php';
require_once __DIR__ . '/inc/platform_seed.php';

// Load config (uses env vars when available)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);

// Ensure logs directory exists
if (!defined('APP_LOG_DIR')) {
    define('APP_LOG_DIR', __DIR__ . '/logs');
}
if (!is_dir(APP_LOG_DIR)) {
    @mkdir(APP_LOG_DIR, 0750, true);
}

/**
 * Log internal errors to a private file (not visible to end users)
 */
function log_error(string $message): void
{
    $ts = date('Y-m-d H:i:s');
    $entry = "[{$ts}] " . $message . "\n";
    @file_put_contents(defined('APP_ERROR_LOG') ? APP_ERROR_LOG : __DIR__ . '/logs/errors.log', $entry, FILE_APPEND | LOCK_EX);
}

if (!function_exists('flash_success')) {
    function flash_success(string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/inc/session_secure.php';
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'text' => $message,
        ];
    }
}

if (!function_exists('flash_error')) {
    function flash_error(string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/inc/session_secure.php';
        }

        $_SESSION['flash'] = [
            'type' => 'error',
            'text' => $message,
        ];
    }
}

// Create DB connection using config constants (or env defaults in config.php)
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
if (!defined('DB_SOCKET')) define('DB_SOCKET', getenv('DB_SOCKET') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'doctor_rating');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

mysqli_report(MYSQLI_REPORT_OFF);

function get_db_connection_error_message(string $message): string
{
    return 'تعذر الاتصال بقاعدة البيانات. يرجى تشغيل MySQL/XAMPP والتأكد من صحة بيانات الاتصال في config.php. التفاصيل: ' . $message;
}

function build_db_connection(): mysqli
{
    $hostCandidates = array_values(array_unique(array_filter([
        defined('DB_HOST') ? DB_HOST : '127.0.0.1',
        '127.0.0.1',
        'localhost',
    ], static function ($value): bool {
        return $value !== '';
    })));

    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $port = DB_PORT > 0 ? DB_PORT : 3306;
    $socket = DB_SOCKET ?: null;
    $lastError = 'Unknown connection error';
    $credentialCandidates = [];

    if ($user === 'root' && $pass !== '') {
        $credentialCandidates[] = ['user' => $user, 'pass' => $pass];
        $credentialCandidates[] = ['user' => $user, 'pass' => ''];
    } else {
        $credentialCandidates[] = ['user' => $user, 'pass' => $pass];
    }

    foreach ($hostCandidates as $host) {
        foreach ($credentialCandidates as $credentials) {
            $conn = mysqli_init();
            if (!$conn) {
                throw new RuntimeException(get_db_connection_error_message('Unable to initialise mysqli'));
            }

            mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            @mysqli_real_connect($conn, $host, $credentials['user'], $credentials['pass'], null, $port, $socket);

            if ($conn->connect_error) {
                $lastError = $conn->connect_error ?: $lastError;
                $conn->close();
                continue;
            }

            $conn->set_charset(DB_CHARSET);

            $dbName = defined('DB_NAME') ? DB_NAME : '';
            if ($dbName !== '') {
                if (!$conn->select_db($dbName)) {
                    if ($conn->errno === 1049) {
                        $escapedDbName = str_replace('`', '``', $dbName);
                        $createSql = 'CREATE DATABASE IF NOT EXISTS `' . $escapedDbName . '`';
                        if ($conn->query($createSql)) {
                            if ($conn->select_db($dbName)) {
                                return $conn;
                            }
                        }
                    }

                    $lastError = $conn->error ?: $lastError;
                    $conn->close();
                    continue;
                }
            }

            return $conn;
        }
    }

    throw new RuntimeException(get_db_connection_error_message($lastError));
}

$conn = null;
try {
    $conn = build_db_connection();
} catch (Throwable $e) {
    log_error('DB connect error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>الخدمة غير متاحة</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:24px;direction:rtl;text-align:right;"><h2>الخدمة غير متاحة مؤقتاً</h2><p>تعذر الاتصال بقاعدة البيانات في الوقت الحالي.</p><p>يرجى تشغيل MySQL/XAMPP والتأكد من صحة بيانات الاتصال في ملف config.php.</p><p>إذا كنت تستخدم XAMPP، تأكد من أن MySQL يعمل وأن المستخدم root ليس لديه كلمة مرور محلية أو أن كلمة المرور صحيحة.</p></body></html>';
    exit();
}

function ensure_db_column(mysqli $conn, string $table, string $column, string $definition): void {
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) {
        log_error('Failed to prepare column check for ' . $table . '.' . $column . ': ' . $conn->error);
        return;
    }

    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (bool) $result->fetch_assoc();
    $stmt->close();

    if ($exists) {
        return;
    }

    $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition;
    if (!$conn->query($sql)) {
        log_error('Failed to add column ' . $table . '.' . $column . ': ' . $conn->error . ' | SQL: ' . $sql);
    }
}

function ensure_db_table(mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) {
        log_error('Failed to ensure database table with SQL: ' . $conn->error . ' | SQL: ' . $sql);
    }
}

function ensure_schema_migrations(mysqli $conn): void {
    ensure_anonymous_identity_tables($conn);

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(100) NOT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `full_name` VARCHAR(255) DEFAULT NULL,
        `phone` VARCHAR(50) DEFAULT NULL,
        `role` ENUM("student","admin","super_admin") NOT NULL DEFAULT "student",
        `college_scope` VARCHAR(255) DEFAULT NULL,
        `department_scope` VARCHAR(255) DEFAULT NULL,
        `telegram_chat_id` VARCHAR(255) DEFAULT NULL,
        `telegram_username` VARCHAR(100) DEFAULT NULL,
        `status` ENUM("active","blocked","pending") NOT NULL DEFAULT "active",
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_users_username` (`username`),
        UNIQUE KEY `uq_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `username` VARCHAR(100) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` ENUM("sub_admin","college_admin","super_admin") NOT NULL DEFAULT "sub_admin",
        `college_scope` VARCHAR(255) DEFAULT NULL,
        `college_responsibility` VARCHAR(255) DEFAULT NULL,
        `parent_admin_id` INT UNSIGNED DEFAULT NULL,
        `permissions` JSON DEFAULT NULL,
        `telegram_chat_id` VARCHAR(255) DEFAULT NULL,
        `telegram_username` VARCHAR(100) DEFAULT NULL,
        `status` ENUM("active","blocked") NOT NULL DEFAULT "active",
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_admins_user` (`user_id`),
        UNIQUE KEY `uq_admins_username` (`username`),
        CONSTRAINT `fk_admins_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `admin_id` INT UNSIGNED DEFAULT NULL,
        `action` VARCHAR(100) NOT NULL,
        `target_type` VARCHAR(100) DEFAULT NULL,
        `target_id` INT UNSIGNED DEFAULT NULL,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `meta` JSON DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_audit_logs_admin` (`admin_id`),
        CONSTRAINT `fk_audit_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `students` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `full_name` VARCHAR(255) DEFAULT NULL,
        `student_number` VARCHAR(100) DEFAULT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `telegram_chat_id` VARCHAR(255) DEFAULT NULL,
        `last_ip_address` VARCHAR(45) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_students_telegram_chat_id` (`telegram_chat_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `student_subjects` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `student_id` INT UNSIGNED NOT NULL,
        `subject_id` INT UNSIGNED NOT NULL,
        `subject_type` ENUM("theoretical","practical") NOT NULL DEFAULT "theoretical",
        `attendance_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        `critical_absence_threshold` DECIMAL(5,2) NOT NULL DEFAULT 25.00,
        `reminder_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `last_reminder_sent_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_student_subjects_student` (`student_id`),
        KEY `idx_student_subjects_subject` (`subject_id`),
        CONSTRAINT `fk_student_subjects_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_student_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `attendance_notifications` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `student_subject_id` INT UNSIGNED NOT NULL,
        `message_text` TEXT DEFAULT NULL,
        `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_attendance_notifications_student_subject` (`student_subject_id`),
        CONSTRAINT `fk_attendance_notifications_student_subject` FOREIGN KEY (`student_subject_id`) REFERENCES `student_subjects` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `schedules` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `course_code` VARCHAR(50) DEFAULT NULL,
        `day_of_week` TINYINT NOT NULL DEFAULT 0,
        `start_time` TIME NOT NULL,
        `end_time` TIME NOT NULL,
        `location` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `curriculum` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `academic_path` VARCHAR(255) DEFAULT NULL,
        `college` VARCHAR(255) DEFAULT NULL,
        `department` VARCHAR(255) DEFAULT NULL,
        `semester` VARCHAR(100) DEFAULT NULL,
        `credits` TINYINT NOT NULL DEFAULT 0,
        `study_level` VARCHAR(100) DEFAULT NULL,
        `objectives` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `curriculum_access` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `curriculum_id` INT UNSIGNED NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_curriculum_access` (`user_id`, `curriculum_id`),
        KEY `idx_curriculum_access_user` (`user_id`),
        KEY `idx_curriculum_access_curriculum` (`curriculum_id`),
        CONSTRAINT `fk_curriculum_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_curriculum_access_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `curriculum` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `attendance_log` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `course_code` VARCHAR(50) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "present",
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_attendance_log_user` (`user_id`),
        CONSTRAINT `fk_attendance_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `academic_colleges` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `college_name` VARCHAR(255) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_academic_colleges_name` (`college_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_table($conn, 'CREATE TABLE IF NOT EXISTS `academic_departments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `college_id` INT UNSIGNED NOT NULL,
        `department_name` VARCHAR(255) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_academic_departments` (`college_id`, `department_name`),
        CONSTRAINT `fk_academic_departments_college` FOREIGN KEY (`college_id`) REFERENCES `academic_colleges` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    ensure_db_column($conn, 'reviews', 'explanation_stars', 'TINYINT NOT NULL DEFAULT 0');
    ensure_db_column($conn, 'reviews', 'handling_stars', 'TINYINT NOT NULL DEFAULT 0');
    ensure_db_column($conn, 'reviews', 'grading_stars', 'TINYINT NOT NULL DEFAULT 0');
    ensure_db_column($conn, 'schedules', 'user_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'user_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'subjects', 'user_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'subjects', 'subject_type', 'ENUM("theoretical","practical") NOT NULL DEFAULT "theoretical"');
    ensure_db_column($conn, 'subjects', 'college', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'subjects', 'department', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'subjects', 'credit_hours', 'TINYINT NOT NULL DEFAULT 0');
    ensure_db_column($conn, 'subjects', 'level_num', 'TINYINT NOT NULL DEFAULT 1');
    ensure_db_column($conn, 'curriculum', 'prerequisite_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'academic_path', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'admins', 'telegram_chat_id', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'admins', 'telegram_username', 'VARCHAR(100) DEFAULT NULL');
    ensure_db_column($conn, 'admins', 'college_responsibility', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'admins', 'college_scope', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'college', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'department', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'study_level', 'VARCHAR(100) DEFAULT NULL');
    ensure_db_column($conn, 'curriculum', 'objectives', 'TEXT DEFAULT NULL');
    ensure_db_column($conn, 'users', 'anonymous_code', 'VARCHAR(64) DEFAULT NULL');
    ensure_db_column($conn, 'users', 'telegram_username', 'VARCHAR(100) DEFAULT NULL');
    ensure_db_column($conn, 'users', 'telegram_bind_code', 'VARCHAR(20) DEFAULT NULL');
    ensure_db_column($conn, 'users', 'specialty', 'VARCHAR(255) DEFAULT NULL');
    ensure_db_column($conn, 'users', 'privacy_consent_hash', 'VARCHAR(64) DEFAULT NULL');

    $dropColumns = ['otp_code', 'otp_expires'];
    foreach ($dropColumns as $dropColumn) {
        $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ss', $dbName, $dropColumn);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->fetch_assoc()) {
                $conn->query('ALTER TABLE `users` DROP COLUMN `' . $dropColumn . '`');
            }
            $stmt->close();
        }
    }

    $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME = "is_verified" LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            $conn->query('ALTER TABLE `users` DROP COLUMN `is_verified`');
        }
        $stmt->close();
    }
    ensure_db_column($conn, 'subjects', 'subject_owner_doctor_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'reviews', 'attendance_weight', 'DECIMAL(6,3) NOT NULL DEFAULT 1.000');
    ensure_db_column($conn, 'reviews', 'status', 'VARCHAR(20) NOT NULL DEFAULT "pending"');
    ensure_db_column($conn, 'reviews', 'course_code', 'VARCHAR(50) DEFAULT NULL');
    ensure_db_column($conn, 'reviews', 'semester', 'VARCHAR(100) DEFAULT NULL');
    ensure_db_column($conn, 'reviews', 'ip_address', 'VARCHAR(45) DEFAULT NULL');
    ensure_db_column($conn, 'attendance_log', 'user_id', 'INT UNSIGNED DEFAULT NULL');
    ensure_db_column($conn, 'attendance_log', 'student_id', 'INT UNSIGNED DEFAULT NULL');
}

ensure_schema_migrations($conn);

function ensure_first_admin_account(mysqli $conn): void
{
    $adminCountStmt = $conn->prepare('SELECT COUNT(*) AS admin_count FROM admins');
    if (!$adminCountStmt) {
        log_error('Failed to prepare admin count query: ' . $conn->error);
        return;
    }

    $adminCountStmt->execute();
    $adminCountResult = $adminCountStmt->get_result();
    $adminCountRow = $adminCountResult->fetch_assoc();
    $adminCountStmt->close();

    if ((int) ($adminCountRow['admin_count'] ?? 0) > 0) {
        return;
    }

    $username = getenv('ADMIN_USERNAME') ?: 'admin';
    $password = getenv('ADMIN_PASSWORD') ?: 'AASS11221LGG';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $existingUserStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if ($existingUserStmt) {
        $existingUserStmt->bind_param('s', $username);
        $existingUserStmt->execute();
        $existingUserResult = $existingUserStmt->get_result();
        $existingUserRow = $existingUserResult->fetch_assoc();
        $existingUserStmt->close();
    } else {
        $existingUserRow = null;
    }

    $userId = null;
    if (!empty($existingUserRow['id'])) {
        $userId = (int) $existingUserRow['id'];
        $updateUserStmt = $conn->prepare('UPDATE users SET password_hash = ?, role = "super_admin", status = "active" WHERE id = ?');
        if ($updateUserStmt) {
            $updateUserStmt->bind_param('si', $passwordHash, $userId);
            $updateUserStmt->execute();
            $updateUserStmt->close();
        }
    } else {
        $createUserStmt = $conn->prepare('INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, "super_admin", "active")');
        if (!$createUserStmt) {
            log_error('Failed to create initial user account: ' . $conn->error);
            return;
        }

        $createUserStmt->bind_param('ss', $username, $passwordHash);
        $createUserStmt->execute();
        $userId = (int) $conn->insert_id;
        $createUserStmt->close();
    }

    if ($userId <= 0) {
        log_error('Failed to resolve user id for the initial admin bootstrap.');
        return;
    }

    $existingAdminStmt = $conn->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    if ($existingAdminStmt) {
        $existingAdminStmt->bind_param('s', $username);
        $existingAdminStmt->execute();
        $existingAdminResult = $existingAdminStmt->get_result();
        $existingAdminRow = $existingAdminResult->fetch_assoc();
        $existingAdminStmt->close();
    } else {
        $existingAdminRow = null;
    }

    if (!empty($existingAdminRow['id'])) {
        return;
    }

    $createAdminStmt = $conn->prepare('INSERT INTO admins (user_id, username, password_hash, role, college_scope, college_responsibility, permissions, status) VALUES (?, ?, ?, "super_admin", NULL, NULL, NULL, "active")');
    if (!$createAdminStmt) {
        log_error('Failed to create initial admin record: ' . $conn->error);
        return;
    }

    $createAdminStmt->bind_param('iss', $userId, $username, $passwordHash);
    $createAdminStmt->execute();
    $createAdminStmt->close();
}

ensure_first_admin_account($conn);

function build_signed_auth_cookie_value(array $payload): string
{
    $secret = defined('APP_COOKIE_SECRET') ? (string) APP_COOKIE_SECRET : '';
    $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = hash_hmac('sha256', $encoded, $secret !== '' ? $secret : 'doctor-rating-default-secret-change-me');
    return $encoded . '.' . $signature;
}

function parse_signed_auth_cookie_value(string $cookieValue): ?array
{
    if ($cookieValue === '') {
        return null;
    }

    $parts = explode('.', $cookieValue, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $signature] = $parts;
    $secret = defined('APP_COOKIE_SECRET') ? (string) APP_COOKIE_SECRET : '';
    $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret !== '' ? $secret : 'doctor-rating-default-secret-change-me');
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $decoded = json_decode(base64_decode($encodedPayload, true), true);
    if (!is_array($decoded)) {
        return null;
    }

    if ((int) ($decoded['expires'] ?? 0) <= time()) {
        return null;
    }

    return $decoded;
}

function set_signed_auth_cookie(string $type, int $userId, string $userName, int $expires): void
{
    $payload = [
        'type' => $type,
        'user_id' => $userId,
        'user_name' => $userName,
        'expires' => $expires,
    ];

    $cookieValue = build_signed_auth_cookie_value($payload);
    setcookie('doctor_rating_auth', $cookieValue, [
        'expires' => $expires,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function read_signed_auth_cookie(): ?array
{
    $cookieValue = $_COOKIE['doctor_rating_auth'] ?? '';
    return parse_signed_auth_cookie_value((string) $cookieValue);
}

function clear_signed_auth_cookie(): void
{
    setcookie('doctor_rating_auth', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function resolve_attendance_user_column(mysqli $conn): string
{
    $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "attendance_log" AND COLUMN_NAME IN ("user_id", "student_id") ORDER BY FIELD(COLUMN_NAME, "user_id", "student_id") LIMIT 1');
    if (!$stmt) {
        return 'user_id';
    }

    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row && !empty($row['COLUMN_NAME'])) {
        return (string) $row['COLUMN_NAME'];
    }

    ensure_db_column($conn, 'attendance_log', 'user_id', 'INT UNSIGNED DEFAULT NULL');
    return 'user_id';
}

/**
 * Execute a count query safely and always return an integer.
 */
function db_fetch_count(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    if ($types !== '' && !empty($params)) {
        $bindParams = [$types];
        foreach ($params as &$value) {
            $bindParams[] = &$value;
        }
        unset($value);
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return 0;
    }

    if (array_key_exists('total', $row)) {
        return (int) $row['total'];
    }

    if (array_key_exists('count', $row)) {
        return (int) $row['count'];
    }

    return 0;
}

function database_table_exists(mysqli $conn, string $table): bool
{
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    if ($dbName === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $dbName, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (bool) $result->fetch_assoc();
    $stmt->close();

    return $exists;
}

function seed_platform_data(mysqli $conn): void {
    $requiredTables = ['academic_colleges', 'academic_departments', 'subjects', 'doctors', 'reviews', 'curriculum', 'schedules'];
    foreach ($requiredTables as $requiredTable) {
        if (!database_table_exists($conn, $requiredTable)) {
            log_error('Skipping seed_platform_data because table is missing: ' . $requiredTable);
            return;
        }
    }

    $academicCollegeCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM academic_colleges');
    $subjectsCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM subjects');
    $curriculumCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM curriculum');
    $schedulesCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM schedules');
    $doctorsCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM doctors');

    if (!seed_data_required_from_counts($academicCollegeCount, $subjectsCount, $curriculumCount, $schedulesCount, $doctorsCount)) {
        return;
    }

    $collegeNames = ['كلية علوم الحاسب وتقنية المعلومات', 'كلية الهندسة'];
    $departmentsByCollege = [
        'كلية علوم الحاسب وتقنية المعلومات' => ['علوم الحاسب', 'نظم المعلومات', 'هندسة البرمجيات'],
        'كلية الهندسة' => ['الهندسة الكهربائية', 'الهندسة المدنية'],
    ];

    $collegeIds = [];
    $stmtCollege = $conn->prepare('INSERT INTO academic_colleges (college_name, is_active) VALUES (?, 1)');
    foreach ($collegeNames as $collegeName) {
        $stmtCollege->bind_param('s', $collegeName);
        $stmtCollege->execute();
        $collegeIds[$collegeName] = (int) $conn->insert_id;
    }
    $stmtCollege->close();

    $stmtDepartment = $conn->prepare('INSERT INTO academic_departments (college_id, department_name, is_active) VALUES (?, ?, 1)');
    foreach ($departmentsByCollege as $collegeName => $departmentNames) {
        $collegeId = $collegeIds[$collegeName] ?? 0;
        foreach ($departmentNames as $departmentName) {
            $stmtDepartment->bind_param('is', $collegeId, $departmentName);
            $stmtDepartment->execute();
        }
    }
    $stmtDepartment->close();

    $subjects = [
        ['مقدمة في البرمجة', 'CS101', 'https://t.me/jzucs', 'مادة أساسية لطلاب علوم الحاسب', 'theoretical', 'كلية علوم الحاسب وتقنية المعلومات', 'علوم الحاسب', 3, 1],
        ['هياكل البيانات', 'CS201', 'https://t.me/jzudata', 'مادة تطبيقية في تنظيم البيانات', 'practical', 'كلية علوم الحاسب وتقنية المعلومات', 'علوم الحاسب', 3, 2],
        ['شبكات الحاسوب', 'CS301', 'https://t.me/jzunetwork', 'مقدمة لأسس الشبكات', 'theoretical', 'كلية علوم الحاسب وتقنية المعلومات', 'نظم المعلومات', 3, 3],
        ['الدوائر الكهربائية', 'EE101', 'https://t.me/jzueng', 'مقدمة في الأنظمة الكهربائية', 'theoretical', 'كلية الهندسة', 'الهندسة الكهربائية', 3, 1],
    ];

    $stmtSubject = $conn->prepare('INSERT INTO subjects (subject_name, course_code, telegram_link, description, subject_type, college, department, credit_hours, level_num) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($subjects as $subject) {
        [$name, $code, $telegram, $description, $type, $college, $department, $creditHours, $level] = $subject;
        $stmtSubject->bind_param('ssssssiii', $name, $code, $telegram, $description, $type, $college, $department, $creditHours, $level);
        $stmtSubject->execute();
    }
    $stmtSubject->close();

    $stmtDoctor = $conn->prepare('INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, 1)');
    $doctorRows = [
        ['د. أحمد السعدي', 'كلية علوم الحاسب وتقنية المعلومات', 'علوم الحاسب', 'male', 'مقدمة في البرمجة، هياكل البيانات'],
        ['د. سارة المالكي', 'كلية علوم الحاسب وتقنية المعلومات', 'نظم المعلومات', 'female', 'شبكات الحاسوب، نظم معلومات'],
        ['د. خالد الزهراني', 'كلية الهندسة', 'الهندسة الكهربائية', 'male', 'الدوائر الكهربائية']
    ];
    foreach ($doctorRows as $doctorRow) {
        [$name, $college, $department, $gender, $courses] = $doctorRow;
        $stmtDoctor->bind_param('sssss', $name, $college, $department, $gender, $courses);
        $stmtDoctor->execute();
    }
    $stmtDoctor->close();

    $reviewStmt = $conn->prepare('INSERT INTO reviews (doctor_id, rating, comment, reviewer_name, course_code, semester, ip_address, sentiment, status, explanation_stars, handling_stars, grading_stars) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $doctorIds = [];
    $doctorResult = $conn->query('SELECT id FROM doctors ORDER BY id ASC');
    if ($doctorResult) {
        while ($row = $doctorResult->fetch_assoc()) {
            $doctorIds[] = (int) $row['id'];
        }
    }
    $sampleReviews = [
        [$doctorIds[0] ?? 1, 5, 'شرح ممتاز ومحتوى عملي ومفيد جداً.', 'سارة', 'CS101', 'الفصل الأول 1447', '127.0.0.1', 'positive', 'approved', 5, 5, 5],
        [$doctorIds[1] ?? 2, 4, 'التدريس واضح والتواصل جيد مع الطلاب.', 'أحمد', 'CS301', 'الفصل الثاني 1447', '127.0.0.2', 'positive', 'approved', 4, 4, 4],
    ];
    foreach ($sampleReviews as $review) {
        [$doctorId, $rating, $comment, $reviewer, $courseCode, $semester, $ip, $sentiment, $status, $exp, $handle, $grade] = $review;
        $reviewStmt->bind_param('iisssssssiii', $doctorId, $rating, $comment, $reviewer, $courseCode, $semester, $ip, $sentiment, $status, $exp, $handle, $grade);
        $reviewStmt->execute();
    }
    $reviewStmt->close();

    $stmtCurriculum = $conn->prepare('INSERT INTO curriculum (title, description, academic_path, college, department, semester, credits, study_level, objectives) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $curriculums = [
        ['مقدمة في البرمجة', 'أساسيات البرمجة وتطوير الحلول المنطقية', 'علوم الحاسب', 'كلية علوم الحاسب وتقنية المعلومات', 'علوم الحاسب', 'الفصل الأول', 3, 'المستوى الأول', 'تعريف الطالب بمفاهيم البرمجة الأساسية والتفكير المنطقي'],
        ['هياكل البيانات', 'تنظيم البيانات والخوارزميات الأساسية', 'علوم الحاسب', 'كلية علوم الحاسب وتقنية المعلومات', 'علوم الحاسب', 'الفصل الثاني', 3, 'المستوى الثاني', 'تمكين الطالب من تصميم هياكل البيانات واستخدام الخوارزميات بكفاءة'],
        ['شبكات الحاسوب', 'أسس الشبكات ونقل البيانات', 'شبكات وتقنية البنية التحتية', 'كلية علوم الحاسب وتقنية المعلومات', 'نظم المعلومات', 'الفصل الثالث', 3, 'المستوى الثالث', 'تقديم أساسيات الشبكات والاتصال الرقمي'],
        ['قواعد البيانات', 'تصميم وإدارة قواعد البيانات', 'أنظمة المعلومات', 'كلية علوم الحاسب وتقنية المعلومات', 'نظم المعلومات', 'الفصل الرابع', 3, 'المستوى الرابع', 'تدريب الطالب على تصميم قواعد البيانات وإدارة البيانات'],
    ];
    foreach ($curriculums as $curriculum) {
        [$title, $description, $academicPath, $college, $department, $semester, $credits, $studyLevel, $objectives] = $curriculum;
        $stmtCurriculum->bind_param('ssssssiss', $title, $description, $academicPath, $college, $department, $semester, $credits, $studyLevel, $objectives);
        $stmtCurriculum->execute();
    }
    $stmtCurriculum->close();

    $stmtSchedule = $conn->prepare('INSERT INTO schedules (title, course_code, day_of_week, start_time, end_time, location, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $scheduleRows = [
        ['مقدمة في البرمجة', 'CS101', 1, '08:00:00', '09:30:00', 'المبنى A - 101', 'محاضرة نظرية'],
        ['هياكل البيانات', 'CS201', 2, '10:00:00', '11:30:00', 'المبنى B - 204', 'مهمة تطبيقية'],
        ['شبكات الحاسوب', 'CS301', 3, '12:00:00', '13:30:00', 'المبنى C - 305', 'مختبر'],
        ['قواعد البيانات', 'CS401', 4, '09:00:00', '10:30:00', 'المبنى D - 402', 'محاضرة عملية'],
    ];
    foreach ($scheduleRows as $schedule) {
        [$title, $code, $day, $start, $end, $location, $notes] = $schedule;
        $stmtSchedule->bind_param('ssissss', $title, $code, $day, $start, $end, $location, $notes);
        $stmtSchedule->execute();
    }
    $stmtSchedule->close();
}

seed_platform_data($conn);

// ============ CSRF TOKEN FUNCTIONS ============

/**
 * Generate and return CSRF token
 * توليد وإرجاع رمز الحماية الأمني للمنصة
 */
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Verify CSRF token
 * التحقق الأمني المشدد من صحة ومطابقة الرمز لمنع ثغرات التزوير
 */
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Get current CSRF token
 * الحصول على الرمز الأمني الفعال الحالي للجلسة
 */
if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        return generate_csrf_token();
    }
}

/**
 * Detect actual timestamp column name in audit_logs table.
 * Returns a safe column name string (falls back to created_at).
 */
function get_audit_timestamp_column() {
    global $conn;

    // تم تحديث مصفوفة الفحص لتتطابق مع جدول العمليات الفعلي الحالي في الموقع [audit_logs]
    $candidates = ['created_at', 'Action_Timestamp', 'timestamp', 'action_date', 'createdAt'];
    $databaseName = defined('DB_NAME') ? DB_NAME : '';

    if ($databaseName === '') {
        return 'created_at';
    }

    $placeholders = implode("','", $candidates);
    // تم توجيه الاستعلام ليفحص البنية الحقيقية لجدول audit_logs لحل مشكلة تجمد الفلترة
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME IN ('" . $placeholders . "') LIMIT 1";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('s', $databaseName);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $col = $row['COLUMN_NAME'];
            $stmt->close();
            return $col;
        }
        $stmt->close();
    }

    return 'created_at';
}
?>