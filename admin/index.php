<?php
require_once __DIR__ . '/../inc/session_secure.php';
require_once __DIR__ . '/../inc/auth_guard.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/../inc/flash.php';

register_shutdown_function('admin_handle_fatal_error');

restrict_to_admins('/login.php');
$authContext = current_auth_context();
if ($authContext['role'] !== 'super' && $authContext['role'] !== 'college_admin') {
    http_response_code(403);
    header('Location: /login.php');
    exit();
}

$section = $_GET['section'] ?? 'dashboard';
$admin_role = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');
$admin_college = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));

if (!can_manage_section($admin_role, $section)) {
    flash_error('لا توجد لديك صلاحية للوصول إلى هذه الصفحة.');
    $section = 'dashboard';
}

if (isset($_POST['promote_user']) || isset($_POST['update_admin_college']) || isset($_POST['delete_admin_action'])) {
    if (!can_manage_admins($admin_role)) {
        flash_error('لا توجد لديك صلاحية إدارة المشرفين.');
        header('Location: /admin?section=dashboard');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $postedToken)) {
        flash_error('طلب غير صالح (CSRF)');
        header('Location: /admin?section=' . urlencode($section));
        exit();
    }

    if (isset($_POST['add_doc'])) {
        if (!can_manage_section($admin_role, 'doctors')) {
            flash_error('لا توجد لديك صلاحية إدارة الدكاترة.');
            header('Location: /admin?section=dashboard');
            exit();
        }

        $name = trim($_POST['doc_name'] ?? '');
        $coll = trim($_POST['college'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $gend = trim($_POST['gender'] ?? '');
        $subj = trim($_POST['subjects'] ?? '');
        if ($name !== '' && $coll !== '' && $dept !== '') {
            $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, 1)');
            mysqli_stmt_bind_param($stmt, 'sssss', $name, $coll, $dept, $gend, $subj);
            if (mysqli_stmt_execute($stmt)) {
                flash_success('تم إضافة الدكتور المعتمد بنجاح.');
            } else {
                flash_error('حدث خطأ أثناء إضافة الدكتور.');
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: /admin?section=doctors');
        exit();
    }

    if (isset($_POST['promote_user'])) {
        $identity = trim($_POST['promote_identity'] ?? '');
        $promoteUserId = (int) ($_POST['promote_user_id'] ?? 0);
        $prom_coll = trim($_POST['promote_admin_college'] ?? '');
        $prom_role = trim($_POST['promote_admin_role'] ?? 'assistant_admin');

        if ($identity === '') {
            flash_error('الرجاء إدخال اسم المستخدم أو البريد الإلكتروني للمستخدم.');
            header('Location: /admin?section=supervision');
            exit();
        }

        // If front-end provided an exact user id (from AJAX selection), prefer it
        $userRow = null;
        if ($promoteUserId > 0) {
            $stmtId = mysqli_prepare($conn, 'SELECT id, username, password_hash, email FROM users WHERE id = ? LIMIT 1');
            if ($stmtId) {
                mysqli_stmt_bind_param($stmtId, 'i', $promoteUserId);
                mysqli_stmt_execute($stmtId);
                $resId = mysqli_stmt_get_result($stmtId);
                $userRow = mysqli_fetch_assoc($resId);
                mysqli_stmt_close($stmtId);
            }
        }

        // Fallback: try to find user by username, then by email
        if (empty($userRow)) {
            $stmt = mysqli_prepare($conn, 'SELECT id, username, password_hash, email FROM users WHERE username = ? LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $identity);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $userRow = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);
            }

            if (empty($userRow)) {
                $stmt2 = mysqli_prepare($conn, 'SELECT id, username, password_hash, email FROM users WHERE email = ? LIMIT 1');
                if ($stmt2) {
                    mysqli_stmt_bind_param($stmt2, 's', $identity);
                    mysqli_stmt_execute($stmt2);
                    $res2 = mysqli_stmt_get_result($stmt2);
                    $userRow = mysqli_fetch_assoc($res2);
                    mysqli_stmt_close($stmt2);
                }
            }
        }

        if (empty($userRow)) {
            flash_error('المستخدم غير موجود. تأكد من اسم المستخدم أو البريد الإلكتروني.');
            header('Location: /admin?section=supervision');
            exit();
        }

        // Check if admins table has user_id column
        $hasUserIdColumn = false;
        $colStmt = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "admins" AND COLUMN_NAME = "user_id" LIMIT 1');
        if ($colStmt) {
            $dbName = defined('DB_NAME') ? DB_NAME : '';
            mysqli_stmt_bind_param($colStmt, 's', $dbName);
            if (mysqli_stmt_execute($colStmt)) {
                $colRes = mysqli_stmt_get_result($colStmt);
                if (mysqli_fetch_assoc($colRes)) {
                    $hasUserIdColumn = true;
                }
            }
            mysqli_stmt_close($colStmt);
        }

        // Prevent duplicate admin records
        if ($hasUserIdColumn) {
            $chk = mysqli_prepare($conn, 'SELECT id FROM admins WHERE user_id = ? LIMIT 1');
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'i', $userRow['id']);
                mysqli_stmt_execute($chk);
                mysqli_stmt_store_result($chk);
                if (mysqli_stmt_num_rows($chk) > 0) {
                    mysqli_stmt_close($chk);
                    flash_error('هذا المستخدم مسجل كمشرف مسبقاً.');
                    header('Location: /admin?section=supervision');
                    exit();
                }
                mysqli_stmt_close($chk);
            }
        } else {
            $chk2 = mysqli_prepare($conn, 'SELECT id FROM admins WHERE username = ? LIMIT 1');
            if ($chk2) {
                mysqli_stmt_bind_param($chk2, 's', $userRow['username']);
                mysqli_stmt_execute($chk2);
                mysqli_stmt_store_result($chk2);
                if (mysqli_stmt_num_rows($chk2) > 0) {
                    mysqli_stmt_close($chk2);
                    flash_error('يوجد مشرف موجود بنفس اسم المستخدم.');
                    header('Location: /admin?section=supervision');
                    exit();
                }
                mysqli_stmt_close($chk2);
            }
        }

        // Insert new admin copying username and password_hash from users
        if ($hasUserIdColumn) {
            $ins = mysqli_prepare($conn, 'INSERT INTO admins (username, college_responsibility, password_hash, role, user_id) VALUES (?, ?, ?, ?, ?)');
            if ($ins) {
                $uid = (int) $userRow['id'];
                mysqli_stmt_bind_param($ins, 'ssssi', $userRow['username'], $prom_coll, $userRow['password_hash'], db_admin_role($prom_role), $uid);
                if (mysqli_stmt_execute($ins)) {
                    flash_success('تم ترقية المستخدم إلى مشرف بنجاح.');
                } else {
                    flash_error('حدث خطأ أثناء ترقية المستخدم.');
                }
                mysqli_stmt_close($ins);
            }
        } else {
            $ins2 = mysqli_prepare($conn, 'INSERT INTO admins (username, college_responsibility, password_hash, role) VALUES (?, ?, ?, ?)');
            if ($ins2) {
                mysqli_stmt_bind_param($ins2, 'ssss', $userRow['username'], $prom_coll, $userRow['password_hash'], db_admin_role($prom_role));
                if (mysqli_stmt_execute($ins2)) {
                    flash_success('تم ترقية المستخدم إلى مشرف بنجاح.');
                } else {
                    flash_error('حدث خطأ أثناء ترقية المستخدم.');
                }
                mysqli_stmt_close($ins2);
            }
        }

        header('Location: /admin?section=supervision');
        exit();
    }

    if (isset($_POST['update_admin_college'])) {
        $edit_admin_id = (int) ($_POST['edit_admin_id'] ?? 0);
        $edit_college = trim($_POST['edit_college'] ?? '');
        $edit_role = trim($_POST['edit_role'] ?? 'assistant_admin');
        if ($edit_admin_id > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE admins SET college_responsibility = ?, role = ? WHERE id = ?');
            $normalizedRole = db_admin_role($edit_role);
            mysqli_stmt_bind_param($stmt, 'ssi', $edit_college, $normalizedRole, $edit_admin_id);
            if (mysqli_stmt_execute($stmt)) {
                flash_success('تم تحديث صلاحية المشرف بنجاح.');
            } else {
                flash_error('خطأ أثناء تحديث صلاحيات المشرف.');
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: /admin?section=supervision');
        exit();
    }

    if (isset($_POST['delete_admin_action'])) {
        $del_admin_id = (int) ($_POST['delete_admin_id'] ?? 0);
        if ($del_admin_id === (int) $_SESSION['admin_id']) {
            flash_error('لا يمكنك حذف حسابك الحالي الذي تسجل به الدخول.');
        } elseif ($del_admin_id > 0) {
            $adminRow = null;
            $getAdminStmt = mysqli_prepare($conn, 'SELECT user_id FROM admins WHERE id = ? LIMIT 1');
            if ($getAdminStmt) {
                mysqli_stmt_bind_param($getAdminStmt, 'i', $del_admin_id);
                mysqli_stmt_execute($getAdminStmt);
                $getAdminRes = mysqli_stmt_get_result($getAdminStmt);
                $adminRow = mysqli_fetch_assoc($getAdminRes);
                mysqli_stmt_close($getAdminStmt);
            }

            $stmt = mysqli_prepare($conn, 'DELETE FROM admins WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $del_admin_id);
            if (mysqli_stmt_execute($stmt)) {
                if (!empty($adminRow['user_id'])) {
                    $userIdToDelete = (int) $adminRow['user_id'];
                    $deleteUserStmt = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ?');
                    if ($deleteUserStmt) {
                        mysqli_stmt_bind_param($deleteUserStmt, 'i', $userIdToDelete);
                        mysqli_stmt_execute($deleteUserStmt);
                        mysqli_stmt_close($deleteUserStmt);
                    }
                }
                flash_success('تم حذف المشرف بنجاح.');
            } else {
                flash_error('خطأ أثناء حذف المشرف.');
            }
            mysqli_stmt_close($stmt);
        }
        header('Location: /admin?section=supervision');
        exit();
    }

    if (isset($_POST['admin_action'])) {
        if (!can_manage_section($admin_role, 'doctors')) {
            flash_error('لا توجد لديك صلاحية إدارة الدكاترة.');
            header('Location: /admin?section=dashboard');
            exit();
        }

        $id = (int) ($_POST['doc_id'] ?? 0);
        $action = $_POST['admin_action'] ?? '';
        if ($id > 0 && $action === 'approve') {
            $stmt = mysqli_prepare($conn, 'UPDATE doctors SET is_approved = 1 WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_success('تم اعتماد الدكتور بنجاح.');
        }
        if ($id > 0 && $action === 'delete') {
            $del_reviews = mysqli_prepare($conn, 'DELETE FROM reviews WHERE doctor_id = ?');
            mysqli_stmt_bind_param($del_reviews, 'i', $id);
            mysqli_stmt_execute($del_reviews);
            mysqli_stmt_close($del_reviews);
            $stmt = mysqli_prepare($conn, 'DELETE FROM doctors WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_success('تم حذف الدكتور وجميع تقييماته بنجاح.');
        }
        header('Location: /admin?section=doctors');
        exit();
    }

    if (isset($_POST['review_action'])) {
        if (!can_manage_section($admin_role, 'reviews')) {
            flash_error('لا توجد لديك صلاحية إدارة التقييمات.');
            header('Location: /admin?section=dashboard');
            exit();
        }

        $review_id = (int) ($_POST['review_id'] ?? 0);
        $action = $_POST['review_action'] ?? '';
        if ($review_id > 0 && in_array($action, ['approve', 'approve_review'], true)) {
            $stmt = mysqli_prepare($conn, 'UPDATE reviews SET status = "approved" WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_success('تمت الموافقة على التقييم بنجاح.');
        }
        if ($review_id > 0 && in_array($action, ['unapprove', 'unapprove_review'], true)) {
            $stmt = mysqli_prepare($conn, 'UPDATE reviews SET status = "pending" WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_success('تم إلغاء اعتماد التقييم وإعادته للمعلق.');
        }
        if ($review_id > 0 && in_array($action, ['delete', 'delete_review'], true)) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM reviews WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_success('تم حذف التقييم بنجاح.');
        }
        header('Location: /admin?section=reviews');
        exit();
    }

    if (isset($_POST['academy_action'])) {
        if (!can_manage_section($admin_role, 'subjects')) {
            flash_error('لا توجد لديك صلاحية إدارة المحتوى الأكاديمي.');
            header('Location: /admin?section=dashboard');
            exit();
        }

        $action = $_POST['academy_action'] ?? '';
        $entityId = (int) ($_POST['entity_id'] ?? 0);

        if ($action === 'create_subject' || $action === 'update_subject' || $action === 'delete_subject') {
            $subject_name = trim($_POST['subject_name'] ?? '');
            $course_code = trim($_POST['course_code'] ?? '');
            $credit_hours = (int) ($_POST['credit_hours'] ?? 0);
            $college = trim($_POST['college'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $level_num = (int) ($_POST['level_num'] ?? 1);
            $telegram = trim($_POST['telegram_link'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($action === 'delete_subject' && $entityId > 0) {
                $stmt = mysqli_prepare($conn, 'DELETE FROM subjects WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم حذف المادة بنجاح.');
            } elseif ($action === 'update_subject' && $entityId > 0 && $subject_name !== '') {
                $stmt = mysqli_prepare($conn, 'UPDATE subjects SET subject_name = ?, course_code = ?, credit_hours = ?, college = ?, department = ?, level_num = ?, telegram_link = ?, description = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'ssississi', $subject_name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $description, $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم تعديل المادة بنجاح.');
            } elseif ($action === 'create_subject' && $subject_name !== '') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO subjects (subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'ssississ', $subject_name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $description);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم إضافة المادة بنجاح.');
            }
        } elseif ($action === 'create_doctor' || $action === 'update_doctor' || $action === 'delete_doctor') {
            $doctor_name = trim($_POST['doctor_name'] ?? '');
            $doctor_college = trim($_POST['doctor_college'] ?? '');
            $doctor_department = trim($_POST['doctor_department'] ?? '');
            $doctor_gender = trim($_POST['doctor_gender'] ?? '');
            $doctor_courses = trim($_POST['doctor_courses'] ?? '');
            $doctor_approved = (int) ($_POST['doctor_approved'] ?? 1);

            if ($action === 'delete_doctor' && $entityId > 0) {
                $stmt = mysqli_prepare($conn, 'DELETE FROM reviews WHERE doctor_id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $stmt = mysqli_prepare($conn, 'DELETE FROM doctors WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم حذف الدكتور بنجاح.');
            } elseif ($action === 'update_doctor' && $entityId > 0 && $doctor_name !== '') {
                $stmt = mysqli_prepare($conn, 'UPDATE doctors SET name = ?, college = ?, department = ?, gender = ?, courses = ?, is_approved = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'sssssii', $doctor_name, $doctor_college, $doctor_department, $doctor_gender, $doctor_courses, $doctor_approved, $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم تعديل الدكتور بنجاح.');
            } elseif ($action === 'create_doctor' && $doctor_name !== '') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'sssssi', $doctor_name, $doctor_college, $doctor_department, $doctor_gender, $doctor_courses, $doctor_approved);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم إضافة الدكتور بنجاح.');
            }
        } elseif ($action === 'create_curriculum' || $action === 'update_curriculum' || $action === 'delete_curriculum') {
            $curriculum_title = trim($_POST['curriculum_title'] ?? '');
            $curriculum_college = trim($_POST['curriculum_college'] ?? '');
            $curriculum_department = trim($_POST['curriculum_department'] ?? '');
            $curriculum_semester = trim($_POST['curriculum_semester'] ?? '');
            $curriculum_credits = (int) ($_POST['curriculum_credits'] ?? 0);
            $curriculum_study_level = trim($_POST['curriculum_study_level'] ?? '');
            $curriculum_path = trim($_POST['curriculum_path'] ?? '');
            $curriculum_description = trim($_POST['curriculum_description'] ?? '');
            $curriculum_objectives = trim($_POST['curriculum_objectives'] ?? '');

            if ($action === 'delete_curriculum' && $entityId > 0) {
                $stmt = mysqli_prepare($conn, 'DELETE FROM curriculum WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم حذف الخطة الأكاديمية بنجاح.');
            } elseif ($action === 'update_curriculum' && $entityId > 0 && $curriculum_title !== '') {
                $stmt = mysqli_prepare($conn, 'UPDATE curriculum SET title = ?, description = ?, academic_path = ?, college = ?, department = ?, semester = ?, credits = ?, study_level = ?, objectives = ? WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'ssssssissi', $curriculum_title, $curriculum_description, $curriculum_path, $curriculum_college, $curriculum_department, $curriculum_semester, $curriculum_credits, $curriculum_study_level, $curriculum_objectives, $entityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم تعديل الخطة الأكاديمية بنجاح.');
            } elseif ($action === 'create_curriculum' && $curriculum_title !== '') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO curriculum (title, description, academic_path, college, department, semester, credits, study_level, objectives) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'ssssssiss', $curriculum_title, $curriculum_description, $curriculum_path, $curriculum_college, $curriculum_department, $curriculum_semester, $curriculum_credits, $curriculum_study_level, $curriculum_objectives);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم إضافة الخطة الأكاديمية بنجاح.');
            }
        }

        header('Location: /admin?section=academy');
        exit();
    }

    if (isset($_POST['catalog_action'])) {
        if (!can_manage_section($admin_role, 'supervision')) {
            flash_error('لا توجد لديك صلاحية إدارة الكليات والتخصصات.');
            header('Location: /admin?section=dashboard');
            exit();
        }

        $action = $_POST['catalog_action'] ?? '';
        if ($action === 'add_college') {
            $name = trim($_POST['college_name'] ?? '');
            if ($name !== '') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO academic_colleges (college_name, is_active) VALUES (?, 1)');
                mysqli_stmt_bind_param($stmt, 's', $name);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم إضافة الكلية بنجاح.');
            }
        } elseif ($action === 'add_department') {
            $collegeId = (int) ($_POST['college_id'] ?? 0);
            $name = trim($_POST['department_name'] ?? '');
            if ($collegeId > 0 && $name !== '') {
                $stmt = mysqli_prepare($conn, 'INSERT INTO academic_departments (college_id, department_name, is_active) VALUES (?, ?, 1)');
                mysqli_stmt_bind_param($stmt, 'is', $collegeId, $name);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم إضافة التخصص بنجاح.');
            }
        } elseif ($action === 'delete_college') {
            $collegeId = (int) ($_POST['college_id'] ?? 0);
            if ($collegeId > 0) {
                $stmt = mysqli_prepare($conn, 'DELETE FROM academic_colleges WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $collegeId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم حذف الكلية والتخصصات التابعة لها بنجاح.');
            }
        } elseif ($action === 'delete_department') {
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            if ($departmentId > 0) {
                $stmt = mysqli_prepare($conn, 'DELETE FROM academic_departments WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $departmentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                flash_success('تم حذف التخصص بنجاح.');
            }
        }
        header('Location: /admin?section=supervision');
        exit();
    }
}

include __DIR__ . '/includes/admin_header.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($flash):
?>
<section class="flash flash--<?= e($flash['type']) ?>" id="admin-flash" role="status" aria-live="polite">
    <span><?= e($flash['text']) ?></span>
    <button type="button" class="flash__close" aria-label="إغلاق الرسالة" onclick="this.parentElement.style.display='none';">×</button>
</section>
<?php endif; ?>

<div class="admin-nav" role="navigation" aria-label="تنقل لوحة الإدارة">
    <?php if (can_manage_section($admin_role, 'dashboard')): ?><a class="btn" href="?section=dashboard">الإحصائيات</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'reviews')): ?><a class="btn" href="?section=reviews">التقييمات</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'doctors')): ?><a class="btn" href="?section=doctors">الدكاترة</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'subjects')): ?><a class="btn" href="?section=subjects">المواد</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'subjects')): ?><a class="btn" href="?section=academy">المحتوى الأكاديمي</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'supervision')): ?><a class="btn" href="?section=supervision">المشرفون</a><?php endif; ?>
    <?php if (can_manage_section($admin_role, 'ai_report')): ?><a class="btn" href="?section=ai_report">التحليل الذكي</a><?php endif; ?>
</div>

<?php
switch ($section) {
    case 'reviews':
        include __DIR__ . '/sections/reviews.php';
        break;
    case 'doctors':
        include __DIR__ . '/sections/doctors.php';
        break;
    case 'subjects':
        include __DIR__ . '/sections/subjects.php';
        break;
    case 'academy':
        include __DIR__ . '/sections/academy_crud.php';
        break;
    case 'supervision':
        include __DIR__ . '/sections/supervision.php';
        break;
    case 'ai_report':
        include __DIR__ . '/../ai_report.php';
        break;
    default:
        include __DIR__ . '/sections/dashboard.php';
}

include __DIR__ . '/includes/admin_footer.php';