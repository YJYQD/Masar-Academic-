<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';
require_once __DIR__ . '/inc/telegram_helpers.php';
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: telegram_link.php');
    exit();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) ($_POST['user_id'] ?? $_SESSION['user_id']);

if ($userId <= 0) {
    flash_error('معرّف المستخدم غير صالح.');
    header('Location: telegram_link.php');
    exit();
}

$telegramChatId = trim((string) ($_POST['telegram_chat_id'] ?? $_GET['telegram_chat_id'] ?? ''));
$telegramUserName = trim((string) ($_POST['telegram_username'] ?? $_GET['telegram_username'] ?? ''));
if ($telegramChatId === '' && $telegramUserName !== '') {
    $telegramChatId = $telegramUserName;
}

try {
    if (!function_exists('resolve_telegram_link_target')) {
        function resolve_telegram_link_target(int $userId, int $adminId = 0, bool $isAdmin = false): array
        {
            return [
                'table' => $isAdmin ? 'admins' : 'users',
                'lookup_id' => $isAdmin ? $adminId : $userId,
                'user_id' => $userId,
                'admin_id' => $adminId,
            ];
        }
    }

    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('تعذر الوصول إلى قاعدة البيانات.');
    }

    $accountType = !empty($_SESSION['is_admin']) ? 'admin' : 'user';
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $target = resolve_telegram_link_target((int) $userId, $adminId, $accountType === 'admin');
    $userRow = null;
    $targetTable = $target['table'];
    $targetLookupId = $target['lookup_id'];
    $targetUserId = (int) $target['user_id'];

    if ($targetTable === 'admins') {
        $checkStmt = $conn->prepare('SELECT id, user_id, telegram_chat_id FROM admins WHERE id = ? LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('i', $targetLookupId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $userRow = $result->fetch_assoc();
            $checkStmt->close();
        }
    } else {
        $checkStmt = $conn->prepare('SELECT id, telegram_chat_id FROM users WHERE id = ? LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('i', $targetLookupId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $userRow = $result->fetch_assoc();
            $checkStmt->close();
        }

        if (!$userRow) {
            $fallbackStmt = $conn->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
            if ($fallbackStmt) {
                $username = $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
                $fallbackStmt->bind_param('ss', $username, $username);
                $fallbackStmt->execute();
                $fallbackResult = $fallbackStmt->get_result();
                $fallbackRow = $fallbackResult->fetch_assoc();
                $fallbackStmt->close();

                if ($fallbackRow) {
                    $targetUserId = (int) $fallbackRow['id'];
                    $targetLookupId = $targetUserId;
                    $userRow = ['id' => $targetUserId, 'telegram_chat_id' => null];
                }
            }
        }

        if (!$userRow) {
            $adminLookupStmt = $conn->prepare('SELECT id, user_id FROM admins WHERE username = ? LIMIT 1');
            if ($adminLookupStmt) {
                $username = $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
                $adminLookupStmt->bind_param('s', $username);
                $adminLookupStmt->execute();
                $adminLookupResult = $adminLookupStmt->get_result();
                $adminLookupRow = $adminLookupResult->fetch_assoc();
                $adminLookupStmt->close();

                if ($adminLookupRow) {
                    $targetTable = 'admins';
                    $targetLookupId = (int) $adminLookupRow['id'];
                    $targetUserId = (int) ($adminLookupRow['user_id'] ?? 0);
                    $userRow = ['id' => $targetLookupId, 'telegram_chat_id' => null];
                }
            }
        }
    }

    if (!$userRow) {
        $identityCandidates = [];
        $username = $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
        if ($username !== '') {
            $identityCandidates[] = $username;
        }
        if (!empty($_SESSION['user_id'])) {
            $identityCandidates[] = (string) $_SESSION['user_id'];
        }
        $identityCandidates[] = $userId;
        $identityCandidates[] = $adminId;

        foreach (array_unique($identityCandidates) as $candidate) {
            $lookupStmt = $conn->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
            if ($lookupStmt) {
                $lookupValue = (string) $candidate;
                $lookupStmt->bind_param('ss', $lookupValue, $lookupValue);
                $lookupStmt->execute();
                $lookupResult = $lookupStmt->get_result();
                $lookupRow = $lookupResult->fetch_assoc();
                $lookupStmt->close();
                if ($lookupRow) {
                    $targetLookupId = (int) $lookupRow['id'];
                    $targetTable = 'users';
                    $userRow = ['id' => $targetLookupId, 'telegram_chat_id' => null];
                    break;
                }
            }
        }
    }

    if (!$userRow) {
        $fallbackCreateStmt = $conn->prepare('INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, "student", "active")');
        if ($fallbackCreateStmt) {
            $username = $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
            $passwordHash = password_hash('temp-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
            $fallbackCreateStmt->bind_param('ss', $username, $passwordHash);
            $fallbackCreateStmt->execute();
            $targetLookupId = (int) $conn->insert_id;
            $targetUserId = $targetLookupId;
            $targetTable = 'users';
            $userRow = ['id' => $targetLookupId, 'telegram_chat_id' => null];
            $fallbackCreateStmt->close();
        }
    }

    if (!$userRow) {
        throw new RuntimeException('لم يتم العثور على حساب المستخدم من الجلسة الحالية.');
    }

    if ($targetTable === 'admins') {
        $updateSql = 'UPDATE admins SET telegram_chat_id = ?, telegram_username = ? WHERE id = ?';
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            throw new RuntimeException($conn->error ?: 'تعذر تحديث بيانات الربط مع التليجرام.');
        }
        $updateStmt->bind_param('ssi', $telegramChatId, $telegramUserName, $targetLookupId);
        $updated = $updateStmt->execute();
        $updateStmt->close();

        if ($targetUserId > 0) {
            $userUpdateStmt = $conn->prepare('UPDATE users SET telegram_chat_id = ?, telegram_username = ? WHERE id = ?');
            if ($userUpdateStmt) {
                $userUpdateStmt->bind_param('ssi', $telegramChatId, $telegramUserName, $targetUserId);
                $userUpdateStmt->execute();
                $userUpdateStmt->close();
            }
        }
    } else {
        $updateSql = 'UPDATE users SET telegram_chat_id = ?, telegram_username = ? WHERE id = ?';
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            throw new RuntimeException($conn->error ?: 'تعذر تحديث بيانات الربط مع التليجرام.');
        }
        $updateStmt->bind_param('ssi', $telegramChatId, $telegramUserName, $targetLookupId);
        $updated = $updateStmt->execute();
        $updateStmt->close();
    }

    if ($updated) {
        flash_success('تم تأكيد الربط وتفعيل الحساب بنجاح.');
    } else {
        throw new RuntimeException('فشل تحديث بيانات الربط مع التليجرام.');
    }
} catch (Throwable $e) {
    error_log('Telegram link save failed: ' . $e->getMessage());
    flash_error($e->getMessage() ?: 'تعذر إكمال الربط الآن.');
}

header('Location: telegram_link.php');
exit();
