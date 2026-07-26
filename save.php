<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit();
}

if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'عذراً، يجب تسجيل الدخول أولاً للقيام بهذه العملية.'];
    header('Location: login');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'فشل التحقق الأمني، أعد تحميل الصفحة وحاول مرة أخرى.'];
    header('Location: index');
    exit();
}

function save_column_exists($conn, $table, $column) {
    $stmt = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $dbName = defined('DB_NAME') ? DB_NAME : '';
    mysqli_stmt_bind_param($stmt, 'sss', $dbName, $table, $column);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $exists;
}

function bind_values($stmt, $types, $values) {
    $params = [$stmt, $types];
    foreach ($values as $index => $value) {
        $params[] = &$values[$index];
    }
    call_user_func_array('mysqli_stmt_bind_param', $params);
}

function review_ip_hash(string $ip_address): string
{
    return hash('sha256', $ip_address);
}

function browser_fingerprint_hash(string $payload): string
{
    return hash('sha256', $payload);
}

function review_lock_cookie_name(int $doctor_id): string
{
    return 'review_lock_' . $doctor_id;
}

function has_recent_review(mysqli $conn, int $doctor_id, string $ip_address): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT created_at FROM reviews WHERE doctor_id = ? AND ip_address = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'is', $doctor_id, $ip_address);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$row || empty($row['created_at'])) {
        return false;
    }

    return (time() - strtotime($row['created_at'])) < 86400;
}

function has_recent_review_lock(mysqli $conn, int $doctor_id, ?string $fingerprint_hash, string $ip_address): bool
{
    $ip_hash = review_ip_hash($ip_address);
    $fingerprint_hash = $fingerprint_hash ?: '';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM review_locks
         WHERE doctor_id = ?
           AND expires_at > NOW()
           AND (fingerprint_hash = ? OR ip_hash = ?)
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'iss', $doctor_id, $fingerprint_hash, $ip_hash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $locked = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $locked;
}

