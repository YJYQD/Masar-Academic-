<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';

// 1. مسح جميع متغيرات الجلسة من الذاكرة
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];
unset(
    $_SESSION['is_admin'],
    $_SESSION['admin_id'],
    $_SESSION['admin_college'],
    $_SESSION['admin_role'],
    $_SESSION['admin_permissions'],
    $_SESSION['role'],
    $_SESSION['user_id'],
    $_SESSION['anonymous_user_id'],
    $_SESSION['user_name'],
    $_SESSION['pending_registration'],
    $_SESSION['pending_registration_expires'],
    $_SESSION['pending_user_id'],
    $_SESSION['flash']
);
session_unset();

// 2. مسح ملف تعريف الارتباط (Session Cookie) من متصفح المستخدم نهائياً
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// 3. تدمير الجلسة بالكامل في السيرفر
session_destroy();

// 4. مسح الكوكي الاحتياطي للهوية
clear_signed_auth_cookie();

// 5. التوجيه الفوري إلى صفحة تسجيل الدخول
header('Location: login.php');
exit();
?>