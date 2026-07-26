<?php
// منصة جامعة جازان للإرشاد والتقييم الأكاديمي - نسخة محسنة بالكامل بالعربية
$host = $_SERVER['HTTP_HOST'] ?? '';

require_once __DIR__ . '/inc/session_secure.php';
require_once __DIR__ . '/db.php';

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensure_review_column($conn, 'is_anonymous', 'TINYINT(1) NOT NULL DEFAULT 0');
$hasAnonymousColumn = review_column_exists($conn, 'is_anonymous');

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

function verify_csrf_request(): void
{
    $token = $_REQUEST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) {
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=UTF-8', true, 403);
            echo json_encode(['success' => false, 'error' => 'csrf']);
            exit();
        }
        die('فشل التحقق الأمني');
    }
}

// Content Security Policy to mitigate XSS while allowing the site to load correctly over HTTP/HTTPS and direct IP access
header("Content-Security-Policy: default-src 'self' http: https:; script-src 'self' 'unsafe-inline' https: http:; style-src 'self' 'unsafe-inline' https: http:; img-src 'self' data: https: http:; object-src 'none'; frame-ancestors 'none'; base-uri 'self';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function censor_profanity(string $text): string
{
    $words = ['كلب', 'غبي', 'قذر', 'حقير', 'زبالة', 'سخيف', 'تافه', 'نذل'];

    foreach ($words as $word) {
        $pattern = '/' . preg_quote($word, '/') . '/iu';
        $text = preg_replace($pattern, '***', $text) ?? $text;
    }

    return $text;
}

function review_lock_cookie_name(int $doctor_id): string
{
    return 'review_lock_' . $doctor_id;
}

function has_recent_review(mysqli $conn, int $doctor_id, string $ip_address): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT created_at FROM reviews WHERE doctor_id = ? AND ip_address = ? ORDER BY id DESC LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'is', $doctor_id, $ip_address);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$row || empty($row['created_at'])) {
        return false;
    }

    return (time() - strtotime($row['created_at'])) < 86400;
}

if (!function_exists('flash_error')) {
    function flash_error(string $message): void
    {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $message];
    }
}

if (!function_exists('flash_success')) {
    function flash_success(string $message): void
    {
        $_SESSION['flash'] = ['type' => 'success', 'text' => $message];
    }
}

if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin']) && !empty($_COOKIE['doctor_rating_auth'] ?? '')) {
    $authCookie = read_signed_auth_cookie();
    if (is_array($authCookie) && !empty($authCookie['user_id']) && !empty($authCookie['expires']) && (int) $authCookie['expires'] > time()) {
        $_SESSION['user_id'] = (int) $authCookie['user_id'];
        $_SESSION['user_name'] = (string) ($authCookie['user_name'] ?? 'مستخدم');
        if (($authCookie['type'] ?? 'user') === 'admin') {
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_id'] = (int) $authCookie['user_id'];
        }
    }
}

function review_column_exists(mysqli $conn, string $column_name): bool
{
    $stmt = $conn->prepare('SHOW COLUMNS FROM reviews LIKE ?');
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $column_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $exists;
}

function ensure_review_column(mysqli $conn, string $column_name, string $definition): void
{
    if (review_column_exists($conn, $column_name)) {
        return;
    }

    $sql = 'ALTER TABLE reviews ADD COLUMN ' . $column_name . ' ' . $definition;
    $conn->query($sql);
}

function fetch_review_distribution(mysqli $conn, int $doctor_id): array
{
    $distribution = array_fill(1, 5, 0);
    $stmt = mysqli_prepare($conn, "SELECT rating, COUNT(*) AS c FROM reviews WHERE doctor_id = ? GROUP BY rating");
    mysqli_stmt_bind_param($stmt, 'i', $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $distribution[(int) $row['rating']] = (int) $row['c'];
    }

    mysqli_stmt_close($stmt);
    return $distribution;
}

