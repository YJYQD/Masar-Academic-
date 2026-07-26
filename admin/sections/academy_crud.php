<?php
if (!defined('IN_ADMIN')) {
    define('IN_ADMIN', true);
}

$authContext = current_auth_context();
if ($authContext['role'] !== 'super' && $authContext['role'] !== 'college_admin') {
    header('Location: ../login.php');
    exit();
}

$academyAdminRole = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');
$academyAdminCollege = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));
$academyCollegeMap = get_colleges_map();
$academyCanManageAllColleges = can_manage_college_scope($academyAdminRole, $academyAdminCollege, null);
$academyCollegeOptions = array_keys($academyCollegeMap);
sort($academyCollegeOptions);

$subjects = [];
$subjectStmt = null;
if ($academyAdminRole === 'faculty_admin' && $academyAdminCollege !== '' && !$academyCanManageAllColleges) {
    $subjectStmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects WHERE college = ? ORDER BY department ASC, level_num ASC, subject_name ASC');
    if ($subjectStmt) {
        mysqli_stmt_bind_param($subjectStmt, 's', $academyAdminCollege);
    }
} else {
    $subjectStmt = mysqli_prepare($conn, 'SELECT id, subject_name, course_code, credit_hours, college, department, level_num, telegram_link, description FROM subjects ORDER BY college ASC, department ASC, level_num ASC, subject_name ASC');
}
if ($subjectStmt) {
    mysqli_stmt_execute($subjectStmt);
    $subjectResult = mysqli_stmt_get_result($subjectStmt);
    while ($row = mysqli_fetch_assoc($subjectResult)) {
        $subjects[] = $row;
    }
    mysqli_stmt_close($subjectStmt);
}

$doctors = [];
$doctorStmt = null;
if ($academyAdminRole === 'faculty_admin' && $academyAdminCollege !== '' && !$academyCanManageAllColleges) {
    $doctorStmt = mysqli_prepare($conn, 'SELECT id, name, college, department, gender, courses, is_approved FROM doctors WHERE college = ? ORDER BY is_approved ASC, id DESC');
    if ($doctorStmt) {
        mysqli_stmt_bind_param($doctorStmt, 's', $academyAdminCollege);
    }
} else {
    $doctorStmt = mysqli_prepare($conn, 'SELECT id, name, college, department, gender, courses, is_approved FROM doctors ORDER BY is_approved ASC, id DESC');
}
if ($doctorStmt) {
    mysqli_stmt_execute($doctorStmt);
    $doctorResult = mysqli_stmt_get_result($doctorStmt);
    while ($row = mysqli_fetch_assoc($doctorResult)) {
        $doctors[] = $row;
    }
    mysqli_stmt_close($doctorStmt);
}

