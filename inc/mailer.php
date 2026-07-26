<?php
// Mailer helper: sends email via Resend HTTP API.
require_once __DIR__ . '/../db.php';

if (!defined('RESEND_API_KEY')) {
    define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
}

if (!defined('RESEND_FROM_EMAIL')) {
    define('RESEND_FROM_EMAIL', getenv('RESEND_FROM_EMAIL') ?: 'noreply@jzu-rating.live');
}

if (!defined('RESEND_FROM_NAME')) {
    define('RESEND_FROM_NAME', getenv('RESEND_FROM_NAME') ?: 'منصة جامعة جازان');
}

function log_mailer_error(string $message): void
{
    if (function_exists('log_error')) {
        log_error($message);
    }
}

function send_smtp_email(string $to_email, string $subject, string $html_content): bool
{
    $api_key = defined('RESEND_API_KEY') ? RESEND_API_KEY : (getenv('RESEND_API_KEY') ?: '');
    $from_email = defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : (getenv('RESEND_FROM_EMAIL') ?: 'noreply@jzu-rating.live');
    $from_name = defined('RESEND_FROM_NAME') ? RESEND_FROM_NAME : (getenv('RESEND_FROM_NAME') ?: 'منصة جامعة جازان');

    if ($api_key === '' || $to_email === '') {
        log_mailer_error('Resend configuration is incomplete.');
        return false;
    }

    $payload = [
        'from' => $from_name !== '' ? ($from_name . ' <' . $from_email . '>') : $from_email,
        'to' => [$to_email],
        'subject' => $subject,
        'html' => $html_content,
        'text' => strip_tags($html_content),
    ];

    if (!function_exists('curl_init')) {
        log_mailer_error('cURL is not available for Resend delivery.');
        return false;
    }

    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        log_mailer_error('Failed to initialize cURL for Resend.');
        return false;
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code < 200 || $http_code >= 300) {
        log_mailer_error('Resend API error. HTTP ' . $http_code . '. ' . $curl_error . ' Response: ' . (string) $response);
        return false;
    }

    return true;
}

function send_otp_email(string $toEmail, string $otp, string $toName = ''): bool
{
    $subject = 'رمز التحقق - منصة جامعة جازان';
    $body = "<p>مرحباً " . htmlspecialchars($toName ?: '') . ",</p>" .
        "<p>رمز التحقق الخاص بك هو: <strong>" . htmlspecialchars($otp) . "</strong></p>" .
        "<p>يرجى إدخال هذا الرمز في صفحة التحقق. إن لم يصلك، تحقق من صندوق الرسائل غير المرغوب فيها (Spam).</p>" .
        "<p>مدة صلاحية الرمز: " . (defined('OTP_TTL_MINUTES') ? OTP_TTL_MINUTES : 10) . " دقيقة.</p>";

    return send_smtp_email($toEmail, $subject, $body);
}
