<?php
require_once __DIR__ . '/../../inc/session_secure.php';
require_once __DIR__ . '/../../inc/auth_guard.php';
require_once __DIR__ . '/functions.php';

// الفحص الأمني: يسمح بالوصول إذا كانت الجلسة الحالية لمشرف فعلاً، حتى لو كانت بياناته غير محملة بالكامل بعد تسجيل الدخول.
$authContext = current_auth_context();
$isAdminSession = !empty($authContext['is_admin']);
$adminId = (int) ($authContext['user_id'] ?? 0);
$adminRole = $authContext['role'] ?? 'student';
$isAdminRole = in_array($adminRole, ['super', 'college_admin'], true);

if (!$isAdminSession || $adminId <= 0 || !$isAdminRole) {
    header('Location: ../index.php?error=unauthorized');
    http_response_code(302);
    exit();
}

// تأكيد تحميل بيانات المشرف (الصلاحية والكلية) من قاعدة البيانات إذا لم تكن موجودة في الجلسة
if (!isset($_SESSION['admin_role']) || !array_key_exists('admin_college', $_SESSION)) {
    $adminId = (int) ($_SESSION['admin_id'] ?? $authContext['user_id'] ?? 0);
    if ($adminId > 0) {
        // db connection relative to this file
        if (file_exists(__DIR__ . '/../../db.php')) {
            require_once __DIR__ . '/../../db.php';
        }

        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = mysqli_prepare($conn, 'SELECT college_responsibility, role FROM admins WHERE id = ? LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $adminId);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $row = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);
                if ($row) {
                    $_SESSION['admin_college'] = $row['college_responsibility'] ?? null;
                    $_SESSION['admin_role'] = normalize_admin_role($row['role'] ?? 'sub_admin');
                    $_SESSION['admin_role_raw'] = $row['role'] ?? 'sub_admin';
                }
            }
        }
    }
}