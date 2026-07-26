<?php
require_once __DIR__ . '/mailer.php';

function ensure_user_otp_columns(mysqli $conn): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME IN ("otp_code", "otp_expires", "is_verified", "telegram_chat_id")'
    );
    if (!$stmt) {
        if (function_exists('log_error')) {
            log_error('OTP column check prepare failed: ' . $conn->error);
        }
        return false;
    }

    $stmt->bind_param('s', $dbName);
    if (!$stmt->execute()) {
        if (function_exists('log_error')) {
            log_error('OTP column check execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return false;
    }

    $res = $stmt->get_result();
    $existing = [];
    while ($row = $res->fetch_assoc()) {
        $existing[$row['COLUMN_NAME']] = true;
    }
    $stmt->close();

    $alterParts = [];
    if (empty($existing['otp_code'])) {
        $alterParts[] = 'ADD COLUMN `otp_code` VARCHAR(16) DEFAULT NULL';
    }
    if (empty($existing['otp_expires'])) {
        $alterParts[] = 'ADD COLUMN `otp_expires` DATETIME DEFAULT NULL';
    }
    if (empty($existing['is_verified'])) {
        $alterParts[] = 'ADD COLUMN `is_verified` TINYINT(1) NOT NULL DEFAULT 0';
    }
    if (empty($existing['telegram_chat_id'])) {
        $alterParts[] = 'ADD COLUMN `telegram_chat_id` VARCHAR(255) DEFAULT NULL';
    }

    if (!empty($alterParts)) {
        $alterSql = 'ALTER TABLE `users` ' . implode(', ', $alterParts);
        if (!$conn->query($alterSql)) {
            if (function_exists('log_error')) {
                log_error('OTP column migration failed: ' . $conn->error . ' | SQL: ' . $alterSql);
            }
            return false;
        }
    }

    $indexStmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users" AND COLUMN_NAME = "telegram_chat_id" AND NON_UNIQUE = 0'
    );
    if ($indexStmt) {
        $indexStmt->bind_param('s', $dbName);
        if ($indexStmt->execute()) {
            $idxRes = $indexStmt->get_result();
            $row = $idxRes->fetch_assoc();
            if (empty($row['total']) || (int) $row['total'] === 0) {
                $conn->query('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_telegram_chat_id` (`telegram_chat_id`)');
            }
        }
        $indexStmt->close();
    }

    if (!ensure_email_counter_table($conn)) {
        if (function_exists('log_error')) {
            log_error('Email counter table could not be ensured.');
        }
    }

    $checked = true;
    return true;
}

function generate_numeric_otp(int $length = 6): string {
    $min = (int) pow(10, $length - 1);
    $max = (int) pow(10, $length) - 1;
    return (string) random_int($min, $max);
}

function is_university_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/@(stu\.)?jazanu\.edu\.sa$/i', $email);
}

function is_otp_still_valid(?string $otpCode, ?string $expires): bool {
    if ($otpCode === null || $expires === null) {
        return false;
    }

    $expiry = DateTime::createFromFormat('Y-m-d H:i:s', $expires);
    if (!$expiry) {
        return false;
    }

    return $expiry >= new DateTime();
}

function ensure_email_counter_table(mysqli $conn): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `email_counter` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `log_date` DATE NOT NULL,
        `send_count` INT DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_log_date` (`log_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    return $conn->query($sql) !== false;
}

function reserve_email_slot(mysqli $conn, int $dailyLimit = 100, bool &$limitReached = null): bool {
    $limitReached = false;

    if (!ensure_user_otp_columns($conn)) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        return false;
    }

    $today = date('Y-m-d');
    $select = $conn->prepare('SELECT send_count FROM email_counter WHERE `log_date` = ? FOR UPDATE');
    if (!$select) {
        $conn->rollback();
        return false;
    }
    $select->bind_param('s', $today);
    if (!$select->execute()) {
        $select->close();
        $conn->rollback();
        return false;
    }

    $result = $select->get_result();
    $row = $result->fetch_assoc();
    $select->close();

    if ($row === null) {
        $insert = $conn->prepare('INSERT INTO email_counter (`log_date`, send_count) VALUES (?, 1)');
        if (!$insert) {
            $conn->rollback();
            return false;
        }
        $insert->bind_param('s', $today);
        if (!$insert->execute()) {
            $insert->close();
            $conn->rollback();
            return false;
        }
        $insert->close();
        $conn->commit();
        return true;
    }

    $currentCount = (int) $row['send_count'];
    if ($currentCount >= $dailyLimit) {
        $conn->rollback();
        $limitReached = true;
        return false;
    }

    $update = $conn->prepare('UPDATE email_counter SET send_count = send_count + 1 WHERE `log_date` = ?');
    if (!$update) {
        $conn->rollback();
        return false;
    }
    $update->bind_param('s', $today);
    if (!$update->execute()) {
        $update->close();
        $conn->rollback();
        return false;
    }
    $update->close();
    $conn->commit();
    return true;
}

function release_email_slot(mysqli $conn): bool {
    if (!$conn->begin_transaction()) {
        return false;
    }

    $today = date('Y-m-d');
    $stmt = $conn->prepare('UPDATE email_counter SET send_count = GREATEST(send_count - 1, 0) WHERE `log_date` = ?');
    if (!$stmt) {
        $conn->rollback();
        return false;
    }
    $stmt->bind_param('s', $today);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        $conn->rollback();
        return false;
    }
    return $conn->commit();
}

/**
 * Generate OTP, save to users table and send via email.
 * Returns true on success (email sent and DB updated), false otherwise.
 */
function generate_and_send_otp(mysqli $conn, int $userId): bool {
    $stmt = $conn->prepare('SELECT email, username FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        if (function_exists('log_error')) {
            log_error('OTP select prepare failed: ' . $conn->error);
        }
        return false;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        if (function_exists('log_error')) {
            log_error('OTP select execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return false;
    }
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user || empty($user['email'])) {
        if (function_exists('log_error')) {
            log_error('OTP generation failed: no email found for user ' . $userId);
        }
        return false;
    }

    $otp = generate_numeric_otp(6);
    $ttl = defined('OTP_TTL_MINUTES') ? (int) OTP_TTL_MINUTES : 10;
    $expires = (new DateTime())->add(new DateInterval('PT' . $ttl . 'M'))->format('Y-m-d H:i:s');

    if (!ensure_user_otp_columns($conn)) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE users SET otp_code = ?, otp_expires = ? WHERE id = ?');
    if (!$stmt) {
        if (function_exists('log_error')) {
            log_error('OTP update prepare failed: ' . $conn->error);
        }
        return false;
    }
    $stmt->bind_param('ssi', $otp, $expires, $userId);
    $ok = $stmt->execute();
    if (!$ok) {
        if (function_exists('log_error')) {
            log_error('OTP update execute failed: ' . $stmt->error);
        }
    }
    $stmt->close();

    if (!$ok) {
        if (function_exists('log_error')) {
            log_error('Failed to write OTP for user ' . $userId . ' - ' . $conn->error);
        }
        return false;
    }

    return send_otp_email($user['email'], $otp, $user['username'] ?? '');
}
