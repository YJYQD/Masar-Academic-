<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/flash.php';
require_once __DIR__ . '/inc/profile_helpers.php';
require_once __DIR__ . '/admin/includes/functions.php';

if (empty($_SESSION['user_id'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'عذراً، يجب تسجيل الدخول أولاً للقيام بهذه العملية.'];
    header('Location: login');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function profile_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function resolve_profile_user_id(mysqli $conn): int
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        return $userId;
    }

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT user_id FROM admins WHERE id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $adminId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if (!empty($row['user_id'])) {
                return (int) $row['user_id'];
            }
        }
    }

    return (int) ($_SESSION['anonymous_user_id'] ?? 0);
}

function load_profile_row(mysqli $conn, int $profileOwnerId): array
{
    $stmt = mysqli_prepare($conn, 'SELECT id, username, email, full_name, phone, role, college_scope, department_scope, specialty, status, created_at, telegram_chat_id, telegram_username FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $profileOwnerId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $userRow = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        if (!empty($userRow)) {
            $adminStmt = mysqli_prepare($conn, 'SELECT role, college_scope, college_responsibility FROM admins WHERE user_id = ? LIMIT 1');
            if ($adminStmt) {
                mysqli_stmt_bind_param($adminStmt, 'i', $profileOwnerId);
                mysqli_stmt_execute($adminStmt);
                $adminRes = mysqli_stmt_get_result($adminStmt);
                $adminRow = mysqli_fetch_assoc($adminRes);
                mysqli_stmt_close($adminStmt);
                if (!empty($adminRow)) {
                    $userRow['role'] = $adminRow['role'] ?? $userRow['role'] ?? 'student';
                    if (!empty($adminRow['college_scope'])) {
                        $userRow['college_scope'] = $adminRow['college_scope'];
                    }
                    if (!empty($adminRow['college_responsibility'])) {
                        $userRow['department_scope'] = $adminRow['college_responsibility'];
                    }
                }
            }

            return $userRow;
        }
    }

    return [];
}

$userId = resolve_profile_user_id($conn);
$viewUserId = isset($_GET['view_user']) ? (int) $_GET['view_user'] : 0;
$profileMode = 'self';
$profileOwnerId = $userId;

if ($viewUserId > 0) {
    if (!empty($_SESSION['is_admin']) && $viewUserId !== $userId) {
        $profileMode = 'admin';
        $profileOwnerId = $viewUserId;
    } else {
        header('Location: profile');
        exit();
    }
}

$userRow = load_profile_row($conn, $profileOwnerId);

if (empty($userRow)) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'المستخدم غير موجود.'];
    header('Location: index');
    exit();
}

$hasSuggestedByUserIdColumn = false;
$colStmt = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "doctors" AND COLUMN_NAME = "suggested_by_user_id" LIMIT 1');
if ($colStmt) {
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    mysqli_stmt_bind_param($colStmt, 's', $dbName);
    if (mysqli_stmt_execute($colStmt)) {
        $colRes = mysqli_stmt_get_result($colStmt);
        if (mysqli_fetch_assoc($colRes)) {
            $hasSuggestedByUserIdColumn = true;
        }
    }
    mysqli_stmt_close($colStmt);
}

$hasUserIdColumn = false;
$colStmt2 = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "reviews" AND COLUMN_NAME = "user_id" LIMIT 1');
if ($colStmt2) {
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    mysqli_stmt_bind_param($colStmt2, 's', $dbName);
    if (mysqli_stmt_execute($colStmt2)) {
        $colRes2 = mysqli_stmt_get_result($colStmt2);
        if (mysqli_fetch_assoc($colRes2)) {
            $hasUserIdColumn = true;
        }
    }
    mysqli_stmt_close($colStmt2);
}

