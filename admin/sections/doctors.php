<?php
if (!defined('DB_HOST') && empty($_SESSION['is_admin'])) { exit('الوصول مقيد'); }

$authContext = current_auth_context();
$admin_college = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));
$admin_role = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');

$college_options = array_keys(get_colleges_map());
$department_options = [];
foreach (get_colleges_map() as $deptList) {
    foreach ($deptList as $dept) {
        $department_options[$dept] = true;
    }
}
$department_options = array_keys($department_options);
sort($department_options);

if ($admin_role === 'root_admin' || empty($admin_college)) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM doctors ORDER BY is_approved ASC, id DESC");
    $doctors = [];
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $doctors[] = $row;
        }
        mysqli_stmt_close($stmt);
    } else {
        // prepare failed: log and return empty list
        if (function_exists('log_error')) log_error('prepare failed in admin/sections/doctors.php');
        $doctors = [];
    }
} else {
    $stmt = mysqli_prepare($conn, "SELECT * FROM doctors WHERE college = ? ORDER BY is_approved ASC, id DESC");
    $doctors = [];
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $admin_college);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $doctors[] = $row;
        }
        mysqli_stmt_close($stmt);
    } else {
        if (function_exists('log_error')) log_error('prepare failed in admin/sections/doctors.php (college)');
    }
}
?>

<section class="admin-section-shell">
    <div class="admin-page-header" style="margin-bottom:18px;">
        <div>
            <span class="section-badge section-badge--doctors">👨‍🏫 إدارة الدكاترة</span>
            <h2>الدكاترة والطلبات الجديدة</h2>
            <p class="muted-text">استخدم الفلاتر الموحدة لعرض الدكتوريات حسب الكلية، القسم، أو حالة الاعتماد.</p>
        </div>
        <a href="../add-doctor" class="btn btn--accent" style="text-decoration: none; white-space: nowrap;">+ إضافة دكتور جديد</a>
    </div>

    <div class="admin-filters" style="margin-bottom: 18px;">
        <label>الكلية
            <select class="filter-select" id="doctor-filter-college">
                <option value="">الكل</option>
                <?php foreach ($college_options as $college_option): ?>
                    <option value="<?= e($college_option) ?>"><?= e($college_option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>القسم
            <select class="filter-select" id="doctor-filter-department">
                <option value="">الكل</option>
                <?php foreach ($department_options as $department_option): ?>
                    <option value="<?= e($department_option) ?>"><?= e($department_option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>الحالة
            <select class="filter-select" id="doctor-filter-status">
                <option value="">الكل</option>
                <option value="0">طلب جديد</option>
                <option value="1">معتمد</option>
            </select>
        </label>
        <button type="button" class="btn btn--secondary" id="doctor-filter-clear" style="margin-left:auto;">مسح الفلاتر</button>
    </div>

    <?php if (empty($doctors)): ?>
        <div class="admin-empty-state">
            <strong>لا توجد دكاترة مسجلة حتى الآن.</strong>
            <p class="muted-text">يمكنك إضافة دكتور جديد أو انتظار الطلبات الجديدة.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($doctors as $doctor): ?>
        <article class="admin-doctor-item <?= (int) $doctor['is_approved'] === 0 ? 'admin-doctor-item--pending' : 'admin-doctor-item--approved' ?>" data-status="<?= (int) $doctor['is_approved'] ?>" data-college="<?= e($doctor['college'] ?? '') ?>" data-department="<?= e($doctor['department'] ?? '') ?>">
            <div class="doctor-meta">
                <h3><?= e($doctor['name']) ?> <?= (int) $doctor['is_approved'] === 0 ? '<small class="doctor-status-badge">(طلب جديد)</small>' : '' ?></h3>
                <p class="muted-text"><?= e($doctor['college']) ?> - <?= e($doctor['department']) ?></p>
                <div class="subject-footnote">
                    <span class="subject-chip">المواد: <?= e($doctor['courses']) ?></span>
                </div>
            </div>

            <form method="POST" action="?section=doctors" class="admin-actions" style="display: flex; gap: 10px; align-items: center;">
                <?= csrf_field() ?>
                <input type="hidden" name="doc_id" value="<?= (int)$doctor['id'] ?>">
                
                <?php if ($doctor['is_approved'] == 0): ?>
                    <button type="submit" name="admin_action" value="approve" class="btn btn--accent">اعتماد الطلب</button>
                <?php endif; ?>
                
                <a href="/add_doctor?doc_id=<?= (int)$doctor['id'] ?>&return_to=<?= urlencode('admin?section=doctors') ?>" class="btn btn--dark" style="text-decoration: none;">تعديل</a>
                
                <button type="submit" name="admin_action" value="delete" class="btn btn--danger" data-confirm="حذف الدكتور سيحذف كافة تعليقاته تلقائياً، هل أنت متأكد؟">حذف</button>
            </form>
        </article>
    <?php endforeach; ?>
    <script>
    (function(){
        const collegeSelect = document.getElementById('doctor-filter-college');
        const departmentSelect = document.getElementById('doctor-filter-department');
        const statusSelect = document.getElementById('doctor-filter-status');
        const cards = Array.from(document.querySelectorAll('.admin-doctor-item'));

        function filterDoctors(){
            const college = collegeSelect.value;
            const department = departmentSelect.value;
            const status = statusSelect.value;
            cards.forEach(card => {
                const matchesCollege = !college || card.dataset.college === college;
                const matchesDepartment = !department || card.dataset.department === department;
                const matchesStatus = !status || card.dataset.status === status;
                card.style.display = (matchesCollege && matchesDepartment && matchesStatus) ? 'flex' : 'none';
            });
        }

        const clearButton = document.getElementById('doctor-filter-clear');
        [collegeSelect, departmentSelect, statusSelect].forEach(select => select && select.addEventListener('change', filterDoctors));
        clearButton && clearButton.addEventListener('click', () => {
            collegeSelect.value = '';
            departmentSelect.value = '';
            statusSelect.value = '';
            filterDoctors();
        });
        filterDoctors();
    })();
    </script>
</section>