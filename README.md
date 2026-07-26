<?php
// تأمين ضبط ملفات الكوكي للمشروع بالكامل لمنع انفصال الجلسة والطرد
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/', 
        'domain' => '',
        'secure' => false, 
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// الاتصال بالداتابيز
include 'db.php';

// جدار الحماية المشدد للوصول المطلق لصفحة الدخول دون انكسار مسارات
if (empty($_SESSION['is_admin']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// إعدادات الأمان وتوليد الرمز تلقائياً في قمة الملف
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- الدوال المساعدة ونظام رسائل الـ Flash ---
if (!function_exists('e')) {
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string { return (string) ($_SESSION['csrf_token'] ?? ''); }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
}
if (!function_exists('client_ip')) {
    function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
}
if (!function_exists('flash_success')) {
    function flash_success(string $msg): void { $_SESSION['flash'] = ['type' => 'success', 'text' => $msg]; }
}
if (!function_exists('flash_error')) {
   
}
    function flash_error(string $msg): void { $_SESSION['flash'] = ['type' => 'danger', 'text' => $msg]; }


$admin_college = $_SESSION['admin_college'] ?? null;
$current_section = $_GET['section'] ?? 'stats';

// --- معالجة طلبات POST الحية ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $postedToken)) {
        flash_error('طلب غير صالح (CSRF)');
        header("Location: admin.php?section=" . urlencode($current_section));
        exit();
    }

    // 1️⃣ معالجة إضافة دكتور جديد
    if (isset($_POST['add_doc'])) {
        $name = trim($_POST['doc_name'] ?? '');
        $coll = trim($_POST['college'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $gend = trim($_POST['gender'] ?? '');
        $subj = trim($_POST['subjects'] ?? '');

        if ($name !== '' && $coll !== '' && $dept !== '') {
            $stmt = mysqli_prepare($conn, "INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, 1)");
            mysqli_stmt_bind_param($stmt, 'sssss', $name, $coll, $dept, $gend, $subj);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'add_doctor', 'doctor', mysqli_insert_id($conn), ['name' => $name, 'college' => $coll]);
                flash_success('تم إضافة الدكتور المعتمد بنجاح.');
            } else {
                flash_error('حدث خطأ أثناء إضافة الدكتور.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=doctors");
        exit();
    }

    // 2️⃣ معالجة إضافة مشرف كليات جديد
    if (isset($_POST['add_admin'])) {
        $new_user = trim($_POST['new_admin_username'] ?? '');
        $new_pass = $_POST['new_admin_password'] ?? '';
        $new_coll = trim($_POST['new_admin_college'] ?? '');

        if ($new_user !== '' && $new_pass !== '') {
            $hash_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO admins (username, college_responsibility, password_hash) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sss', $new_user, $new_coll, $hash_pass);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'add_admin', 'admin', mysqli_insert_id($conn), ['username' => $new_user, 'college' => $new_coll]);
                flash_success('تم إضافة المشرف الجديد بنجاح.');
            } else {
                flash_error('اسم المشرف محجوز مسبقاً.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=supervision");
        exit();
    }

    // 3️⃣ معالجة تحديث صلاحية/مسؤولية مشرف حالي
    if (isset($_POST['update_admin_college'])) {
        $edit_admin_id = (int)($_POST['edit_admin_id'] ?? 0);
        $edit_college = trim($_POST['edit_college'] ?? '');

        if ($edit_admin_id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE admins SET college_responsibility = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $edit_college, $edit_admin_id);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'update_admin_permission', 'admin', $edit_admin_id, ['new_college' => $edit_college]);
                flash_success('تم تحديث صلاحية الكلية للمشرف بنجاح.');
            } else {
                flash_error('خطأ أثناء تحديث صلاحيات المشرف.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=supervision");
        exit();
    }

    // 4️⃣ معالجة حذف مشرف كليات
    if (isset($_POST['delete_admin_action'])) {
        $del_admin_id = (int)($_POST['delete_admin_id'] ?? 0);
        if ($del_admin_id === (int)$_SESSION['admin_id']) {
            flash_error('لا يمكنك حذف حسابك الحالي الذي تسجل به الدخول.');
        } elseif ($del_admin_id > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM admins WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $del_admin_id);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'delete_admin', 'admin', $del_admin_id, []);
                flash_success('تم حذف المشرف بنجاح.');
            } else {
                flash_error('خطأ أثناء حذف المشرف.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=supervision");
        exit();
    }

    // 5️⃣ معالجة إجراءات الأطباء (اعتماد وحذف)
    if (isset($_POST['admin_action'])) {
        $id = (int) ($_POST['doc_id'] ?? 0);
        $action = $_POST['admin_action'] ?? '';
        $doc_college = null;
        $doc_name = '';

        if ($id > 0) {
            $check_stmt = mysqli_prepare($conn, 'SELECT name, college FROM doctors WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($check_stmt, 'i', $id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            $doctor = mysqli_fetch_assoc($check_result);
            mysqli_stmt_close($check_stmt);
            $doc_college = $doctor['college'] ?? null;
            $doc_name = $doctor['name'] ?? '';
        }

        if ($id > 0 && $action === 'approve') {
            $stmt = mysqli_prepare($conn, "UPDATE doctors SET is_approved = 1 WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_admin_action($conn, 'approve_doctor', 'doctor', $id, ['name' => $doc_name, 'college' => $doc_college]);
            flash_success('تم اعتماد الدكتور بنجاح.');
        }

        if ($id > 0 && $action === 'delete') {
            $del_reviews = mysqli_prepare($conn, 'DELETE FROM reviews WHERE doctor_id = ?');
            mysqli_stmt_bind_param($del_reviews, 'i', $id);
            mysqli_stmt_execute($del_reviews);
            mysqli_stmt_close($del_reviews);

            $stmt = mysqli_prepare($conn, 'DELETE FROM doctors WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'delete_doctor', 'doctor', $id, ['name' => $doc_name, 'college' => $doc_college]);
                flash_success('تم حذف الدكتور وجميع تقييماته بنجاح.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=doctors");
        exit();
    }

    // 6️⃣ معالجة إجراءات التعليقات والتقييمات
    if (isset($_POST['review_action'])) {
        $review_id = (int) ($_POST['review_id'] ?? 0);
        $action = $_POST['review_action'] ?? '';
        $review_doctor_id = 0;
        $review_doctor_name = '';

        if ($review_id > 0) {
            $chk_stmt = mysqli_prepare($conn, 'SELECT r.doctor_id, d.name AS doctor_name, d.college FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.id = ? LIMIT 1');
            mysqli_stmt_bind_param($chk_stmt, 'i', $review_id);
            mysqli_stmt_execute($chk_stmt);
            $chk_res = mysqli_stmt_get_result($chk_stmt);
            $rev_row = mysqli_fetch_assoc($chk_res);
            mysqli_stmt_close($chk_stmt);
            $review_doctor_id = (int) ($rev_row['doctor_id'] ?? 0);
            $review_doctor_name = (string) ($rev_row['doctor_name'] ?? '');
        }

        if ($review_id > 0 && $action === 'approve') {
            $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'approved' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_admin_action($conn, 'approve_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name]);
            flash_success('تمت الموافقة على التقييم بنجاح.');
        }

        if ($review_id > 0 && $action === 'unapprove') {
            $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'pending' WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_admin_action($conn, 'unapprove_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name]);
            flash_success('تم سحب اعتماد التقييم وإعادته للمعلق.');
        }

        if ($review_id > 0 && $action === 'delete') {
            $stmt = mysqli_prepare($conn, 'DELETE FROM reviews WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'delete_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name]);
                flash_success('تم حذف التقييم بنجاح.');
            }
            mysqli_stmt_close($stmt);
        }
        header("Location: admin.php?section=reviews");
        exit();
    }
}

// هيدرز الحماية الأمنية
header("Content-Security-Policy: default-src 'self' http://jzu-rating.live; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net http://jzu-rating.live; style-src 'self' 'unsafe-inline' http://jzu-rating.live; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self';");

function sentiment_label_ar(string $sentiment): string { return match ($sentiment) { 'positive' => 'إيجابي', 'negative' => 'سلبي', default => 'محايد' }; }
function college_label_ar(?string $college): string { return ($college === null || $college === '') ? 'إدارة عامة' : $college; }
function audit_action_label_ar(string $action): string {
    return match ($action) { 'add_admin' => 'إضافة مشرف', 'delete_admin' => 'حذف مشرف', 'update_admin_permission' => 'تعديل صلاحية مشرف', 'approve_doctor' => 'اعتماد دكتور', 'delete_doctor' => 'حذف دكتور', 'approve_review' => 'اعتماد تقييم', 'unapprove_review' => 'إلغاء اعتماد تقييم', 'delete_review' => 'حذف تقييم', default => $action };
}
function audit_target_label_ar(array $log): string {
    $targetType = (string) ($log['target_type'] ?? ''); $targetId = (int) ($log['target_id'] ?? 0);
    if ($targetType === 'admin') return 'المشرف #' . $targetId;
    if ($targetType === 'doctor') return 'الدكتور #' . $targetId;
    if ($targetType === 'review') return 'التقييم #' . $targetId;
    return 'إجراء عام';
}

function fetch_audit_logs(mysqli $conn, int $limit = 10, ?string $admin_college = null): array {
    $conditions = []; $types = ''; $params = [];
    if (!empty($admin_college)) { $conditions[] = 'COALESCE(ad.college_responsibility, "") = ?'; $types .= 's'; $params[] = $admin_college; }
    $whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
    $stmt = mysqli_prepare($conn, "SELECT a.action, a.target_type, a.target_id, a.ip_address, a.created_at, a.meta, COALESCE(ad.username, 'غير معروف') AS admin_name FROM audit_logs a LEFT JOIN admins ad ON ad.id = a.admin_id $whereSql ORDER BY a.id DESC LIMIT ?");
    $types .= 'i'; $params[] = $limit;
    bind_stmt_params($stmt, $types, $params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt); $logs = [];
    while ($row = mysqli_fetch_assoc($result)) { $logs[] = $row; }
    mysqli_stmt_close($stmt);
    return $logs;
}

function fetch_comparative_analytics(mysqli $conn): array {
    $data = [ 'top_doctors' => [], 'active_college' => ['name' => 'غير متوفر', 'count' => 0], 'departments' => [] ];
    $topDoctorsStmt = mysqli_prepare($conn, "SELECT d.id, d.name, d.college, d.department, d.gender, ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count FROM doctors d INNER JOIN reviews r ON r.doctor_id = d.id AND r.status = 'approved' WHERE d.is_approved = 1 GROUP BY d.id ORDER BY avg_rating DESC, review_count DESC LIMIT 5");
    mysqli_stmt_execute($topDoctorsStmt); $result = mysqli_stmt_get_result($topDoctorsStmt);
    while ($row = mysqli_fetch_assoc($result)) { $data['top_doctors'][] = $row; }
    mysqli_stmt_close($topDoctorsStmt);
    return $data;
}

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): void {
    $bindParams = [$stmt, $types];
    foreach ($params as $index => $value) { $bindParams[] = &$params[$index]; }
    call_user_func_array('mysqli_stmt_bind_param', $bindParams);
}

function doctors_count(mysqli $conn): array {
    $stats = [ 'approved' => 0, 'pending' => 0, 'reviews' => 0 ];
    $res = mysqli_query($conn, 'SELECT SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved_count, SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending_count FROM doctors');
    if ($res && $row = mysqli_fetch_assoc($res)) { $stats['approved'] = (int)$row['approved_count']; $stats['pending'] = (int)$row['pending_count']; }
    $rev = mysqli_query($conn, "SELECT COUNT(*) AS c FROM reviews WHERE status = 'approved'");
    if ($rev && $r = mysqli_fetch_assoc($rev)) { $stats['reviews'] = (int)$r['c']; }
    return $stats;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// --- إضافة وتحديث خريطة كليات جامعة جازان كاملة وموسعة ---
$departments_map = [
    'الهندسة وعلوم الحاسب' => ['علوم الحاسب', 'هندسة الحاسب والشبكات', 'نظم المعلومات', 'الهندسة الكهربائية', 'الهندسة الميكانيكية', 'الهندسة المدنية', 'الهندسة الكيميائية', 'الهندسة الصناعية'],
    'الطب' => ['الطب والجراحة العامة'],
    'طب الأسنان' => ['طب وجراحة الفم والأسنان'],
    'الصيدلة' => ['الصيدلة السريرية', 'العلوم الصيدلانية'],
    'العلوم الطبية التطبيقية' => ['تقنية المختبرات الطبية', 'العلاج الطبيعي', 'التغذية الإكلينيكية', 'الأشعة التشخيصية'],
    'التمريض' => ['التمريض'],
    'الصحة العامة' => ['الصحة العامة', 'الوبائيات', 'صحة البيئة', 'التثقيف الصحي'],
    'العلوم' => ['الرياضيات', 'الفيزياء', 'الكيمياء', 'الأحياء', 'الجيولوجيا'],
    'إدارة الأعمال' => ['المحاسبة', 'إدارة الأعمال', 'التسويق والتجارة الإلكترونية', 'التمويل والاستثمار', 'نظم المعلومات الإدارية', 'الاقتصاد'],
    'الشريعة والقانون' => ['الشريعة', 'القانون العام', 'القانون الخاص'],
    'الآداب والعلوم الإنسانية' => ['اللغة العربية وآدابها', 'اللغة الإنجليزية', 'الصحافة والإعلام', 'السياحة والآثار', 'المكتبات والمعلومات', 'العلوم الاجتماعية'],
    'التربية' => ['التربية الفنية', 'علم النفس', 'رياض الأطفال', 'التربية الخاصة', 'التربية البدنية'],
    'التصميم والعمارة' => ['العمارة', 'التصميم الداخلي', 'تصميم المنتجات', 'التصميم الجرافيكي', 'الفنون التطبيقية'],
    'الكلية التطبيقية' => ['برمجة ونظم المعلومات', 'المحاسبة والتمويل', 'إدارة المبيعات', 'إدارة المستودعات', 'السكرتارية الطبية', 'إدارة الفنادق'],
];

$college_options = array_keys($departments_map);

$stats = doctors_count($conn);
$analytics = fetch_comparative_analytics($conn);
$audit_logs = fetch_audit_logs($conn, 12, $admin_college);

$admin_list_q = mysqli_query($conn, "SELECT id, username, college_responsibility, created_at FROM admins ORDER BY id DESC");
$pending_reviews_result = mysqli_query($conn, "SELECT r.id, r.rating, r.comment, r.reviewer_name, r.course_code, r.semester, r.created_at, r.sentiment, d.name AS doctor_name, d.college, d.department, d.gender FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.status = 'pending' ORDER BY r.id DESC");
$approved_reviews_result = mysqli_query($conn, "SELECT r.id, r.rating, r.comment, r.reviewer_name, r.course_code, r.semester, r.created_at, r.sentiment, d.name AS doctor_name FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.status = 'approved' ORDER BY r.id DESC LIMIT 12");
$pending_doctors_result = mysqli_query($conn, "SELECT id, name, college, department, gender, courses FROM doctors WHERE is_approved = 0 ORDER BY id DESC");
$approved_doctors_result = mysqli_query($conn, "SELECT id, name, college, department, gender, courses FROM doctors WHERE is_approved = 1 ORDER BY id DESC LIMIT 12");
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإدارة - جامعة جازان</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="page">
        <header class="hero">
            <div class="hero__content">
                <h1>لوحة الإدارة الشاملة</h1>
                <p class="hero__text">التحكم المطلق بالمنظومة لجامعة جازان — ثبات فوري للأقسام والعمليات.</p>
                <div class="hero__actions">
                    <a class="btn btn--light" href="index.php">العودة للرئيسية</a>
                    <a class="btn btn--accent" href="logout.php">تسجيل الخروج</a>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash flash--<?php echo e($flash['type']); ?>" style="padding:15px; background: #333; color:#fff; border-radius:8px; margin:20px 0;">
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <section class="panel admin" id="admin">
            <div class="admin-hero">
                <div class="admin-nav" style="display:flex; gap:10px; margin-bottom:20px;">
                    <button type="button" class="btn nav-btn" data-target="stats">📊 الإحصاءات</button>
                    <button type="button" class="btn nav-btn" data-target="reviews">📥 لإدارة التقييمات</button>
                    <button type="button" class="btn nav-btn" data-target="doctors">👨‍🏫 إدارة الدكاترة</button>
                    <button type="button" class="btn nav-btn" data-target="supervision">🔒 المشرفين والعمليات</button>
                </div>
            </div>

            <div class="admin-section" data-admin-section="stats">
                <h3>📊 الأرقام الحالية في المنظومة</h3>
                <div class="admin-analytics" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px; margin-bottom:30px;">
                    <article style="background:#222; padding:20px; border-radius:8px; color:#fff;">
                        <strong><?php echo $stats['approved']; ?></strong><span> دكاترة معتمدين</span>
                    </article>
                    <article style="background:#222; padding:20px; border-radius:8px; color:#fff;">
                        <strong><?php echo $stats['pending']; ?></strong><span> دكاترة قيد الانتظار</span>
                    </article>
                    <article style="background:#222; padding:20px; border-radius:8px; color:#fff;">
                        <strong><?php echo $stats['reviews']; ?></strong><span> تقييمات منشورة</span>
                    </article>
                </div>
            </div>

            <div class="admin-section" data-admin-section="reviews" style="display:none;">
                <h3>📥 التقييمات والتعليقات الواردة (تحتاج إجراء)</h3>
                <div class="pending-reviews">
                    <?php while ($review = mysqli_fetch_assoc($pending_reviews_result)): ?>
                        <article class="admin-review-item" style="border-bottom:1px solid #ddd; padding:15px 0;">
                            <h4>دكتور: <?php echo e($review['doctor_name']); ?></h4>
                            <p>التعليق: <?php echo nl2br(e($review['comment'])); ?></p>
                            <form method="POST" action="admin.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" name="review_action" value="approve" class="btn btn--accent">قبول ونشر</button>
                                <button type="submit" name="review_action" value="delete" class="btn btn--danger">حذف نهائي</button>
                            </form>
                        </article>
                    <?php endwhile; ?>
                </div>

                <h3 style="margin-top:30px;">✅ التقييمات المنشورة سابقاً</h3>
                <div class="approved-reviews">
                    <?php while ($arev = mysqli_fetch_assoc($approved_reviews_result)): ?>
                        <article class="admin-review-item" style="border-bottom:1px solid #ddd; padding:10px 0;">
                            <p><strong><?php echo e($arev['doctor_name']); ?>:</strong> <?php echo e($arev['comment']); ?></p>
                            <form method="POST" action="admin.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="review_id" value="<?php echo $arev['id']; ?>">
                                <button type="submit" name="review_action" value="unapprove" class="btn btn--warning">سحب الاعتماد وعودة للمعلق</button>
                                <button type="submit" name="review_action" value="delete" class="btn btn--danger">حذف</button>
                            </form>
                        </article>
                    <?php endwhile; ?>
                </div>
            </div>

            <div class="admin-section" data-admin-section="doctors" style="display:none;">
                <h3>➕ إضافة دكتور جديد مباشرة للمنظومة</h3>
                <form method="POST" action="admin.php" style="background:#f9f9f9; padding:20px; border-radius:8px; margin-bottom:30px;">
                    <?php echo csrf_field(); ?>
                    <input type="text" name="doc_name" placeholder="الاسم الثلاثي للدكتور" required style="width:100%; padding:10px; margin-bottom:10px;">
                    <select name="college" required style="width:100%; padding:10px; margin-bottom:10px;">
                        <?php foreach($college_options as $c): ?> <option value="<?php echo $c; ?>"><?php echo $c; ?></option> <?php endforeach; ?>
                    </select>
                    <input type="text" name="department" placeholder="القسم الأكاديمي" required style="width:100%; padding:10px; margin-bottom:10px;">
                    <select name="gender" style="width:100%; padding:10px; margin-bottom:10px;">
                        <option value="male">دكتور</option>
                        <option value="female">دكتورة</option>
                    </select>
                    <input type="text" name="subjects" placeholder="المواد المسندة إليه (فواصل)" style="width:100%; padding:10px; margin-bottom:10px;">
                    <button type="submit" name="add_doc" class="btn btn--accent">حفظ ونشر الدكتور فوراً</button>
                </form>

                <h3>📥 طلبات الدكاترة المعلقة من الطلاب</h3>
                <div class="pending-doctors">
                    <?php while ($pdoc = mysqli_fetch_assoc($pending_doctors_result)): ?>
                        <article style="border:1px solid #ccc; padding:15px; margin-bottom:10px; border-radius:8px;">
                            <h4><?php echo e($pdoc['name']); ?> — كلية <?php echo e($pdoc['college']); ?></h4>
                            <form method="POST" action="admin.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="doc_id" value="<?php echo $pdoc['id']; ?>">
                                <button type="submit" name="admin_action" value="approve" class="btn btn--accent">قبول وإدراج</button>
                                <button type="submit" name="admin_action" value="delete" class="btn btn--danger">رفض وحذف</button>
                            </form>
                        </article>
                    <?php endwhile; ?>
                </div>

                <h3>👨‍🏫 قائمة الدكاترة المعتمدين</h3>
                <div class="approved-doctors">
                    <?php while ($ad = mysqli_fetch_assoc($approved_doctors_result)): ?>
                        <article style="display:flex; justify-content:space-between; padding:10px; border-bottom:1px solid #eee;">
                            <span><?php echo e($ad['name']); ?> (<?php echo e($ad['department']); ?>)</span>
                            <form method="POST" action="admin.php">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="doc_id" value="<?php echo $ad['id']; ?>">
                                <a href="add_doctor.php?doc_id=<?php echo $ad['id']; ?>&return_to=admin.php" class="btn btn--light">تعديل البيانات</a>
                                <button type="submit" name="admin_action" value="delete" class="btn btn--danger">حذف مبرم</button>
                            </form>
                        </article>
                    <?php endwhile; ?>
                </div>
            </div>

            <div class="admin-section" data-admin-section="supervision" style="display:none;">
                <h3>🔒 إضافة مشرف كليات فرعي جديد</h3>
                <form method="POST" action="admin.php" style="background:#f4f4f4; padding:15px; border-radius:8px;">
                    <?php echo csrf_field(); ?>
                    <input type="text" name="new_admin_username" placeholder="اسم مستخدم المشرف الجديد" required style="width:100%; padding:10px; margin-bottom:10px;">
                    <select name="new_admin_college" style="width:100%; padding:10px; margin-bottom:10px;">
                        <option value="">مسؤولية عامة عن كل الكليات</option>
                        <?php foreach ($college_options as $c): ?> <option value="<?php echo $c; ?>"><?php echo $c; ?></option> <?php endforeach; ?>
                    </select>
                    <input type="password" name="new_admin_password" placeholder="باسوورد قوي للمشرف" required style="width:100%; padding:10px; margin-bottom:10px;">
                    <button type="submit" name="add_admin" class="btn btn--accent">صناعة الحساب وتشفيره</button>
                </form>

                <h3 style="margin-top:30px;">👥 قائمة حسابات المشرفين بالنظام (تعديل كليات وحذف)</h3>
                <div class="admin-list">
                    <?php while ($admin_item = mysqli_fetch_assoc($admin_list_q)): ?>
                        <article style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:12px; border:1px solid #eee; margin-bottom:5px; border-radius:6px;">
                            <div>
                                <strong><?php echo e($admin_item['username']); ?></strong>
                                <br>
                                <form method="POST" action="admin.php" style="display:inline-flex; gap:5px; margin-top:5px;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="edit_admin_id" value="<?php echo $admin_item['id']; ?>">
                                    <select name="edit_college" style="padding:2px 5px; font-size:12px;">
                                        <option value="">إدارة عامة (كل الكليات)</option>
                                        <?php foreach ($college_options as $co): ?>
                                            <option value="<?php echo $co; ?>" <?php echo ($admin_item['college_responsibility'] === $co) ? 'selected' : ''; ?>><?php echo $co; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="update_admin_college" class="btn btn--light" style="padding:2px 8px; font-size:11px;">تعديل الصلاحية</button>
                                </form>
                            </div>
                            <form method="POST" action="admin.php" onsubmit="return confirm('هل أنت متأكد من حذف هذا الحساب الإداري تماماً؟');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_admin_id" value="<?php echo $admin_item['id']; ?>">
                                <button type="submit" name="delete_admin_action" class="btn btn--danger" style="padding:4px 10px; font-size:12px;">حذف الحساب</button>
                            </form>
                        </article>
                    <?php endwhile; ?>
                </div>

                <h3 style="margin-top:30px;">📜 سجل العمليات المباشر (Audit Logs)</h3>
                <div class="audit-log-list" style="background:#111; color:#fff; padding:15px; border-radius:8px; max-height:300px; overflow-y:scroll;">
                    <?php foreach ($audit_logs as $log): ?>
                        <p style="font-size:13px; border-bottom:1px solid #222; padding-bottom:5px; margin: 4px 0;">
                            [<?php echo $log['created_at']; ?>] <strong><?php echo e($log['admin_name']); ?></strong> قام بـ 
                            <span style="color:#00ffcc;"><?php echo audit_action_label_ar($log['action']); ?></span> على <?php echo audit_target_label_ar($log); ?> (IP: <?php echo $log['ip_address']; ?>)
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <script>
        (function () {
            const buttons = document.querySelectorAll('.nav-btn');
            const sections = document.querySelectorAll('.admin-section');
            
            // قراءة القسم المحفوظ في الرابط لمنع الانقلاع التلقائي بعد الـ Post
            const urlParams = new URLSearchParams(window.location.search);
            const activeSection = urlParams.get('section') || 'stats';

            function showSection(target) {
                sections.forEach(s => s.style.display = s.dataset.adminSection === target ? '' : 'none');
                buttons.forEach(b => b.classList.toggle('btn--accent', b.dataset.target === target));
                
                // تحديث الرابط في المتصفح دون عمل Refresh
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.set('section', target);
                window.history.replaceState({}, '', nextUrl.toString());

                // تحديث مسار الـ action لكل النماذج ديناميكياً لتثبيت التبويب الفعلي
                const forms = document.querySelectorAll('form');
                forms.forEach(form => {
                    const currentAction = new URL(form.action || window.location.href);
                    currentAction.searchParams.set('section', target);
                    form.action = currentAction.pathname + currentAction.search;
                });
            }

            buttons.forEach(b => b.addEventListener('click', () => showSection(b.dataset.target)));
            showSection(activeSection);
        })();
    </script>
    <footer class="footer" style="text-align:center; margin-top:5px; padding:20px;">
        <p>© جميع الحقوق محفوظة لعام ٢٠٢٦م — جامعة جازان</p>
        <p>تطوير وبرمجة المهندس: <strong>يحيى مكرشي</strong></p>
    </footer>
</body>
</html>