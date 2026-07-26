<?php
require_once __DIR__ . '/session_secure.php';

if (!function_exists('flash_success')) {
    function flash_success(string $message): void
    {
        $_SESSION['flash'] = [
            'type' => 'success',
            'text' => $message,
        ];
    }
}

if (!function_exists('flash_error')) {
    function flash_error(string $message): void
    {
        $_SESSION['flash'] = [
            'type' => 'error',
            'text' => $message,
        ];
    }
}
