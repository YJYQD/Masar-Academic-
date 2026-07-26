<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/auth_guard.php';
require_once __DIR__ . '/admin/includes/functions.php';
require_once __DIR__ . '/inc/telegram_helpers.php';
require_once __DIR__ . '/inc/academy_sync.php';

if (!($conn instanceof mysqli)) {
    http_response_code(200);
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>الخدمة غير متاحة</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:24px;direction:rtl;text-align:right;"><h2>الخدمة غير متاحة مؤقتاً</h2><p>تعذر الوصول إلى قاعدة البيانات حالياً، لذلك لا يمكن عرض الخطة الدراسية الآن.</p><p>يرجى المحاولة لاحقاً أو التأكد من تشغيل قاعدة البيانات.</p></body></html>';
    exit();
}

$accessContext = resolve_access_context();
$effectiveRole = $accessContext['role'];
$accessCollegeName = $accessContext['college_name'];
$canManageSyllabus = can_manage_academic_content($effectiveRole, $accessCollegeName);
$userId = current_authenticated_user_id();

if ($userId <= 0) {
    http_response_code(403);
    header('Location: index.php?error=unauthorized');
    exit();
}

function normalize_syllabus_college(string $college): string
{
    return normalize_college_label($college);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageSyllabus) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'ليس لديك صلاحية تعديل الخطة الدراسية.'];
        header('Location: syllabus.php');
        exit();
    }
    $deleteSubject = !empty($_POST['delete_subject']);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $subject_name = trim((string) ($_POST['subject_name'] ?? ''));
    $course_code = trim((string) ($_POST['course_code'] ?? ''));
    $credit_hours = isset($_POST['credit_hours']) && $_POST['credit_hours'] !== '' ? (int) $_POST['credit_hours'] : 0;
    $college = normalize_syllabus_college(trim((string) ($_POST['college'] ?? '')));
    $department = trim((string) ($_POST['department'] ?? ''));
    $level_num = isset($_POST['level_num']) && $_POST['level_num'] !== '' ? (int) $_POST['level_num'] : 1;
    $telegram = trim((string) ($_POST['telegram_link'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $college = normalize_syllabus_college($accessCollegeName);
    }

    if ($deleteSubject && $subject_id > 0) {
        $subjectDeleteSql = 'DELETE FROM subjects WHERE id = ?';
        $subjectDeleteTypes = 'i';
        $subjectDeleteParams = [$subject_id];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $subjectDeleteSql .= ' AND college = ?';
            $subjectDeleteTypes .= 's';
            $subjectDeleteParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $subjectDeleteSql .= ' AND user_id = ?';
            $subjectDeleteTypes .= 'i';
            $subjectDeleteParams[] = $userId;
        }

        $stmt = mysqli_prepare($conn, $subjectDeleteSql);
        if ($stmt) {
            $bindValues = [$stmt, $subjectDeleteTypes];
            foreach ($subjectDeleteParams as &$subjectDeleteValue) {
                $bindValues[] = &$subjectDeleteValue;
            }
            unset($subjectDeleteValue);
            call_user_func_array('mysqli_stmt_bind_param', $bindValues);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        $curriculumDeleteSql = 'DELETE FROM curriculum WHERE title = ?';
        $curriculumDeleteTypes = 's';
        $curriculumDeleteParams = [$subject_name];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $curriculumDeleteSql .= ' AND college = ?';
            $curriculumDeleteTypes .= 's';
            $curriculumDeleteParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $curriculumDeleteSql .= ' AND user_id = ?';
            $curriculumDeleteTypes .= 'i';
            $curriculumDeleteParams[] = $userId;
        }

        $curriculumDeleteStmt = mysqli_prepare($conn, $curriculumDeleteSql);
        if ($curriculumDeleteStmt) {
            $bindValues = [$curriculumDeleteStmt, $curriculumDeleteTypes];
            foreach ($curriculumDeleteParams as &$curriculumDeleteValue) {
                $bindValues[] = &$curriculumDeleteValue;
            }
            unset($curriculumDeleteValue);
            call_user_func_array('mysqli_stmt_bind_param', $bindValues);
            mysqli_stmt_execute($curriculumDeleteStmt);
            mysqli_stmt_close($curriculumDeleteStmt);
        }
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم حذف المادة بنجاح.'];
    } elseif ($subject_id > 0 && $subject_name !== '') {
        $subjectUpdateSql = 'UPDATE subjects SET subject_name = ?, course_code = ?, credit_hours = ?, college = ?, department = ?, level_num = ?, telegram_link = ?, description = ? WHERE id = ?';
        $subjectUpdateTypes = 'ssississi';
        $subjectUpdateParams = [$subject_name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $description, $subject_id];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $subjectUpdateSql .= ' AND college = ?';
            $subjectUpdateTypes .= 's';
            $subjectUpdateParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $subjectUpdateSql .= ' AND user_id = ?';
            $subjectUpdateTypes .= 'i';
            $subjectUpdateParams[] = $userId;
        }

        $stmt = mysqli_prepare($conn, $subjectUpdateSql);
        if ($stmt) {
            $bindValues = [$stmt, $subjectUpdateTypes];
            foreach ($subjectUpdateParams as &$subjectUpdateValue) {
                $bindValues[] = &$subjectUpdateValue;
            }
            unset($subjectUpdateValue);
            call_user_func_array('mysqli_stmt_bind_param', $bindValues);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم حفظ بيانات المادة بنجاح.'];
    } elseif ($subject_name !== '') {
        $stmt = mysqli_prepare($conn, 'INSERT INTO subjects (user_id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issississ', $userId, $subject_name, $course_code, $credit_hours, $college, $department, $level_num, $telegram, $description);
            $inserted = mysqli_stmt_execute($stmt);
            $newSubjectId = $inserted ? (int) $conn->insert_id : 0;
            mysqli_stmt_close($stmt);

            if ($newSubjectId > 0) {
                $curriculumTitle = $subject_name;
                $curriculumDescription = $description !== '' ? $description : 'رمز المادة: ' . ($course_code !== '' ? $course_code : 'غير محدد');
                $curriculumSemester = $level_num > 0 ? 'المستوى ' . $level_num : 'غير محدد';
                $curriculumCredits = $credit_hours > 0 ? $credit_hours : 3;

                $curriculumStmt = mysqli_prepare($conn, 'INSERT INTO curriculum (user_id, title, description, semester, credits, college, department) VALUES (?, ?, ?, ?, ?, ?, ?)');
                if ($curriculumStmt) {
                    mysqli_stmt_bind_param($curriculumStmt, 'isssiss', $userId, $curriculumTitle, $curriculumDescription, $curriculumSemester, $curriculumCredits, $college, $department);
                    mysqli_stmt_execute($curriculumStmt);
                    mysqli_stmt_close($curriculumStmt);
                }
            }
        }
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إضافة المادة بنجاح وظهرت في خطة المسار.'];
    }
    header('Location: syllabus.php');
    exit();
}

