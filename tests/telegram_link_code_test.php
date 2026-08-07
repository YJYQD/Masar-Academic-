<?php
require_once __DIR__ . '/../inc/telegram_helpers.php';

$code = generate_telegram_link_code(6);
if (!preg_match('/^\d{6}$/', $code)) {
    fwrite(STDERR, "generated code is not 6 digits\n");
    exit(1);
}

$payload = 'bind:123456';
$extracted = extract_telegram_link_code_from_payload($payload);
if ($extracted !== '123456') {
    fwrite(STDERR, "failed to extract bind code from payload\n");
    exit(1);
}

$text = '/bind 123456';
$fromText = extract_telegram_link_code_from_text($text);
if ($fromText !== '123456') {
    fwrite(STDERR, "failed to extract bind code from text\n");
    exit(1);
}

$plainText = '123456';
$fromPlainText = extract_telegram_link_code_from_text($plainText);
if ($fromPlainText !== '123456') {
    fwrite(STDERR, "failed to extract bind code from plain numeric text\n");
    exit(1);
}

echo "telegram link helper test passed\n";
