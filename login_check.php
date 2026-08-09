<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';
require_once __DIR__ . '/admin/includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

function fail_login(string $reason, string $message): void
{
    log_error('Login failure [' . $reason . '] session_id=' . session_id() . ' has_session_token=' . (empty($_SESSION['csrf_token']) ? 'no' : 'yes'));
    flash_error($message);
    header('Location: /login.php?login=' . urlencode($reason));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    fail_login('method', 'تعذر إتمام تسجيل الدخول. أعد المحاولة من جديد.');
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    fail_login('csrf_mismatch', 'حدث خلل أو انتهت صلاحية الجلسة الأمنية. أعد تحميل صفحة الدخول ثم حاول مرة أخرى.');
}

$posted = $_POST;
$identity = trim($posted['identity'] ?? '');
$password = $posted['password'] ?? '';

if ($identity === '' || $password === '') { 
    fail_login('empty', 'يرجى كتابة اسم المستخدم وكلمة المرور كاملة.');
}

$adminBootstrapUsername = getenv('ADMIN_USERNAME') ?: 'admin';
$adminBootstrapPassword = getenv('ADMIN_PASSWORD') ?: 'Admin@123456';

$adminCountStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS admin_count FROM admins');
if ($adminCountStmt) {
    mysqli_stmt_execute($adminCountStmt);
    $adminCountRes = mysqli_stmt_get_result($adminCountStmt);
    $adminCountRow = mysqli_fetch_assoc($adminCountRes);
    mysqli_stmt_close($adminCountStmt);
}

