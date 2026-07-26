<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/telegram_helpers.php';

function write_telegram_debug_log(string $message): void
{
    $logDir = defined('APP_LOG_DIR') ? APP_LOG_DIR : __DIR__ . '/logs';
    if ($logDir === '') {
        return;
    }

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logDir . '/telegram_debug.log', '[' . $timestamp . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function verify_webhook_signature(array $headers, string $rawBody): bool
{
    $secret = (string) (getenv('SITE_WEBHOOK_SECRET') ?: '');
    if ($secret === '') {
        return true;
    }

    $signature = '';
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-Site-Webhook-Signature') === 0) {
            $signature = (string) $value;
            break;
        }
    }

    if ($signature === '') {
        $signature = (string) ($_SERVER['HTTP_X_SITE_WEBHOOK_SIGNATURE'] ?? '');
    }

    if ($signature === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function send_telegram_text(string $chatId, string $text, array $extraPayload = []): bool
{
    if ($chatId === '' || empty(TELEGRAM_BOT_TOKEN)) {
        write_telegram_debug_log('send_telegram_text skipped: missing chat id or bot token');
        return false;
    }

    if (!function_exists('curl_init')) {
        write_telegram_debug_log('send_telegram_text skipped: curl extension missing');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $payload = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $extraPayload);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

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

$input = file_get_contents('php://input');
write_telegram_debug_log('webhook payload: ' . ($input !== '' ? substr($input, 0, 1000) : 'empty'));
if ($input === '') {
    http_response_code(400);
    exit('invalid');
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}
if (!verify_webhook_signature($headers, $input)) {
    write_telegram_debug_log('webhook signature validation failed');
    http_response_code(401);
    exit('invalid signature');
}

$update = json_decode($input, true);
if (!is_array($update)) {
    write_telegram_debug_log('webhook payload invalid JSON');
    http_response_code(400);
    exit('invalid');
}

$eventName = isset($update['event']) ? trim((string) $update['event']) : '';
if ($eventName !== '') {
    $payload = $update['payload'] ?? $update;
    $chatId = isset($payload['chat_id']) ? (string) $payload['chat_id'] : '';
    $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
    $telegramUsername = trim((string) ($payload['telegram_username'] ?? $payload['username'] ?? ''));

    write_telegram_debug_log('webhook event received: ' . $eventName . ' user=' . $userId . ' chat=' . $chatId);

    if ($userId > 0) {
        $target = resolve_telegram_link_target((int) $userId, (int) ($payload['admin_id'] ?? 0), !empty($payload['is_admin']));
        $targetTable = $target['table'];
        $targetLookupId = $target['lookup_id'];

        if ($targetTable === 'admins') {
            $stmt = $conn->prepare('SELECT id FROM admins WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $targetLookupId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();

                if ($row) {
                    $updateStmt = $conn->prepare('UPDATE admins SET telegram_chat_id = COALESCE(NULLIF(?, ""), telegram_chat_id), telegram_username = COALESCE(NULLIF(?, ""), telegram_username) WHERE id = ?');
                    if ($updateStmt) {
                        $updateStmt->bind_param('ssi', $chatId, $telegramUsername, $targetLookupId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    if ((int) ($payload['user_id'] ?? 0) > 0) {
                        $userUpdateStmt = $conn->prepare('UPDATE users SET telegram_chat_id = COALESCE(NULLIF(?, ""), telegram_chat_id), telegram_username = COALESCE(NULLIF(?, ""), telegram_username) WHERE id = ?');
                        if ($userUpdateStmt) {
                            $linkedUserId = (int) ($payload['user_id'] ?? 0);
                            $userUpdateStmt->bind_param('ssi', $chatId, $telegramUsername, $linkedUserId);
                            $userUpdateStmt->execute();
                            $userUpdateStmt->close();
                        }
                    }
                }
            }
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $targetLookupId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();

                if ($row) {
                    $updateStmt = $conn->prepare('UPDATE users SET telegram_chat_id = COALESCE(NULLIF(?, ""), telegram_chat_id), telegram_username = COALESCE(NULLIF(?, ""), telegram_username) WHERE id = ?');
                    if ($updateStmt) {
                        $updateStmt->bind_param('ssi', $chatId, $telegramUsername, $targetLookupId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                }
            }
        }
    }

    http_response_code(200);
    exit('ok');
}

$message = $update['message'] ?? [];
$callbackQuery = $update['callback_query'] ?? [];
$chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : '';
$text = trim((string) ($message['text'] ?? ''));
$callbackData = trim((string) ($callbackQuery['data'] ?? ''));

if ($chatId === '' && isset($callbackQuery['message']['chat']['id'])) {
    $chatId = (string) $callbackQuery['message']['chat']['id'];
}

if ($chatId === '') {
    http_response_code(200);
    exit('ok');
}

$reply = "أهلاً بك في بوت منصة مسار الأكاديمية.\n";
$reply .= "استخدم الرابط من الموقع لإتمام الربط أو أرسل /start مرة أخرى.";
write_telegram_debug_log('message received: chat=' . $chatId . ' text=' . $text . ' callback=' . $callbackData);

$bindCode = extract_telegram_link_code_from_text($text);
if ($bindCode !== '') {
    $bindStmt = $conn->prepare('SELECT id FROM users WHERE telegram_bind_code = ? LIMIT 1');
    if ($bindStmt) {
        $bindStmt->bind_param('s', $bindCode);
        $bindStmt->execute();
        $bindRes = $bindStmt->get_result();
        $bindRow = $bindRes->fetch_assoc();
        $bindStmt->close();

        if ($bindRow) {
            $userId = (int) $bindRow['id'];
            $telegramUsername = trim((string) ($update['message']['from']['username'] ?? ''));
            $updateStmt = $conn->prepare('UPDATE users SET telegram_chat_id = ?, telegram_username = ?, telegram_bind_code = NULL WHERE id = ?');
            if ($updateStmt) {
                $updateStmt->bind_param('ssi', $chatId, $telegramUsername, $userId);
                $updateStmt->execute();
                $updateStmt->close();
                $reply = "تم ربط حسابك بنجاح ✅\nاسم المستخدم في التليجرام: " . ($telegramUsername !== '' ? $telegramUsername : 'غير متوفر') . "\nستتلقى التنبيهات من البوت الآن.";
            }
        } else {
            $reply = "الكود غير صحيح أو لم يعد صالحاً.\nيرجى فتح صفحة الربط من الموقع مرة أخرى ثم استخدم الكود الجديد.";
        }
    }
}

if ($callbackData !== '') {
    $reply = match ($callbackData) {
        'reminder_10' => "تم ضبط التنبيه قبل كلاس بـ 10 دقائق ✅",
        'reminder_30' => "تم ضبط التنبيه قبل كلاس بـ 30 دقيقة ✅",
        'reminder_off' => "تم إيقاف التنبيهات ✅",
        default => "تم استلام اختيارك ✅",
    };

    send_telegram_text($chatId, $reply);
    http_response_code(200);
    exit('ok');
}

$payload = extract_start_payload_from_text($text);
if ($payload !== null) {
    $tokenPayload = trim($payload);
    $userId = normalize_start_payload_to_user_id($tokenPayload);
    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row) {
                $telegramUsername = trim((string) ($update['message']['from']['username'] ?? ''));
                $updateStmt = $conn->prepare('UPDATE users SET telegram_chat_id = ?, telegram_username = ? WHERE id = ?');
                if ($updateStmt) {
                    $updateStmt->bind_param('ssi', $chatId, $telegramUsername, $userId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    $reply = "تم ربط حسابك بنجاح ✅\nاسم المستخدم في التليجرام: " . ($telegramUsername !== '' ? $telegramUsername : 'غير متوفر') . "\nستتلقى التنبيهات من البوت الآن.";
                }
            }
        }
    } else {
        $reply = "أهلاً بك في بوت منصة مسار الأكاديمية.\n";
        $reply .= "افتح الموقع ثم استخدم زر ربط التليجرام ليتم الربط تلقائياً.";
    }
}

if ($text === '/start' || $text === '/menu') {
    $reply = "مرحباً بك في بوت منصة مسار الأكاديمية ✅\n";
    $reply .= "لربط حسابك، أرسل الكود الرقمي الذي يظهر لك في صفحة الربط على الموقع.\n";
    $reply .= "مثال: 123456";
    send_telegram_text($chatId, $reply);
} else {
    send_telegram_text($chatId, $reply);
}
http_response_code(200);
exit('ok');
