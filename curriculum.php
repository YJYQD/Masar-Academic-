<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/auth_guard.php';
require_once __DIR__ . '/inc/academy_sync.php';
require_once __DIR__ . '/admin/includes/functions.php';

if (!($conn instanceof mysqli)) {
    http_response_code(200);
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>الخدمة غير متاحة</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:24px;direction:rtl;text-align:right;"><h2>الخدمة غير متاحة مؤقتاً</h2><p>تعذر الوصول إلى قاعدة البيانات حالياً، لذلك لا يمكن عرض المسار الأكاديمي الآن.</p><p>يرجى المحاولة لاحقاً أو التأكد من تشغيل قاعدة البيانات.</p></body></html>';
    exit();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function resolve_curriculum_access_context(): array
{
    $sessionRole = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($sessionRole === '') {
        $sessionRole = 'student';
    }

    if (in_array($sessionRole, ['super', 'super_admin', 'root_admin', 'admin'], true)) {
        $role = 'super_admin';
    } elseif (in_array($sessionRole, ['college_admin', 'faculty_admin', 'manager'], true)) {
        $role = 'college_admin';
    } else {
        $role = 'student';
    }

    return [
        'role' => $role,
        'college_name' => trim((string) ($_SESSION['college_scope'] ?? $_SESSION['admin_college'] ?? $_SESSION['college_name'] ?? $_SESSION['college'] ?? '')),
    ];
}

$accessContext = resolve_access_context();
$effectiveRole = $accessContext['role'];
$accessCollegeName = $accessContext['college_name'];
$canManageCurriculum = can_manage_academic_content($effectiveRole, $accessCollegeName);

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

$userId = current_authenticated_user_id();
if ($userId <= 0) {
    http_response_code(403);
    header('Location: index.php?error=unauthorized');
    exit();
}
$collegeCatalog = get_colleges_map();
$selectedCollege = trim((string) ($_GET['college_filter'] ?? ''));
$selectedDepartment = trim((string) ($_GET['department_filter'] ?? ''));
$filterOptions = [];

if ($conn) {
    $filterSql = 'SELECT DISTINCT college, department FROM curriculum';
    $filterTypes = '';
    $filterParams = [];
    if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $filterSql .= ' WHERE college = ?';
        $filterTypes = 's';
        $filterParams[] = $accessCollegeName;
    } elseif ($effectiveRole === 'student') {
        $filterSql .= ' WHERE user_id = ?';
        $filterTypes = 'i';
        $filterParams[] = $userId;
    }
    $filterSql .= ' ORDER BY college, department';

    $filterStmt = mysqli_prepare($conn, $filterSql);
    if ($filterStmt) {
        if ($filterTypes !== '') {
            $filterBindValues = [$filterStmt, $filterTypes];
            foreach ($filterParams as &$filterValue) {
                $filterBindValues[] = &$filterValue;
            }
            unset($filterValue);
            call_user_func_array('mysqli_stmt_bind_param', $filterBindValues);
        }
        mysqli_stmt_execute($filterStmt);
        $filterResult = mysqli_stmt_get_result($filterStmt);
        while ($filterRow = mysqli_fetch_assoc($filterResult)) {
            $collegeName = trim((string) ($filterRow['college'] ?? ''));
            $departmentName = trim((string) ($filterRow['department'] ?? ''));
            if ($collegeName !== '') {
                $filterOptions[$collegeName][] = $departmentName;
            }
        }
        mysqli_stmt_close($filterStmt);
    }
}

$availableColleges = array_keys($filterOptions);
sort($availableColleges);
$availableDepartments = [];
foreach ($filterOptions as $collegeName => $departments) {
    $departmentList = array_values(array_unique(array_filter($departments, static function ($value): bool {
        return trim((string) $value) !== '';
    })));
    sort($departmentList, SORT_STRING);
    $availableDepartments[$collegeName] = $departmentList;
}

