<?php
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';
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

// التقاط رسائل التنبيه والخطأ من الجلسة ومسحها فوراً لكي لا تتكرر عند التحديث
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$loginState = $_GET['login'] ?? '';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <title>تسجيل الدخول - منصة مسار الأكاديمية</title>
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-hero">
        <div class="brand-pill">منصة مسار</div>
        <h1>منصة مسار الأكاديمية</h1>
        <p>المنظومة السحابية الذكية للإرشاد الأكاديمي والتقييم الجامعي.</p>
        <ul class="benefit-list">
            <li>تسجيل دخول آمن </li>
            <li>مراجعة دكاترة معتمدة ومقترحات الطلاب</li>
            <li>وصول سريع إلى الخطة الدراسية والملف الشخصي</li>
        </ul>
    </section>

    <section class="auth-card">
        <div class="auth-card__header">
            <h2>تسجيل الدخول</h2>
            <p>أدخل بياناتك للوصول إلى حسابك</p>
        </div>

        <?php if ($flash): ?>
            <div class="flash flash--<?php echo e($flash['type']); ?>">
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <?php if ($loginState === 'failed'): ?>
            <div class="flash flash--error">اسم المستخدم أو كلمة المرور غير صحيحة، أو أن الحساب غير موجود في قاعدة البيانات الحالية.</div>
        <?php elseif ($loginState === 'csrf_session'): ?>
            <div class="flash flash--error">المتصفح لا يحتفظ بجلسة تسجيل الدخول. فعّل الكوكيز أو امسح البيانات المؤقتة ثم أعد المحاولة.</div>
        <?php elseif ($loginState === 'csrf_token'): ?>
            <div class="flash flash--error">حدث خلل في إرسال نموذج تسجيل الدخول. أعد تحميل الصفحة ثم حاول مرة أخرى.</div>
        <?php elseif ($loginState === 'csrf_mismatch' || $loginState === 'method'): ?>
            <div class="flash flash--error">انتهت صلاحية الجلسة الأمنية. أعد تحميل صفحة الدخول ثم حاول مرة أخرى.</div>
        <?php elseif ($loginState === 'empty'): ?>
            <div class="flash flash--error">يرجى كتابة اسم المستخدم وكلمة المرور كاملة.</div>
        <?php endif; ?>

        <form method="POST" action="/login_check.php" class="auth-form-grid" autocomplete="on" novalidate>
            <?php echo csrf_field(); ?>
            <label>
                اسم المستخدم
                <input type="text" name="identity" placeholder="اسم المستخدم أو الأدمن" required>
            </label>
            <label>
                كلمة المرور
                <input type="password" name="password" placeholder="كلمة المرور الخاصة بك" required>
            </label>
            <button type="submit" class="btn btn--primary full-width">دخول</button>
        </form>
        <p class="auth-footer">لا تملك حساب؟ <a href="/register.php">إنشاء حساب جديد</a></p>
        <p class="auth-footer"><a href="/index.php">العودة إلى القائمة الرئيسية</a></p>
    </section>
</main>
</body>
</html>