$reviews = [];
if ($hasUserIdColumn) {
    $revStmt = mysqli_prepare($conn, 'SELECT r.id, r.rating, r.comment, r.created_at, r.reviewer_name, r.is_anonymous, d.name AS doctor_name FROM reviews r LEFT JOIN doctors d ON d.id = r.doctor_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 100');
    if ($revStmt) {
        mysqli_stmt_bind_param($revStmt, 'i', $profileOwnerId);
        mysqli_stmt_execute($revStmt);
        $revRes = mysqli_stmt_get_result($revStmt);
        while ($row = mysqli_fetch_assoc($revRes)) {
            $reviews[] = $row;
        }
        mysqli_stmt_close($revStmt);
    }
} else {
    $revStmt = mysqli_prepare($conn, 'SELECT r.id, r.rating, r.comment, r.created_at, d.name AS doctor_name FROM reviews r LEFT JOIN doctors d ON d.id = r.doctor_id WHERE r.reviewer_name = ? ORDER BY r.id DESC');
    if ($revStmt) {
        $reviewerName = $userRow['username'] ?? '';
        mysqli_stmt_bind_param($revStmt, 's', $reviewerName);
        mysqli_stmt_execute($revStmt);
        $revRes = mysqli_stmt_get_result($revStmt);
        while ($row = mysqli_fetch_assoc($revRes)) {
            $reviews[] = $row;
        }
        mysqli_stmt_close($revStmt);
    }
}

$suggestedDoctors = [];
if ($hasSuggestedByUserIdColumn) {
    $docStmt = mysqli_prepare($conn, 'SELECT id, name, college, department, gender, courses, is_approved, created_at FROM doctors WHERE suggested_by_user_id = ? ORDER BY id DESC');
    if ($docStmt) {
        mysqli_stmt_bind_param($docStmt, 'i', $profileOwnerId);
        mysqli_stmt_execute($docStmt);
        $docRes = mysqli_stmt_get_result($docStmt);
        while ($row = mysqli_fetch_assoc($docRes)) {
            $suggestedDoctors[] = $row;
        }
        mysqli_stmt_close($docStmt);
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$profileSummary = normalize_profile_row($userRow);
$collegeCatalog = get_colleges_map();
$profileCollegeValue = trim((string) ($userRow['college_scope'] ?? ''));
$profileDepartmentValue = trim((string) ($userRow['department_scope'] ?? ''));
$profileSpecialtyValue = trim((string) ($userRow['specialty'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($profileMode !== 'self') {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'لا يمكن تعديل ملف هذا المستخدم من هذه الواجهة.'];
        header('Location: profile');
        exit();
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'فشل التحقق الأمني، أعد المحاولة.'];
        header('Location: profile');
        exit();
    }

    $newUsername = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newUsername !== '') {
        if (strlen($newUsername) < 3 || strlen($newUsername) > 30) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'اسم المستخدم يجب أن يكون بين 3 و 30 حرفاً.'];
            header('Location: profile');
            exit();
        }

        $checkUser = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
        if ($checkUser) {
            mysqli_stmt_bind_param($checkUser, 'si', $newUsername, $profileOwnerId);
            mysqli_stmt_execute($checkUser);
            mysqli_stmt_store_result($checkUser);
            if (mysqli_stmt_num_rows($checkUser) > 0) {
                mysqli_stmt_close($checkUser);
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'اسم المستخدم هذا مستخدم بالفعل.'];
                header('Location: profile');
                exit();
            }
            mysqli_stmt_close($checkUser);
        }
    }

    if ($newPassword !== '' || $currentPassword !== '' || $confirmPassword !== '') {
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'يرجى ملء جميع حقول كلمة المرور.'];
            header('Location: profile');
            exit();
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'تأكيد كلمة المرور غير متطابق.'];
            header('Location: profile');
            exit();
        }

        $pwdStmt = mysqli_prepare($conn, 'SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        if ($pwdStmt) {
            mysqli_stmt_bind_param($pwdStmt, 'i', $profileOwnerId);
            mysqli_stmt_execute($pwdStmt);
            $pwdRes = mysqli_stmt_get_result($pwdStmt);
            $pwdRow = mysqli_fetch_assoc($pwdRes);
            mysqli_stmt_close($pwdStmt);
        }

        if (empty($pwdRow['password_hash']) || !password_verify($currentPassword, $pwdRow['password_hash'])) {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'كلمة المرور الحالية غير صحيحة.'];
            header('Location: profile');
            exit();
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = mysqli_prepare($conn, 'UPDATE users SET password_hash = ? WHERE id = ?');
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, 'si', $newHash, $profileOwnerId);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
        }
    }

    $updateProfileFields = [];
    $updateProfileTypes = '';
    $updateProfileValues = [];

    if ($newUsername !== '') {
        $updateProfileFields[] = 'username = ?';
        $updateProfileTypes .= 's';
        $updateProfileValues[] = $newUsername;
        $_SESSION['user_name'] = $newUsername;
    }

    $updateProfileFields[] = 'full_name = ?';
    $updateProfileTypes .= 's';
    $updateProfileValues[] = $fullName;

    if (!empty($updateProfileFields)) {
        $updateProfileValues[] = $profileOwnerId;
        $updateProfileSql = 'UPDATE users SET ' . implode(', ', $updateProfileFields) . ' WHERE id = ?';
        $updateProfileStmt = mysqli_prepare($conn, $updateProfileSql);
        if ($updateProfileStmt) {
            $bindValues = [$updateProfileStmt, $updateProfileTypes . 'i'];
            foreach ($updateProfileValues as &$profileValue) {
                $bindValues[] = &$profileValue;
            }
            unset($profileValue);
            call_user_func_array('mysqli_stmt_bind_param', $bindValues);
            mysqli_stmt_execute($updateProfileStmt);
            mysqli_stmt_close($updateProfileStmt);
        }

        $updateAdminStmt = mysqli_prepare($conn, 'UPDATE admins SET username = ? WHERE user_id = ?');
        if ($updateAdminStmt && $newUsername !== '') {
            mysqli_stmt_bind_param($updateAdminStmt, 'si', $newUsername, $profileOwnerId);
            mysqli_stmt_execute($updateAdminStmt);
            mysqli_stmt_close($updateAdminStmt);
        }
    }

    if ($newPassword !== '') {
        $updateAdminPwdStmt = mysqli_prepare($conn, 'UPDATE admins SET password_hash = ? WHERE user_id = ?');
        if ($updateAdminPwdStmt) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($updateAdminPwdStmt, 'si', $newHash, $profileOwnerId);
            mysqli_stmt_execute($updateAdminPwdStmt);
            mysqli_stmt_close($updateAdminPwdStmt);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم تحديث بيانات الملف الشخصي بنجاح.'];
    header('Location: profile');
    exit();
}