if (isset($_GET['api']) && $_GET['api'] === 'academy_sync') {
    $curriculumRows = [];
    $subjectRows = [];
    $departmentRows = [];

    if ($conn) {
        $curriculumSql = 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum';
        $curriculumTypes = '';
        $curriculumParams = [];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $curriculumSql .= ' WHERE college = ?';
            $curriculumTypes = 's';
            $curriculumParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $curriculumSql .= ' WHERE user_id = ?';
            $curriculumTypes = 'i';
            $curriculumParams[] = $userId;
        }
        $curriculumSql .= ' ORDER BY id DESC';

        $curriculumStmt = mysqli_prepare($conn, $curriculumSql);
        if ($curriculumStmt) {
            if ($curriculumTypes !== '') {
                $curriculumBindValues = [$curriculumStmt, $curriculumTypes];
                foreach ($curriculumParams as &$curriculumValue) {
                    $curriculumBindValues[] = &$curriculumValue;
                }
                unset($curriculumValue);
                call_user_func_array('mysqli_stmt_bind_param', $curriculumBindValues);
            }
            mysqli_stmt_execute($curriculumStmt);
            $curriculumResult = mysqli_stmt_get_result($curriculumStmt);
            while ($row = mysqli_fetch_assoc($curriculumResult)) {
                $curriculumRows[] = $row;
            }
            mysqli_stmt_close($curriculumStmt);
        }

        $subjectSql = 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects';
        $subjectTypes = '';
        $subjectParams = [];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $subjectSql .= ' WHERE college = ?';
            $subjectTypes = 's';
            $subjectParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $subjectSql .= ' WHERE user_id = ?';
            $subjectTypes = 'i';
            $subjectParams[] = $userId;
        }
        $subjectSql .= ' ORDER BY college ASC, department ASC, level_num ASC, subject_name ASC';

        $subjectStmt = mysqli_prepare($conn, $subjectSql);
        if ($subjectStmt) {
            if ($subjectTypes !== '') {
                $subjectBindValues = [$subjectStmt, $subjectTypes];
                foreach ($subjectParams as &$subjectValue) {
                    $subjectBindValues[] = &$subjectValue;
                }
                unset($subjectValue);
                call_user_func_array('mysqli_stmt_bind_param', $subjectBindValues);
            }
            mysqli_stmt_execute($subjectStmt);
            $subjectResult = mysqli_stmt_get_result($subjectStmt);
            while ($row = mysqli_fetch_assoc($subjectResult)) {
                $subjectRows[] = $row;
            }
            mysqli_stmt_close($subjectStmt);
        }

        $departmentSql = 'SELECT college, department, COUNT(*) AS cnt FROM subjects';
        $departmentTypes = '';
        $departmentParams = [];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $departmentSql .= ' WHERE college = ?';
            $departmentTypes = 's';
            $departmentParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $departmentSql .= ' WHERE user_id = ?';
            $departmentTypes = 'i';
            $departmentParams[] = $userId;
        }
        $departmentSql .= ' GROUP BY college, department ORDER BY college, department';

        $departmentStmt = mysqli_prepare($conn, $departmentSql);
        if ($departmentStmt) {
            if ($departmentTypes !== '') {
                $departmentBindValues = [$departmentStmt, $departmentTypes];
                foreach ($departmentParams as &$departmentValue) {
                    $departmentBindValues[] = &$departmentValue;
                }
                unset($departmentValue);
                call_user_func_array('mysqli_stmt_bind_param', $departmentBindValues);
            }
            mysqli_stmt_execute($departmentStmt);
            $departmentResult = mysqli_stmt_get_result($departmentStmt);
            while ($row = mysqli_fetch_assoc($departmentResult)) {
                $departmentRows[] = $row;
            }
            mysqli_stmt_close($departmentStmt);
        }
    }

    header('Content-Type: application/json');
    echo json_encode(build_academy_live_payload($curriculumRows, $subjectRows, $departmentRows));
    exit();
}

