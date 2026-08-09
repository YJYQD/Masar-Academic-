<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';

$basePath = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register.php');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    flash_error('فشل التحقق الأمني، أعد المحاولة.');
    header('Location: /register.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$password2 = $_POST['password2'] ?? '';

if ($username === '' || $password === '' || $password2 === '') {
    flash_error('يرجى ملء جميع الحقول المطلوبة.');
    header('Location: /register.php');
    exit();
}

if ($password !== $password2) {
    flash_error('كلمتا المرور غير متطابقتين.');
    header('Location: /register.php');
    exit();
}

$check = $conn->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
if (!$check) {
    flash_error('خطأ داخلي في الخادم، يرجى المحاولة لاحقاً.');
    header('Location: /register.php');
    exit();
}
$check->bind_param('s', $username);
$check->execute();
$result = $check->get_result();
$existing = $result->fetch_assoc();
$check->close();

if ($existing) {
    flash_error('عذراً، اسم المستخدم هذا محجوز مسبقاً.');
    header('Location: /register.php');
    exit();
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insert = $conn->prepare('INSERT INTO users (username, email, password_hash, telegram_chat_id) VALUES (?, NULL, ?, NULL)');
if (!$insert) {
    flash_error('تعذر إنشاء الحساب، يرجى المحاولة لاحقاً.');
    header('Location: /register.php');
    exit();
}
$insert->bind_param('ss', $username, $passwordHash);
if (!$insert->execute()) {
    $insert->close();
    flash_error('تعذر إنشاء الحساب، يرجى المحاولة لاحقاً.');
    header('Location: /register.php');
    exit();
}
$userId = $insert->insert_id;
$insert->close();

flash_success('تم إنشاء الحساب بنجاح. يمكنك تسجيل الدخول الآن باسم المستخدم وكلمة المرور.');
header('Location: /login.php');
exit();