// Reload user data after any updates
$userRow = load_profile_row($conn, $profileOwnerId);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
    <title>ملفي الشخصي - منصة مسار الأكاديمية</title>
</head>
<body>
<main class="page profile-page">
    <section class="panel profile-shell">
        <div class="back-links">
            <a class="btn btn--light" href="index.php">العودة للقائمة الرئيسية</a>
        </div>

        <div class="profile-summary">
            <div class="profile-summary__content">
                <div class="brand-pill">حسابي</div>
                <h2><?php echo $profileMode === 'admin' ? 'ملف المستخدم الشخصي' : 'ملفي الشخصي'; ?></h2>
                <p><?php echo $profileMode === 'admin' ? 'عرض بيانات المستخدم من منظور الإدارة.' : 'إدارة بياناتك وتعديل اسم المستخدم وكلمة المرور بأمان.'; ?></p>
            </div>
            <div class="profile-badge"><?php echo profile_e($profileSummary['display_name']); ?></div>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash--<?php echo profile_e($flash['type']); ?>">
                <?php echo profile_e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <div class="profile-grid">
            <div class="profile-card">
                <h3>بطاقة الحساب</h3>
                <div class="info-pill">معرّف #: <?php echo profile_e($userRow['id'] ?? ''); ?></div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span>الاسم الكامل</span>
                        <strong><?php echo profile_e($profileSummary['display_name']); ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span>اسم المستخدم</span>
                        <strong><?php echo profile_e($profileSummary['display_username']); ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span>الدور</span>
                        <strong><?php echo profile_e($profileSummary['role_label']); ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span>اسم المستخدم في التليجرام</span>
                        <strong>
    <?php 
    if (!empty($userRow['telegram_username'])) {
        echo '@' . profile_e($userRow['telegram_username']);
    } elseif (!empty($userRow['telegram_chat_id'])) {
        echo '✅ مرتبط بنجاح (ID: ' . profile_e($userRow['telegram_chat_id']) . ')';
    } else {
        echo '❌ غير مرتبط';
    }
    ?>