// API: return top 3 doctors for a subject
if (isset($_GET['api']) && $_GET['api'] === 'top_doctors' && isset($_GET['subject_id'])) {
    $subjectId = (int) $_GET['subject_id'];
    $sql = "SELECT d.id, d.name, d.college, d.department, AVG(r.rating) AS avg_rating, COUNT(r.id) AS review_count
            FROM doctors d
            INNER JOIN doctor_subject ds ON ds.doctor_id = d.id
            LEFT JOIN reviews r ON r.doctor_id = d.id
            WHERE ds.subject_id = ? AND d.is_approved = 1
            GROUP BY d.id
            ORDER BY avg_rating DESC
            LIMIT 3";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row;
        }
        mysqli_stmt_close($stmt);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'doctors' => $out]);
        exit();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit();
}

// API: get subject details
if (isset($_GET['api']) && $_GET['api'] === 'get_subject' && isset($_GET['subject_id']) && $canManageSyllabus) {
    $sid = (int) $_GET['subject_id'];
    $subjectApiSql = 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects WHERE id = ?';
    $subjectApiTypes = 'i';
    $subjectApiParams = [$sid];
    if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $subjectApiSql .= ' AND college = ?';
        $subjectApiTypes .= 's';
        $subjectApiParams[] = $accessCollegeName;
    } elseif ($effectiveRole === 'student') {
        $subjectApiSql .= ' AND user_id = ?';
        $subjectApiTypes .= 'i';
        $subjectApiParams[] = $userId;
    }
    $subjectApiSql .= ' LIMIT 1';

    $stmt = mysqli_prepare($conn, $subjectApiSql);
    if ($stmt) {
        $subjectApiBindValues = [$stmt, $subjectApiTypes];
        foreach ($subjectApiParams as &$subjectApiValue) {
            $subjectApiBindValues[] = &$subjectApiValue;
        }
        unset($subjectApiValue);
        call_user_func_array('mysqli_stmt_bind_param', $subjectApiBindValues);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'subject' => $row]);
        exit();
    }
    header('Content-Type: application/json'); echo json_encode(['success' => false]); exit();
}

