<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/privacy.php';
require_once __DIR__ . '/inc/anonymous_identity.php';
require_once __DIR__ . '/inc/telegram_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'يجب تسجيل الدخول أولاً']);
    exit();
}

$userId = (int) $_SESSION['user_id'];
$telegramUserId = trim((string) ($_POST['telegram_user_id'] ?? $_GET['telegram_user_id'] ?? ''));
$telegramUsername = trim((string) ($_POST['telegram_username'] ?? $_GET['telegram_username'] ?? ''));

if ($telegramUserId === '' && $telegramUsername === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'لا توجد بيانات ربط صالحة']);
    exit();
}

$conn->query('CREATE TABLE IF NOT EXISTS `anonymous_profiles` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

$profile = upsert_anonymous_profile($conn, $userId, $telegramUserId !== '' ? $telegramUserId : null, $telegramUsername !== '' ? $telegramUsername : null);

$stmt = $conn->prepare('UPDATE users SET telegram_chat_id = ? WHERE id = ?');
if ($stmt) {
    $telegramValue = $telegramUserId !== '' ? $telegramUserId : $telegramUsername;
    $stmt->bind_param('si', $telegramValue, $userId);
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'anonymous_code' => $profile['anonymous_code'] ?? '',
    'telegram_username' => $telegramUsername,
    'telegram_user_id' => $telegramUserId,
    'message' => 'تم تحديث الربط بنجاح باستخدام معرف مجهول آمن',
], JSON_UNESCAPED_UNICODE);