</strong>
                    </div>
                    <div class="profile-stat">
                        <span>الحالة</span>
                        <strong class="status-pill status-pill--success"><?php echo profile_e($profileSummary['status_label']); ?></strong>
                    </div>
                    <div class="profile-stat">
                        <span>تاريخ التسجيل</span>
                        <strong><?php echo profile_e($profileSummary['created_at']); ?></strong>
                    </div>
                </div>
            </div>

            <?php if ($profileMode === 'self'): ?>
            <div class="profile-card">
                <h3>تحديث بيانات الدخول</h3>
                <form method="POST" class="auth-form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo profile_e($_SESSION['csrf_token']); ?>">
                    <p style="margin:0 0 8px; color:#64748b; grid-column:1 / -1;">سيتم حفظ اسم المستخدم وكلمة المرور عند الضغط على زر الحفظ.</p>
                    <label class="full-width">الاسم الكامل<input type="text" name="full_name" value="<?php echo profile_e($userRow['full_name'] ?? ''); ?>" maxlength="100" placeholder="أدخل الاسم الكامل"></label>
                    <label class="full-width">اسم المستخدم الجديد<input type="text" name="username" value="<?php echo profile_e($userRow['username'] ?? ''); ?>" maxlength="30" placeholder="أدخل اسم المستخدم الجديد"></label>
                    <label>كلمة المرور الحالية<input type="password" name="current_password" placeholder="أدخل كلمة المرور الحالية"></label>
                    <label>كلمة المرور الجديدة<input type="password" name="new_password" placeholder="أدخل كلمة المرور الجديدة"></label>
                    <label>تأكيد كلمة المرور<input type="password" name="confirm_password" placeholder="أعد كتابة كلمة المرور الجديدة"></label>
                    <button type="submit" class="btn btn--primary full-width">حفظ التغييرات</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="profile-sections">
            <div class="profile-card">
                <h3>تقييماتي السابقة</h3>
                <?php if (empty($reviews)): ?>
                    <p class="empty-state">لم تقم بإرسال أي تقييمات حتى الآن.</p>
                <?php else: ?>
                    <ul class="stack-list">
                        <?php foreach ($reviews as $review): ?>
                            <li>
                                <div class="review-row">
                                    <div>
                                        <strong><?php echo profile_e($review['doctor_name'] ?? 'دكتور'); ?></strong>
                                        <p><?php echo profile_e(mb_substr($review['comment'] ?? '', 0, 110)); ?><?php echo mb_strlen($review['comment'] ?? '') > 110 ? '...' : ''; ?></p>
                                    </div>
                                    <span class="status-pill <?php echo ((int) ($review['rating'] ?? 0) >= 4) ? 'status-pill--success' : (((int) ($review['rating'] ?? 0) >= 2) ? 'status-pill--warning' : 'status-pill--danger'); ?>"><?php echo (int) ($review['rating'] ?? 0); ?>/5</span>
                                </div>
                                <div class="review-meta"><?php echo profile_e($review['created_at'] ?? ''); ?><?php if (!empty($review['is_anonymous'])): ?> • مجهول<?php endif; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="profile-card">
                <h3>الدكاترة المقترحون من قبلك</h3>
                <?php if (empty($suggestedDoctors)): ?>
                    <p class="empty-state">لم تقم باقتراح أي دكاترة بعد.</p>
                <?php else: ?>
                    <ul class="stack-list">
                        <?php foreach ($suggestedDoctors as $doctor): ?>
                            <li>
                                <div class="review-row">
                                    <div>
                                        <strong><?php echo profile_e($doctor['name'] ?? ''); ?></strong>
                                        <p>الكلية: <?php echo profile_e($doctor['college'] ?? ''); ?> • القسم: <?php echo profile_e($doctor['department'] ?? ''); ?></p>
                                    </div>
                                    <span class="status-pill <?php echo ((int) ($doctor['is_approved'] ?? 0) === 1) ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo ((int) ($doctor['is_approved'] ?? 0) === 1) ? 'معتمد' : 'قيد المراجعة'; ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
</body>
</html>
