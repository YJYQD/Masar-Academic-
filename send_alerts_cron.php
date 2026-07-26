<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Riyadh');
}

date_default_timezone_set(APP_TIMEZONE);

function send_telegram_text(string $chatId, string $text): bool
{
    if ($chatId === '' || !function_exists('curl_init')) {
        return false;
    }

    $botToken = '8266756174:AAG8jSRGVqOtQfCYRUm2MuscWURyW_ZofXA';
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ], '', '&');

    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return is_string($response) && $httpCode >= 200 && $httpCode < 300;
}

function build_next_occurrence_for_schedule(array $schedule, DateTimeImmutable $now): DateTimeImmutable
{
    $dayOfWeek = (int) ($schedule['day_of_week'] ?? 0);
    $startTime = trim((string) ($schedule['start_time'] ?? ''));
    [$hour, $minute, $second] = array_pad(explode(':', $startTime), 3, '00');

    $candidate = $now->setTime((int) $hour, (int) $minute, (int) $second);
    $currentDay = (int) $now->format('w');
    $deltaDays = ($dayOfWeek - $currentDay + 7) % 7;

    if ($deltaDays === 0 && $candidate <= $now) {
        $candidate = $candidate->modify('+7 days');
    } elseif ($deltaDays > 0) {
        $candidate = $candidate->modify('+' . $deltaDays . ' days');
    }

    return $candidate;
}

function should_send_reminder(DateTimeImmutable $now, DateTimeImmutable $nextOccurrence): bool
{
    $diffMinutes = (int) floor(($nextOccurrence->getTimestamp() - $now->getTimestamp()) / 60);
    return $diffMinutes === 10;
}

function build_alert_message(array $schedule, DateTimeImmutable $nextOccurrence): string
{
    $title = trim((string) ($schedule['title'] ?? 'مقرر غير محدد'));
    $courseCode = trim((string) ($schedule['course_code'] ?? ''));
    $location = trim((string) ($schedule['location'] ?? ''));
    $notes = trim((string) ($schedule['notes'] ?? ''));
    $startTime = $nextOccurrence->format('H:i');
    $dayLabel = $nextOccurrence->format('Y-m-d');

    $message = "🔔 تنبيه قبل المحاضرة بـ 10 دقائق\n";
    $message .= "المقرر: {$title}\n";
    if ($courseCode !== '') {
        $message .= "الرمز: {$courseCode}\n";
    }
    $message .= "اليوم: {$dayLabel}\n";
    $message .= "الوقت: {$startTime}\n";
    if ($location !== '') {
        $message .= "المكان: {$location}\n";
    }
    if ($notes !== '') {
        $message .= "ملاحظات: {$notes}\n";
    }
    $message .= "استعدوا للمحاضرة من الآن.";

    return $message;
}

$now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
$sentCount = 0;
$details = [];

if (!$conn instanceof mysqli) {
    $response = "DB unavailable: MySQL/XAMPP is not reachable.";
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $response;
    } else {
        echo $response . PHP_EOL;
    }
    exit(0);
}

$stmt = $conn->prepare('SELECT id, title, course_code, day_of_week, start_time, location, notes FROM schedules WHERE start_time IS NOT NULL ORDER BY day_of_week, start_time');
if (!$stmt) {
    $response = "query failed";
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $response;
    } else {
        echo $response . PHP_EOL;
    }
    exit(0);
}

$stmt->execute();
$result = $stmt->get_result();
$usersStmt = $conn->prepare('SELECT id, username, telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ""');
$users = [];
if ($usersStmt) {
    $usersStmt->execute();
    $usersResult = $usersStmt->get_result();
    while ($row = $usersResult->fetch_assoc()) {
        $users[] = [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'telegram_chat_id' => trim((string) ($row['telegram_chat_id'] ?? '')),
        ];
    }
    $usersStmt->close();
}

while ($row = $result->fetch_assoc()) {
    $schedule = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'course_code' => (string) ($row['course_code'] ?? ''),
        'day_of_week' => (int) ($row['day_of_week'] ?? 0),
        'start_time' => (string) ($row['start_time'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
    ];

    $nextOccurrence = build_next_occurrence_for_schedule($schedule, $now);
    if (!should_send_reminder($now, $nextOccurrence)) {
        continue;
    }

    $message = build_alert_message($schedule, $nextOccurrence);
    foreach ($users as $user) {
        if ($user['telegram_chat_id'] === '') {
            continue;
        }

        if (send_telegram_text($user['telegram_chat_id'], $message)) {
            $sentCount++;
            $details[] = [
                'schedule_id' => $schedule['id'],
                'user_id' => $user['id'],
                'username' => $user['username'],
            ];
        }
    }
}

$stmt->close();

if (function_exists('log_error') && $sentCount === 0) {
    log_error('send_alerts_cron completed with 0 alerts');
}

$response = "Sent alerts: {$sentCount}";
if ($sentCount === 0) {
    $response .= "\nNo reminder was sent. Possible reasons: MySQL is offline, no schedule is due within 10 minutes, or no verified Telegram-linked users exist.";
}
if (!empty($details)) {
    $response .= "\n" . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

echo $response . PHP_EOL;