function create_review_lock(mysqli $conn, int $doctor_id, ?string $fingerprint_hash, string $ip_address): void
{
    $ip_hash = review_ip_hash($ip_address);
    $fingerprint_hash = $fingerprint_hash ?: null;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO review_locks (doctor_id, fingerprint_hash, ip_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iss', $doctor_id, $fingerprint_hash, $ip_hash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

$reviewAction = trim((string) ($_POST['review_action'] ?? ''));
if (!empty($_POST['add_review']) || $reviewAction === 'add_review') {
    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $course_code = trim((string) ($_POST['course_code'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $reviewer = trim((string) ($_SESSION['user_name'] ?? ''));
    $is_anonymous = !empty($_POST['is_anonymous']) ? 1 : 0;
    $consent = !empty($_POST['integrity_consent']);
    $teaching = (int) ($_POST['teaching_quality'] ?? 0);
    $fairness = (int) ($_POST['fairness'] ?? 0);
    $communication = (int) ($_POST['communication'] ?? 0);
    $fingerprint = trim((string) ($_POST['browser_fingerprint'] ?? ''));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $sentiment = 'neutral';

    $dimensionRatings = [$teaching, $fairness, $communication];
    $dimensionCount = count(array_filter($dimensionRatings, static fn ($value) => $value > 0));
    $rating = $dimensionCount > 0 ? (int) round(array_sum($dimensionRatings) / $dimensionCount) : 0;

    if ($doc_id <= 0 || !$consent || $course_code === '' || $semester === '' || $dimensionCount < 3 || $rating < 1 || $rating > 5) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'الرجاء اختيار تقييمات الثلاثة الأبعاد والموافقة على المعلومة قبل الإرسال مع المادة والفصل الدراسي.'];
        header('Location: index');
        exit();
    }

    if ($reviewer === '') {
        $reviewer = 'طالب';
    }

    if (has_recent_review($conn, $doc_id, $ip_address)) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تم تسجيل تقييم لهذا الدكتور من نفس الجهاز خلال آخر 24 ساعة.'];
        header('Location: index');
        exit();
    }

    $fingerprintHash = $fingerprint !== '' ? browser_fingerprint_hash($fingerprint) : null;
    if (has_recent_review_lock($conn, $doc_id, $fingerprintHash, $ip_address)) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تم تسجيل تقييم لهذا الدكتور من هذا المتصفح خلال آخر 24 ساعة.'];
        header('Location: index');
        exit();
    }

    if (!empty($_SESSION['review_locks'][$doc_id]) && (time() - (int) $_SESSION['review_locks'][$doc_id]) < 86400) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تم تسجيل تقييم لهذا الدكتور من هذه الجلسة خلال آخر 24 ساعة.'];
        header('Location: index');
        exit();
    }

    $cookieLockName = review_lock_cookie_name($doc_id);
    if (!empty($_COOKIE[$cookieLockName]) && (time() - (int) $_COOKIE[$cookieLockName]) < 86400) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تم تسجيل تقييم لهذا الدكتور من هذا المتصفح خلال آخر 24 ساعة.'];
        header('Location: index');
        exit();
    }

    $hasUserIdColumn = save_column_exists($conn, 'reviews', 'user_id');
    $hasAnonymousColumn = save_column_exists($conn, 'reviews', 'is_anonymous');
    $hasExplanationStars = save_column_exists($conn, 'reviews', 'explanation_stars');
    $hasHandlingStars = save_column_exists($conn, 'reviews', 'handling_stars');
    $hasGradingStars = save_column_exists($conn, 'reviews', 'grading_stars');

    $columns = ['doctor_id', 'rating', 'comment', 'reviewer_name', 'course_code', 'semester', 'ip_address', 'sentiment', 'status'];
    $types = 'iisssssss';
    $values = [$doc_id, $rating, $comment, $reviewer, $course_code, $semester, $ip_address, $sentiment, 'approved'];

    if ($hasUserIdColumn && !empty($_SESSION['user_id'])) {
        $columns[] = 'user_id';
        $types .= 'i';
        $values[] = (int) $_SESSION['user_id'];
    }

    if ($hasAnonymousColumn) {
        $columns[] = 'is_anonymous';
        $types .= 'i';
        $values[] = $is_anonymous;
    }

    if ($hasExplanationStars) {
        $columns[] = 'explanation_stars';
        $types .= 'i';
        $values[] = $teaching;
    }

    if ($hasHandlingStars) {
        $columns[] = 'handling_stars';
        $types .= 'i';
        $values[] = $fairness;
    }

    if ($hasGradingStars) {
        $columns[] = 'grading_stars';
        $types .= 'i';
        $values[] = $communication;
    }

    // Ensure $values array has correct count and order for bind_values()
    $values = array_values($values);

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO reviews (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        bind_values($stmt, $types, $values);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إرسال التقييم بنجاح وظهر على الموقع!'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'حدث خطأ أثناء حفظ التقييم.'];
        }
        mysqli_stmt_close($stmt);
    }

    $_SESSION['review_locks'][$doc_id] = time();
    setcookie($cookieLockName, (string) time(), [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    create_review_lock($conn, $doc_id, $fingerprintHash, $ip_address);

    header('Location: index');
    exit();
}

$name = trim($_POST['doc_name'] ?? '');
$college = trim($_POST['college'] ?? '');
$department = trim($_POST['department'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$subjects = trim($_POST['subjects'] ?? '');
$subjectIds = isset($_POST['subject_ids']) && is_array($_POST['subject_ids']) ? array_map('intval', $_POST['subject_ids']) : [];
$returnTo = trim($_POST['return_to'] ?? 'index.php');

if ($name === '' || $college === '' || $department === '' || $gender === '') {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'الرجاء إدخال اسم الدكتور والكلية والقسم والنوع.'];
    header('Location: ' . $returnTo);
    exit();
}

if (!empty($_POST['doc_id'])) {
    if (empty($_SESSION['is_admin'])) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'غير مسموح: هذه العملية خاصة بالمشرفين فقط.'];
        header('Location: index');
        exit();
    }

    $doc_id = (int) $_POST['doc_id'];
    $stmt = mysqli_prepare($conn, 'UPDATE doctors SET name = ?, college = ?, department = ?, gender = ?, courses = ? WHERE id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssssi', $name, $college, $department, $gender, $subjects, $doc_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    $del = mysqli_prepare($conn, 'DELETE FROM doctor_subject WHERE doctor_id = ?');
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $doc_id);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }

    if (!empty($subjectIds)) {
        $insDs = mysqli_prepare($conn, 'INSERT IGNORE INTO doctor_subject (doctor_id, subject_id) VALUES (?, ?)');
        if ($insDs) {
            foreach ($subjectIds as $sid) {
                mysqli_stmt_bind_param($insDs, 'ii', $doc_id, $sid);
                mysqli_stmt_execute($insDs);
            }
            mysqli_stmt_close($insDs);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم حفظ التعديلات بنجاح.'];
    header('Location: ' . ($_POST['return_to'] ?? 'admin?section=doctors'));
    exit();
}

$normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
$foundId = null;
$chk = mysqli_prepare($conn, 'SELECT id, courses, department FROM doctors WHERE LOWER(TRIM(REPLACE(name, "\r\n", " "))) = ? AND college = ? LIMIT 1');
if ($chk) {
    mysqli_stmt_bind_param($chk, 'ss', $normalized, $college);
    mysqli_stmt_execute($chk);
    $resChk = mysqli_stmt_get_result($chk);
    $rchk = mysqli_fetch_assoc($resChk);
    if ($rchk) {
        $foundId = (int) $rchk['id'];
        $existingDepartment = trim((string) ($rchk['department'] ?? ''));
        $existingCourses = trim((string) ($rchk['courses'] ?? ''));
    }
    mysqli_stmt_close($chk);
}

if ($foundId) {
    $doc_id = $foundId;
    if (!isset($existingDepartment)) {
        $existingDepartment = '';
    }
    if (!isset($existingCourses)) {
        $existingCourses = '';
    }

    $courseParts = array_filter(array_map('trim', array_merge(explode(',', (string) $existingCourses), explode(',', (string) $subjects))));
    $courseParts = array_values(array_unique($courseParts));
    $finalCourses = implode(', ', $courseParts);

    $up = mysqli_prepare($conn, 'UPDATE doctors SET courses = ?, is_approved = 0 WHERE id = ?');
    if ($up) {
        mysqli_stmt_bind_param($up, 'si', $finalCourses, $doc_id);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    if (!empty($subjectIds)) {
        $insDs = mysqli_prepare($conn, 'INSERT IGNORE INTO doctor_subject (doctor_id, subject_id) VALUES (?, ?)');
        if ($insDs) {
            foreach ($subjectIds as $sid) {
                mysqli_stmt_bind_param($insDs, 'ii', $doc_id, $sid);
                mysqli_stmt_execute($insDs);
            }
            mysqli_stmt_close($insDs);
        }
    }

    $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم دمج المادة مع ملف الدكتور الموجود بالفعل ولم يُنشأ سجل مكرر.'];
} else {
    $hasSuggestedColumn = false;
    $colStmt = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "doctors" AND COLUMN_NAME = "suggested_by_user_id" LIMIT 1');
    if ($colStmt) {
        $dbName = defined('DB_NAME') ? DB_NAME : '';
        mysqli_stmt_bind_param($colStmt, 's', $dbName);
        if (mysqli_stmt_execute($colStmt)) {
            $colRes = mysqli_stmt_get_result($colStmt);
            if (mysqli_fetch_assoc($colRes)) {
                $hasSuggestedColumn = true;
            }
        }
        mysqli_stmt_close($colStmt);
    }
    
    if ($hasSuggestedColumn && !empty($_SESSION['user_id'])) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved, suggested_by_user_id) VALUES (?, ?, ?, ?, ?, 0, ?)');
        $userId = (int) $_SESSION['user_id'];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssssi', $name, $college, $department, $gender, $subjects, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $doc_id = mysqli_insert_id($conn);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إرسال الطلب بنجاح! سيظهر في الموقع بعد مراجعة المسؤول.'];
            } else {
                if (function_exists('log_error')) {
                    log_error('doctor insert failed: ' . mysqli_stmt_error($stmt));
                }
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'حدث خطأ أثناء إرسال الطلب، يرجى المحاولة لاحقًا.'];
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, 0)');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssss', $name, $college, $department, $gender, $subjects);
            if (mysqli_stmt_execute($stmt)) {
                $doc_id = mysqli_insert_id($conn);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إرسال الطلب بنجاح! سيظهر في الموقع بعد مراجعة المسؤول.'];
            } else {
                if (function_exists('log_error')) {
                    log_error('doctor insert failed: ' . mysqli_stmt_error($stmt));
                }
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'حدث خطأ أثناء إرسال الطلب، يرجى المحاولة لاحقًا.'];
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (!empty($doc_id) && !empty($subjectIds)) {
        $insDs = mysqli_prepare($conn, 'INSERT IGNORE INTO doctor_subject (doctor_id, subject_id) VALUES (?, ?)');
        if ($insDs) {
            foreach ($subjectIds as $sid) {
                mysqli_stmt_bind_param($insDs, 'ii', $doc_id, $sid);
                @mysqli_stmt_execute($insDs);
            }
            mysqli_stmt_close($insDs);
        }
    }
}

header('Location: ' . $returnTo);
exit();