// Normal page: either show department selector or subjects per department/level
$dept = trim((string) ($_GET['dept'] ?? ''));
$departments = [];
$subjectsByLevel = [];
$maxLevel = 0;
$selectedCollege = '';
$departmentsByCollege = [];

if ($dept === '') {
    $deptSql = 'SELECT college, department, COUNT(*) AS cnt FROM subjects';
    $deptTypes = '';
    $deptParams = [];
    if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $deptSql .= ' WHERE college = ?';
        $deptTypes = 's';
        $deptParams[] = $accessCollegeName;
    } elseif ($effectiveRole === 'student') {
        $deptSql .= ' WHERE user_id = ?';
        $deptTypes = 'i';
        $deptParams[] = $userId;
    }
    $deptSql .= ' GROUP BY college, department ORDER BY college, department';
    $deptStmt = mysqli_prepare($conn, $deptSql);
    if ($deptStmt) {
        if ($deptTypes !== '') {
            $deptBindValues = [$deptStmt, $deptTypes];
            foreach ($deptParams as &$deptValue) {
                $deptBindValues[] = &$deptValue;
            }
            unset($deptValue);
            call_user_func_array('mysqli_stmt_bind_param', $deptBindValues);
        }
        mysqli_stmt_execute($deptStmt);
        $res = mysqli_stmt_get_result($deptStmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $departments[] = $row;
        }
        mysqli_stmt_close($deptStmt);
    }

    if (empty($departments)) {
        if ($effectiveRole === 'super_admin') {
            $curriculumDeptStmt = mysqli_prepare($conn, 'SELECT college, department, COUNT(*) AS cnt FROM curriculum WHERE COALESCE(college, "") <> "" AND COALESCE(department, "") <> "" GROUP BY college, department ORDER BY college, department');
        } elseif ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $curriculumDeptStmt = mysqli_prepare($conn, 'SELECT college, department, COUNT(*) AS cnt FROM curriculum WHERE COALESCE(college, "") = ? AND COALESCE(college, "") <> "" AND COALESCE(department, "") <> "" GROUP BY college, department ORDER BY college, department');
        } else {
            $curriculumDeptStmt = mysqli_prepare($conn, 'SELECT college, department, COUNT(*) AS cnt FROM curriculum WHERE user_id = ? AND COALESCE(college, "") <> "" AND COALESCE(department, "") <> "" GROUP BY college, department ORDER BY college, department');
        }
        if ($curriculumDeptStmt) {
            if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
                mysqli_stmt_bind_param($curriculumDeptStmt, 's', $accessCollegeName);
            } elseif ($effectiveRole === 'student') {
                mysqli_stmt_bind_param($curriculumDeptStmt, 'i', $userId);
            }
            mysqli_stmt_execute($curriculumDeptStmt);
            $res = mysqli_stmt_get_result($curriculumDeptStmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $departments[] = [
                    'college' => $row['college'],
                    'department' => $row['department'],
                    'cnt' => (int) $row['cnt'],
                ];
            }
            mysqli_stmt_close($curriculumDeptStmt);
        }
    }
} else {
    if ($effectiveRole === 'super_admin') {
        $maxStmt = mysqli_prepare($conn, 'SELECT MAX(level_num) AS mx FROM subjects WHERE department = ?');
    } elseif ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $maxStmt = mysqli_prepare($conn, 'SELECT MAX(level_num) AS mx FROM subjects WHERE department = ? AND COALESCE(college, "") = ?');
    } else {
        $maxStmt = mysqli_prepare($conn, 'SELECT MAX(level_num) AS mx FROM subjects WHERE department = ? AND user_id = ?');
    }
    if ($maxStmt) {
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            mysqli_stmt_bind_param($maxStmt, 'ss', $dept, $accessCollegeName);
        } elseif ($effectiveRole === 'student') {
            mysqli_stmt_bind_param($maxStmt, 'si', $dept, $userId);
        } else {
            mysqli_stmt_bind_param($maxStmt, 's', $dept);
        }
        mysqli_stmt_execute($maxStmt);
        $res = mysqli_stmt_get_result($maxStmt);
        $row = mysqli_fetch_assoc($res);
        $maxLevel = (int) ($row['mx'] ?? 0);
        mysqli_stmt_close($maxStmt);
    }

    if ($maxLevel <= 0) {
        $curriculumQuery = 'SELECT id, title AS subject_name, COALESCE(NULLIF(course_code, ""), "CUR") AS course_code, credits AS credit_hours FROM curriculum WHERE COALESCE(department, "") = ?';
        $curriculumTypes = 's';
        $curriculumParams = [$dept];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $curriculumQuery .= ' AND COALESCE(college, "") = ?';
            $curriculumTypes .= 's';
            $curriculumParams[] = $accessCollegeName;
        } elseif ($effectiveRole === 'student') {
            $curriculumQuery .= ' AND user_id = ?';
            $curriculumTypes .= 'i';
            $curriculumParams[] = $userId;
        }
        $curriculumQuery .= ' ORDER BY id ASC';

        $curriculumStmt = mysqli_prepare($conn, $curriculumQuery);
        if ($curriculumStmt) {
            $curriculumBindValues = [$curriculumStmt, $curriculumTypes];
            foreach ($curriculumParams as &$curriculumValue) {
                $curriculumBindValues[] = &$curriculumValue;
            }
            unset($curriculumValue);
            call_user_func_array('mysqli_stmt_bind_param', $curriculumBindValues);
            mysqli_stmt_execute($curriculumStmt);
            $res = mysqli_stmt_get_result($curriculumStmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $subjectsByLevel[1][] = [
                    'id' => 0,
                    'subject_name' => $row['subject_name'],
                    'course_code' => $row['course_code'],
                    'credit_hours' => (int) ($row['credit_hours'] ?? 0),
                    'is_curriculum_fallback' => true,
                ];
            }
            mysqli_stmt_close($curriculumStmt);
        }
        $maxLevel = !empty($subjectsByLevel[1]) ? 1 : 0;
    } else {
        $subjectsQuery = 'SELECT id, subject_name, course_code, credit_hours FROM subjects WHERE department = ? AND level_num = ?';
        $subjectsTypes = 'si';
        $subjectsParams = [$dept, 0];
        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $subjectsQuery .= ' AND COALESCE(college, "") = ?';
            $subjectsTypes .= 's';
        } elseif ($effectiveRole === 'student') {
            $subjectsQuery .= ' AND user_id = ?';
            $subjectsTypes .= 'i';
        }
        $subjectsQuery .= ' ORDER BY course_code ASC, subject_name ASC';

        $sStmt = mysqli_prepare($conn, $subjectsQuery);
        for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
            $subjectsByLevel[$lvl] = [];
            if ($sStmt) {
                $levelParams = [$dept, $lvl];
                if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
                    $levelParams[] = $accessCollegeName;
                } elseif ($effectiveRole === 'student') {
                    $levelParams[] = $userId;
                }

                bind_stmt_params($sStmt, $subjectsTypes, $levelParams);
                mysqli_stmt_execute($sStmt);
                $res2 = mysqli_stmt_get_result($sStmt);
                while ($r = mysqli_fetch_assoc($res2)) {
                    $subjectsByLevel[$lvl][] = $r;
                }
            }
        }
        if ($sStmt) {
            mysqli_stmt_close($sStmt);
        }
    }
}

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
    <title>الخطة الدراسية — منصة مسار الأكاديمية</title>
