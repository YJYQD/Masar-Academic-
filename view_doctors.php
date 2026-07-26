<?php
require_once __DIR__ . '/db.php';
// تأكد من تشغيل الجلسة إذا أردت عرض حالة تسجيل الدخول في الهيدر
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// استخدام استعلام آمن ومباشر
// Fetch approved doctors via prepared statement
$stmt = mysqli_prepare($conn, "SELECT id, name, college, department, courses FROM doctors WHERE is_approved = 1 ORDER BY name ASC");
$doctors = [];
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $doctors[] = $row;
    }
    mysqli_stmt_close($stmt);
} else {
    if (function_exists('log_error')) log_error('prepare failed in view_doctors.php');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الدكاترة المعتمدون - منصة مسار الأكاديمية</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="page">
    <section class="panel">
        <h1>قائمة الدكاترة المعتمدين</h1>
        <?php if (count($doctors) > 0): ?>
            <div class="cards">
                <?php foreach ($doctors as $row): ?>
                    <article class="doctor-card">
                        <div class="doctor-name"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="doctor-info">
                            <strong>الكلية:</strong> <?php echo htmlspecialchars($row['college'], ENT_QUOTES, 'UTF-8'); ?> <br>
                            <strong>القسم:</strong> <?php echo htmlspecialchars($row['department'], ENT_QUOTES, 'UTF-8'); ?> <br>
                            <strong>المواد:</strong> <?php echo htmlspecialchars($row['courses'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <a href="index?doc_id=<?php echo (int)$row['id']; ?>" class="btn btn--accent" style="margin-top:10px; display:inline-block;">عرض التقييمات</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style='text-align:center;'>لا يوجد دكاترة معتمدين حالياً.</p>
        <?php endif; ?>
        
        <hr style="margin: 20px 0;">
        <p style="text-align: center;"><a href="index.php">العودة للرئيسية</a></p>
    </section>
</main>
</body>
</html>