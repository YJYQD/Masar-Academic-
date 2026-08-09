<?php
// تأمين ضبط ملفات الكوكي للمشروع بالكامل لمنع انفصال الجلسة والطرد
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/', // جعل الجلسة مقروءة عبر جميع المجلدات الفرعية
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// الاتصال بالداتابيز
include 'db.php';

// جدار الحماية المشدد للوصول المطلق لصفحة الدخول دون انكسار مسارات
if (empty($_SESSION['is_admin']) || !isset($_SESSION['admin_id'])) {
    header('Location: /login.php');
    exit();
}
// إعدادات الأمان وتوليد الرمز تلقائياً في قمة الملف
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- 3. الدوال المساعدة (تم نقلها للأعلى لضمان عمل الفحص بدون Fatals) ---
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return (string) ($_SESSION['csrf_token'] ?? ''); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }

$admin_college = $_SESSION['admin_college'] ?? null;

// --- معالجة طلبات الحذف والإضافة والـ CSRF (تنفذ فقط عند إرسال النماذج POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $postedToken)) {
        flash_error('طلب غير صالح (CSRF)');
        header('Location: admin.php');
        exit();
    }
}

    // A. معالجة إضافة دكتور جديد من لوحة التحكم مباشرة
   

    // B. معالجة إضافة مشرف كليات جديد
    


    // B. معالجة إضافة مشرف كليات جديد
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
        header('Location: admin.php?section=supervision');
        exit();
    }

// إعدادات الأمان وبقية الدوال
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header("Content-Security-Policy: default-src 'self' http://jzu-rating.live; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net http://jzu-rating.live; style-src 'self' 'unsafe-inline' http://jzu-rating.live; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
// ... (ضع بقية الدوال هنا: e, csrf_token, csrf_field, إلخ) ...

// --- 3. الدوال المساعدة ---
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return (string) ($_SESSION['csrf_token'] ?? ''); }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
// ... (ضع باقي دوالك هنا)

function sentiment_label_ar(string $sentiment): string
{
    return match ($sentiment) {
        'positive' => 'إيجابي',
        'negative' => 'سلبي',
        default => 'محايد',
    };
}

function college_label_ar(?string $college): string
{
    return ($college === null || $college === '') ? 'إدارة عامة' : $college;
}

function audit_action_label_ar(string $action): string
{
    return match ($action) {
        'create_first_admin' => 'إنشاء المشرف الأول',
        'add_admin' => 'إضافة مشرف',
        'approve_doctor' => 'اعتماد دكتور',
        'unapprove_doctor' => 'إلغاء اعتماد دكتور',
        'delete_doctor' => 'حذف دكتور',
        'approve_review' => 'اعتماد تقييم',
        'unapprove_review' => 'إلغاء اعتماد تقييم',
        'delete_review' => 'حذف تقييم',
        default => $action,
    };
}