</head>
<body>
<main class="page syllabus-page">
    <section class="panel syllabus-panel">
        <div class="panel-head">
            <div>
                <h2>الخطة الدراسية التفاعلية</h2>
                <p>اضغط على أي مادة لعرض أفضل 3 دكاترة لهذا المقرر.</p>
            </div>
            <div class="panel-actions">
                <a class="btn btn--light" href="index.php">الصفحة الرئيسية</a>
                <?php if ($canManageSyllabus): ?><button class="btn btn--accent" id="open-syllabus-editor">إضافة/تعديل مادة</button><?php endif; ?>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?><p class="flash flash--<?= e($_SESSION['flash']['type']) ?>"><?= e($_SESSION['flash']['text']) ?></p><?php endif; ?>

        <?php if (!$canManageSyllabus): ?>
            <div style="background:#1e222b; border:1px solid #2d3139; padding:12px 14px; border-radius:10px; color:#aaa; margin-bottom:16px;">ℹ️ أنت الآن في وضع المشاهدة فقط. لا يمكنك إضافة أو تعديل المواد أو الخطة الدراسية.</div>
        <?php endif; ?>

        <?php if ($dept === ''): ?>
            <div class="syllabus-section">
                <h3>التخصصات المتاحة</h3>
                <?php if (empty($departments)): ?>
                    <p class="empty-state">لا توجد مواد مصنفة بعد.</p>
                <?php else: ?>
                    <div class="department-grid">
                        <?php foreach ($departments as $d): ?>
                            <a class="department-card" href="syllabus.php?dept=<?= urlencode($d['department']) ?>">
                                <div class="department-card__title"><?= e($d['department']) ?></div>
                                <p><?= e($d['college']) ?></p>
                                <span><?= (int)$d['cnt'] ?> مادة</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="syllabus-section">
                <h3>الخطة الدراسية لتخصص: <?= e($dept) ?></h3>
                <?php if (empty($subjectsByLevel) || count(array_filter($subjectsByLevel)) === 0): ?>
                    <p class="empty-state">لم تُعرّف مواد لهذا التخصص بعد.</p>
                <?php else: ?>
                    <div class="syllabus-tabs">
                        <?php for ($i = 1; $i <= $maxLevel; $i++): ?>
                            <button class="btn tab-btn <?= $i === 1 ? 'active' : '' ?>" data-level="<?= $i ?>">المستوى <?= $i ?></button>
                        <?php endfor; ?>
                    </div>

                    <?php for ($i=1;$i<=$maxLevel;$i++): ?>
                        <div class="level-panel" data-level-panel="<?= $i ?>" style="display:<?= $i===1 ? 'block' : 'none' ?>;">
                            <div class="level-section">
                                <h4>المستوى <?= $i ?></h4>
                                <?php if (empty($subjectsByLevel[$i])): ?><p class="empty-state">لا توجد مواد في هذا المستوى.</p><?php else: ?>
                                    <?php foreach ($subjectsByLevel[$i] as $sub): ?>
                                        <div class="subject-row">
                                            <strong><?= e($sub['course_code'] ?? '') ?></strong>
                                            <span><?= e($sub['subject_name']) ?></span>
                                            <span><?= e($sub['credit_hours'] ?? '') ?> ساعة</span>
                                            <div class="panel-actions">
                                                <?php if ($canManageSyllabus && empty($sub['is_curriculum_fallback'])): ?>
                                                    <button class="btn btn--light edit-subject-btn"
                                                        data-subject-id="<?= (int)$sub['id'] ?>"
                                                        data-subject-name="<?= e($sub['subject_name'] ?? '') ?>"
                                                        data-course-code="<?= e($sub['course_code'] ?? '') ?>"
                                                        data-credit-hours="<?= (int) ($sub['credit_hours'] ?? 0) ?>">تعديل</button>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل تريد حذف هذه المادة؟')">
                                                        <input type="hidden" name="delete_subject" value="1">
                                                        <input type="hidden" name="subject_id" value="<?= (int)$sub['id'] ?>">
                                                        <input type="hidden" name="subject_name" value="<?= e($sub['subject_name'] ?? '') ?>">
                                                        <button class="btn btn--light" type="submit">حذف</button>
                                                    </form>
                                                <?php endif; ?>
                                                <button class="btn view-doctors" data-subject-id="<?= (int)$sub['id'] ?>">استعراض</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($canManageSyllabus): ?>
