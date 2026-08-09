<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/auth_guard.php';
require_once __DIR__ . '/inc/time_helpers.php';
require_once __DIR__ . '/inc/telegram_helpers.php';
require_once __DIR__ . '/inc/schedule_access.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

restrict_to_logged_in_users('/login.php');
$userId = current_authenticated_user_id();

function resolve_schedule_access_context(): array
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

$accessContext = resolve_schedule_access_context();
$effectiveRole = $accessContext['role'];
$accessCollegeName = $accessContext['college_name'];
$canManageSchedule = can_manage_schedule($userId, $_SESSION ?? []);

if ($userId <= 0) {
    http_response_code(403);
    header('Location: index.php?error=unauthorized');
    exit();
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Riyadh');
}
date_default_timezone_set(APP_TIMEZONE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_to_schedule']) || isset($_POST['schedule_action']))) {
    if (!$canManageSchedule) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'ليس لديك صلاحية تعديل الجدول الدراسي.'];
        header('Location: /schedule.php');
        exit();
    }

    $scheduleAction = trim((string) ($_POST['schedule_action'] ?? ''));
    if ($scheduleAction === '') {
        $scheduleAction = 'create';
    }

    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $course_code = trim((string) ($_POST['course_code'] ?? ''));
    $day_of_week = isset($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : 0;
    $start_time = trim((string) ($_POST['start_time'] ?? ''));
    $end_time = trim((string) ($_POST['end_time'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($scheduleAction === 'delete' && $scheduleId > 0) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM schedules WHERE id = ? AND user_id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $scheduleId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم حذف المقرر من الجدول بنجاح.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'تعذر حذف المقرر من الجدول.'];
        }
    } elseif ($scheduleAction === 'update' && $scheduleId > 0) {
        if ($title !== '' && $start_time !== '' && $end_time !== '') {
            $stmt = mysqli_prepare($conn, 'UPDATE schedules SET title = ?, course_code = ?, day_of_week = ?, start_time = ?, end_time = ?, location = ?, notes = ? WHERE id = ? AND user_id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssissssii', $title, $course_code, $day_of_week, $start_time, $end_time, $location, $notes, $scheduleId, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم تعديل المقرر في الجدول بنجاح.'];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'تعذر تعديل المقرر في الجدول.'];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'يرجى تعبئة عنوان المقرر والوقت.'];
        }
    } else {
        if ($title !== '' && $start_time !== '' && $end_time !== '') {
            $stmt = mysqli_prepare($conn, 'INSERT INTO schedules (user_id, title, course_code, day_of_week, start_time, end_time, location, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ississss', $userId, $title, $course_code, $day_of_week, $start_time, $end_time, $location, $notes);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'تمت إضافة المقرر إلى الجدول بنجاح.'];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'تعذر إضافة المقرر إلى الجدول.'];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'يرجى تعبئة عنوان المقرر والوقت.'];
        }
    }

    header('Location: /schedule.php');
    exit();
}

$timezone = normalize_timezone($_GET['timezone'] ?? APP_TIMEZONE);

date_default_timezone_set($timezone);

$today = date('Y-m-d');
$weekdays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$days = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime($today . ' +' . $i . ' days'));
    $dayIndex = (int) date('w', strtotime($date));
    $days[] = [
        'label' => $weekdays[$dayIndex],
        'date' => $date,
        'day_index' => $dayIndex,
    ];
}

$rows = [];
if ($conn) {
    $hasUserColumn = false;
    $columnCheck = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "schedules" AND COLUMN_NAME = "user_id" LIMIT 1');
    if ($columnCheck) {
        $dbName = defined('DB_NAME') ? DB_NAME : '';
        mysqli_stmt_bind_param($columnCheck, 's', $dbName);
        mysqli_stmt_execute($columnCheck);
        $columnResult = mysqli_stmt_get_result($columnCheck);
        $hasUserColumn = (bool) ($columnResult ? mysqli_fetch_assoc($columnResult) : null);
        mysqli_stmt_close($columnCheck);
    }

    $sql = 'SELECT id, title, course_code, day_of_week, start_time, end_time, location, notes FROM schedules';
    if ($hasUserColumn && $userId > 0) {
        $sql .= ' WHERE user_id = ?';
    }
    $sql .= ' ORDER BY day_of_week, start_time';

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($hasUserColumn && $userId > 0) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'course_code' => $row['course_code'],
                'day_of_week' => (int) $row['day_of_week'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'location' => $row['location'],
                'notes' => $row['notes'],
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

$byDay = [];
foreach ($rows as $row) {
    $byDay[(int) $row['day_of_week']][] = $row;
}

include __DIR__ . '/templates/schedule_view.php';
