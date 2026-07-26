<?php
// Copy this file to config.php and update values for your environment.
// Do NOT commit config.php with real credentials to version control.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'doctors_eval';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';
$DB_CHARSET = getenv('DB_CHARSET') ?: 'utf8mb4';
$TELEGRAM_BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN') ?: getenv('TELEGRAM_TOKEN') ?: '';
$TELEGRAM_ADMIN_CHAT_ID = getenv('TELEGRAM_ADMIN_CHAT_ID') ?: '';
$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '';
?>