<div id="syllabus-editor-modal" class="modal-backdrop" style="display:none;">
    <div class="modal-card">
        <h3>إضافة أو تعديل مادة</h3>
        <form method="POST" class="auth-form-grid">
            <input type="hidden" name="subject_id" value="">
            <label>اسم المادة<input type="text" name="subject_name" required></label>
            <label>رمز المادة<input type="text" name="course_code"></label>
            <label>عدد الساعات<input type="number" name="credit_hours" min="0" step="1"></label>
            <label>الكلية
                <select name="college" id="syllabus-college-select">
                    <option value="">-- اختر الكلية --</option>
                    <?php $map = get_colleges_map(); foreach (array_keys($map) as $c): ?>
                        <option value="<?= e($c) ?>" <?= (($accessContext['role'] ?? 'student') === 'college_admin' && ($accessContext['college_name'] ?? '') === $c) ? 'selected disabled' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>القسم/التخصص
                <select name="department" id="syllabus-department-select"><option value="">-- اختر القسم --</option></select>
            </label>
            <label>رقم المستوى<input type="number" name="level_num" min="1" step="1"></label>
            <label>رابط التليجرام<input type="url" name="telegram_link" placeholder="https://t.me/..."></label>
            <label class="full-width">وصف المادة<textarea name="description" rows="3"></textarea></label>
            <div class="panel-actions full-width">
                <button type="button" class="btn btn--light" id="close-syllabus-editor">إلغاء</button>
                <button type="submit" class="btn btn--accent">حفظ</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="syllabus-modal-root" style="display:none;"></div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const syncUrl = 'syllabus.php?api=academy_sync';

    function refreshSyllabusView() {
        fetch(syncUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('sync-failed');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    return;
                }
                const departmentGrid = document.querySelector('.department-grid');
                if (departmentGrid) {
                    departmentGrid.innerHTML = '';
                    (payload.departments || []).forEach(function (d) {
                        const card = document.createElement('a');
                        card.className = 'department-card';
                        card.href = 'syllabus.php?dept=' + encodeURIComponent(d.department || '');
                        card.innerHTML = '<div class="department-card__title">' + escapeHtml(d.department || '') + '</div><p>' + escapeHtml(d.college || '') + '</p><span>' + (Number(d.cnt || 0)) + ' مادة</span>';
                        departmentGrid.appendChild(card);
                    });
                }
            })
            .catch(function () {
                // ignore transient failures
            });
    }

    window.refreshSyllabusView = refreshSyllabusView;
    window.setInterval(refreshSyllabusView, 8000);

    <?php if ($canManageSyllabus): ?>
    const editorModal = document.getElementById('syllabus-editor-modal');
    const openEditor = document.getElementById('open-syllabus-editor');
    const closeEditor = document.getElementById('close-syllabus-editor');

    function openEditorModal() {
        editorModal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }
    function closeEditorModal() {
        editorModal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    if (openEditor) {
        openEditor.addEventListener('click', function(){ openEditorModal(); });
    }
    if (closeEditor) {
        closeEditor.addEventListener('click', function(){ closeEditorModal(); });
    }
    editorModal?.addEventListener('click', function(e){ if (e.target === editorModal) closeEditorModal(); });

    document.querySelectorAll('.edit-subject-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            const id = btn.getAttribute('data-subject-id');
            const form = editorModal.querySelector('form');
            form.subject_id.value = id;
            form.subject_name.value = btn.getAttribute('data-subject-name') || '';
            form.course_code.value = btn.getAttribute('data-course-code') || '';
            form.credit_hours.value = btn.getAttribute('data-credit-hours') || '';
            form.level_num.value = '';
            form.telegram_link.value = '';
            form.description.value = '';
            var collegeSel = form.querySelector('select[name="college"]');
            var deptSel = form.querySelector('select[name="department"]');
            collegeSel.value = '';
            var map = <?= json_encode(get_colleges_map(), JSON_UNESCAPED_UNICODE) ?>;
            deptSel.innerHTML = '<option value="">-- اختر القسم --</option>';
            openEditorModal();
        });
    });

    var syllabusCollege = document.getElementById('syllabus-college-select');
    var syllabusDept = document.getElementById('syllabus-department-select');
    if (syllabusCollege) {
        var cmap = <?= json_encode(get_colleges_map(), JSON_UNESCAPED_UNICODE) ?>;
        syllabusCollege.addEventListener('change', function(){
            var list = cmap[this.value] || [];
            syllabusDept.innerHTML = '<option value="">-- اختر القسم --</option>';
            list.forEach(function(d){ var o=document.createElement('option'); o.value=d; o.textContent=d; syllabusDept.appendChild(o); });
        });
    }
    <?php endif; ?>

    function createModal(content){
        const root = document.getElementById('syllabus-modal-root');
        root.innerHTML = '';
        const backdrop = document.createElement('div'); backdrop.className = 'modal-backdrop';
        const modal = document.createElement('div'); modal.className = 'modal-card';
        modal.innerHTML = content;
        backdrop.appendChild(modal);
        backdrop.addEventListener('click', function(e){ if (e.target === backdrop) root.style.display='none'; });
        root.appendChild(backdrop);
        root.style.display = 'block';
    }

    document.querySelectorAll('.tab-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            const lvl = btn.getAttribute('data-level');
            document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            document.querySelectorAll('.level-panel').forEach(function(p){ p.style.display = p.getAttribute('data-level-panel') === lvl ? 'block' : 'none'; });
        });
    });

    document.querySelectorAll('.view-doctors').forEach(function(b){ b.addEventListener('click', function(){ openTopDoctorsModal(b.getAttribute('data-subject-id')); }); });

    function openTopDoctorsModal(id){
        const loadingHtml = '<div class="modal-loading"><div class="spinner"></div><p>جاري التحميل...</p></div>';
        createModal(loadingHtml);
        fetch('syllabus.php?api=top_doctors&subject_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){ return r.json(); })
            .then(function(payload){
                if (!payload || payload.success !== true) {
                    createModal('<p class="empty-state">فشل جلب الدكاترة المميزين.</p>');
                    return;
                }
                const docs = payload.doctors || [];
                let html = '<h3>أفضل الدكاترة للمقرر</h3>';
                if (docs.length === 0) {
                    html += '<p class="empty-state">لا يوجد دكاترة مرتبطون بعد أو لا توجد تقييمات معتمدة.</p>';
                } else {
                    html += '<ul class="stack-list">' + docs.map(function(d){
                        return '<li><strong>' + escapeHtml(d.name) + '</strong> — ' + escapeHtml(d.college || '') + ' (' + (Number(d.avg_rating).toFixed(1) || '0.0') + ' من 5 — ' + (d.review_count||0) + ' تقييم)</li>';
                    }).join('') + '</ul>';
                }
                html += '<div class="panel-actions"><button class="btn btn--light" onclick="document.getElementById(\'syllabus-modal-root\').style.display=\'none\'">إغلاق</button></div>';
                createModal(html);
            }).catch(function(){ createModal('<p class="empty-state">خطأ بالشبكة، حاول مرة أخرى.</p>'); });
    }

    function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
});
</script>
</body>
</html>
