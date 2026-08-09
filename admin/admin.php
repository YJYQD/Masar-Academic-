<?php
// نقطة دخول معالجة إجراءات المشرف (اعتماد/حذف/إجراءات إدارية أخرى)
require_once __DIR__ . '/../inc/session_secure.php';
require_once __DIR__ . '/../inc/auth_guard.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../inc/flash.php';

register_shutdown_function('admin_handle_fatal_error');

$authContext = current_auth_context();
if ($authContext['role'] !== 'super' && $authContext['role'] !== 'college_admin') {
    http_response_code(403);
    header('Location: /admin/index.php');
    exit();
}

// فقط طلبات POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit();
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

$action = $_POST['admin_action'] ?? '';
$docId = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
$reviewId = isset($_POST['review_id']) ? (int)$_POST['review_id'] : 0;
$token = $_POST['csrf_token'] ?? '';

if (!hash_equals(csrf_token(), $token)) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'csrf']);
        exit();
    }
    flash_error('انتهت صلاحية الجلسة الأمنية، يرجى إعادة المحاولة.');
    header('Location: /admin/index.php');
    exit();
}

$adminRole = $authContext['role'] === 'super' ? 'super' : 'college_admin';
$adminCollege = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));

// Helper to deny
function deny($msg = 'غير مصرح') {
    if (isset($GLOBALS['isAjax']) && $GLOBALS['isAjax']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'forbidden', 'msg' => $msg]);
        exit();
    }
    flash_error($msg);
    header('Location: /admin/index.php');
    exit();
}

// تحقق الصلاحيات: المشرف العادي يمكنه التعامل فقط مع بيانات كليته، أما super فيمكنه كل شيء
function allowed_for_doctor($doctorCollege) {
    $role = $_SESSION['role'] ?? 'student';
    $college = trim((string) ($_SESSION['college_scope'] ?? $_SESSION['admin_college'] ?? ''));
    if ($role === 'super') return true;
    if ($role === 'college_admin' && $college !== '' && $doctorCollege === $college) return true;
    return false;
}