function fetch_recent_reviews(mysqli $conn, int $doctor_id, int $limit = 5): array
{
    $reviews = [];
    $limit = max(1, $limit);
    $selectSql = "SELECT rating, comment, reviewer_name, course_code, semester, created_at, sentiment";
    if (review_column_exists($conn, 'is_anonymous')) {
        $selectSql .= ', is_anonymous';
    }

    $stmt = mysqli_prepare(
        $conn,
        $selectSql . "
         FROM reviews
         WHERE doctor_id = ?
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    mysqli_stmt_bind_param($stmt, 'i', $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $reviews[] = $row;
    }

    mysqli_stmt_close($stmt);
    return $reviews;
}

function doctor_gender_label_ar(?string $gender): string
{
    return match ($gender) {
        'male' => 'دكتور',
        'female' => 'دكتورة',
        default => 'غير محدد',
    };
}

function sentiment_label_ar(?string $sentiment): string
{
    return match ($sentiment) {
        'positive' => 'إيجابي',
        'negative' => 'سلبي',
        default => 'محايد',
    };
}

function build_doctor_search_query(string $search_query, array $filters = []): array
{
    $conditions = ['d.is_approved = 1'];
    $types = '';
    $params = [];

    $gender = trim((string) ($filters['gender'] ?? ''));
    $college = trim((string) ($filters['college'] ?? ''));
    $department = trim((string) ($filters['department'] ?? ''));

    if ($search_query !== '') {
        $like = '%' . $search_query . '%';
        $conditions[] = '(d.name LIKE ? OR d.department LIKE ? OR d.college LIKE ? OR d.gender LIKE ?)';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    if ($gender === 'male' || $gender === 'female') {
        $conditions[] = 'd.gender = ?';
        $types .= 's';
        $params[] = $gender;
    }

    if ($college !== '') {
        $conditions[] = 'd.college = ?';
        $types .= 's';
        $params[] = $college;
    }

    if ($department !== '') {
        $conditions[] = 'd.department = ?';
        $types .= 's';
        $params[] = $department;
    }

    $sql = "SELECT d.id, d.name, d.college, d.department, d.gender, d.courses, COALESCE(AVG(r.rating), 0) AS avg_rating, COUNT(r.id) AS review_count
         FROM doctors d
         LEFT JOIN reviews r ON r.doctor_id = d.id
         WHERE " . implode(' AND ', $conditions) . "
         GROUP BY d.id
         ORDER BY d.id DESC";

    return [$sql, $types, $params];
}

if (!function_exists('bind_stmt_params')) {
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
}

function analyze_sentiment(string $text): array
{
    $normalized = mb_strtolower($text, 'UTF-8');
    $positiveWords = ['ممتاز', 'رائع', 'ممتعه', 'ممتعة', 'مفيد', 'واضح', 'لطيف', 'مبسط', 'متعاون', 'سهل', 'جيد', 'احسن', 'محترم', 'مذهل'];
    $negativeWords = ['سيء', 'سيئ', 'صعب', 'معقد', 'متشدد', 'مزعج', 'محبط', 'متعب', 'سيئة', 'سيئه', 'ظالم', 'ملل', 'قاسي'];

    $score = 0;

    foreach ($positiveWords as $word) {
        $score += preg_match_all('/' . preg_quote($word, '/') . '/iu', $normalized) ?: 0;
    }

    foreach ($negativeWords as $word) {
        $score -= preg_match_all('/' . preg_quote($word, '/') . '/iu', $normalized) ?: 0;
    }

    if ($score > 0) {
        return ['sentiment' => 'positive', 'score' => $score];
    }

    if ($score < 0) {
        return ['sentiment' => 'negative', 'score' => $score];
    }

    return ['sentiment' => 'neutral', 'score' => 0];
}

function browser_fingerprint_hash(string $payload): string
{
    return hash('sha256', $payload);
}

function review_ip_hash(string $ip_address): string
{
    return hash('sha256', $ip_address);
}

function has_recent_review_lock(mysqli $conn, int $doctor_id, ?string $fingerprint_hash, string $ip_address): bool
{
    $ip_hash = review_ip_hash($ip_address);
    $fingerprint_hash = $fingerprint_hash ?: '';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM review_locks
         WHERE doctor_id = ?
           AND expires_at > NOW()
           AND (fingerprint_hash = ? OR ip_hash = ?)
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $doctor_id, $fingerprint_hash, $ip_hash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $locked = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $locked;
}

function create_review_lock(mysqli $conn, int $doctor_id, ?string $fingerprint_hash, string $ip_address): void
{
    $ip_hash = review_ip_hash($ip_address);
    $fingerprint_hash = $fingerprint_hash ?: null;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO review_locks (doctor_id, fingerprint_hash, ip_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $doctor_id, $fingerprint_hash, $ip_hash);
    mysqli_stmt_execute($stmt);
    $stmt->close();
}

function current_admin_id(): int
{
    return !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
}

function log_admin_action(mysqli $conn, string $action, ?string $target_type = null, ?int $target_id = null, array $meta = []): void
{
    if (empty($_SESSION['is_admin'])) {
        return;
    }

    $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '{}';
    $adminId = current_admin_id();
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

function fetch_audit_logs(mysqli $conn, int $limit = 10, ?string $admin_college = null): array
{
    $limit = max(1, $limit);
    $logs = [];
    $where = '';
    $types = '';
    $params = [];
    if (!empty($admin_college)) {
        $where = 'WHERE COALESCE(ad.college_responsibility, "") = ?';
        $types = 's';
        $params[] = $admin_college;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.action, a.target_type, a.target_id, a.ip_address, a.created_at, COALESCE(ad.username, 'غير معروف') AS admin_name
         FROM audit_logs a
         LEFT JOIN admins ad ON ad.id = a.admin_id
         {$where}
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

function fetch_admin_notifications(mysqli $conn, ?string $admin_college = null): array
{
    $data = [
        'pending_count' => 0,
        'latest_pending' => [],
    ];

    if (!empty($admin_college)) {
        $stmt_count = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE r.status = 'pending' AND d.college = ?");
        mysqli_stmt_bind_param($stmt_count, 's', $admin_college);
        mysqli_stmt_execute($stmt_count);
        $res_count = mysqli_stmt_get_result($stmt_count);
        if ($r = mysqli_fetch_assoc($res_count)) {
            $data['pending_count'] = (int) ($r['c'] ?? 0);
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
            $data['latest_pending'][] = $row;
        }
        mysqli_stmt_close($stmt);
    } else {
        $data['pending_count'] = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews WHERE status = ?', 's', ['pending']);

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
            $data['latest_pending'][] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    return $data;
}

function build_search_payload(mysqli $conn, string $search_query): array
{
    [$sql, $types, $params] = build_doctor_search_query($search_query, [
        'gender' => $_GET['gender'] ?? '',
        'college' => $_GET['college'] ?? '',
        'department' => $_GET['department'] ?? '',
    ]);
    $results = [];

    $stmt = mysqli_prepare($conn, $sql);
    bind_stmt_params($stmt, $types, $params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($doc = mysqli_fetch_assoc($result)) {
        $doctor_id = (int) $doc['id'];
        $results[] = [
            'id' => $doctor_id,
            'name' => $doc['name'],
            'college' => $doc['college'],
            'department' => $doc['department'],
            'gender' => $doc['gender'] ?? null,
            'courses' => $doc['courses'],
            'avg_rating' => round((float) $doc['avg_rating'], 1),
            'review_count' => (int) $doc['review_count'],
            'rating_distribution' => fetch_review_distribution($conn, $doctor_id),
            'reviews' => fetch_recent_reviews($conn, $doctor_id, 5),
        ];
    }

    mysqli_stmt_close($stmt);

    return [
        'success' => true,
        'query' => $search_query,
        'count' => count($results),
        'results' => $results,
    ];
}

$admin_pass = getenv('ADMIN_PASS') ?: '123';
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

if (isset($_GET['api']) && $_GET['api'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(build_search_payload($conn, trim($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
    exit();
}

if (isset($_GET['api']) && $_GET['api'] === 'admin_notifications') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['success' => true] + fetch_admin_notifications($conn, $_SESSION['admin_college'] ?? null), JSON_UNESCAPED_UNICODE);
    exit();
}

if (isset($_GET['logout'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION = [];
    session_unset();
    session_destroy();

    $cookieParams = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => (bool) ($cookieParams['secure'] ?? false),
        'httponly' => (bool) ($cookieParams['httponly'] ?? true),
        'samesite' => $cookieParams['samesite'] ?? 'Lax',
    ]);

    clear_signed_auth_cookie();

    header('Location: login.php');
    exit();
}

$admins_count = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM admins');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $postedToken)) {
        flash_error('فشل التحقق الأمني، أعد تحميل الصفحة وحاول مرة أخرى.');
        header('Location: index');
        exit();
    }

        $hasSuggestedByUserIdColumn = false;
        $colStmt2 = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "doctors" AND COLUMN_NAME = "suggested_by_user_id" LIMIT 1');
        if ($colStmt2) {
            $dbName = defined('DB_NAME') ? DB_NAME : '';
            mysqli_stmt_bind_param($colStmt2, 's', $dbName);
            if (mysqli_stmt_execute($colStmt2)) {
                $colRes2 = mysqli_stmt_get_result($colStmt2);
                if (mysqli_fetch_assoc($colRes2)) $hasSuggestedByUserIdColumn = true;
            }
            mysqli_stmt_close($colStmt2);
        }

        $hasUserIdColumn = false;
        $colStmt3 = mysqli_prepare($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "reviews" AND COLUMN_NAME = "user_id" LIMIT 1');
        if ($colStmt3) {
            $dbName = defined('DB_NAME') ? DB_NAME : '';
            mysqli_stmt_bind_param($colStmt3, 's', $dbName);
            if (mysqli_stmt_execute($colStmt3)) {
                $colRes3 = mysqli_stmt_get_result($colStmt3);
                if (mysqli_fetch_assoc($colRes3)) $hasUserIdColumn = true;
            }
            mysqli_stmt_close($colStmt3);
        }

    if (isset($_POST['add_doc'])) {
        if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
            flash_error('يجب تسجيل الدخول لإضافة دكاترة.');
            header('Location: login');
            exit();
        }
        $name = trim($_POST['doc_name'] ?? '');
        $college = trim($_POST['college'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $subjects = trim($_POST['subjects'] ?? '');

        if ($name === '' || $college === '' || $department === '' || !in_array($gender, ['male', 'female'], true)) {
            flash_error('يرجى تعبئة اسم الدكتور والكلية والقسم ونوع الدكتور.');
            header('Location: index');
            exit();
        }

        if ($hasSuggestedByUserIdColumn && !empty($_SESSION['user_id'])) {
            $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved, suggested_by_user_id) VALUES (?, ?, ?, ?, ?, 0, ?)');
            $uid = (int) $_SESSION['user_id'];
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssssi', $name, $college, $department, $gender, $subjects, $uid);
                mysqli_stmt_execute($stmt);
                $newDoctorId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            } else {
                $newDoctorId = 0;
            }
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO doctors (name, college, department, gender, courses, is_approved) VALUES (?, ?, ?, ?, ?, 0)');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssss', $name, $college, $department, $gender, $subjects);
                mysqli_stmt_execute($stmt);
                $newDoctorId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
            } else {
                $newDoctorId = 0;
            }
        }

        if ($newDoctorId > 0) {
            log_admin_action($conn, 'add_doctor', 'doctor', $newDoctorId, ['name' => $name, 'college' => $college, 'department' => $department]);
            flash_success('تم إرسال الطلب للمراجعة، وسيظهر بعد اعتماد المسؤول.');
        } else {
            flash_error('تعذر إضافة الدكتور حالياً، يرجى المحاولة لاحقاً.');
        }
        header('Location: index.php');
        exit();
    }

    if (isset($_POST['add_review'])) {
        if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
            flash_error('يجب تسجيل الدخول لإرسال تقييم.');
            header('Location: login');
            exit();
        }
        $doc_id = (int) ($_POST['doc_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $teaching = (int) ($_POST['teaching_quality'] ?? 0);
        $fairness = (int) ($_POST['fairness'] ?? 0);
        $communication = (int) ($_POST['communication'] ?? 0);
        $reviewer = trim((string) ($_SESSION['user_name'] ?? ''));
        $comment = trim($_POST['comment'] ?? '');
        $comment = strip_tags($comment);
        $course_code = trim($_POST['course_code'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $consent = !empty($_POST['integrity_consent']);
        $fingerprint = trim($_POST['browser_fingerprint'] ?? '');
        $ip_address = client_ip();

        $dimensionRatings = [$teaching, $fairness, $communication];
        $dimensionSum = array_sum($dimensionRatings);
        $dimensionCount = count(array_filter($dimensionRatings, static fn($value) => $value > 0));
        $effectiveRating = $dimensionCount > 0 ? (int) round($dimensionSum / $dimensionCount) : $rating;

        if ($doc_id <= 0 || !$consent || $comment === '' || $course_code === '' || $semester === '' || $dimensionCount < 3) {
            flash_error('الرجاء اختيار تقييمات الثلاثة الأبعاد والموافقة على المعلومة قبل الإرسال مع المادة والفصل الدراسي.');
            header('Location: index');
            exit();
        }

        if (has_recent_review($conn, $doc_id, $ip_address)) {
            flash_error('تم تسجيل تقييم لهذا الدكتور من نفس الجهاز خلال آخر 24 ساعة.');
            header('Location: index');
            exit();
        }

        $fingerprintHash = $fingerprint !== '' ? browser_fingerprint_hash($fingerprint) : null;

        if (has_recent_review_lock($conn, $doc_id, $fingerprintHash, $ip_address)) {
            flash_error('تم تسجيل تقييم لهذا الدكتور من هذا المتصفح خلال آخر 24 ساعة.');
            header('Location: index');
            exit();
        }

        if (!empty($_SESSION['review_locks'][$doc_id]) && (time() - (int) $_SESSION['review_locks'][$doc_id]) < 86400) {
            flash_error('تم تسجيل تقييم لهذا الدكتور من هذه الجلسة خلال آخر 24 ساعة.');
            header('Location: index');
            exit();
        }

        $cookieLockName = review_lock_cookie_name($doc_id);
        if (!empty($_COOKIE[$cookieLockName]) && (time() - (int) $_COOKIE[$cookieLockName]) < 86400) {
            flash_error('تم تسجيل تقييم لهذا الدكتور من هذا المتصفح خلال آخر 24 ساعة.');
            header('Location: index');
            exit();
        }

        $comment = censor_profanity($comment);
        $reviewAnalysis = analyze_sentiment($comment);
        $sentiment = $reviewAnalysis['sentiment'];
        $isAnonymous = !empty($_POST['is_anonymous']) ? 1 : 0;
        $rating = max(1, min(5, $effectiveRating));

        if ($reviewer === '') {
            flash_error('يجب أن يكون التعليق باسم الحساب المسجل.');
            header('Location: index');
            exit();
        }

        // يتم حفظ التقييم بوضع الموافقة 'approved' لكي يظهر فوراً على الموقع
        $hasExplanationStars = review_column_exists($conn, 'explanation_stars');
        $hasHandlingStars = review_column_exists($conn, 'handling_stars');
        $hasGradingStars = review_column_exists($conn, 'grading_stars');

        $insertColumns = ['doctor_id', 'rating', 'comment', 'reviewer_name', 'course_code', 'semester', 'ip_address', 'sentiment', 'status'];
        $bindTypes = 'iissssssi';
        $bindValues = [$doc_id, $rating, $comment, $reviewer, $course_code, $semester, $ip_address, $sentiment, 'approved'];

        if ($hasUserIdColumn && !empty($_SESSION['user_id'])) {
            $insertColumns[] = 'user_id';
            $bindTypes .= 'i';
            $bindValues[] = (int) $_SESSION['user_id'];
        }

        if ($hasAnonymousColumn) {
            $insertColumns[] = 'is_anonymous';
            $bindTypes .= 'i';
            $bindValues[] = $isAnonymous;
        }

        if ($hasExplanationStars) {
            $insertColumns[] = 'explanation_stars';
            $bindTypes .= 'i';
            $bindValues[] = $teaching;
        }

        if ($hasHandlingStars) {
            $insertColumns[] = 'handling_stars';
            $bindTypes .= 'i';
            $bindValues[] = $fairness;
        }

        if ($hasGradingStars) {
            $insertColumns[] = 'grading_stars';
            $bindTypes .= 'i';
            $bindValues[] = $communication;
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $sql = 'INSERT INTO reviews (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
        $stmt = mysqli_prepare($conn, $sql);

        $review_id = 0;
        if ($stmt) {
            $bindParams = [$stmt, $bindTypes];
            foreach ($bindValues as $index => $value) {
                $bindParams[] = &$bindValues[$index];
            }
            call_user_func_array('mysqli_stmt_bind_param', $bindParams);
            if (mysqli_stmt_execute($stmt)) {
                $review_id = (int) mysqli_insert_id($conn);
            }
            mysqli_stmt_close($stmt);
        }

        if ($review_id <= 0) {
            flash_error('تعذر حفظ التقييم حالياً، يرجى المحاولة مرة أخرى.');
            header('Location: index.php');
            exit();
        }

        $_SESSION['review_locks'][$doc_id] = time();
        setcookie(review_lock_cookie_name($doc_id), (string) time(), [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        create_review_lock($conn, $doc_id, $fingerprintHash, $ip_address);
        log_admin_action($conn, 'add_review', 'review', $review_id, ['doctor_id' => $doc_id, 'sentiment' => $sentiment]);

        flash_success('شكرًا لك، تم إرسال تقييمك للمراجعة بنجاح وسيظهر فور اعتماده.');
        header('Location: index.php');
        exit();
    }
}

$search_query = trim($_GET['q'] ?? '');
$search_filters = [
    'gender' => trim($_GET['gender'] ?? ''),
    'college' => trim($_GET['college'] ?? ''),
    'department' => trim($_GET['department'] ?? ''),
];

[$approved_sql, $approved_types, $approved_params] = build_doctor_search_query($search_query, $search_filters);

$approved_stmt = mysqli_prepare($conn, $approved_sql);
bind_stmt_params($approved_stmt, $approved_types, $approved_params);
mysqli_stmt_execute($approved_stmt);
$approved_result = mysqli_stmt_get_result($approved_stmt);

$stats = [
    'approved' => 0,
    'pending' => 0,
    'reviews' => 0,
    'pending_reviews' => 0,
];

// fetch approved/pending doctors counts via prepared statement
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

// use helper to fetch simple counts
$stats['reviews'] = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews WHERE status = ?', 's', ['approved']);
$stats['pending_reviews'] = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews WHERE status = ?', 's', ['pending']);

// top college by approved reviews
$top_college = 'غير متوفر';
$top_college_count = 0;
$stmt = mysqli_prepare($conn, "SELECT d.college, COUNT(r.id) AS review_total FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id AND d.is_approved = 1 WHERE r.status = 'approved' GROUP BY d.college ORDER BY review_total DESC LIMIT 1");
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($top_row = mysqli_fetch_assoc($res)) {
        $top_college = $top_row['college'] ?? 'غير متوفر';
        $top_college_count = (int) ($top_row['review_total'] ?? 0);
    }
    mysqli_stmt_close($stmt);
}

$stats['top_college'] = $top_college;
$stats['top_college_count'] = $top_college_count;

$analytics = [];
$analytics['average_rating'] = $approved_result && mysqli_num_rows($approved_result) > 0 ? round($stats['reviews'] > 0 ? ($stats['approved'] > 0 ? ($stats['approved'] * 4.5) / $stats['approved'] : 4.5) : 4.5, 1) : 0.0;
$analytics['attendance_trend'] = 'مستقر';
$analytics['top_subject'] = 'مقدمة في البرمجة';
$analytics['active_students'] = max(1, (int) $stats['approved'] + 2);
$analytics['pending_actions'] = max(0, (int) $stats['pending'] + 1);

$audit_logs = fetch_audit_logs($conn, 8, $_SESSION['admin_college'] ?? null);
?>
<?php
// تحقق هل المستخدم ظهرت له الشروط من قبل؟
$showTermsModal = false;
if (!isset($_SESSION['has_seen_terms'])) {
    $showTermsModal = true;
    $_SESSION['has_seen_terms'] = true; // تم وضع العلامة حتى لا تظهر له مرة أخرى
}

$showUnauthorizedAlert = isset($_GET['error']) && $_GET['error'] === 'unauthorized';
$canSeeAdminPanel = (!empty($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin') || !empty($_SESSION['is_admin']);

if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin']) && !empty($_COOKIE['doctor_rating_auth'] ?? '')) {
    $authCookie = read_signed_auth_cookie();
    if (is_array($authCookie) && !empty($authCookie['user_id']) && !empty($authCookie['expires']) && (int) $authCookie['expires'] > time()) {
        $_SESSION['user_id'] = (int) $authCookie['user_id'];
        $_SESSION['user_name'] = (string) ($authCookie['user_name'] ?? 'مستخدم');
        if (($authCookie['type'] ?? 'user') === 'admin') {
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_id'] = (int) $authCookie['user_id'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>منصة مسار الأكاديمية</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="page">
        <header class="hero">
            <div class="hero__content">
                <h1>منصة مسار الأكاديمية</h1>
                <p class="hero__text">المنظومة السحابية الذكية للإرشاد الأكاديمي والتقييم الجامعي.</p>
            <div class="hero__actions">
                    <?php if ($showUnauthorizedAlert): ?>
                        <div class="alert alert-danger" role="alert" style="margin-bottom: 16px; width: 100%; text-align: right;">
                            <strong>عذراً</strong> لا تملك صلاحية الوصول لهذه الصفحة.
                        </div>
                    <?php endif; ?>
                    <?php $isLoggedIn = (!empty($_SESSION['is_admin']) || !empty($_SESSION['user_id']) || (!empty($_COOKIE['doctor_rating_auth'] ?? '') && !empty(read_signed_auth_cookie()['user_id']))); ?>
                    <?php if ($canSeeAdminPanel): ?>
                        <a class="btn btn--light" href="admin/index.php">لوحة الإدارة</a>
                        <a class="btn btn--accent" href="profile.php">ملفي الشخصي</a>
                        <a class="btn btn--accent" href="schedule.php">الجدول الأكاديمي</a>
                        <a class="btn btn--light" href="curriculum.php">المسار الأكاديمي</a>
                        <a class="btn btn--light" href="syllabus.php">📚 الخطة الدراسية</a>
                        <a class="btn btn--light" href="attendance.php">سجل الحضور</a>
                        <a class="btn btn--light" href="telegram_link.php">ربط التليجرام</a>
                        <a class="btn btn--accent" href="index.php?logout">تسجيل الخروج</a>
                    <?php endif; ?>
                    <?php if ((!empty($_SESSION['user_id']) || (!empty($_COOKIE['doctor_rating_auth'] ?? '') && !empty(read_signed_auth_cookie()['user_id']))) && empty($_SESSION['is_admin'])): ?>
                        <a class="btn btn--accent" href="profile.php">الملف الشخصي</a>
                        <a class="btn btn--light" href="schedule.php">الجدول الأكاديمي</a>
                        <a class="btn btn--light" href="curriculum.php">المسار الأكاديمي</a>
                        <a class="btn btn--light" href="syllabus.php">📚 الخطة الدراسية</a>
                        <a class="btn btn--light" href="attendance.php">سجل الحضور</a>
                        <a class="btn btn--light" href="telegram_link.php">ربط التليجرام</a>
                        <a class="btn btn--accent" href="index.php?logout">تسجيل الخروج</a>
                    <?php endif; ?>
                    <?php if (!$isLoggedIn): ?>
                        <a class="btn btn--accent" href="login.php">تسجيل الدخول</a>
                        <a class="btn btn--light" href="register.php">إنشاء حساب</a>
                        <a class="btn btn--light" href="schedule.php">الجدول الأكاديمي</a>
                        <a class="btn btn--light" href="curriculum.php">المسار الأكاديمي</a>
                        <a class="btn btn--light" href="attendance.php">سجل الحضور</a>
                        <a class="btn btn--accent" href="syllabus.php">📚 الخطة الدراسية</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <section class="stats" aria-label="إحصائيات المنصة">
            <article class="stat-card">
                <h2><?php echo $stats['approved']; ?></h2>
                <p>دكتور معتمد</p>
            </article>
            <article class="stat-card">
                <h2><?php echo $stats['pending']; ?></h2>
                <p>طلب بانتظار المراجعة</p>
            </article>
            <article class="stat-card">
                <h2><?php echo $stats['reviews']; ?></h2>
                <p>تقييم منشور</p>
            </article>
            <article class="stat-card">
                <h2><?php echo $stats['pending_reviews']; ?></h2>
                <p>تقييمات معلّقة</p>
            </article>
            <article class="stat-card">
                <h2><?php echo $stats['top_college_count']; ?></h2>
                <p>الكلية الأكثر تقييماً</p>
                <small><?php echo e($stats['top_college']); ?></small>
            </article>
        </section>

        <?php if ($flash): ?>
            <section class="flash flash--<?php echo e($flash['type']); ?>">
                <?php echo e($flash['text']); ?>
            </section>
        <?php endif; ?>

        <section class="panel" aria-label="الأدوات الأكاديمية">
            <h2>أدواتي الأكاديمية</h2>
            <p>تعرّف على الجدول الدراسي والخطة الأكاديمية وسجل الحضور مباشرة من واجهة موحدة.</p>
            <div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <a class="doctor-card" href="schedule.php" style="text-decoration:none; display:block;">
                    <h3>📅 جدول دراستي</h3>
                    <p>الجدول الأسبوعي والمواعيد الذكية.</p>
                </a>
                <a class="doctor-card" href="curriculum.php" style="text-decoration:none; display:block;">
                    <h3>🧭 خطتي الأكاديمية</h3>
                    <p>الخطة المتقدمة والمتطلبات المسبقة.</p>
                </a>
                <a class="doctor-card" href="attendance.php" style="text-decoration:none; display:block;">
                    <h3>✅ سجل الحضور</h3>
                    <p>مراقبة الالتزام والحضور ولوحة التقدم.</p>
                </a>
                <a class="doctor-card" href="telegram_link.php" style="text-decoration:none; display:block;">
                    <h3>🤖 ربط التليجرام</h3>
                    <p>ربط حسابك مع البوت لتلقي تنبيهات الحضور قبل الكسل.</p>
                </a>
            </div>
        </section>

        <section class="panel" id="add-doctor">
            <h2>إضافة دكتور جديد</h2>
            <p>سيتم نشر الاسم بعد مراجعة المسؤول للحفاظ على جودة المحتوى.</p>
            <?php if (!empty($_SESSION['user_id']) || !empty($_SESSION['is_admin'])): ?>
            <form method="POST" action="save.php" class="form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="return_to" value="index">
                <label>
                    اسم الدكتور
                    <input type="text" name="doc_name" placeholder="الاسم الثلاثي" required>
                </label>

                <label>
                    الكلية
                    <select name="college" id="college" required onchange="window.updateAddDoctorDepartments && window.updateAddDoctorDepartments()">
                        <option value="">اختر الكلية</option>
                        <?php foreach ($departments_map as $college_name => $depts): ?>
                            <option value="<?php echo e($college_name); ?>"><?php echo e($college_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    القسم
                    <select name="department" id="department" required>
                        <option value="">اختر الكلية أولًا</option>
                    </select>
                </label>

                <label>
                    نوع الدكتور
                    <select name="gender" required>
                        <option value="">اختر النوع</option>
                        <option value="male">دكتور</option>
                        <option value="female">دكتورة</option>
                    </select>
                </label>

                <label class="full-width">
                    المواد التي يدرّسها
                    <input type="text" name="subjects" placeholder="مثال: إدارة قواعد البيانات - برمجة ويب">
                </label>

                <button type="submit" name="add_doc" class="btn btn--primary full-width">إرسال للمراجعة</button>
            </form>
            <?php else: ?>
                <p>يجب تسجيل الدخول لتتمكن من طلب إضافة دكتور. <a href="login.php">تسجيل الدخول</a> أو <a href="register.php">إنشاء حساب</a></p>
            <?php endif; ?>
        </section>

        <section class="panel" id="search">
            <h2>البحث عن دكتور</h2>
            <form method="GET" class="search-form" id="live-search-form" data-current-department="<?php echo e($search_filters['department'] ?? ''); ?>">
                <input type="text" name="q" id="search-input" value="<?php echo e($search_query); ?>" placeholder="ابحث باسم الدكتور/الدكتورة أو الكلية أو القسم">
                <div class="search-hint">مثال: "أحمد" أو ابحث بعبارة مثل "الهندسة وعلوم الحاسب"</div>
                <select name="gender" id="search-gender">
                    <option value="">النوع: الكل</option>
                    <option value="male" <?php echo ($search_filters['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>دكتور</option>
                    <option value="female" <?php echo ($search_filters['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>دكتورة</option>
                </select>
                <select name="college" id="search-colleges">
                    <option value="">الكلية: الكل</option>
                    <?php foreach ($departments_map as $college_name => $depts): ?>
                        <option value="<?php echo e($college_name); ?>" <?php echo ($search_filters['college'] ?? '') === $college_name ? 'selected' : ''; ?>><?php echo e($college_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="department" id="search-depts">
                    <option value="">القسم: الكل</option>
                </select>
                <button type="submit" class="btn btn--dark">بحث</button>
            </form>
        </section>

        <section class="panel" id="results" data-live-search-root="1">
            <h2>نتائج التقييمات</h2>
            <div class="cards">
                <?php if (mysqli_num_rows($approved_result) === 0): ?>
                    <p class="empty">لا توجد نتائج حالياً. جرّب إضافة دكتور أو غيّر كلمات البحث.</p>
                <?php endif; ?>

                <?php while ($doc = mysqli_fetch_assoc($approved_result)): ?>
                    <?php
                    $rating_distribution = fetch_review_distribution($conn, (int) $doc['id']);
                    $rounded_rating = (int) round((float) $doc['avg_rating']);
                    ?>
                    <article class="doctor-card">
                        <div class="doctor-card__head">
                            <div>
                                <h3><?php echo e($doc['name']); ?> <small class="doctor-badge"><?php echo e(doctor_gender_label_ar($doc['gender'] ?? null)); ?></small></h3>
                                <p><?php echo e(($doc['college'] ?? 'غير محددة') . ' - ' . ($doc['department'] ?? 'غير محدد')); ?></p>
                            </div>
                            <div class="rating-pill">
                                <strong><?php echo number_format((float) $doc['avg_rating'], 1); ?></strong>
                                <div class="rating-stars" aria-label="<?php echo e('التقييم ' . number_format((float) $doc['avg_rating'], 1) . ' من 5'); ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="rating-stars__star <?php echo $i <= $rounded_rating ? 'is-filled' : ''; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <span>من 5</span>
                                <small><?php echo (int) $doc['review_count']; ?> تقييم</small>
                            </div>
                        </div>

                        <div class="doctor-card__meta">
                            <span class="meta-chip meta-chip--highlight">
                                <strong><?php echo number_format((float) $doc['avg_rating'], 1); ?></strong>
                                <span>متوسط التقييم</span>
                            </span>
                            <span class="meta-chip">
                                <strong><?php echo (int) $doc['review_count']; ?></strong>
                                <span>تقييمات</span>
                            </span>
                            <span class="meta-chip">
                                <strong><?php echo e(doctor_gender_label_ar($doc['gender'] ?? null)); ?></strong>
                                <span>النوع</span>
                            </span>
                        </div>

                        <?php $review_result = fetch_recent_reviews($conn, (int) $doc['id'], 5); ?>

                        <div class="reviews">
                            <?php if (count($review_result) === 0): ?>
                                <p class="empty small">لا توجد تقييمات لهذا الدكتور حتى الآن.</p>
                            <?php endif; ?>

                            <?php foreach ($review_result as $r): ?>
                                <div class="review-item">
                                    <p class="review-item__meta">
                                        <div class="rating-stars" aria-label="تقييم <?php echo (int) $r['rating']; ?> من 5">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="rating-stars__star <?php echo $i <= (int) $r['rating'] ? 'is-filled' : ''; ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <strong><?php echo e(((!empty($r['is_anonymous']) && (int) $r['is_anonymous'] === 1) ? 'مجهول' : ($r['reviewer_name'] ?: 'طالب'))); ?></strong>
                                        <span class="review-sentiment review-sentiment--<?php echo e($r['sentiment'] ?? 'neutral'); ?>"><?php echo e(sentiment_label_ar($r['sentiment'] ?? 'neutral')); ?></span>
                                    </p>
                                    <p class="review-item__course"><?php echo e(($r['course_code'] ?? '') . ' - ' . ($r['semester'] ?? '')); ?></p>
                                    <p><?php echo nl2br(e($r['comment'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($_SESSION['user_id']) || !empty($_SESSION['is_admin'])): ?>
                        <form method="POST" action="save.php" class="review-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                            <input type="hidden" name="add_review" value="1">

                            <label>
                                اسم الحساب
                                <input type="text" value="<?php echo e($_SESSION['user_name'] ?? 'مستخدم'); ?>" readonly>
                            </label>

                            <label>
                                كود المادة
                                <input type="text" name="course_code" placeholder="مثال: COMP-111" required>
                            </label>

                            <label>
                                الفصل الدراسي
                                <input type="text" name="semester" placeholder="مثال: الفصل الأول ١٤٤٧هـ" required>
                            </label>

                                            <div class="field-group full-width">
                                <span class="field-label">أبعاد التقييم</span>
                                <div class="review-dimensions">
                                    <label>الشرح والتدريس
                                        <select name="teaching_quality" required>
                                            <option value="">اختر</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </label>
                                    <label>العدالة والشفافية
                                        <select name="fairness" required>
                                            <option value="">اختر</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </label>
                                    <label>التواصل والتفاعل
                                        <select name="communication" required>
                                            <option value="">اختر</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <label class="full-width">
                                <span style="color:#9ca3af;">التعليق النصي غير متاح حاليًا</span>
                            </label>

                            <label class="toggle full-width">
                                <input type="checkbox" name="integrity_consent" value="1" required>
                                <span class="toggle__slider"></span>
                                <span class="toggle__label">أؤكد أن التقييم صادق ويحترم قواعد المنصة</span>
                            </label>

                            <label class="toggle full-width">
                                <input type="checkbox" name="is_anonymous" value="1">
                                <span class="toggle__slider"></span>
                                <span class="toggle__label">تقييم باسم مجهول</span>
                            </label>

                            <input type="hidden" name="browser_fingerprint" data-fingerprint-field value="">
                            <button type="submit" name="add_review" value="1" class="btn btn--accent full-width">إرسال التقييم</button>
                        </form>
                        <?php else: ?>
                            <p style="background:#f3f4f6; padding:12px; border-radius:8px; text-align:center;">يجب <a href="login.php" style="color:#0a7e8c; font-weight:bold;">تسجيل الدخول</a> لإرسال تقييم أو <a href="register.php" style="color:#0a7e8c; font-weight:bold;">إنشاء حساب</a></p>
                        <?php endif; ?>
                    </article>
                <?php endwhile; ?>
            </div>
        </section>

        <footer class="footer">
            <p>© «جميع الحقوق محفوظة لعام ٢٠٢٦م — منصة مسار الأكاديمية (مبادرة طلابية مستقلة غير رسمية)» [Masar]</p>
            <p>تطوير وبرمجة المهندس: <strong>يحيى مكرشي</strong></p>
        </footer>
    </main>

    <div id="syllabus-coming-soon-modal-root" style="display:none;"></div>

    <div id="ai-chat-widget" class="ai-chat-widget" aria-live="polite">
        <button id="ai-chat-toggle" class="ai-chat-toggle" type="button" aria-expanded="false" aria-controls="ai-chat-panel">
            <span class="ai-chat-icon">🤖</span>
            <span class="ai-chat-label">مساعد الذكاء الاصطناعي</span>
        </button>

        <div id="ai-chat-panel" class="ai-chat-panel" hidden>
            <div class="ai-chat-header">
                <strong>مساعد الطلاب الذكي</strong>
                <button id="ai-chat-close" type="button" aria-label="إغلاق">×</button>
            </div>
            <div id="ai-chat-messages" class="ai-chat-messages">
                <div class="ai-chat-bubble ai-chat-bubble--bot">أهلاً! اسألني عن أفضل دكتور أو مادة بناءً على تقييمات الطلاب.</div>
            </div>
            <div class="ai-chat-actions">
                <button type="button" id="ai-complaint-btn" class="ai-chat-action-btn">شكوى</button>
                <button type="button" id="ai-suggestion-btn" class="ai-chat-action-btn">اقتراح / ميزة</button>
                <button type="button" id="ai-contact-dev-btn" class="ai-chat-action-btn ai-chat-action-btn--secondary">تواصل مع المطور</button>
            </div>
            <form id="ai-chat-form" class="ai-chat-form">
                <label for="ai-chat-input" class="sr-only">سؤالك</label>
                <textarea id="ai-chat-input" class="ai-chat-textarea" rows="2" placeholder="مثال: من أفضل دكتور لمادة تراكيب البيانات؟" autocomplete="off" required></textarea>
                <button type="submit" class="ai-chat-send" aria-label="أرسل السؤال">➡</button>
            </form>
        </div>
    </div>

    <style>
        .ai-chat-widget {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1200;
            font-family: Tahoma, Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        .ai-chat-toggle {
            border: 0;
            background: linear-gradient(135deg, #0a7e8c 0%, #0d5f78 100%);
            color: #fff;
            border-radius: 999px;
            padding: 12px 16px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 14px 36px rgba(0,0,0,.18);
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .ai-chat-toggle:hover,
        .ai-chat-toggle:focus-visible {
            transform: translateY(-1px);
            box-shadow: 0 18px 45px rgba(0,0,0,.25);
        }
        .ai-chat-icon {
            font-size: 18px;
        }
        .ai-chat-panel {
            width: min(400px, calc(100vw - 32px));
            max-width: 420px;
            margin-top: 8px;
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 16px 40px rgba(0,0,0,.12);
            border: 1px solid #d2e7f3;
        }
        .ai-chat-header {
            background: #0f4c81;
            color: #fff;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .ai-chat-header button {
            background: transparent;
            color: #fff;
            border: 0;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
        }
        .ai-chat-messages {
            max-height: 320px;
            overflow-y: auto;
            padding: 14px;
            background: #f4fbff;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .ai-chat-bubble {
            padding: 12px 14px;
            border-radius: 16px;
            max-width: 92%;
            line-height: 1.65;
            font-size: 14px;
            word-break: break-word;
        }
        .ai-chat-bubble--bot {
            background: #e8f3ff;
            color: #1f4f75;
            align-self: flex-start;
        }
        .ai-chat-bubble--user {
            background: #0a7e8c;
            color: #fff;
            align-self: flex-end;
        }
        .ai-chat-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid #e5eff6;
            background: #fff;
        }
        .ai-chat-actions {
            display: flex;
            gap: 10px;
            padding: 12px 16px 0;
            background: #fff;
        }
        .ai-chat-action-btn {
            flex: 1;
            border: 1px solid #d3e7f2;
            border-radius: 16px;
            background: #f7fbff;
            color: #0f4c81;
            font-weight: 600;
            padding: 10px 12px;
            cursor: pointer;
            transition: background .18s ease, border-color .18s ease;
        }
        .ai-chat-action-btn--secondary {
            background: #eef2ff;
            border-color: #c7d2fe;
            color: #4338ca;
        }
        .ai-chat-action-btn:hover,
        .ai-chat-action-btn:focus-visible {
            background: #e6f2fb;
            border-color: #0a7e8c;
        }
        .ai-chat-textarea {
            flex: 1;
            min-height: 56px;
            max-height: 120px;
            resize: vertical;
            border: 1px solid #d3e7f2;
            border-radius: 18px;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 14px;
            line-height: 1.5;
            outline: none;
        }
        .ai-chat-textarea:focus {
            border-color: #0a7e8c;
            box-shadow: 0 0 0 3px rgba(10,126,140,.12);
        }
        .ai-chat-send {
            border: 0;
            background: #0a7e8c;
            color: #fff;
            border-radius: 16px;
            width: 48px;
            min-width: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: background .18s ease;
        }
        .ai-chat-send:hover,
        .ai-chat-send:focus-visible {
            background: #084a6c;
        }
        @media (max-width: 768px) {
            .ai-chat-widget {
                bottom: 12px;
                left: 12px;
                right: 12px;
                align-items: stretch;
            }
            .ai-chat-panel {
                width: 100%;
            }
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            white-space: nowrap;
            border: 0;
        }
    </style>

    <script>
        (function () {
            var toggle = document.getElementById('ai-chat-toggle');
            var panel = document.getElementById('ai-chat-panel');
            var close = document.getElementById('ai-chat-close');
            var form = document.getElementById('ai-chat-form');
            var input = document.getElementById('ai-chat-input');
            var messages = document.getElementById('ai-chat-messages');
            var complaintBtn = document.getElementById('ai-complaint-btn');
            var suggestionBtn = document.getElementById('ai-suggestion-btn');
            var contactDevBtn = document.getElementById('ai-contact-dev-btn');
            var sendButton = form.querySelector('.ai-chat-send');

            if (!toggle || !panel || !form || !input || !messages || !complaintBtn || !suggestionBtn || !contactDevBtn) {
                return;
            }

            function setOpen(open) {
                panel.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function setSending(isSending) {
                if (!sendButton) {
                    return;
                }
                sendButton.disabled = isSending;
                sendButton.setAttribute('aria-busy', isSending ? 'true' : 'false');
                sendButton.style.opacity = isSending ? '0.7' : '1';
                sendButton.style.cursor = isSending ? 'wait' : 'pointer';
            }

            toggle.addEventListener('click', function () {
                setOpen(panel.hidden);
            });

            close.addEventListener('click', function () {
                setOpen(false);
            });

            complaintBtn.addEventListener('click', function () {
                setOpen(true);
                input.value = 'لدي شكوى حول تجربة الموقع أو خدمة التقييمات، الرجاء مساعدتي.';
                input.focus();
            });

            suggestionBtn.addEventListener('click', function () {
                setOpen(true);
                input.value = 'لدي اقتراح لتحسين الموقع أو إضافة ميزة جديدة.';
                input.focus();
            });

            contactDevBtn.addEventListener('click', function () {
                setOpen(true);
                var contactMessage = 'يمكنك التواصل مع المطور عبر تليجرام: @mYJYQD';
                var botBubble = document.createElement('div');
                botBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                botBubble.textContent = contactMessage;
                messages.appendChild(botBubble);
                messages.scrollTop = messages.scrollHeight;
                input.value = 'أريد التواصل مع المطور عبر التليجرام.';
                input.focus();
                window.open('https://t.me/mYJYQD', '_blank', 'noopener');
            });

            function classifySupportCategory(rawQuestion) {
                var q = String(rawQuestion || '').trim();
                if (!q) {
                    return 'general';
                }
                var normalized = q.toLowerCase();
                if (normalized.indexOf('تواصل مع المطور') !== -1 || normalized.indexOf('المطور') !== -1 || normalized.indexOf('developer') !== -1) {
                    return 'developer_contact';
                }
                if (normalized.indexOf('اقتراح') !== -1 || normalized.indexOf('ميزة') !== -1 || normalized.indexOf('أقترح') !== -1) {
                    return 'suggestion';
                }
                if (normalized.indexOf('مشكلة') !== -1 || normalized.indexOf('شكوى') !== -1 || normalized.indexOf('خطأ') !== -1 || normalized.indexOf('تعذر') !== -1 || normalized.indexOf('فشل') !== -1) {
                    return 'problem';
                }
                return 'general';
            }

            function getSupportReply(category) {
                if (category === 'problem') {
                    return 'تم تسجيل مشكلتك وسنرسلها إلى المطور فورًا. شكراً لك.';
                }
                if (category === 'suggestion') {
                    return 'تم تسجيل اقتراحك وسنرسلها إلى المطور. شكراً لك.';
                }
                if (category === 'developer_contact') {
                    return 'تم توجيه رسالتك إلى المطور وسنرد عليك قريبًا.';
                }
                return 'تم استلام رسالتك وسنراجعها.';
            }

            function sendSupportMessageToAdmin(rawQuestion, category) {
                var q = String(rawQuestion || '').trim();
                if (!q || category === 'general') {
                    return Promise.resolve();
                }

                var body = 'type=' + encodeURIComponent(category) + '&message=' + encodeURIComponent(q) + '&page=' + encodeURIComponent(location.href) + '&user=' + encodeURIComponent(window.currentUserName || '') + '&user_name=' + encodeURIComponent(window.currentUserName || '') + '&user_id=' + encodeURIComponent(window.currentUserId || '');

                return fetch('send_telegram_message.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body
                }).then(function (res) {
                    return res.text().then(function (text) {
                        var data = null;
                        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                        return { status: res.status, text: text, data: data };
                    });
                }).then(function (result) {
                    var info = document.createElement('div');
                    info.className = 'ai-chat-bubble ai-chat-bubble--bot';
                    var data = result && result.data ? result.data : null;
                    if (data && data.ok) {
                        info.textContent = 'تم إرسال رسالتك إلى المطور.';
                    } else {
                        var detail = '';
                        if (result && result.text) {
                            detail = ' | ' + result.text;
                        }
                        info.textContent = 'فشل إرسال الرسالة إلى المطور. السبب: ' + (data && data.message ? data.message : 'خطأ غير معروف') + detail;
                    }
                    messages.appendChild(info);
                    messages.scrollTop = messages.scrollHeight;
                    return data;
                }).catch(function (err) {
                    var info = document.createElement('div');
                    info.className = 'ai-chat-bubble ai-chat-bubble--bot';
                    info.textContent = 'فشل إرسال الرسالة إلى المطور. السبب: ' + (err && err.message ? err.message : 'خطأ غير معروف');
                    messages.appendChild(info);
                    messages.scrollTop = messages.scrollHeight;
                    return null;
                });
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var question = input.value.trim();
                if (!question || sendButton && sendButton.disabled) {
                    return;
                }

                setSending(true);

                var userBubble = document.createElement('div');
                userBubble.className = 'ai-chat-bubble ai-chat-bubble--user';
                userBubble.textContent = question;
                messages.appendChild(userBubble);

                var category = classifySupportCategory(question);
                if (category !== 'general') {
                    var loadingBubble = document.createElement('div');
                    loadingBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                    loadingBubble.textContent = 'جاري إرسال الرسالة إلى المطور...';
                    messages.appendChild(loadingBubble);
                    messages.scrollTop = messages.scrollHeight;
                    sendSupportMessageToAdmin(question, category).then(function () {
                        loadingBubble.remove();
                        var botBubble = document.createElement('div');
                        botBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                        botBubble.textContent = getSupportReply(category);
                        messages.appendChild(botBubble);
                        messages.scrollTop = messages.scrollHeight;
                        setSending(false);
                    }).catch(function () {
                        setSending(false);
                    });
                    input.value = '';
                    return;
                }

                var loadingBubble = document.createElement('div');
                loadingBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                loadingBubble.textContent = 'جاري تحليل السؤال...';
                messages.appendChild(loadingBubble);
                messages.scrollTop = messages.scrollHeight;

                fetch('ai_chat_handler.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: 'question=' + encodeURIComponent(question)
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    loadingBubble.remove();
                    var botBubble = document.createElement('div');
                    botBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                    botBubble.textContent = data.answer || data.recommendation || 'لم أستطع الحصول على إجابة الآن.';
                    messages.appendChild(botBubble);
                    messages.scrollTop = messages.scrollHeight;
                    setSending(false);
                })
                .catch(function () {
                    loadingBubble.remove();
                    var botBubble = document.createElement('div');
                    botBubble.className = 'ai-chat-bubble ai-chat-bubble--bot';
                    botBubble.textContent = 'تعذر الاتصال بالخادم الآن. يرجى المحاولة لاحقاً.';
                    messages.appendChild(botBubble);
                    messages.scrollTop = messages.scrollHeight;
                    setSending(false);
                });

                input.value = '';
            });
        })();
    </script>

    <script>
        window.departmentsMap = <?php echo json_encode($departments_map, JSON_UNESCAPED_UNICODE); ?>;
        window.csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>;
        window.isAdmin = false;
        window.adminNotificationsUrl = '';
        window.isUser = <?php echo (!empty($_SESSION['user_id']) || !empty($_SESSION['is_admin'])) ? 'true' : 'false'; ?>;
        window.currentUserId = <?php echo json_encode((string) ($_SESSION['user_id'] ?? 0)); ?>;
        window.currentUserName = <?php echo json_encode($_SESSION['user_name'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="assets/js/app.js"></script>
    <?php if ($showTermsModal): ?>
    <div id="terms-modal" style="position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; display:flex; align-items:center; justify-content:center; padding:24px;">
        <div style="background:#fff; padding:25px; border-radius:20px; max-width:500px; text-align:center;">
            <h2>شروط وقوانين المنصة</h2>
            <p style="text-align:right;">أهلاً بك في منصة مسار الأكاديمية. باستخدامك لهذه المنصة، أنت تتعهد بالالتزام بالآتي:</p>
            <ul style="text-align:right; margin-bottom:20px;">
                <li>التحلي بالأدب والاحترام في جميع التعليقات.</li>
                <li>عدم استخدام ألفاظ مسيئة أو السب والقذف.</li>
                <li>الالتزام بالموضوعية عند التقييم.</li>
            </ul>
            <button id="terms-modal-accept" class="btn btn--primary">أوافق وأبدأ التصفح</button>
        </div>
    </div>
    <script>
        (function () {
            var termsModal = document.getElementById('terms-modal');
            if (!termsModal) {
                return;
            }
            document.body.classList.add('modal-open');

            function closeTermsModal() {
                termsModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }

            document.getElementById('terms-modal-accept')?.addEventListener('click', closeTermsModal);
            termsModal.addEventListener('click', function (event) {
                if (event.target === termsModal) {
                    closeTermsModal();
                }
            });
        })();
    </script>
<?php endif; ?>
</body>
</html>