if (!empty($adminCountRow) && (int) ($adminCountRow['admin_count'] ?? 0) === 0) {
    $bootstrapUserStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? LIMIT 1');
    if ($bootstrapUserStmt) {
        mysqli_stmt_bind_param($bootstrapUserStmt, 's', $adminBootstrapUsername);
        mysqli_stmt_execute($bootstrapUserStmt);
        $bootstrapUserRes = mysqli_stmt_get_result($bootstrapUserStmt);
        $bootstrapUserRow = mysqli_fetch_assoc($bootstrapUserRes);
        mysqli_stmt_close($bootstrapUserStmt);
    } else {
        $bootstrapUserRow = null;
    }

    if (!empty($bootstrapUserRow['id'])) {
        $userId = (int) $bootstrapUserRow['id'];
        $bootstrapUpdateUserStmt = mysqli_prepare($conn, 'UPDATE users SET password_hash = ?, role = "super_admin", status = "active" WHERE id = ?');
        if ($bootstrapUpdateUserStmt) {
            $bootstrapPasswordHash = password_hash($adminBootstrapPassword, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($bootstrapUpdateUserStmt, 'si', $bootstrapPasswordHash, $userId);
            mysqli_stmt_execute($bootstrapUpdateUserStmt);
            mysqli_stmt_close($bootstrapUpdateUserStmt);
        }
    } else {
        $bootstrapPasswordHash = password_hash($adminBootstrapPassword, PASSWORD_DEFAULT);
        $bootstrapCreateUserStmt = mysqli_prepare($conn, 'INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, "super_admin", "active")');
        if ($bootstrapCreateUserStmt) {
            mysqli_stmt_bind_param($bootstrapCreateUserStmt, 'ss', $adminBootstrapUsername, $bootstrapPasswordHash);
            mysqli_stmt_execute($bootstrapCreateUserStmt);
            mysqli_stmt_close($bootstrapCreateUserStmt);
            $userId = (int) $conn->insert_id;
        } else {
            $userId = 0;
        }
    }

    if ($userId > 0) {
        $bootstrapExistingAdminStmt = mysqli_prepare($conn, 'SELECT id FROM admins WHERE username = ? LIMIT 1');
        if ($bootstrapExistingAdminStmt) {
            mysqli_stmt_bind_param($bootstrapExistingAdminStmt, 's', $adminBootstrapUsername);
            mysqli_stmt_execute($bootstrapExistingAdminStmt);
            $bootstrapExistingAdminRes = mysqli_stmt_get_result($bootstrapExistingAdminStmt);
            $bootstrapExistingAdminRow = mysqli_fetch_assoc($bootstrapExistingAdminRes);
            mysqli_stmt_close($bootstrapExistingAdminStmt);
        } else {
            $bootstrapExistingAdminRow = null;
        }

        if (!empty($bootstrapExistingAdminRow['id'])) {
            $bootstrapUpdateAdminStmt = mysqli_prepare($conn, 'UPDATE admins SET user_id = ?, password_hash = ?, role = "super_admin", college_scope = NULL, college_responsibility = NULL, permissions = NULL, status = "active" WHERE id = ?');
            if ($bootstrapUpdateAdminStmt) {
                mysqli_stmt_bind_param($bootstrapUpdateAdminStmt, 'isi', $userId, $bootstrapPasswordHash, (int) $bootstrapExistingAdminRow['id']);
                mysqli_stmt_execute($bootstrapUpdateAdminStmt);
                mysqli_stmt_close($bootstrapUpdateAdminStmt);
            }
        } else {
            $bootstrapCreateAdminStmt = mysqli_prepare($conn, 'INSERT INTO admins (user_id, username, password_hash, role, college_scope, college_responsibility, permissions, status) VALUES (?, ?, ?, "super_admin", NULL, NULL, NULL, "active")');
            if ($bootstrapCreateAdminStmt) {
                mysqli_stmt_bind_param($bootstrapCreateAdminStmt, 'iss', $userId, $adminBootstrapUsername, $bootstrapPasswordHash);
                mysqli_stmt_execute($bootstrapCreateAdminStmt);
                mysqli_stmt_close($bootstrapCreateAdminStmt);
            }
        }
    }
}

// أولاً: محاولة التحقق إذا كان الحساب يخص مشرف (admins)
$stmtAdmin = mysqli_prepare($conn, 'SELECT id, username, password_hash, COALESCE(college_scope, college_responsibility) AS college_responsibility, role, parent_admin_id, permissions FROM admins WHERE username = ? LIMIT 1');
mysqli_stmt_bind_param($stmtAdmin, 's', $identity);
mysqli_stmt_execute($stmtAdmin);
$resAdmin = mysqli_stmt_get_result($stmtAdmin);
$adminRow = mysqli_fetch_assoc($resAdmin);
mysqli_stmt_close($stmtAdmin);

if ($adminRow && password_verify($password, $adminRow['password_hash'])) {
    session_regenerate_id(true);
    session_unset();
    session_destroy();
    @session_start();
    $_SESSION = [];
    $_SESSION['flash'] = [];
    $adminRoleValue = normalize_admin_role($adminRow['role'] ?? 'sub_admin');
    $resolvedAdminRole = $adminRoleValue === 'root_admin' ? 'super' : 'college_admin';
    $adminCollege = trim((string) ($adminRow['college_responsibility'] ?? ''));
    $linkedUserId = (int) ($adminRow['user_id'] ?? 0);
    if ($linkedUserId <= 0) {
        $linkedUserId = (int) $adminRow['id'];
    }

    $linkedUserRow = null;
    if ($linkedUserId > 0) {
        $linkedUserStmt = mysqli_prepare($conn, 'SELECT id, username FROM users WHERE id = ? LIMIT 1');
        if ($linkedUserStmt) {
            mysqli_stmt_bind_param($linkedUserStmt, 'i', $linkedUserId);
            mysqli_stmt_execute($linkedUserStmt);
            $linkedUserRes = mysqli_stmt_get_result($linkedUserStmt);
            $linkedUserRow = mysqli_fetch_assoc($linkedUserRes);
            mysqli_stmt_close($linkedUserStmt);
        }
    }

    $displayUserName = $linkedUserRow['username'] ?? $adminRow['username'] ?? 'مستخدم';

    $_SESSION['is_admin'] = true;
    $_SESSION['admin_id'] = (int) $adminRow['id'];
    $_SESSION['user_id'] = $linkedUserId;
    $_SESSION['role'] = $resolvedAdminRole;
    $_SESSION['college_scope'] = $adminCollege;
    $_SESSION['anonymous_user_id'] = $linkedUserId;
    $_SESSION['user_name'] = $displayUserName;
    $_SESSION['admin_role'] = $resolvedAdminRole;
    $_SESSION['admin_college'] = $adminCollege;
    $_SESSION['admin_role_raw'] = $adminRow['role'] ?? 'sub_admin';
    $_SESSION['admin_parent_id'] = $adminRow['parent_admin_id'] ?? null;
    $_SESSION['admin_permissions'] = $adminRow['permissions'] ?? null;
    set_signed_auth_cookie('admin', $linkedUserId, (string) $displayUserName, time() + 86400);

    flash_success('مرحباً بك يا مهندس ' . $displayUserName . '، تم تسجيل دخولك بنجاح.');
    header('Location: /index.php');
    exit();
}

// ثانياً: محاولة التحقق إذا كان الحساب يخص مستخدم عادي / طالب (users)
$stmt = mysqli_prepare($conn, 'SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $identity);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if ($row && password_verify($password, $row['password_hash'])) {
    session_regenerate_id(true);
    session_unset();
    session_destroy();
    @session_start();
    $_SESSION = [];
    $_SESSION['flash'] = [];
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['anonymous_user_id'] = (int) $row['id'];
    $_SESSION['user_name'] = $row['username'] ?: 'مستخدم';
    $_SESSION['role'] = 'student';
    $_SESSION['college_scope'] = '';
    $_SESSION['is_admin'] = false;
    $_SESSION['admin_id'] = 0;
    $_SESSION['admin_role'] = 'student';
    $_SESSION['admin_college'] = '';
    clear_signed_auth_cookie();
    set_signed_auth_cookie('user', (int) $row['id'], (string) ($row['username'] ?: 'مستخدم'), time() + 86400);

    flash_success('تم تسجيل الدخول بنجاح، مرحباً بك في المنصة.');
    header('Location: /index.php');
    exit();
}

// ثالثاً: في حال الفشل التام (بيانات خاطئة)
// نقوم بتخزين رسالة الخطأ في الفلاش ستيشن قبل إعادة التوجيه لكي تظهر فوق النموذج
flash_error('اسم المستخدم أو كلمة المرور غير صحيحة، يرجى التأكد وإعادة المحاولة.');
header('Location: /login.php?login=failed');
exit();
?>