function audit_target_label_ar(array $log): string
{
    $targetType = (string) ($log['target_type'] ?? '');
    $targetId = (int) ($log['target_id'] ?? 0);
    $meta = [];
    if (!empty($log['meta'])) {
        $decoded = json_decode((string) $log['meta'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    if ($targetType === 'admin') {
        return 'المشرف #' . $targetId . (!empty($meta['username']) ? ' (' . $meta['username'] . ')' : '');
    }

    if ($targetType === 'doctor') {
        $parts = ['الدكتور #' . $targetId];
        if (!empty($meta['name'])) {
            $parts[] = $meta['name'];
        }
        if (!empty($meta['college'])) {
            $parts[] = 'كلية ' . $meta['college'];
        }
        return implode(' - ', $parts);
    }

    if ($targetType === 'review') {
        $parts = ['التقييم #' . $targetId];
        if (!empty($meta['doctor_id'])) {
            $parts[] = 'لطبيب #' . (int) $meta['doctor_id'];
        }
        if (!empty($meta['sentiment'])) {
            $parts[] = 'النتيجة ' . sentiment_label_ar((string) $meta['sentiment']);
        }
        return implode(' - ', $parts);
    }

    return $targetType !== '' ? ($targetType . ' #' . $targetId) : 'إجراء عام';
}

function log_admin_action(mysqli $conn, string $action, ?string $target_type = null, ?int $target_id = null, array $meta = []): void
{
    if (empty($_SESSION['is_admin'])) {
        return;
    }

    $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '{}';
    $adminId = !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
    $ipAddress = client_ip();
    $targetTypeValue = $target_type;
    $targetIdValue = $target_id ?? 0;
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO audit_logs (admin_id, action, target_type, target_id, ip_address, meta) VALUES (?, ?, ?, ?, ?, ?)'
    );
    mysqli_stmt_bind_param($stmt, 'ississ', $adminId, $action, $targetTypeValue, $targetIdValue, $ipAddress, $metaJson);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function fetch_audit_logs(mysqli $conn, int $limit = 10, array $filters = [], ?string $admin_college = null): array
{
    $limit = max(1, $limit);
    $conditions = [];
    $types = '';
    $params = [];

    $actionFilter = trim((string) ($filters['action'] ?? ''));
    $targetTypeFilter = trim((string) ($filters['target_type'] ?? ''));
    $adminFilter = trim((string) ($filters['admin'] ?? ''));
    $searchFilter = trim((string) ($filters['search'] ?? ''));

    if ($actionFilter !== '') {
        $conditions[] = 'a.action = ?';
        $types .= 's';
        $params[] = $actionFilter;
    }

    if ($targetTypeFilter !== '') {
        $conditions[] = 'a.target_type = ?';
        $types .= 's';
        $params[] = $targetTypeFilter;
    }

    if ($adminFilter !== '') {
        $conditions[] = 'ad.username LIKE ?';
        $types .= 's';
        $params[] = '%' . $adminFilter . '%';
    }

if (!empty($admin_college) && ($_SESSION['admin_role'] ?? '') !== 'super') {
    $conditions[] = 'COALESCE(ad.college_responsibility, "") = ?';
    $types .= 's';
    $params[] = $admin_college;
}
    

    if ($searchFilter !== '') {
        $conditions[] = '(a.action LIKE ? OR a.target_type LIKE ? OR CAST(a.target_id AS CHAR) LIKE ? OR a.ip_address LIKE ? OR ad.username LIKE ?)';
        $types .= 'sssss';
        $like = '%' . $searchFilter . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $logs = [];
    $whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.action, a.target_type, a.target_id, a.ip_address, a.created_at, a.meta, COALESCE(ad.username, 'غير معروف') AS admin_name
         FROM audit_logs a
         LEFT JOIN admins ad ON ad.id = a.admin_id
        {$whereSql}
         ORDER BY a.id DESC
         LIMIT {$limit}"
    );
    if ($types !== '') {
        bind_stmt_params($stmt, $types, $params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $logs;
}

function fetch_comparative_analytics(mysqli $conn): array
{
    $data = [
        'top_doctors' => [],
        'active_college' => ['name' => 'غير متوفر', 'count' => 0],
        'departments' => [],
    ];

    $topDoctorsStmt = mysqli_prepare(
        $conn,
        "SELECT d.id, d.name, d.college, d.department, d.gender, ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count
         FROM doctors d
         INNER JOIN reviews r ON r.doctor_id = d.id AND r.status = 'approved'
         WHERE d.is_approved = 1
         GROUP BY d.id
         ORDER BY avg_rating DESC, review_count DESC, d.id DESC
         LIMIT 5"
    );
    mysqli_stmt_execute($topDoctorsStmt);
    $topDoctorsResult = mysqli_stmt_get_result($topDoctorsStmt);
    while ($row = mysqli_fetch_assoc($topDoctorsResult)) {
        $data['top_doctors'][] = $row;
    }
    mysqli_stmt_close($topDoctorsStmt);

    $collegeStmt = mysqli_prepare(
        $conn,
        "SELECT d.college, COUNT(*) AS review_total
         FROM reviews r
         INNER JOIN doctors d ON d.id = r.doctor_id AND d.is_approved = 1
         WHERE r.status = 'approved'
           AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY d.college
         ORDER BY review_total DESC
         LIMIT 1"
    );
    mysqli_stmt_execute($collegeStmt);
    $collegeResult = mysqli_stmt_get_result($collegeStmt);
    if ($row = mysqli_fetch_assoc($collegeResult)) {
        $data['active_college'] = [
            'name' => $row['college'] ?? 'غير متوفر',
            'count' => (int) ($row['review_total'] ?? 0),
        ];
    }
    mysqli_stmt_close($collegeStmt);

    $departmentStmt = mysqli_prepare(
        $conn,
        "SELECT d.department, ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count
         FROM doctors d
         INNER JOIN reviews r ON r.doctor_id = d.id AND r.status = 'approved'
         WHERE d.is_approved = 1
           AND d.department IS NOT NULL
           AND d.department <> ''
         GROUP BY d.department
         HAVING review_count > 0
         ORDER BY review_count DESC, avg_rating DESC
         LIMIT 6"
    );
    mysqli_stmt_execute($departmentStmt);
    $departmentResult = mysqli_stmt_get_result($departmentStmt);
    while ($row = mysqli_fetch_assoc($departmentResult)) {
        $data['departments'][] = $row;
    }
    mysqli_stmt_close($departmentStmt);

    return $data;
}

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bindParams = [$stmt, $types];
    foreach ($params as $index => $value) {
        $bindParams[] = &$params[$index];
    }

    call_user_func_array('mysqli_stmt_bind_param', $bindParams);
}

function doctors_count(mysqli $conn): array
{
    $stats = [
        'approved' => 0,
        'pending' => 0,
        'reviews' => 0,
        'top_college' => 'غير متوفر',
    ];

        $stmt = mysqli_prepare($conn, 'SELECT SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved_count, SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending_count FROM doctors');
        if ($stmt) {
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $stats['approved'] = (int) ($row['approved_count'] ?? 0);
                $stats['pending'] = (int) ($row['pending_count'] ?? 0);
            }
            mysqli_stmt_close($stmt);
        }

    $stats['reviews'] = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews WHERE status = ?', 's', ['approved']);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT d.college, COUNT(r.id) AS review_total
         FROM reviews r
         INNER JOIN doctors d ON d.id = r.doctor_id AND d.is_approved = 1
         WHERE r.status = 'approved'
         GROUP BY d.college
         ORDER BY review_total DESC
         LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($top_row = mysqli_fetch_assoc($res)) {
            $stats['top_college'] = $top_row['college'] ?? 'غير متوفر';
        }
        mysqli_stmt_close($stmt);
    }

    return $stats;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

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

$admins_count = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM admins');

$admin_college = null;
if (!empty($_SESSION['is_admin'])) {
    if (isset($_SESSION['admin_college'])) {
        $admin_college = $_SESSION['admin_college'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $aid = (int) $_SESSION['admin_id'];
        $stmt_ac = mysqli_prepare($conn, 'SELECT college_responsibility FROM admins WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt_ac, 'i', $aid);
        mysqli_stmt_execute($stmt_ac);
        $res_ac = mysqli_stmt_get_result($stmt_ac);
        if ($row_ac = mysqli_fetch_assoc($res_ac)) {
            $admin_college = $row_ac['college_responsibility'] ?? null;
            $_SESSION['admin_college'] = $admin_college;
        }
        mysqli_stmt_close($stmt_ac);
    }
}

if (isset($_GET['api']) && $_GET['api'] === 'admin_notifications') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $payload = [
        'pending_count' => 0,
        'latest_pending' => [],
    ];

    if (!empty($admin_college)) {
        $stmt_count = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.status = 'pending' AND d.college = ?");
        mysqli_stmt_bind_param($stmt_count, 's', $admin_college);
        mysqli_stmt_execute($stmt_count);
        $res_count = mysqli_stmt_get_result($stmt_count);
        if ($r = mysqli_fetch_assoc($res_count)) {
            $payload['pending_count'] = (int) ($r['c'] ?? 0);
        }
        mysqli_stmt_close($stmt_count);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT r.id, r.rating, r.sentiment, r.created_at, d.name AS doctor_name
             FROM reviews r
             INNER JOIN doctors d ON d.id = r.doctor_id
             WHERE r.status = 'pending' AND d.college = ?
             ORDER BY r.id DESC
             LIMIT 5"
        );
        mysqli_stmt_bind_param($stmt, 's', $admin_college);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $payload['latest_pending'][] = $row;
        }
        mysqli_stmt_close($stmt);
    } else {
        $payload['pending_count'] = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews WHERE status = ?', 's', ['pending']);

        $stmt = mysqli_prepare(
            $conn,
            "SELECT r.id, r.rating, r.sentiment, r.created_at, d.name AS doctor_name
             FROM reviews r
             INNER JOIN doctors d ON d.id = r.doctor_id
             WHERE r.status = 'pending'
             ORDER BY r.id DESC
             LIMIT 5"
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $payload['latest_pending'][] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') 
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $postedToken)) {
    flash_error('طلب غير صالح (CSRF)');
    header('Location: admin.php');
    exit();
}
        if (isset($_POST['admin_action']) && !empty($_SESSION['is_admin'])) 

    $id = (int) ($_POST['doc_id'] ?? 0);
    $action = $_POST['admin_action'] ?? '';

    $doc_college = null;
    $doc_name = '';

    if ($id > 0) {

        $check_stmt = mysqli_prepare(
            $conn,
            'SELECT name, college FROM doctors WHERE id = ? LIMIT 1'
        );

        mysqli_stmt_bind_param($check_stmt, 'i', $id);
        mysqli_stmt_execute($check_stmt);

        $check_result = mysqli_stmt_get_result($check_stmt);
        $doctor = mysqli_fetch_assoc($check_result);

        mysqli_stmt_close($check_stmt);

        $doc_college = $doctor['college'] ?? null;
        $doc_name = $doctor['name'] ?? '';

        if (
            !empty($admin_college)
            && ($_SESSION['admin_role'] ?? '') !== 'super'
            && $doc_college !== $admin_college
        ) {

            flash_error('ليس لديك صلاحية لهذا الدكتور.');
            header('Location: admin.php?section=doctors');
            exit();
        }
    }

    // اعتماد الدكتور
    if ($id > 0 && $action === 'approve') {

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE doctors SET is_approved = 1 WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_admin_action(
            $conn,
            'approve_doctor',
            'doctor',
            $id,
            [
                'name' => $doc_name,
                'college' => $doc_college
            ]
        );

        flash_success('تم اعتماد الدكتور بنجاح.');

        header('Location: admin.php?section=doctors');
        exit();
    }

    // حذف الدكتور
    if ($id > 0 && $action === 'delete') {

        // حذف التقييمات أولاً
        $del_reviews = mysqli_prepare(
            $conn,
            'DELETE FROM reviews WHERE doctor_id = ?'
        );

        mysqli_stmt_bind_param($del_reviews, 'i', $id);
        mysqli_stmt_execute($del_reviews);
        mysqli_stmt_close($del_reviews);

        // حذف الدكتور
        $stmt = mysqli_prepare(
            $conn,
            'DELETE FROM doctors WHERE id = ?'
        );

        mysqli_stmt_bind_param($stmt, 'i', $id);

        if (mysqli_stmt_execute($stmt)) {

            if (mysqli_stmt_affected_rows($stmt) > 0) {

                log_admin_action(
                    $conn,
                    'delete_doctor',
                    'doctor',
                    $id,
                    [
                        'name' => $doc_name,
                        'college' => $doc_college
                    ]
                );

                flash_success('تم حذف الدكتور بنجاح.');

            } else {

                flash_error('الدكتور غير موجود.');
            }

        } else {

            flash_error(
                'خطأ SQL: ' .
                mysqli_stmt_error($stmt)
            );
        }

        mysqli_stmt_close($stmt);

        header('Location: admin.php?section=doctors');
        exit();
    }


     

    if (isset($_POST['review_action']) && !empty($_SESSION['is_admin'])) {
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
            $rev_college = $rev_row['college'] ?? null;

            if (!empty($admin_college) && $rev_college !== $admin_college) {
                flash_error('غير مسموح: هذا التقييم لا ينتمي للكلية التي تشرف عليها.');
                if (is_ajax_request()) {
                    header('Content-Type: application/json; charset=utf-8', true, 403);
                    echo json_encode(['success' => false, 'error' => 'auth']);
                    exit();
                }
               
            }

            if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'sub') {
                $perms = json_decode((string) ($_SESSION['admin_permissions'] ?? '[]'), true) ?: [];
                if (!in_array('reviews', $perms, true) && !in_array('both', $perms, true)) {
                    flash_error('غير مسموح: ليس لديك صلاحية إدارة التعليقات.');
                    if (is_ajax_request()) {
                        header('Content-Type: application/json; charset=utf-8', true, 403);
                        echo json_encode(['success' => false, 'error' => 'auth']);
                        exit();
                    }
                    header('Location: admin.php');
                    exit();
                }
            }
        }

        if ($review_id > 0 && $action === 'approve') {
            $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'approved' WHERE id = ? AND status = 'pending'");
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_admin_action($conn, 'approve_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name, 'sentiment' => 'approved']);
            flash_success('تمت الموافقة على التقييم بنجاح.');
            
            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true]);
                exit();
            }
        }

        if ($review_id > 0 && $action === 'unapprove') {
            $stmt = mysqli_prepare($conn, "UPDATE reviews SET status = 'pending' WHERE id = ? AND status = 'approved'");
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            log_admin_action($conn, 'unapprove_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name, 'sentiment' => 'pending']);
            flash_success('تم التراجع عن اعتماد التقييم بنجاح.');
            
            if (is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true]);
                exit();
            }
        }

       if ($review_id > 0 && $action === 'delete') {
            $stmt = mysqli_prepare($conn, 'DELETE FROM reviews WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $review_id);
            
            if (mysqli_stmt_execute($stmt)) {
                log_admin_action($conn, 'delete_review', 'review', $review_id, ['doctor_id' => $review_doctor_id, 'doctor_name' => $review_doctor_name]);
                flash_success('تم حذف التقييم بنجاح.');
                if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit();
}
            } else {
                flash_error('فشل حذف التقييم: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
            
            header('Location: admin.php?section=reviews');
            exit();
           }
        }

        header('Location: admin.php');
        exit();
    


$audit_log_search = trim((string) ($_GET['audit_log_search'] ?? ''));
$audit_log_action = trim((string) ($_GET['audit_log_action'] ?? ''));
$audit_log_target = trim((string) ($_GET['audit_log_target'] ?? ''));
$audit_log_admin = trim((string) ($_GET['audit_log_admin'] ?? ''));

$stats = doctors_count($conn);
$analytics = fetch_comparative_analytics($conn);
$audit_logs = fetch_audit_logs($conn, 12, [
    'search' => $audit_log_search,
    'action' => $audit_log_action,
    'target_type' => $audit_log_target,
    'admin' => $audit_log_admin,
], $_SESSION['admin_college'] ?? null);

if (!empty($admin_college)) {
    $al_stmt = mysqli_prepare($conn, 'SELECT username, college_responsibility, created_at FROM admins WHERE college_responsibility = ? ORDER BY id DESC');
    mysqli_stmt_bind_param($al_stmt, 's', $admin_college);
    mysqli_stmt_execute($al_stmt);
    $admin_list = mysqli_stmt_get_result($al_stmt);
    mysqli_stmt_close($al_stmt);
} else {
    $stmt = mysqli_prepare($conn, 'SELECT username, college_responsibility, created_at FROM admins ORDER BY id DESC');
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $admin_list = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        // prepare failed — return empty result set
        $admin_list = [];
    }
}