$curriculums = [];
$curriculumStmt = null;
if ($academyAdminRole === 'faculty_admin' && $academyAdminCollege !== '' && !$academyCanManageAllColleges) {
    $curriculumStmt = mysqli_prepare($conn, 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum WHERE college = ? ORDER BY id DESC');
    if ($curriculumStmt) {
        mysqli_stmt_bind_param($curriculumStmt, 's', $academyAdminCollege);
    }
} else {
    $curriculumStmt = mysqli_prepare($conn, 'SELECT id, title, description, academic_path, college, department, semester, credits, study_level, objectives FROM curriculum ORDER BY id DESC');
}
if ($curriculumStmt) {
    mysqli_stmt_execute($curriculumStmt);
    $curriculumResult = mysqli_stmt_get_result($curriculumStmt);
    while ($row = mysqli_fetch_assoc($curriculumResult)) {
        $curriculums[] = $row;
    }
    mysqli_stmt_close($curriculumStmt);
}
?>

<section class="admin-section-shell">
    <div class="admin-page-header" style="margin-bottom:24px;">
        <div>
            <span class="section-badge section-badge--stats">🧭 الأكاديمية</span>
            <h2>إدارة الأكاديمية بالكامل</h2>
            <p class="muted-text">أضف أو عدّل أو احذف المواد والدكاترة والخطط الأكاديمية مباشرة من لوحة الإدارة المؤمنة.</p>
        </div>
    </div>

    <div class="admin-section-card" style="margin-bottom:28px;">
        <h3>📚 المواد الدراسية</h3>
        <form method="POST" action="?section=academy" class="form-card" id="subject-form">
            <?= csrf_field() ?>
            <input type="hidden" name="academy_action" value="create_subject">
            <input type="hidden" name="entity_id" value="">
            <div class="form-grid--3">
                <label>اسم المادة<input type="text" name="subject_name" required></label>
                <label>رمز المادة<input type="text" name="course_code"></label>
                <label>الساعات<input type="number" name="credit_hours" min="0" step="1" value="3"></label>
            </div>
            <div class="form-grid--3" style="margin-top:12px;">
                <label>الكلية
                    <select name="college">
                        <option value="">-- اختر الكلية --</option>
                        <?php foreach ($academyCollegeOptions as $collegeOption): ?>
                            <option value="<?= e($collegeOption) ?>"><?= e($collegeOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>القسم/التخصص<input type="text" name="department"></label>
                <label>المستوى<input type="number" name="level_num" min="1" step="1" value="1"></label>
            </div>
            <div class="form-grid--2" style="margin-top:12px;">
                <label>رابط تيليجرام<input type="url" name="telegram_link" placeholder="https://t.me/..."></label>
                <label>الوصف<textarea name="description" rows="3"></textarea></label>
            </div>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn--accent">حفظ المادة</button>
                <button type="button" class="btn btn--light" onclick="resetAcademySubjectForm()">إعادة تعيين</button>
            </div>
        </form>

        <?php if (empty($subjects)): ?>
            <div class="admin-empty-state">
                <strong>لا توجد مواد بعد.</strong>
                <p class="muted-text">أضف أول مادة من النموذج أعلاه لبدء إدارة المحتوى الأكاديمي.</p>
            </div>
        <?php else: ?>
            <div class="cards admin-cards" style="margin-top:16px;">
                <?php foreach ($subjects as $subject): ?>
                    <article class="admin-item">
                        <div>
                            <h4><?= e($subject['subject_name']) ?></h4>
                            <p class="muted-text"><?= e($subject['college'] ?? '') ?> • <?= e($subject['department'] ?? '') ?> • المستوى <?= e($subject['level_num'] ?? '') ?></p>
                            <p><strong>رمز المادة:</strong> <?= e($subject['course_code'] ?? '') ?> | <strong>الساعات:</strong> <?= e($subject['credit_hours'] ?? '') ?></p>
                        </div>
                        <div class="admin-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn--light" onclick="fillAcademySubjectForm(<?= json_encode($subject, JSON_UNESCAPED_UNICODE) ?>)">تعديل</button>
                            <form method="POST" action="?section=academy" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="academy_action" value="delete_subject">
                                <input type="hidden" name="entity_id" value="<?= (int) $subject['id'] ?>">
                                <button type="submit" class="btn btn--danger">حذف</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-section-card" style="margin-bottom:28px;">
        <h3>👨‍🏫 الدكاترة</h3>
        <form method="POST" action="?section=academy" class="form-card" id="doctor-form">
            <?= csrf_field() ?>
            <input type="hidden" name="academy_action" value="create_doctor">
            <input type="hidden" name="entity_id" value="">
            <div class="form-grid--3">
                <label>اسم الدكتور<input type="text" name="doctor_name" required></label>
                <label>الكلية
                    <select name="doctor_college">
                        <option value="">-- اختر الكلية --</option>
                        <?php foreach ($academyCollegeOptions as $collegeOption): ?>
                            <option value="<?= e($collegeOption) ?>"><?= e($collegeOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>القسم<input type="text" name="doctor_department"></label>
            </div>
            <div class="form-grid--3" style="margin-top:12px;">
                <label>الجنس
                    <select name="doctor_gender">
                        <option value="">-- اختر --</option>
                        <option value="male">دكتور</option>
                        <option value="female">دكتورة</option>
                    </select>
                </label>
                <label>المقررات<input type="text" name="doctor_courses" placeholder="CS101, CS201"></label>
                <label>الحالة
                    <select name="doctor_approved">
                        <option value="1">معتمد</option>
                        <option value="0">قيد الانتظار</option>
                    </select>
                </label>
            </div>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn--accent">حفظ الدكتور</button>
                <button type="button" class="btn btn--light" onclick="resetAcademyDoctorForm()">إعادة تعيين</button>
            </div>
        </form>

        <?php if (empty($doctors)): ?>
            <div class="admin-empty-state">
                <strong>لا توجد دكاترة بعد.</strong>
                <p class="muted-text">أضف أول دكتور أو راجع الطلبات الجديدة من قسم الدكاترة.</p>
            </div>
        <?php else: ?>
            <div class="cards admin-cards" style="margin-top:16px;">
                <?php foreach ($doctors as $doctor): ?>
                    <article class="admin-item">
                        <div>
                            <h4><?= e($doctor['name']) ?></h4>
                            <p class="muted-text"><?= e($doctor['college'] ?? '') ?> • <?= e($doctor['department'] ?? '') ?></p>
                            <p><strong>المقررات:</strong> <?= e($doctor['courses'] ?? '') ?></p>
                        </div>
                        <div class="admin-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn--light" onclick="fillAcademyDoctorForm(<?= json_encode($doctor, JSON_UNESCAPED_UNICODE) ?>)">تعديل</button>
                            <form method="POST" action="?section=academy" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="academy_action" value="delete_doctor">
                                <input type="hidden" name="entity_id" value="<?= (int) $doctor['id'] ?>">
                                <button type="submit" class="btn btn--danger">حذف</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-section-card">
        <h3>🗺️ الخطط والمسارات الأكاديمية</h3>
        <form method="POST" action="?section=academy" class="form-card" id="curriculum-form">
            <?= csrf_field() ?>
            <input type="hidden" name="academy_action" value="create_curriculum">
            <input type="hidden" name="entity_id" value="">
            <div class="form-grid--3">
                <label>عنوان الخطة<input type="text" name="curriculum_title" required></label>
                <label>الكلية
                    <select id="curriculum-college-select" name="curriculum_college">
                        <option value="">-- اختر الكلية --</option>
                        <?php foreach ($academyCollegeOptions as $collegeOption): ?>
                            <option value="<?= e($collegeOption) ?>"><?= e($collegeOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>القسم/التخصص
                    <select id="curriculum-department-select" name="curriculum_department">
                        <option value="">-- اختر القسم/التخصص --</option>
                        <?php foreach ($academyCollegeMap as $collegeName => $departments): ?>
                            <optgroup label="<?= e($collegeName) ?>">
                                <?php foreach ($departments as $departmentName): ?>
                                    <option value="<?= e($departmentName) ?>"><?= e($departmentName) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-grid--3" style="margin-top:12px;">
                <label>الفصل/الترم<input type="text" name="curriculum_semester"></label>
                <label>الساعات<input type="number" name="curriculum_credits" min="0" step="1" value="3"></label>
                <label>المستوى<input type="text" name="curriculum_study_level"></label>
            </div>
            <div class="form-grid--2" style="margin-top:12px;">
                <label>المسار الأكاديمي<input type="text" name="curriculum_path"></label>
                <label>الوصف<textarea name="curriculum_description" rows="3"></textarea></label>
            </div>
            <label style="margin-top:12px; display:block;">الأهداف<textarea name="curriculum_objectives" rows="3"></textarea></label>
            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn btn--accent">حفظ الخطة</button>
                <button type="button" class="btn btn--light" onclick="resetAcademyCurriculumForm()">إعادة تعيين</button>
            </div>
        </form>

        <?php if (empty($curriculums)): ?>
            <div class="admin-empty-state">
                <strong>لا توجد خطط أكاديمية بعد.</strong>
                <p class="muted-text">أضف أول خطة أو مسار أكاديمي من النموذج أعلاه.</p>
            </div>
        <?php else: ?>
            <div class="cards admin-cards" style="margin-top:16px;">
                <?php foreach ($curriculums as $curriculum): ?>
                    <article class="admin-item">
                        <div>
                            <h4><?= e($curriculum['title']) ?></h4>
                            <p class="muted-text"><?= e($curriculum['college'] ?? '') ?> • <?= e($curriculum['department'] ?? '') ?> • <?= e($curriculum['semester'] ?? '') ?></p>
                            <p><strong>المسار:</strong> <?= e($curriculum['academic_path'] ?? '') ?></p>
                        </div>
                        <div class="admin-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn--light" onclick="fillAcademyCurriculumForm(<?= json_encode($curriculum, JSON_UNESCAPED_UNICODE) ?>)">تعديل</button>
                            <form method="POST" action="?section=academy" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="academy_action" value="delete_curriculum">
                                <input type="hidden" name="entity_id" value="<?= (int) $curriculum['id'] ?>">
                                <button type="submit" class="btn btn--danger">حذف</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
function resetAcademySubjectForm() {
    const form = document.getElementById('subject-form');
    if (!form) return;
    form.reset();
    form.querySelector('input[name="academy_action"]').value = 'create_subject';
    form.querySelector('input[name="entity_id"]').value = '';
}
function fillAcademySubjectForm(data) {
    const form = document.getElementById('subject-form');
    if (!form || !data) return;
    form.querySelector('input[name="academy_action"]').value = 'update_subject';
    form.querySelector('input[name="entity_id"]').value = data.id || '';
    form.querySelector('input[name="subject_name"]').value = data.subject_name || '';
    form.querySelector('input[name="course_code"]').value = data.course_code || '';
    form.querySelector('input[name="credit_hours"]').value = data.credit_hours || '3';
    form.querySelector('select[name="college"]').value = data.college || '';
    form.querySelector('input[name="department"]').value = data.department || '';
    form.querySelector('input[name="level_num"]').value = data.level_num || '1';
    form.querySelector('input[name="telegram_link"]').value = data.telegram_link || '';
    form.querySelector('textarea[name="description"]').value = data.description || '';
}
function resetAcademyDoctorForm() {
    const form = document.getElementById('doctor-form');
    if (!form) return;
    form.reset();
    form.querySelector('input[name="academy_action"]').value = 'create_doctor';
    form.querySelector('input[name="entity_id"]').value = '';
}
function fillAcademyDoctorForm(data) {
    const form = document.getElementById('doctor-form');
    if (!form || !data) return;
    form.querySelector('input[name="academy_action"]').value = 'update_doctor';
    form.querySelector('input[name="entity_id"]').value = data.id || '';
    form.querySelector('input[name="doctor_name"]').value = data.name || '';
    form.querySelector('select[name="doctor_college"]').value = data.college || '';
    form.querySelector('input[name="doctor_department"]').value = data.department || '';
    form.querySelector('select[name="doctor_gender"]').value = data.gender || '';
    form.querySelector('input[name="doctor_courses"]').value = data.courses || '';
    form.querySelector('select[name="doctor_approved"]').value = String(data.is_approved ?? '1');
}
function resetAcademyCurriculumForm() {
    const form = document.getElementById('curriculum-form');
    if (!form) return;
    form.reset();
    form.querySelector('input[name="academy_action"]').value = 'create_curriculum';
    form.querySelector('input[name="entity_id"]').value = '';
}
function setSelectValue(select, value) {
    if (!select) return;
    const normalizedValue = value || '';
    const hasValue = Array.from(select.options).some(function (option) {
        return option.value === normalizedValue;
    });
    if (!hasValue && normalizedValue !== '') {
        select.add(new Option(normalizedValue, normalizedValue));
    }
    select.value = normalizedValue;
}
function fillAcademyCurriculumForm(data) {
    const form = document.getElementById('curriculum-form');
    if (!form || !data) return;
    form.querySelector('input[name="academy_action"]').value = 'update_curriculum';
    form.querySelector('input[name="entity_id"]').value = data.id || '';
    form.querySelector('input[name="curriculum_title"]').value = data.title || '';
    setSelectValue(form.querySelector('select[name="curriculum_college"]'), data.college || '');
    setSelectValue(form.querySelector('select[name="curriculum_department"]'), data.department || '');
    form.querySelector('input[name="curriculum_semester"]').value = data.semester || '';
    form.querySelector('input[name="curriculum_credits"]').value = data.credits || '3';
    form.querySelector('input[name="curriculum_study_level"]').value = data.study_level || '';
    form.querySelector('input[name="curriculum_path"]').value = data.academic_path || '';
    form.querySelector('textarea[name="curriculum_description"]').value = data.description || '';
    form.querySelector('textarea[name="curriculum_objectives"]').value = data.objectives || '';
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('#subject-form, #doctor-form, #curriculum-form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'academy-updated' }, '*');
            }
        });
    });
});
</script>
