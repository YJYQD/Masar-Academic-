<?php
// إدارة المواد (CRUD) - شاشة المشرف
if (!defined('IN_ADMIN')) {
    // simple define to avoid direct include issues
    define('IN_ADMIN', true);
}

// قائمة الكليات/الأقسام من الدالة المساعدة
$colleges_map = get_colleges_map();
$college_options = array_keys($colleges_map);
$subject_department_options = [];
foreach ($colleges_map as $department_list) {
    foreach ($department_list as $department_name) {
        $subject_department_options[$department_name] = true;
    }
}
$subject_department_options = array_keys($subject_department_options);
sort($subject_department_options);

// جلب المواد مع الحقول الموسعة
$subjects = [];
// If admin is college_admin, limit to their college
$authContext = current_auth_context();
$adminCollege = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));
$adminRole = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');
if (in_array($adminRole, ['faculty_admin', 'assistant_admin'], true) && $adminCollege) {
    $stmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects WHERE college = ? ORDER BY department ASC, level_num ASC, subject_name ASC');
    if ($stmt) mysqli_stmt_bind_param($stmt, 's', $adminCollege);
} else {
    $stmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects ORDER BY college ASC, department ASC, level_num ASC, subject_name ASC');
}
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $subjects = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} elseif (function_exists('log_error')) {
    log_error('prepare failed in admin/sections/subjects.php');
}
?>