if (isset($_GET['api']) && $_GET['api'] === 'academy_sync') {
    $curriculumRows = [];
    $subjectRows = [];
    $departmentRows = [];

    if ($conn) {
        $curriculumStmt = mysqli_prepare($conn, 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum WHERE user_id = ? ORDER BY id DESC');
        if ($curriculumStmt) {
            mysqli_stmt_bind_param($curriculumStmt, 'i', $userId);
            mysqli_stmt_execute($curriculumStmt);
            $curriculumResult = mysqli_stmt_get_result($curriculumStmt);
            while ($row = mysqli_fetch_assoc($curriculumResult)) {
                $curriculumRows[] = $row;
            }
            mysqli_stmt_close($curriculumStmt);
        }

        $subjectStmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects WHERE user_id = ? ORDER BY college ASC, department ASC, level_num ASC, subject_name ASC');
        if ($subjectStmt) {
            mysqli_stmt_bind_param($subjectStmt, 'i', $userId);
            mysqli_stmt_execute($subjectStmt);
            $subjectResult = mysqli_stmt_get_result($subjectStmt);
            while ($row = mysqli_fetch_assoc($subjectResult)) {
                $subjectRows[] = $row;
            }
            mysqli_stmt_close($subjectStmt);
        }

        $departmentStmt = mysqli_prepare($conn, 'SELECT college, department, COUNT(*) AS cnt FROM subjects WHERE user_id = ? GROUP BY college, department ORDER BY college, department');
        if ($departmentStmt) {
            mysqli_stmt_bind_param($departmentStmt, 'i', $userId);
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

if ($conn && $userId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && $canManageCurriculum) {
    $action = isset($_POST['action']) && $_POST['action'] === 'delete' ? 'delete' : 'save';
    if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $college = trim((string) ($_POST['college'] ?? ''));
        if ($college === '') {
            $_POST['college'] = $accessCollegeName;
        } else {
            $_POST['college'] = $accessCollegeName;
        }
    }
    $curriculumId = (int) ($_POST['curriculum_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $academicPath = trim((string) ($_POST['academic_path'] ?? ''));
    $college = trim((string) ($_POST['college'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $credits = isset($_POST['credits']) && $_POST['credits'] !== '' ? (int) $_POST['credits'] : 0;
    $studyLevel = trim((string) ($_POST['study_level'] ?? ''));
    $objectives = trim((string) ($_POST['objectives'] ?? ''));

    if ($action === 'delete' && $curriculumId > 0) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM curriculum WHERE id = ? AND user_id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $curriculumId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم حذف العنصر من المسار الأكاديمي بنجاح.'];
    } elseif ($title !== '') {
        if ($curriculumId > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE curriculum SET title = ?, description = ?, academic_path = ?, college = ?, department = ?, semester = ?, credits = ?, study_level = ?, objectives = ? WHERE id = ? AND user_id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssssssissii', $title, $description, $academicPath, $college, $department, $semester, $credits, $studyLevel, $objectives, $curriculumId, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم تحديث عنصر المسار الأكاديمي بنجاح.'];
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO curriculum (user_id, title, description, academic_path, college, department, semester, credits, study_level, objectives) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'issssssiss', $userId, $title, $description, $academicPath, $college, $department, $semester, $credits, $studyLevel, $objectives);
                mysqli_stmt_execute($stmt);
                $newCurriculumId = (int) $conn->insert_id;
                mysqli_stmt_close($stmt);

                if ($newCurriculumId > 0) {
                    $accessStmt = mysqli_prepare($conn, 'INSERT IGNORE INTO curriculum_access (user_id, curriculum_id) VALUES (?, ?)');
                    if ($accessStmt) {
                        mysqli_stmt_bind_param($accessStmt, 'ii', $userId, $newCurriculumId);
                        mysqli_stmt_execute($accessStmt);
                        mysqli_stmt_close($accessStmt);
                    }
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إضافة عنصر جديد إلى المسار الأكاديمي بنجاح.'];
        }
    }

    header('Location: curriculum.php');
    exit();
}

$rows = [];
if ($conn) {
    $whereClauses = [];
    $bindTypes = '';
    $bindParams = [];

    if ($effectiveRole === 'super_admin') {
        $query = 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum';
    } elseif ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
        $whereClauses[] = 'COALESCE(college, "") = ?';
        $bindTypes .= 's';
        $bindParams[] = $accessCollegeName;
        $query = 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum';
    } else {
        $whereClauses[] = 'user_id = ?';
        $bindTypes .= 'i';
        $bindParams[] = $userId;
        $query = 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum';
    }

    if ($selectedCollege !== '') {
        $whereClauses[] = 'COALESCE(college, "") = ?';
        $bindTypes .= 's';
        $bindParams[] = $selectedCollege;
    }

    if ($selectedDepartment !== '') {
        $whereClauses[] = 'COALESCE(department, "") = ?';
        $bindTypes .= 's';
        $bindParams[] = $selectedDepartment;
    }

    if (!empty($whereClauses)) {
        $query .= ' WHERE ' . implode(' AND ', $whereClauses);
    }

    $query .= ' ORDER BY semester, title';

    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if ($bindTypes !== '') {
            $bindValues = [$bindTypes];
            foreach ($bindParams as &$bindValue) {
                $bindValues[] = &$bindValue;
            }
            unset($bindValue);
            call_user_func_array([$stmt, 'bind_param'], $bindValues);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'academic_path' => $row['academic_path'],
                'college' => $row['college'],
                'department' => $row['department'],
                'semester' => $row['semester'],
                'credits' => (int) $row['credits'],
                'study_level' => $row['study_level'],
                'objectives' => $row['objectives'],
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

include __DIR__ . '/templates/curriculum_view.php';