$pending_reviews_result = null;
if (!empty($admin_college)) {
    $stmt_pr = mysqli_prepare(
        $conn,
        "SELECT r.id, r.rating, r.comment, r.reviewer_name, r.course_code, r.semester, r.created_at, r.sentiment,
                d.name AS doctor_name, d.college, d.department, d.gender
         FROM reviews r
         INNER JOIN doctors d ON d.id = r.doctor_id
         WHERE r.status = 'pending' AND d.college = ?
         ORDER BY r.id DESC"
    );
    mysqli_stmt_bind_param($stmt_pr, 's', $admin_college);
    mysqli_stmt_execute($stmt_pr);
    $pending_reviews_result = mysqli_stmt_get_result($stmt_pr);
    mysqli_stmt_close($stmt_pr);
} else {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.id, r.rating, r.comment, r.reviewer_name, r.course_code, r.semester, r.created_at, r.sentiment,
                d.name AS doctor_name, d.college, d.department, d.gender
         FROM reviews r
         INNER JOIN doctors d ON d.id = r.doctor_id
         WHERE r.status = 'pending'
         ORDER BY r.id DESC"
    );
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $pending_reviews_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $pending_reviews_result = [];
    }
}

$is_admin = !empty($_SESSION['is_admin']);

if (!empty($admin_college)) {
    $stats = ['approved' => 0, 'pending' => 0, 'reviews' => 0, 'top_college' => $admin_college];

    $stmt_s = mysqli_prepare(
        $conn,
        'SELECT
            SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending_count
         FROM doctors WHERE college = ?'
    );
    mysqli_stmt_bind_param($stmt_s, 's', $admin_college);
    mysqli_stmt_execute($stmt_s);
    $res_s = mysqli_stmt_get_result($stmt_s);
    if ($r = mysqli_fetch_assoc($res_s)) {
        $stats['approved'] = (int) ($r['approved_count'] ?? 0);
        $stats['pending'] = (int) ($r['pending_count'] ?? 0);
    }
    mysqli_stmt_close($stmt_s);

    $stmt_r = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.status = 'approved' AND d.college = ?");
    mysqli_stmt_bind_param($stmt_r, 's', $admin_college);
    mysqli_stmt_execute($stmt_r);
    $res_r = mysqli_stmt_get_result($stmt_r);
    if ($rr = mysqli_fetch_assoc($res_r)) {
        $stats['reviews'] = (int) ($rr['c'] ?? 0);
    }
    mysqli_stmt_close($stmt_r);

    $analytics = ['top_doctors' => [], 'active_college' => ['name' => $admin_college, 'count' => 0], 'departments' => []];

    $topDoctorsStmt = mysqli_prepare(
        $conn,
        "SELECT d.id, d.name, d.college, d.department, d.gender, ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count
         FROM doctors d
         INNER JOIN reviews r ON r.doctor_id = d.id AND r.status = 'approved'
         WHERE d.is_approved = 1 AND d.college = ?
         GROUP BY d.id
         ORDER BY avg_rating DESC, review_count DESC, d.id DESC
         LIMIT 5"
    );
    mysqli_stmt_bind_param($topDoctorsStmt, 's', $admin_college);
    mysqli_stmt_execute($topDoctorsStmt);
    $topDoctorsResult = mysqli_stmt_get_result($topDoctorsStmt);
    while ($row = mysqli_fetch_assoc($topDoctorsResult)) {
        $analytics['top_doctors'][] = $row;
    }
    mysqli_stmt_close($topDoctorsStmt);

    $collegeStmt = mysqli_prepare(
        $conn,
        "SELECT d.college, COUNT(*) AS review_total
         FROM reviews r
         INNER JOIN doctors d ON d.id = r.doctor_id AND d.is_approved = 1
         WHERE r.status = 'approved' AND d.college = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY d.college
         ORDER BY review_total DESC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($collegeStmt, 's', $admin_college);
    mysqli_stmt_execute($collegeStmt);
    $collegeResult = mysqli_stmt_get_result($collegeStmt);
    if ($row = mysqli_fetch_assoc($collegeResult)) {
        $analytics['active_college'] = [
            'name' => $row['college'] ?? $admin_college,
            'count' => (int) ($row['review_total'] ?? 0),
        ];
    }
    mysqli_stmt_close($collegeStmt);

    $departmentStmt = mysqli_prepare(
        $conn,
        "SELECT d.department, ROUND(AVG(r.rating), 1) AS avg_rating, COUNT(r.id) AS review_count
         FROM doctors d
         INNER JOIN reviews r ON r.doctor_id = d.id AND r.status = 'approved'
         WHERE d.is_approved = 1 AND d.department IS NOT NULL AND d.department <> '' AND d.college = ?
         GROUP BY d.department
         HAVING review_count > 0
         ORDER BY review_count DESC, avg_rating DESC
         LIMIT 6"
    );
    mysqli_stmt_bind_param($departmentStmt, 's', $admin_college);
    mysqli_stmt_execute($departmentStmt);
    $departmentResult = mysqli_stmt_get_result($departmentStmt);
    while ($row = mysqli_fetch_assoc($departmentResult)) {
        $analytics['departments'][] = $row;
    }
    mysqli_stmt_close($departmentStmt);
}

$filter_q = trim((string) ($_GET['filter_q'] ?? ''));
$filter_college = trim((string) ($_GET['filter_college'] ?? ''));
$filter_department = trim((string) ($_GET['filter_department'] ?? ''));
$filter_doctor_id = (int) ($_GET['filter_doctor_id'] ?? 0);

$docConditions = ['is_approved = 0'];
$docTypes = '';
$docParams = [];

// التعديل هنا: أضفنا شرط الـ superAdmin
if (!empty($admin_college) && $_SESSION['admin_role'] !== 'super') {
    $docConditions[] = 'college = ?';
    $docTypes .= 's';
    $docParams[] = $admin_college;
} elseif ($filter_college !== '') {
    $docConditions[] = 'college = ?';
    $docTypes .= 's';
    $docParams[] = $filter_college;
}

if ($filter_department !== '') {
    $docConditions[] = 'department = ?';
    $docTypes .= 's';
    $docParams[] = $filter_department;
}

if ($filter_doctor_id > 0) {
    $docConditions[] = 'id = ?';
    $docTypes .= 'i';
    $docParams[] = $filter_doctor_id;
}

if ($filter_q !== '') {
    $docConditions[] = '(name LIKE ? OR department LIKE ? OR college LIKE ?)';
    $docTypes .= 'sss';
    $like = '%' . $filter_q . '%';
    $docParams[] = $like;
    $docParams[] = $like;
    $docParams[] = $like;
}

$docSql = 'SELECT id, name, college, department, gender, courses FROM doctors WHERE ' . implode(' AND ', $docConditions) . ' ORDER BY id DESC LIMIT 200';
$stmt_pd = mysqli_prepare($conn, $docSql);
if ($docTypes !== '') {
    bind_stmt_params($stmt_pd, $docTypes, $docParams);
}
mysqli_stmt_execute($stmt_pd);
$pending_doctors_result = mysqli_stmt_get_result($stmt_pd);
mysqli_stmt_close($stmt_pd);

$adConditions = ['is_approved = 1'];
$adTypes = '';
$adParams = [];

if (!empty($admin_college)) {
    $adConditions[] = 'college = ?';
    $adTypes .= 's';
    $adParams[] = $admin_college;
} elseif ($filter_college !== '') {
    $adConditions[] = 'college = ?';
    $adTypes .= 's';
    $adParams[] = $filter_college;
}

if ($filter_department !== '') {
    $adConditions[] = 'department = ?';
    $adTypes .= 's';
    $adParams[] = $filter_department;
}

if ($filter_doctor_id > 0) {
    $adConditions[] = 'id = ?';
    $adTypes .= 'i';
    $adParams[] = $filter_doctor_id;
}

if ($filter_q !== '') {
    $adConditions[] = '(name LIKE ? OR department LIKE ? OR college LIKE ?)';
    $adTypes .= 'sss';
    $like = '%' . $filter_q . '%';
    $adParams[] = $like;
    $adParams[] = $like;
    $adParams[] = $like;
}

$adSql = 'SELECT id, name, college, department, gender, courses FROM doctors WHERE ' . implode(' AND ', $adConditions) . ' ORDER BY id DESC LIMIT 12';
$stmt_ad = mysqli_prepare($conn, $adSql);
if ($adTypes !== '') {
    bind_stmt_params($stmt_ad, $adTypes, $adParams);
}
mysqli_stmt_execute($stmt_ad);
$approved_doctors_result = mysqli_stmt_get_result($stmt_ad);
mysqli_stmt_close($stmt_ad);

$revConditions = ["r.status = 'approved'"];
$revTypes = '';
$revParams = [];

if (!empty($admin_college)) {
    $revConditions[] = 'd.college = ?';
    $revTypes .= 's';
    $revParams[] = $admin_college;
} elseif ($filter_college !== '') {
    $revConditions[] = 'd.college = ?';
    $revTypes .= 's';
    $revParams[] = $filter_college;
}

if ($filter_department !== '') {
    $revConditions[] = 'd.department = ?';
    $revTypes .= 's';
    $revParams[] = $filter_department;
}

if ($filter_doctor_id > 0) {
    $revConditions[] = 'd.id = ?';
    $revTypes .= 'i';
    $revParams[] = $filter_doctor_id;
}

if ($filter_q !== '') {
    $revConditions[] = '(d.name LIKE ? OR r.comment LIKE ?)';
    $revTypes .= 'ss';
    $like = '%' . $filter_q . '%';
    $revParams[] = $like;
    $revParams[] = $like;
}

$revSql = 'SELECT r.id, r.rating, r.comment, r.reviewer_name, r.course_code, r.semester, r.created_at, r.sentiment, d.name AS doctor_name FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE ' . implode(' AND ', $revConditions) . ' ORDER BY r.id DESC LIMIT 12';
$stmt_ar = mysqli_prepare($conn, $revSql);
if ($revTypes !== '') {
    bind_stmt_params($stmt_ar, $revTypes, $revParams);
}
mysqli_stmt_execute($stmt_ar);
$approved_reviews_result = mysqli_stmt_get_result($stmt_ar);
mysqli_stmt_close($stmt_ar);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإدارة</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="page">
        <header class="hero">
            <div class="hero__content">
                <h1>لوحة الإدارة</h1>
                <p class="hero__text">إدارة الدكاترة والتقييمات والمشرفين من صفحة واحدة منفصلة.</p>
                <div class="hero__actions">
                    <a class="btn btn--light" href="index.php">العودة للواجهة الرئيسية</a>
                    <?php if ($is_admin): ?>
                        <a class="btn btn--accent" href="logout.php">تسجيل الخروج</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php if ($flash): ?>
            <section class="flash flash--<?php echo e($flash['type']); ?>">
                <?php echo e($flash['text']); ?>
            </section>
        <?php endif; ?>

        <?php if ($admins_count === 0): ?>
            <section class="panel admin" id="admin">
                <h2>إنشاء المشرف الأول</h2>
                <p>لا يوجد مشرفون بعد. أنشئ الحساب الأول لتفعيل لوحة الإدارة.</p>
                <form method="POST" class="admin-login">
                    <?php echo csrf_field(); ?>
                    <label>
                        الكلية المسؤولة
                        <select name="admin_college" required>
                            <option value="">اختر الكلية</option>
                            <?php foreach ($college_options as $college_name): ?>
                                <option value="<?php echo e($college_name); ?>"><?php echo e('كلية ' . $college_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        اسم المستخدم للمشرف
                        <input type="text" name="admin_username" placeholder="مثال: admin" required>
                    </label>
                    <label>
                        كلمة المرور
                        <input type="password" name="admin_password" placeholder="كلمة المرور" required>
                    </label>
                    <button type="submit" name="create_first_admin" class="btn btn--dark">إنشاء المشرف الأول</button>
                </form>
            </section>
        <?php elseif (!$is_admin): ?>
            <section class="panel admin">
                <h2>الوصول مقيد</h2>
                <p>هذه الصفحة خاصة بالمشرفين فقط. سجّل الدخول بحساب مشرف من صفحة الدخول الرئيسية.</p>
                <div class="hero__actions">
                    <a class="btn btn--accent" href="login.php">تسجيل الدخول</a>
                </div>
            </section>
        <?php else: ?>
            <section class="panel admin" id="admin">
                <div class="admin-hero">
                    <div>
                        <h2>لوحة الإدارة</h2>
                        <p>تحكم كامل بالإحصاءات، التقييمات، الدكاترة، والإشراف من مكان واحد.</p>
                    </div>
                    <div class="admin-nav">
                        <button type="button" class="btn nav-btn btn--accent" data-target="stats">📊 الإحصاءات العامة</button>
                        <button type="button" class="btn nav-btn" data-target="reviews">📥 إدارة التقييمات</button>
                        <button type="button" class="btn nav-btn" data-target="doctors">👨‍🏫 إدارة الدكاترة</button>
                        <button type="button" class="btn nav-btn" data-target="supervision">🔒 الإشراف والعمليات</button>
                    </div>
                </div>

                <div class="admin-section" data-admin-section="stats">
                    <div class="section-badge section-badge--stats">📊 الإحصاءات العامة</div>
                    <div class="admin-analytics">
                        <article>
                            <strong><?php echo $stats['approved']; ?></strong>
                            <span>إجمالي الدكاترة</span>
                        </article>
                        <article>
                            <strong><?php echo $stats['pending']; ?></strong>
                            <span>الدكاترة المعلقة</span>
                        </article>
                        <article>
                            <strong><?php echo $stats['reviews']; ?></strong>
                            <span>إجمالي التقييمات</span>
                        </article>
                        <article>
                            <strong><?php echo e($stats['top_college']); ?></strong>
                            <span>الكلية الأكثر تقييماً</span>
                        </article>
                    </div>

                    <section class="panel analytics-panel">
                        <h2>الإحصاءات المقارنة</h2>
                        <p>أفضل الدكاترة والأقسام والكلية الأكثر تفاعلاً خلال الأسبوع.</p>
                        <div class="analytics-grid">
                            <article class="analytics-card">
                                <h3>الكلية الأكثر تفاعلاً هذا الأسبوع</h3>
                                <strong><?php echo e($analytics['active_college']['name']); ?></strong>
                                <span><?php echo (int) $analytics['active_college']['count']; ?> تقييم خلال 7 أيام</span>
                            </article>
                            <article class="analytics-card analytics-card--chart">
                                <div class="chart-box__head">
                                    <h3>متوسط تقييم الأقسام</h3>
                                    <small>مقارنة سريعة</small>
                                </div>
                                <canvas class="comparison-chart" data-comparison-labels="<?php echo e(json_encode(array_column($analytics['departments'], 'department'), JSON_UNESCAPED_UNICODE)); ?>" data-comparison-values="<?php echo e(json_encode(array_map('floatval', array_column($analytics['departments'], 'avg_rating')), JSON_UNESCAPED_UNICODE)); ?>"></canvas>
                            </article>
                        </div>
                        <div class="top-doctors-grid">
                            <?php foreach ($analytics['top_doctors'] as $topDoctor): ?>
                                <article class="top-doctor-card">
                                    <h4><?php echo e($topDoctor['name']); ?></h4>
                                    <p><?php echo e('كلية ' . ($topDoctor['college'] ?? 'غير محددة')); ?> - <?php echo e($topDoctor['department'] ?? 'غير محدد'); ?></p>
                                    <strong><?php echo number_format((float) $topDoctor['avg_rating'], 1); ?></strong>
                                    <small><?php echo (int) $topDoctor['review_count']; ?> تقييم</small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div class="admin-section" data-admin-section="reviews" style="display:none;">
                    <div class="admin-actions-panel">
                        <div class="section-badge section-badge--reviews">📥 إدارة التقييمات</div>
                        <h3>التقييمات المعلقة</h3>
                        <div class="pending-reviews">
                            <?php if ($pending_reviews_result && mysqli_num_rows($pending_reviews_result) === 0): ?>
                                <p class="empty small">لا توجد تقييمات معلّقة حالياً.</p>
                            <?php endif; ?>

                            <?php if ($pending_reviews_result): ?>
                                <?php while ($review = mysqli_fetch_assoc($pending_reviews_result)): ?>
                                    <article class="admin-review-item">
                                        <div>
                                            <h4><?php echo e($review['doctor_name']); ?></h4>
                                            <p><?php echo e('كلية ' . ($review['college'] ?? 'غير محددة')); ?> - <?php echo e($review['department'] ?? 'غير محدد'); ?></p>
                                            <p><?php echo e('النوع: ' . (($review['gender'] ?? '') === 'female' ? 'دكتورة' : 'دكتور')); ?></p>
                                            <p class="review-item__course"><?php echo e(($review['course_code'] ?? '') . ' - ' . ($review['semester'] ?? '')); ?></p>
                                            <p class="review-sentiment review-sentiment--<?php echo e($review['sentiment'] ?? 'neutral'); ?>"><?php echo e(sentiment_label_ar($review['sentiment'] ?? 'neutral')); ?></p>
                                            <p><?php echo nl2br(e($review['comment'])); ?></p>
                                        </div>

                                        <form method="POST" action="admin.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="review_id" value="<?php echo (int) $review['id']; ?>">
                                            <button type="submit" name="review_action" value="approve" class="btn btn--accent">موافقة</button>
                                            <button type="submit" name="review_action" value="delete" class="btn btn--danger" data-confirm="هل أنت متأكد من حذف هذا التقييم؟">حذف</button>
                                        </form>
                                    </article>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>

                        <h3>التقييمات المعتمدة الأخيرة</h3>
                        <form method="GET" class="admin-filters">
                            <input type="text" name="filter_q" placeholder="بحث بالاسم أو تعليق" value="<?php echo e($filter_q); ?>">
                            <input type="text" name="filter_doctor_id" placeholder="معرف الدكتور" value="<?php echo $filter_doctor_id > 0 ? (int) $filter_doctor_id : ''; ?>">
                            <select name="filter_college" id="admin-filter-college">
                                <option value="">الكلية: الكل</option>
                                <?php foreach ($college_options as $college_name): ?>
                                    <option value="<?php echo e($college_name); ?>" <?php echo ($filter_college === $college_name) ? 'selected' : ''; ?>><?php echo e('كلية ' . $college_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="filter_department" id="admin-filter-dept" data-current-department="<?php echo e($filter_department); ?>">
                                <option value="">القسم: الكل</option>
                            </select>
                            <button type="submit" class="btn btn--dark">تطبيق الفلاتر</button>
                            <a href="admin.php" class="btn" style="text-decoration:none;">مسح</a>
                        </form>
                        <div class="approved-reviews">
                            <?php if ($approved_reviews_result && mysqli_num_rows($approved_reviews_result) === 0): ?>
                                <p class="empty small">لا توجد تقييمات معتمدة حديثاً.</p>
                            <?php endif; ?>

                            <?php if ($approved_reviews_result): ?>
                                <?php while ($arev = mysqli_fetch_assoc($approved_reviews_result)): ?>
                                    <article class="admin-review-item">
                                        <div>
                                            <h4><?php echo e($arev['doctor_name']); ?></h4>
                                            <p class="review-item__course"><?php echo e(($arev['course_code'] ?? '') . ' - ' . ($arev['semester'] ?? '')); ?></p>
                                            <p class="review-sentiment review-sentiment--<?php echo e($arev['sentiment'] ?? 'neutral'); ?>"><?php echo e(sentiment_label_ar($arev['sentiment'] ?? 'neutral')); ?></p>
                                            <p><?php echo nl2br(e($arev['comment'])); ?></p>
                                        </div>
                                        <form method="POST" action="admin.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="review_id" value="<?php echo (int) $arev['id']; ?>">
                                            <button type="submit" name="review_action" value="unapprove" class="btn btn--warning">تراجع الاعتماد</button>
                                        </form>
                                    </article>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-section" data-admin-section="doctors" style="display:none;">
                    <div class="admin-actions-panel">
                        <div class="section-badge section-badge--doctors">👨‍🏫 إدارة الدكاترة</div>
                       
                                اسم الدكتور
                                <input type="text" name="doc_name" placeholder="الاسم الثلاثي" required>
                            </label>
                            <label>
                                الكلية
                                <select id="colleges" name="college" required>
                                    <option value="">-- اختر الكلية --</option>
                                    <?php foreach ($college_options as $college_name): ?>
                                        <option value="<?php echo e($college_name); ?>"><?php echo e('كلية ' . $college_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                التخصص (القسم)
                                <select id="depts" name="department" required>
                                    <option value="">اختر الكلية أولاً...</option>
                                </select>
                            </label>
                            <label>
                                نوع الدكتور
                                <select name="gender" required>
                                    <option value="">-- اختر النوع --</option>
                                    <option value="male">دكتور</option>
                                    <option value="female">دكتورة</option>
                                </select>
                            </label>
                            <label class="full-width">
                                المواد
                                <input type="text" name="subjects" placeholder="مثال: داتابيز، شبكات">
                            </label>
                            <button type="submit" name="add_doc" class="btn btn--accent full-width">إضافة دكتور</button>
                        </form>

                        <h3>الدكاترة المعلقة</h3>
                        <div class="pending-doctors">
                            <?php if ($pending_doctors_result && mysqli_num_rows($pending_doctors_result) === 0): ?>
                                <p class="empty small">لا توجد دكاترة معلّقة حالياً.</p>
                            <?php endif; ?>

                            <?php if ($pending_doctors_result): ?>
                                <?php while ($pdoc = mysqli_fetch_assoc($pending_doctors_result)): ?>
                                    <article class="admin-doctor-item">
                                        <div>
                                            <h4><?php echo e($pdoc['name']); ?></h4>
                                            <p><?php echo e('كلية ' . ($pdoc['college'] ?? 'غير محددة')); ?> - <?php echo e($pdoc['department'] ?? 'غير محدد'); ?></p>
                                            <p class="doctor-courses"><?php echo e($pdoc['courses'] ?? ''); ?></p>
                                            <p><?php echo e('النوع: ' . (($pdoc['gender'] ?? '') === 'female' ? 'دكتورة' : 'دكتور')); ?></p>
                                        </div>

                                        <form method="POST" action="admin.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="doc_id" value="<?php echo (int) $pdoc['id']; ?>">
                                            <button type="submit" name="admin_action" value="approve" class="btn btn--accent">موافقة</button>
                                            <button type="submit" name="admin_action" value="delete" class="btn btn--danger" data-confirm="هل أنت متأكد من حذف هذا الدكتور وكل التقييمات المرتبطة به؟">حذف</button>
                                        </form>
                                    </article>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>

                        <h3>الدكاترة المعتمدون الأخيرة</h3>
                        <div class="approved-doctors">
                            <?php if ($approved_doctors_result && mysqli_num_rows($approved_doctors_result) === 0): ?>
                                <p class="empty small">لا توجد دكاترة معتمدة حديثاً.</p>
                            <?php endif; ?>

                            <?php if ($approved_doctors_result): ?>
                                <?php while ($ad = mysqli_fetch_assoc($approved_doctors_result)): ?>
                                    <article class="admin-doctor-item">
                                        <div>
                                            <h4><?php echo e($ad['name']); ?></h4>
                                            <p><?php echo e('كلية ' . ($ad['college'] ?? 'غير محددة')); ?> - <?php echo e($ad['department'] ?? 'غير محدد'); ?></p>
                                            <p class="doctor-courses"><?php echo e($ad['courses'] ?? ''); ?></p>
                                            <p><?php echo e('النوع: ' . (($ad['gender'] ?? '') === 'female' ? 'دكتورة' : 'دكتور')); ?></p>
                                        </div>

                                        <form method="POST" action="admin.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="doc_id" value="<?php echo (int) $ad['id']; ?>">
                                                <a href="add_doctor.php?doc_id=<?php echo (int) $ad['id']; ?>&return_to=<?php echo urlencode('admin.php?section=doctors'); ?>" class="btn">تعديل</a>
                                                <button type="submit" name="admin_action" value="delete" class="btn btn--danger" data-confirm="هل أنت متأكد من حذف هذا الدكتور وكل التقييمات المرتبطة به؟">حذف</button>
                                        </form>
                                    </article>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-section" data-admin-section="supervision" style="display:none;">
                    <div class="admin-actions-panel">
                        <div class="section-badge section-badge--supervision">🔒 الإشراف والعمليات</div>
                        <h3>إضافة مشرف جديد</h3>
                        <form method="POST" class="admin-login" style="margin-bottom:12px;">
                            <?php echo csrf_field(); ?>
                                <label>
                                    الكلية المسؤولة
                                    <?php if (!empty($admin_college)): ?>
                                        <div style="padding:8px 10px;background:#f3f5f7;border-radius:8px;margin-bottom:8px;"><?php echo e('كلية ' . $admin_college); ?></div>
                                        <input type="hidden" name="new_admin_college" value="<?php echo e($admin_college); ?>">
                                    <?php else: ?>
                                        <select name="new_admin_college" required>
                                            <option value="">اختر الكلية</option>
                                            <?php foreach ($college_options as $college_name): ?>
                                                <option value="<?php echo e($college_name); ?>"><?php echo e('كلية ' . $college_name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </label>
                            <label>
                                اسم المستخدم للمشرف الجديد
                                <input type="text" name="new_admin_username" placeholder="مثال: moderator" required>
                            </label>
                            <?php if (empty($admin_college)): ?>
                            <label>
                                دور المشرف
                                <select name="new_admin_role">
                                    <option value="college">مسؤول كلية</option>
                                    <option value="sub">مشرف فرعي</option>
                                </select>
                            </label>
                            <?php else: ?>
                            <input type="hidden" name="new_admin_role" value="sub">
                            <?php endif; ?>
                            <label>
                                صلاحية المشرف الفرعي
                                <select name="new_admin_permission">
                                    <option value="doctors">إدارة الدكاترة</option>
                                    <option value="reviews">إدارة التعليقات</option>
                                    <option value="both">كلاهما</option>
                                </select>
                            </label>
                            <label>
                                كلمة المرور
                                <input type="password" name="new_admin_password" placeholder="كلمة مرور قوية" required>
                            </label>
                            <button type="submit" name="add_admin" class="btn btn--accent">إضافة مشرف</button>
                        </form>

                        <h3>سجل العمليات</h3>
                        <form method="GET" class="admin-filters" style="margin-bottom:12px;">
                            <input type="text" name="audit_log_search" placeholder="بحث في السجل" value="<?php echo e($audit_log_search); ?>">
                            <input type="text" name="audit_log_admin" placeholder="اسم الأدمن" value="<?php echo e($audit_log_admin); ?>">
                            <select name="audit_log_action">
                                <option value="">كل العمليات</option>
                                <option value="approve_doctor" <?php echo $audit_log_action === 'approve_doctor' ? 'selected' : ''; ?>>اعتماد دكتور</option>
                                <option value="unapprove_doctor" <?php echo $audit_log_action === 'unapprove_doctor' ? 'selected' : ''; ?>>تراجع دكتور</option>
                                <option value="delete_doctor" <?php echo $audit_log_action === 'delete_doctor' ? 'selected' : ''; ?>>حذف دكتور</option>
                                <option value="approve_review" <?php echo $audit_log_action === 'approve_review' ? 'selected' : ''; ?>>اعتماد تقييم</option>
                                <option value="unapprove_review" <?php echo $audit_log_action === 'unapprove_review' ? 'selected' : ''; ?>>تراجع تقييم</option>
                                <option value="delete_review" <?php echo $audit_log_action === 'delete_review' ? 'selected' : ''; ?>>حذف تقييم</option>
                                <option value="add_admin" <?php echo $audit_log_action === 'add_admin' ? 'selected' : ''; ?>>إضافة مشرف</option>
                                <option value="create_first_admin" <?php echo $audit_log_action === 'create_first_admin' ? 'selected' : ''; ?>>إنشاء أول مشرف</option>
                            </select>
                            <select name="audit_log_target">
                                <option value="">كل الأهداف</option>
                                <option value="doctor" <?php echo $audit_log_target === 'doctor' ? 'selected' : ''; ?>>دكاترة</option>
                                <option value="review" <?php echo $audit_log_target === 'review' ? 'selected' : ''; ?>>تقييمات</option>
                                <option value="admin" <?php echo $audit_log_target === 'admin' ? 'selected' : ''; ?>>مشرفون</option>
                            </select>
                            <button type="submit" class="btn btn--dark">تطبيق فلتر السجل</button>
                            <a href="admin.php#supervision" class="btn" style="text-decoration:none;">مسح</a>
                        </form>
                        <div class="audit-log-list">
                            <?php foreach ($audit_logs as $log): ?>
                                <article class="audit-log-item">
                                    <strong><?php echo e($log['admin_name']); ?></strong>
                                    <p><?php echo e(audit_action_label_ar((string) ($log['action'] ?? ''))); ?> على <?php echo e(audit_target_label_ar($log)); ?></p>
                                    <small><?php echo e($log['created_at']); ?> - <?php echo e((string) ($log['ip_address'] ?? '')); ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <h3>المشرفون والكلية المسؤولة</h3>
                        <div class="admin-list">
                            <?php if ($admin_list && mysqli_num_rows($admin_list) === 0): ?>
                                <p class="empty small">لا توجد بيانات مشرفين.</p>
                            <?php endif; ?>
                            <?php if ($admin_list): ?>
                                <?php while ($admin = mysqli_fetch_assoc($admin_list)): ?>
                                    <article class="admin-item">
                                        <div>
                                            <h3><?php echo e($admin['username']); ?></h3>
                                            <p><?php echo e('الكلية المسؤولة: ' . college_label_ar($admin['college_responsibility'] ?? null)); ?></p>
                                            <small><?php echo e($admin['created_at']); ?></small>
                                        </div>
                                    </article>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <div id="admin-toasts" class="toast-stack"></div>
    <script>
        window.departmentsMap = <?php echo json_encode($departments_map, JSON_UNESCAPED_UNICODE); ?>;
        window.csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;
        window.isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        window.adminNotificationsUrl = <?php echo json_encode('admin.php?api=admin_notifications', JSON_UNESCAPED_UNICODE); ?>;
        window.isUser = <?php echo (!empty($_SESSION['user_id']) || !empty($_SESSION['is_admin'])) ? 'true' : 'false'; ?>;
    </script>
    <script>
        (function () {
            const buttons = document.querySelectorAll('.nav-btn');
            const sections = document.querySelectorAll('.admin-section');
            const urlParams = new URLSearchParams(window.location.search);
            const defaultTarget = urlParams.get('section') || 'stats';
            function showSection(target) {
                sections.forEach(function (section) {
                    section.style.display = section.dataset.adminSection === target ? '' : 'none';
                });
                buttons.forEach(function (button) {
                    button.classList.toggle('btn--accent', button.dataset.target === target);
                });
                const nextUrl = new URL(window.location.href);
                if (target && target !== 'stats') {
                    nextUrl.searchParams.set('section', target);
                } else {
                    nextUrl.searchParams.delete('section');
                }
                window.history.replaceState({}, '', nextUrl.toString());
            }
            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    showSection(button.dataset.target);
                });
            });
            showSection(defaultTarget);
            window.addEventListener('popstate', function () {
                const target = new URLSearchParams(window.location.search).get('section') || 'stats';
                showSection(target);
            });
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js"></script>
    <footer class="footer">
        <p>© جميع الحقوق محفوظة لعام ٢٠٢٦م — جامعة جازان</p>
        <p>تطوير وبرمجة المهندس: <strong>يحيى مكرشي</strong></p>
    </footer>
</body>
</html>