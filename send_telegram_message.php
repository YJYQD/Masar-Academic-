<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function respond($ok, $msg = '', array $extra = []) {
    echo json_encode(array_merge(['ok' => (bool) $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid method');
}

$type = trim($_POST['type'] ?? '');
$message = trim($_POST['message'] ?? '');
$page = trim($_POST['page'] ?? '');
$user = trim($_POST['user'] ?? '');
$userName = trim($_POST['user_name'] ?? '');
$userId = trim($_POST['user_id'] ?? '');

if ($userId === '' && !empty($_SESSION['user_id'])) {
    $userId = (string) $_SESSION['user_id'];
}
if ($userName === '' && !empty($_SESSION['user_name'])) {
    $userName = (string) $_SESSION['user_name'];
}
if ($userName === '' && $user !== '') {
    $userName = $user;
}

if ($message === '') {
    respond(false, 'Empty message');
}

$botToken = '8266756174:AAG8jSRGVqOtQfCYRUm2MuscWURyW_ZofXA';
$chatId = '7284600657';

if ($botToken === '' || $chatId === '') {
    if (function_exists('log_error')) {
        log_error('send_telegram_message.php missing Telegram config');
    }
    respond(false, 'Telegram bot token or admin chat id not configured');
}

$text = "[Website Message]" . "\n";
if ($type !== '') { $text .= "Type: " . $type . "\n"; }
if ($userId !== '') { $text .= "User ID: " . $userId . "\n"; }
if ($userName !== '') { $text .= "User Name: " . $userName . "\n"; }
if ($user !== '' && $user !== $userName) { $text .= "User Label: " . $user . "\n"; }
if ($page !== '') { $text .= "Page: " . $page . "\n"; }
if (!empty($_SESSION['role'])) { $text .= "Role: " . $_SESSION['role'] . "\n"; }
$text .= "\n" . $message;
$cleanText = strip_tags($text);
$cleanText = str_replace(["\r\n", "\r"], "\n", $cleanText);

$payload = [
    'chat_id' => (string) $chatId,
    'text' => $cleanText,
    'disable_web_page_preview' => true,
];

$url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    if (function_exists('log_error')) {
        log_error('send_telegram_message curl failed: ' . $err);
    }
    respond(false, 'Curl error: ' . $err);
}

$data = json_decode($resp, true);
if (!empty($data['ok'])) {
    respond(true, 'sent', ['response' => $data]);
}

if (function_exists('log_error')) {
    log_error('send_telegram_message Telegram API failed: ' . $resp);
}

if (!empty($data['error_code']) && $data['error_code'] === 401) {
    respond(false, 'Telegram rejected the bot token. Please update the bot token in the project settings.', ['response' => $data ?: ['raw' => $resp], 'http_code' => $httpCode, 'raw_body' => $resp]);
}

respond(false, 'Telegram API error', ['response' => $data ?: ['raw' => $resp], 'http_code' => $httpCode, 'raw_body' => $resp]);
