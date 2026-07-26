<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>منصة مسار الأكاديمية</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; padding: 20px; }
        .form-container { background: white; max-width: 500px; margin: 30px auto; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { color: #004a99; text-align: center; }
        label { display: block; margin: 10px 0 5px; font-weight: bold; color: #333; }
        input, select { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 15px; }
        .submit-btn { background: #27ae60; color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; font-size: 17px; font-weight: bold; cursor: pointer; }
        .submit-btn:hover { background: #219150; }
        .back-link { display: block; text-align: center; margin-top: 15px; color: #004a99; text-decoration: none; }
    </style>
</head>
<body>

<?php
require_once __DIR__ . '/inc/session_secure.php';

require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) && empty($_SESSION['is_admin'])) {
    echo '<div class="form-container"><h2>الدخول مطلوب</h2><p>يجب أن تكون مسجلاً لتقديم طلب إضافة دكتور. <a href="login.php">تسجيل الدخول</a> أو <a href="register.php">إنشاء حساب</a></p><a href="index.php" class="back-link">العودة للبحث</a></div>';
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$editing = false;
$existing = ['name' => '', 'college' => '', 'department' => '', 'gender' => '', 'subjects' => '', 'id' => null];
$returnTo = trim($_GET['return_to'] ?? '');
$departments_map = [
    'الهندسة وعلوم الحاسب' => ['علوم الحاسب', 'نظم المعلومات', 'هندسة الحاسب والشبكات'],
    'الطب' => ['الطب', 'الطب والجراحة العامة'],
    'طب الأسنان' => ['طب الأسنان'],
    'الصيدلة' => ['الصيدلة'],
    'العلوم الطبية التطبيقية' => ['العلاج الطبيعي', 'التغذية'],
    'التمريض' => ['التمريض'],
    'العلوم' => ['الرياضيات', 'الفيزياء', 'الكيمياء', 'الأحياء'],
    'إدارة الأعمال' => ['المحاسبة', 'إدارة الأعمال'],
    'الشريعة والقانون' => ['الشريعة', 'القانون'],
    'الآداب والعلوم الإنسانية' => ['اللغة العربية', 'اللغة الإنجليزية'],
    'التربية' => ['التربية'],
    'التصميم والعمارة' => ['التصميم', 'العمارة'],
    'الكلية التطبيقية' => ['التطبيقية'],
];
$collegeCatalog = $departments_map;
$collegeOptions = array_keys($collegeCatalog);

if (!empty($_GET['doc_id'])) {
    // التحرير والتعديل يتطلب صلاحيات مشرف الإدارة
    if (empty($_SESSION['is_admin'])) {
        echo '<div class="form-container"><h2>غير مسموح</h2><p>التحرير متاح للمشرفين فقط.</p><a href="admin/index.php" class="back-link">العودة للوحة</a></div>';
        exit();
    }
    $editing = true;
    include 'db.php';
    $did = (int) $_GET['doc_id'];
    $stmt = mysqli_prepare($conn, 'SELECT id, name, college, department, gender, courses FROM doctors WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $did);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if ($row) {
        $existing = $row;
    } else {
        echo '<div class="form-container"><h2>غير موجود</h2><p>الطبيب المطلوب للتعديل غير موجود.</p><a href="admin/index.php?section=doctors" class="back-link">العودة للوحة</a></div>';
        exit();
    }
}

// جلب قائمة المواد لعرضها في النموذج
$subjectsList = [];
$subStmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code FROM subjects ORDER BY subject_name ASC');
if ($subStmt) {
    mysqli_stmt_execute($subStmt);
    $resS = mysqli_stmt_get_result($subStmt);
    while ($s = mysqli_fetch_assoc($resS)) {
        $subjectsList[] = $s;
    }
    mysqli_stmt_close($subStmt);
}

// إذا كنا في وضع التعديل، جلب مواد الدكتور المختارة
$selectedSubjects = [];
if ($editing) {
    $ds = mysqli_prepare($conn, 'SELECT subject_id FROM doctor_subject WHERE doctor_id = ?');
    if ($ds) {
        mysqli_stmt_bind_param($ds, 'i', $did);
        mysqli_stmt_execute($ds);
        $resDs = mysqli_stmt_get_result($ds);
        while ($r = mysqli_fetch_assoc($resDs)) $selectedSubjects[] = (int)$r['subject_id'];
        mysqli_stmt_close($ds);
    }
}
?>

<div class="form-container">
    <h2><?php echo $editing ? 'تعديل بيانات الدكتور' : 'طلب إضافة دكتور جديد'; ?></h2>
    <form action="save.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo ?: 'admin?section=doctors', ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="doc_id" value="<?php echo (int) $existing['id']; ?>">
        <?php endif; ?>
        
        <label>اسم الدكتور</label>
        <input type="text" name="doc_name" placeholder="الاسم الثلاثي" value="<?php echo htmlspecialchars($existing['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

        <label>الكلية</label>
        <select id="admin_college" name="college" onchange="updateDepartmentsAdmin()" required>
            <option value="">-- اختر الكلية --</option>
            <?php foreach ($collegeOptions as $collegeOption): ?>
                <option value="<?= htmlspecialchars($collegeOption, ENT_QUOTES, 'UTF-8') ?>" <?= ($existing['college'] ?? '') === $collegeOption ? 'selected' : '' ?>><?= htmlspecialchars($collegeOption, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <label>التخصص (القسم)</label>
        <select id="admin_department" name="department" data-selected-department="<?php echo htmlspecialchars($existing['department'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            <option value="">اختر الكلية أولاً...</option>
        </select>

        <label>نوع الدكتور</label>
        <select name="gender" required>
            <option value="">-- اختر النوع --</option>
            <option value="male" <?php echo ($existing['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>دكتور</option>
            <option value="female" <?php echo ($existing['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>دكتورة</option>
        </select>
        <label>المواد (اختر من القائمة)</label>
        <div style="margin-bottom:12px;">
            <?php if (count($subjectsList) === 0): ?>
                <div class="small">لم تُعرّف أية مواد بعد. اطلب من المشرف إضافة مواد أو استخدم حقل النص الحر أدناه.</div>
            <?php else: ?>
                <?php foreach ($subjectsList as $sub): ?>
                    <label style="display:block; margin:4px 0;"><input type="checkbox" name="subject_ids[]" value="<?= (int)$sub['id'] ?>" <?php echo in_array((int)$sub['id'], $selectedSubjects) ? 'checked' : ''; ?>> <?= htmlspecialchars($sub['subject_name']) ?> <?= $sub['course_code'] ? '(' . htmlspecialchars($sub['course_code']) . ')' : '' ?></label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <label>أو أدخل مواد نصياً (اختياري)</label>
        <input type="text" name="subjects" placeholder="مثال: داتابيز، شبكات" value="<?php echo htmlspecialchars($existing['courses'] ?? $existing['subjects'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <button type="submit" class="submit-btn"><?php echo $editing ? 'حفظ التعديلات' : 'إرسال للمراجعة'; ?></button>
        <a href="index.php" class="back-link">العودة للبحث</a>
    </form>
</div>

<script>
const collegeDepartments = <?= json_encode($collegeCatalog, JSON_UNESCAPED_UNICODE) ?>;

function updateDepartmentsAdmin() {
    const college = document.getElementById("admin_college").value;
    const deptSelect = document.getElementById("admin_department");
    deptSelect.innerHTML = "";

    const options = collegeDepartments[college] || [];
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = options.length ? "اختر القسم" : "اختر الكلية أولاً...";
    deptSelect.appendChild(placeholder);

    options.forEach(function(dept) {
        const el = document.createElement("option");
        el.textContent = dept;
        el.value = dept;
        deptSelect.appendChild(el);
    });

    deptSelect.disabled = false;
    const selectedDepartment = deptSelect.getAttribute("data-selected-department") || "";
    if (selectedDepartment) {
        deptSelect.value = selectedDepartment;
    } else {
        deptSelect.value = "";
    }
}

document.addEventListener("DOMContentLoaded", function () {
    updateDepartmentsAdmin();
});
</script>

</body>
</html>