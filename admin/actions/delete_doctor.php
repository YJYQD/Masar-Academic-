<?php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

validate_csrf();

$doctor_id = (int)($_POST['doctor_id'] ?? 0);

if ($doctor_id <= 0) {

    flash_error('معرف غير صالح');

    header('Location: /admin?section=doctors');
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "DELETE FROM doctors WHERE id = ?"
);

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $doctor_id
);

if (mysqli_stmt_execute($stmt)) {

    flash_success('تم حذف الدكتور');

} else {

    flash_error('فشل الحذف');
}

mysqli_stmt_close($stmt);

header('Location: /admin?section=doctors');
exit();