<?php
require_once __DIR__ . '/../inc/session_secure.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$results = [];

if (isset($_GET['all']) && $_GET['all'] == '1') {
    $stmt = mysqli_prepare($conn, 'SELECT id, username, email FROM users ORDER BY username ASC LIMIT 200');
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) {
            $results[] = ['id' => (int) $r['id'], 'username' => $r['username'], 'email' => $r['email']];
        }
        mysqli_stmt_close($stmt);
    }
    echo json_encode($results);
    exit();
}

if ($q === '') {
    echo json_encode([]);
    exit();
}

$prefix = str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

$stmt = mysqli_prepare($conn, 'SELECT id, username, email FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 10');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ss', $prefix, $prefix);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) {
        $results[] = ['id' => (int) $r['id'], 'username' => $r['username'], 'email' => $r['email']];
    }
    mysqli_stmt_close($stmt);
}

echo json_encode($results);
