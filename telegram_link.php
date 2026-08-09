<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/inc/flash.php';
require_once __DIR__ . '/inc/telegram_helpers.php';
require_once __DIR__ . '/config.php';

if (!($conn instanceof mysqli)) {
    http_response_code(200);
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>الربط غير متاح</title></head><body style="font-family:Tahoma,Arial,sans-serif;padding:24px;direction:rtl;text-align:right;"><h2>الربط غير متاح مؤقتاً</h2><p>تعذر الوصول إلى قاعدة البيانات حالياً، لذلك لا يمكن إكمال ربط التليجرام الآن.</p><p>يرجى المحاولة لاحقاً أو التأكد من تشغيل قاعدة البيانات.</p></body></html>';
    exit();
}

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$botLink = 'https://t.me/' . ltrim((string) TELEGRAM_BOT_USERNAME, '@');
if (empty(TELEGRAM_BOT_USERNAME)) {
    $botLink = 'https://t.me/YJYQD_bot';
}
$accountType = !empty($_SESSION['is_admin']) ? 'admin' : 'user';
$sessionAdminId = (int) ($_SESSION['admin_id'] ?? 0);
$targetId = $accountType === 'admin' && $sessionAdminId > 0 ? $sessionAdminId : $userId;
$payload = $accountType === 'admin' ? 'admin:' . $targetId : 'user:' . $userId;
$botStartLink = $botLink . '?start=' . urlencode($payload);
$botStartLink = rtrim($botStartLink, '?');
$telegramUsername = trim((string) ($_GET['telegram_username'] ?? ''));
$telegramUserId = trim((string) ($_GET['telegram_user_id'] ?? ''));
$bindingStatus = $telegramUsername !== '' || $telegramUserId !== '' ? 'linked' : 'pending';
if ($bindingStatus === 'linked' && $telegramUsername !== '') {
    $_SESSION['telegram_username'] = $telegramUsername;
}
$bindCode = isset($_SESSION['telegram_bind_code']) ? (string) $_SESSION['telegram_bind_code'] : generate_telegram_link_code(6);
$_SESSION['telegram_bind_code'] = $bindCode;

if ($userId > 0) {
    $codeStmt = $conn->prepare('UPDATE users SET telegram_bind_code = ? WHERE id = ?');
    if ($codeStmt) {
        $codeStmt->bind_param('si', $bindCode, $userId);
        $codeStmt->execute();
        $codeStmt->close();
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
    <title>ربط التليجرام</title>
</head>
<body>
<main class="page">
    <section class="panel">
        <h2>ربط حسابك مع البوت</h2>
        <p>لإرسال تنبيهات الحضور قبل الكسل ب 10 دقائق، يجب ربط حسابك مع البوت التالي.</p>
        <?php if ($flash): ?>
            <div class="flash flash--<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
        <?php endif; ?>
        <?php if ($bindingStatus === 'linked'): ?>
            <div class="doctor-card" style="margin-bottom:12px;">
                <strong>تم ربط الحساب بنجاح.</strong>
                <div>اسم المستخدم: <?= htmlspecialchars($telegramUsername !== '' ? $telegramUsername : $telegramUserId) ?></div>
            </div>
        <?php else: ?>
            <div class="doctor-card" style="margin-bottom:12px;">
                <strong>يرجى إكمال الربط من البوت.</strong>
                <div>بعد الضغط على الزر أدناه، سيُرسل البوت رسالة تأكيد عند إتمام الربط.</div>
            </div>
        <?php endif; ?>
        <div class="doctor-card" style="margin-bottom:12px; background:#0f172a; border:1px solid #2563eb;">
            <strong>طريقة ربط أسهل:</strong>
            <p style="margin:8px 0 0;">اكتب هذا الكود داخل البوت:</p>
            <div style="font-size:28px; font-weight:800; letter-spacing:3px; margin-top:8px; color:#38bdf8;"><?= htmlspecialchars($bindCode) ?></div>
            <p style="margin:8px 0 0;">ثم اضغط Start في البوت وبعدها استخدم زر التأكيد أدناه.</p>
        </div>
        <p><a class="btn btn--primary" href="<?= htmlspecialchars($botStartLink) ?>" target="_blank" rel="noopener">افتح البوت وربط الحساب</a></p>
        <p>هذه الطريقة أسهل من استخدام روابط معقدة أو معرفات طويلة، لكنها تعتمد على تفعيل البوت على الخادم. إذا لم تظهر الرسالة أو لم يتم الربط، فهذا يعني أن البوت أو الويبهوك غير متاحين حالياً.</p>
        <form method="post" action="telegram_link_save.php">
            <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
            <input type="hidden" name="telegram_chat_id" value="<?= htmlspecialchars($telegramUserId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="telegram_username" value="<?= htmlspecialchars($telegramUsername, ENT_QUOTES, 'UTF-8') ?>">
            <button class="btn btn--accent" type="submit">تأكيد الربط</button>
        </form>
        <p style="margin-top:12px;"><a href="index.php">العودة للقائمة الرئيسية</a></p>
    </section>
</main>
</body>
</html>
