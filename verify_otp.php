<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Location: /login.php');
exit();