<section class="admin-section-shell">
    <div class="admin-page-header">
        <div>
            <span class="section-badge section-badge--stats">📚 إدارة المواد</span>
            <h2>المواد الدراسية</h2>
            <p class="muted-text">أنشئ أو عدّل أو أدرِ المقررات بسهولة من واجهة مرتبة ومريحة للمشرف.</p>
        </div>
        <button id="add-subject-btn" class="btn btn--accent">+ إضافة مادة جديدة</button>
    </div>

    <div class="admin-filters" style="margin-bottom: 18px;">
        <label>الكلية
            <select class="filter-select" id="subject-filter-college">
                <option value="">الكل</option>
                <?php foreach ($college_options as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>القسم
            <select class="filter-select" id="subject-filter-department">
                <option value="">الكل</option>
                <?php foreach ($subject_department_options as $deptOption): ?>
                    <option value="<?= e($deptOption) ?>"><?= e($deptOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>المستوى
            <select class="filter-select" id="subject-filter-level">
                <option value="">الكل</option>
                <?php for ($lvl = 1; $lvl <= 10; $lvl++): ?>
                    <option value="<?= $lvl ?>"><?= $lvl ?></option>
                <?php endfor; ?>
            </select>
        </label>
    </div>

    <?php if (empty($subjects)): ?>
        <div class="admin-empty-state">
            <strong>لا توجد مواد مسجّلة بعد.</strong>
            <p class="muted-text">استخدم زر إضافة مادة جديدة لبدء إنشاء المحتوى الأكاديمي.</p>
        </div>
    <?php else: ?>
        <div class="cards admin-cards" id="subject-list">
            <?php foreach ($subjects as $s): ?>
                <article class="admin-item" data-college="<?= e($s['college'] ?? '') ?>" data-department="<?= e($s['department'] ?? '') ?>" data-level="<?= e($s['level_num'] ?? '') ?>">
                    <div class="subject-meta">
                        <h3><?= e($s['subject_name']) ?></h3>
                        <p class="muted-text"><?= e($s['college'] ?? '') ?> • <?= e($s['department'] ?? '') ?> • المستوى <?= e($s['level_num'] ?? '') ?></p>
                        <div class="subject-footnote">
                            <span class="subject-chip">رمز: <?= e($s['course_code'] ?? '') ?></span>
                            <span class="subject-chip">الساعات: <?= e($s['credit_hours'] ?? '') ?></span>
                        </div>
                    </div>
                    <div class="admin-actions">
                        <button class="btn btn--light edit-subject" data-subject='<?= e(json_encode($s, JSON_UNESCAPED_UNICODE)) ?>'>تعديل</button>
                        <form class="admin-actions" method="POST" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="admin_action" value="delete_subject">
                            <input type="hidden" name="subject_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn--danger" data-confirm="هل أنت متأكد من حذف هذه المادة؟">حذف</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Modal form -->
    <div id="subject-modal" class="modal" style="display:none;">
        <div class="modal__content">
            <h3 id="subject-modal-title">إضافة مادة</h3>
            <form id="subject-form" class="form-card" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="admin_action" value="create_subject">
                <input type="hidden" name="subject_id" value="">

                <div class="form-grid--3">
                    <label>اسم المادة
                        <input type="text" name="subject_name" required>
                    </label>
                    <label>رمز المادة
                        <input type="text" name="course_code">
                    </label>
                    <label>عدد الساعات
                        <input type="number" name="credit_hours" min="0" step="1">
                    </label>
                </div>

                <div class="form-grid--3" style="margin-top:16px;">
                    <label>الكلية
                        <select name="college" id="subject-college-select">
                            <option value="">-- اختر الكلية --</option>
                            <?php foreach ($college_options as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= (in_array($adminRole, ['faculty_admin', 'assistant_admin'], true) && $adminCollege === $opt) ? 'selected disabled' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>القسم/التخصص
                        <select name="department" id="subject-department-select">
                            <option value="">-- اختر القسم --</option>
                        </select>
                    </label>
                    <label>رقم المستوى
                        <input type="number" name="level_num" min="1" step="1" placeholder="مثال: 1 أو 4">
                    </label>
                </div>

                <div class="form-grid--2" style="margin-top:16px;">
                    <label>رابط تيليجرام (اختياري)
                        <input type="url" name="telegram" placeholder="https://t.me/...">
                    </label>
                    <label>وصف المادة (اختياري)
                        <textarea name="description" rows="3"></textarea>
                    </label>
                </div>

                <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap;">
                    <button type="button" class="btn btn--light" id="subject-modal-cancel">إلغاء</button>
                    <button type="submit" class="btn btn--accent">حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const addBtn = document.getElementById('add-subject-btn');
            const modal = document.getElementById('subject-modal');
            const form = document.getElementById('subject-form');
            const cancel = document.getElementById('subject-modal-cancel');
            const title = document.getElementById('subject-modal-title');

            function openModal() {
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            }
            function closeModal() {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            }
            function resetSubjectForm() {
                form.reset();
                const deptSel = form.querySelector('select[name="department"]');
                if (deptSel) {
                    deptSel.innerHTML = '<option value="">-- اختر القسم --</option>';
                }
                const collegeSel = form.querySelector('select[name="college"]');
                if (collegeSel) {
                    collegeSel.value = '';
                }
            }

            addBtn.addEventListener('click', function () {
                title.textContent = 'إضافة مادة';
                form.querySelector('input[name="admin_action"]').value = 'create_subject';
                form.querySelector('input[name="subject_id"]').value = '';
                resetSubjectForm();
                openModal();
            });

            cancel.addEventListener('click', function () { closeModal(); });

            // Edit buttons
            Array.from(document.querySelectorAll('.edit-subject')).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const article = btn.closest('article');
                    const payload = JSON.parse(btn.dataset.subject || '{}');
                    title.textContent = 'تعديل مادة';
                    form.querySelector('input[name="admin_action"]').value = 'update_subject';
                    form.querySelector('input[name="subject_id"]').value = payload.id || '';
                    form.querySelector('input[name="subject_name"]').value = payload.subject_name || '';
                    form.querySelector('input[name="course_code"]').value = payload.course_code || '';
                    form.querySelector('input[name="credit_hours"]').value = payload.credit_hours || '';
                    form.querySelector('select[name="college"]').value = payload.college || '';
                    // populate department options based on college
                    const col = payload.college || form.querySelector('select[name="college"]').value || '';
                    populateDepartments(form.querySelector('select[name="department"]'), col);
                    form.querySelector('select[name="department"]').value = payload.department || '';
                    form.querySelector('input[name="level_num"]').value = payload.level_num || '';
                    form.querySelector('input[name="telegram"]').value = payload.telegram_link || '';
                    form.querySelector('textarea[name="description"]').value = payload.description || '';
                    openModal();
                });
            });

            // populate department options helper
            function populateDepartments(deptSelect, college){
                deptSelect.innerHTML = '<option value="">-- اختر القسم --</option>';
                if (!college) return;
                var map = <?= json_encode($colleges_map, JSON_UNESCAPED_UNICODE) ?>;
                var list = map[college] || [];
                list.forEach(function(d){
                    var o = document.createElement('option'); o.value = d; o.textContent = d; deptSelect.appendChild(o);
                });
            }

            // update department select when college changes
            var collegeSelect = document.getElementById('subject-college-select');
            var deptSelect = document.getElementById('subject-department-select');
            if (collegeSelect){
                collegeSelect.addEventListener('change', function(){ populateDepartments(deptSelect, this.value); });
                // initialize if admin is college_admin
                <?php if (in_array($adminRole, ['faculty_admin', 'assistant_admin'], true) && $adminCollege): ?>
                    populateDepartments(deptSelect, <?= json_encode($adminCollege, JSON_UNESCAPED_UNICODE) ?>);
                <?php endif; ?>
            }

            const allSubjectDepartments = <?= json_encode(array_values($subject_department_options), JSON_UNESCAPED_UNICODE) ?>;
            const subjectDepartmentMap = <?= json_encode($colleges_map, JSON_UNESCAPED_UNICODE) ?>;
            const subjectCards = Array.from(document.querySelectorAll('#subject-list article'));
            const filterCollege = document.getElementById('subject-filter-college');
            const filterDept = document.getElementById('subject-filter-department');
            const filterLevel = document.getElementById('subject-filter-level');

            function populateDepartmentFilter(college) {
                const deptSelect = document.getElementById('subject-filter-department');
                const previousValue = deptSelect.value;
                deptSelect.innerHTML = '<option value="">الكل</option>';
                const departments = college ? (subjectDepartmentMap[college] || []) : allSubjectDepartments;
                Array.from(new Set(departments)).sort().forEach(function(dep){
                    const opt = document.createElement('option'); opt.value = dep; opt.textContent = dep; deptSelect.appendChild(opt);
                });
                if (previousValue && Array.from(deptSelect.options).some(function(option){ return option.value === previousValue; })) {
                    deptSelect.value = previousValue;
                }
            }

            function filterSubjects(){
                const college = filterCollege.value;
                const dept = filterDept.value;
                const level = filterLevel.value;
                subjectCards.forEach(card => {
                    const matchesCollege = !college || card.dataset.college === college;
                    const matchesDept = !dept || card.dataset.department === dept;
                    const matchesLevel = !level || card.dataset.level === level;
                    card.style.display = (matchesCollege && matchesDept && matchesLevel) ? 'grid' : 'none';
                });
            }

            if (subjectCards.length > 0) {
                populateDepartmentFilter();
                filterCollege && filterCollege.addEventListener('change', function(){ populateDepartmentFilter(this.value); filterSubjects(); });
                [filterDept, filterLevel].forEach(select => select && select.addEventListener('change', filterSubjects));
            }

            // Close modal on outside click
            window.addEventListener('click', function (ev) {
                if (ev.target === modal) closeModal();
            });

            // after successful admin-actions AJAX, close modal and reload section if needed
            document.addEventListener('DOMContentLoaded', function () {});
        })();
    </script>
</section>

<?php
// end subjects.php
?>