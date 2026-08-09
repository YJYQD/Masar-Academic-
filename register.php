<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';

$basePath = '';

if (empty($_SESSION['csrf_token'])) { 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}

if (!function_exists('e')) {
    function e($v){ 
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(){ 
        return '<input type="hidden" name="csrf_token" value="'.e($_SESSION['csrf_token']).'">'; 
    }
}

// التقاط رسائل التنبيه والخطأ من الجلسة ومسحها فوراً لمنع تكرارها عند التحديث
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <title>إنشاء حساب جديد - منصة مسار الأكاديمية</title>
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-hero">
        <div class="brand-pill">منصة مسار</div>
        <h1>أنشئ حسابك الآن</h1>
        <p>ابدأ بإنشاء حسابك لتتمكن من إضافة تقييماتك، وطلب الدكاترة، ومتابعة نشاطك داخل المنصة.</p>
        <ul class="benefit-list">
            <li>تسجيل سريع ومباشر</li>
            <li>دخول باسم المستخدم وكلمة المرور فقط</li>
            <li>الوصول إلى الجدول، المسار الأكاديمي، وسجل الحضور</li>
        </ul>
    </section>

    <section class="auth-card">
        <div class="auth-card__header">
            <h2>إنشاء حساب جديد</h2>
            <p>استخدم بياناتك الجامعية بشكل صحيح</p>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash--<?php echo e($flash['type']); ?>">
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/save_register.php" class="auth-form-grid">
            <?php echo csrf_field(); ?>
            <label>
                اسم المستخدم
                <input type="text" name="username" placeholder="اختر اسم مستخدم" required>
            </label>
            <label>
                كلمة المرور
                <input type="password" name="password" placeholder="اكتب كلمة مرور قوية" required>
            </label>
            <label>
                تأكيد كلمة المرور
                <input type="password" name="password2" placeholder="أعد كتابة كلمة المرور" required>
            </label>
            <button type="submit" class="btn btn--primary full-width">إنشاء الحساب</button>
        </form>
        <p class="auth-footer">لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a></p>
    </section>
</main>
</body>
</html>