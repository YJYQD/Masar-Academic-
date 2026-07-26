<?php
require_once __DIR__ . '/privacy.php';

if (!function_exists('ensure_anonymous_identity_tables')) {
    function ensure_anonymous_identity_tables(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS `anonymous_profiles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `anonymous_code` VARCHAR(64) NOT NULL,
            `telegram_user_id` VARCHAR(64) DEFAULT NULL,
            `telegram_username` VARCHAR(100) DEFAULT NULL,
            `consent_hash` VARCHAR(64) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_anonymous_profiles_user` (`user_id`),
            UNIQUE KEY `uq_anonymous_profiles_code` (`anonymous_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `attendance_events` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profile_id` INT UNSIGNED NOT NULL,
            `course_code` VARCHAR(50) NOT NULL,
            `event_type` VARCHAR(30) NOT NULL DEFAULT 'checkin',
            `event_payload` JSON DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_attendance_events_profile` (`profile_id`),
            CONSTRAINT `fk_attendance_events_profile` FOREIGN KEY (`profile_id`) REFERENCES `anonymous_profiles` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `academic_programs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `program_code` VARCHAR(80) NOT NULL,
            `program_name` VARCHAR(255) NOT NULL,
            `college_id` INT UNSIGNED DEFAULT NULL,
            `department_id` INT UNSIGNED DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_academic_programs_code` (`program_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `program_curriculum_items` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `program_id` INT UNSIGNED NOT NULL,
            `subject_id` INT UNSIGNED NOT NULL,
            `semester_label` VARCHAR(100) DEFAULT NULL,
            `is_required` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_program_subject` (`program_id`, `subject_id`),
            CONSTRAINT `fk_program_curriculum_program` FOREIGN KEY (`program_id`) REFERENCES `academic_programs` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_program_curriculum_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('upsert_anonymous_profile')) {
    function upsert_anonymous_profile(mysqli $conn, int $userId, ?string $telegramUserId = null, ?string $telegramUsername = null): array
    {
        $code = build_anonymous_profile_id($userId);
        $stmt = $conn->prepare('SELECT id, anonymous_code, telegram_user_id, telegram_username FROM anonymous_profiles WHERE user_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $updateStmt = $conn->prepare('UPDATE anonymous_profiles SET anonymous_code = ?, telegram_user_id = ?, telegram_username = ? WHERE user_id = ?');
                if ($updateStmt) {
                    $updateStmt->bind_param('sssi', $code, $telegramUserId, $telegramUsername, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
                return ['id' => (int) $row['id'], 'anonymous_code' => $code];
            }
        }

        $insertStmt = $conn->prepare('INSERT INTO anonymous_profiles (user_id, anonymous_code, telegram_user_id, telegram_username, consent_hash) VALUES (?, ?, ?, ?, ?)');
        if ($insertStmt) {
            $consentHash = hash_for_storage((string) $userId . '|' . ($telegramUserId ?? ''));
            $insertStmt->bind_param('issss', $userId, $code, $telegramUserId, $telegramUsername, $consentHash);
            $insertStmt->execute();
            $insertStmt->close();
        }

        return ['id' => (int) $conn->insert_id, 'anonymous_code' => $code];
    }
}