// تنفيذ الأفعال
try {
    if ($action === 'approve' && $docId > 0) {
        // جلب كلية الدكتور للتحقق
        $stmt = mysqli_prepare($conn, 'SELECT college, is_approved FROM doctors WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $docId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $doctor = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$doctor) deny('لم يتم العثور على الدكتور.');
        if (!allowed_for_doctor($doctor['college'])) deny('غير مخوّل لاعتماد دكاترة هذه الكلية.');

        $stmt = mysqli_prepare($conn, 'UPDATE doctors SET is_approved = 1 WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $docId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            if (function_exists('log_error')) log_error('Failed to approve doctor id: ' . $docId . ' - ' . mysqli_error($conn));
            deny('فشل اعتماد الطلب، يرجى المحاولة لاحقاً.');
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
        flash_success('تم اعتماد طلب الدكتور بنجاح.');
        header('Location: index.php?section=doctors');
        exit();

    } elseif ($action === 'delete' && $docId > 0) {
        // جلب كلية الدكتور للتحقق
        $stmt = mysqli_prepare($conn, 'SELECT college FROM doctors WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $docId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $doctor = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$doctor) deny('لم يتم العثور على الدكتور.');
        if (!allowed_for_doctor($doctor['college'])) deny('غير مخوّل بحذف دكاترة هذه الكلية.');

        $stmt = mysqli_prepare($conn, 'DELETE FROM doctors WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $docId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            if (function_exists('log_error')) log_error('Failed to delete doctor id: ' . $docId . ' - ' . mysqli_error($conn));
            deny('فشل حذف الدكتور، يرجى المحاولة لاحقاً.');
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
        flash_success('تم حذف الدكتور وجميع تقييماته بنجاح.');
        header('Location: index.php?section=doctors');
        exit();

    } elseif ($action === 'delete_review' && $reviewId > 0) {
        // جلب هوية الدكتور / الكلية للتحقق
        $stmt = mysqli_prepare($conn, 'SELECT r.doctor_id, d.college FROM reviews r JOIN doctors d ON d.id = r.doctor_id WHERE r.id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$row) deny('التقييم غير موجود.');
        if (!allowed_for_doctor($row['college'])) deny('غير مخوّل بحذف تقييمات هذه الكلية.');

        $stmt = mysqli_prepare($conn, 'DELETE FROM reviews WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            if (function_exists('log_error')) log_error('Failed to delete review id: ' . $reviewId . ' - ' . mysqli_error($conn));
            deny('فشل حذف التقييم، يرجى المحاولة لاحقاً.');
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
        flash_success('تم حذف التقييم بنجاح.');
        header('Location: index.php?section=reviews');
        exit();

    } elseif ($action === 'approve_review' && $reviewId > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT r.id, d.college FROM reviews r JOIN doctors d ON d.id = r.doctor_id WHERE r.id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$row) deny('التقييم غير موجود.');
        if (!allowed_for_doctor($row['college'])) deny('غير مخوّل لاعتماد تقييمات هذه الكلية.');

        $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'approved' WHERE id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            if (function_exists('log_error')) log_error('Failed to approve review id: ' . $reviewId . ' - ' . mysqli_error($conn));
            deny('فشل اعتماد التقييم، يرجى المحاولة لاحقاً.');
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
        flash_success('تم اعتماد التقييم ونشره.');
        header('Location: index.php?section=reviews');
        exit();

    } elseif ($action === 'unapprove_review' && $reviewId > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT r.id, d.college FROM reviews r JOIN doctors d ON d.id = r.doctor_id WHERE r.id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$row) deny('التقييم غير موجود.');
        if (!allowed_for_doctor($row['college'])) deny('غير مخوّل بإلغاء اعتماد تقييمات هذه الكلية.');

        $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'pending' WHERE id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($stmt, 'i', $reviewId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok) {
            if (function_exists('log_error')) log_error('Failed to unapprove review id: ' . $reviewId . ' - ' . mysqli_error($conn));
            deny('فشل إلغاء اعتماد التقييم، يرجى المحاولة لاحقاً.');
        }

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
        flash_success('تم إلغاء اعتماد التقييم.');
        header('Location: index.php?section=reviews');
        exit();

    }

    // Admin update and delete
    elseif (isset($_POST['update_admin_college']) || isset($_POST['delete_admin_action'])) {
        if (($authContext['role'] ?? 'student') !== 'super') {
            deny('غير مخوّل لإدارة المشرفين.');
        }

        if (isset($_POST['update_admin_college'])) {
            $editAdminId = (int) ($_POST['edit_admin_id'] ?? 0);
            $editCollege = trim($_POST['edit_college'] ?? '');
            $editRole = trim($_POST['edit_role'] ?? 'sub_admin');
            if ($editAdminId <= 0) deny('معرّف المشرف غير صالح.');

            $stmt = mysqli_prepare($conn, 'UPDATE admins SET college_responsibility = ?, role = ? WHERE id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssi', $editCollege, $editRole, $editAdminId);
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if (!$ok) deny('فشل تحديث صلاحيات المشرف.');
                flash_success('تم تحديث صلاحية المشرف بنجاح.');
            }
            header('Location: index.php?section=supervision');
            exit();
        }

        if (isset($_POST['delete_admin_action'])) {
            $delAdminId = (int) ($_POST['delete_admin_id'] ?? 0);
            if ($delAdminId <= 0) deny('معرّف المشرف غير صالح.');
            if ($delAdminId === (int) ($_SESSION['admin_id'] ?? 0)) deny('لا يمكنك حذف الحساب الذي تستخدمه حالياً.');

            $stmt = mysqli_prepare($conn, 'DELETE FROM admins WHERE id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $delAdminId);
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                if (!$ok) deny('فشل حذف المشرف.');
                flash_success('تم حذف المشرف بنجاح.');
            }
            header('Location: index.php?section=supervision');
            exit();
        }
    }

    // Subjects CRUD
    elseif ($action === 'create_subject' || $action === 'update_subject' || $action === 'delete_subject') {
        // allow 'super' or 'college_admin' (college_admin limited to their college)
        if (!(($adminRole ?? '') === 'super' || ($adminRole ?? '') === 'college_admin')) {
            deny('غير مخوّل لإدارة المواد.');
        }

        if ($action === 'create_subject') {
            $name = trim($_POST['subject_name'] ?? '');
            $course_code = trim($_POST['course_code'] ?? '');
            $credit_hours = isset($_POST['credit_hours']) ? (int) $_POST['credit_hours'] : null;
            $college = trim($_POST['college'] ?? '');
            // if current admin is college_admin, enforce their college
            if (($adminRole ?? '') === 'college_admin' && !empty($adminCollege)) {
                $college = $adminCollege;
            }
            $department = trim($_POST['department'] ?? '');
            $level_num = isset($_POST['level_num']) ? (int) $_POST['level_num'] : null;
            $telegram = trim($_POST['telegram'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($name === '') deny('اسم المادة مطلوب.');
            $ins = mysqli_prepare($conn, 'INSERT INTO subjects (subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if ($ins) {
                mysqli_stmt_bind_param($ins, 'ssississ', $name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $desc);
                $ok = mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
                if (!$ok) {
                    if (function_exists('log_error')) log_error('create_subject failed: ' . mysqli_error($conn));
                    deny('فشل إنشاء المادة.');
                }
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'msg' => 'تم إنشاء المادة']); exit(); }
                flash_success('تم إنشاء المادة');
                header('Location: index.php?section=subjects'); exit();
            }
        }

        if ($action === 'update_subject') {
            $id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            $name = trim($_POST['subject_name'] ?? '');
            $course_code = trim($_POST['course_code'] ?? '');
            $credit_hours = isset($_POST['credit_hours']) ? (int) $_POST['credit_hours'] : null;
            $college = trim($_POST['college'] ?? '');
            // enforce college for college_admin
            if (($adminRole ?? '') === 'college_admin' && !empty($adminCollege)) {
                $college = $adminCollege;
            }
            $department = trim($_POST['department'] ?? '');
            $level_num = isset($_POST['level_num']) ? (int) $_POST['level_num'] : null;
            $telegram = trim($_POST['telegram'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($id <= 0 || $name === '') deny('بيانات غير صحيحة.');
            // if college_admin, ensure this subject belongs to their college
            if (($adminRole ?? '') === 'college_admin' && !empty($adminCollege)) {
                $chk = mysqli_prepare($conn, 'SELECT college FROM subjects WHERE id = ? LIMIT 1');
                if ($chk) {
                    mysqli_stmt_bind_param($chk, 'i', $id);
                    mysqli_stmt_execute($chk);
                    $cres = mysqli_stmt_get_result($chk);
                    $crow = mysqli_fetch_assoc($cres);
                    mysqli_stmt_close($chk);
                    if (!$crow || ($crow['college'] ?? '') !== $adminCollege) {
                        deny('غير مخوّل بتعديل مادة من كلية أخرى.');
                    }
                }
            }
            $up = mysqli_prepare($conn, 'UPDATE subjects SET subject_name = ?, course_code = ?, credit_hours = ?, college = ?, department = ?, level_num = ?, telegram_link = ?, description = ? WHERE id = ?');
            if ($up) {
                mysqli_stmt_bind_param($up, 'ssississi', $name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $desc, $id);
                $ok = mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
                if (!$ok) { if (function_exists('log_error')) log_error('update_subject failed: ' . mysqli_error($conn)); deny('فشل تحديث المادة.'); }
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'msg' => 'تم تحديث المادة']); exit(); }
                flash_success('تم تحديث المادة'); header('Location: index.php?section=subjects'); exit();
            }
        }

        if ($action === 'delete_subject') {
            $id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
            if ($id <= 0) deny('معرّف غير صحيح.');
            // if college_admin, ensure this subject belongs to their college
            if (($adminRole ?? '') === 'college_admin' && !empty($adminCollege)) {
                $chk = mysqli_prepare($conn, 'SELECT college FROM subjects WHERE id = ? LIMIT 1');
                if ($chk) {
                    mysqli_stmt_bind_param($chk, 'i', $id);
                    mysqli_stmt_execute($chk);
                    $cres = mysqli_stmt_get_result($chk);
                    $crow = mysqli_fetch_assoc($cres);
                    mysqli_stmt_close($chk);
                    if (!$crow || ($crow['college'] ?? '') !== $adminCollege) {
                        deny('غير مخوّل بحذف مادة من كلية أخرى.');
                    }
                }
            }
            // حذف الروابط أولاً
            $delRel = mysqli_prepare($conn, 'DELETE FROM doctor_subject WHERE subject_id = ?');
            if ($delRel) { mysqli_stmt_bind_param($delRel, 'i', $id); mysqli_stmt_execute($delRel); mysqli_stmt_close($delRel); }
            $del = mysqli_prepare($conn, 'DELETE FROM subjects WHERE id = ?');
            if ($del) {
                mysqli_stmt_bind_param($del, 'i', $id);
                $ok = mysqli_stmt_execute($del);
                mysqli_stmt_close($del);
                if (!$ok) { if (function_exists('log_error')) log_error('delete_subject failed: ' . mysqli_error($conn)); deny('فشل حذف المادة.'); }
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'msg' => 'تم حذف المادة']); exit(); }
                flash_success('تم حذف المادة'); header('Location: index.php?section=subjects'); exit();
            }
        }
    }

    // لم يتم التعرف على الإجراء
    deny('إجراء غير معروف.');

} catch (Exception $ex) {
    if (function_exists('log_error')) {
        log_error('Exception in admin.php: ' . $ex->getMessage());
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false]);
        exit();
    }
    flash_error('حدث خطأ غير متوقع، تم تسجيله لدينا.');
    header('Location: index.php');
    exit();
}

?>
