<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/auth_guard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

restrict_to_logged_in_users('login.php');

$userId = current_authenticated_user_id();
if ($userId <= 0) {
    http_response_code(403);
    header('Location: index.php?error=unauthorized');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: attendance.php');
    exit();
}

$rows = [];
if ($conn) {
    $userColumn = resolve_attendance_user_column($conn);

    $sql = 'SELECT id, course_code, status, created_at FROM attendance_log';
    if ($userId > 0) {
        $sql .= ' WHERE ' . $userColumn . ' = ?';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($userId > 0) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

include __DIR__ . '/templates/attendance_view.php';
