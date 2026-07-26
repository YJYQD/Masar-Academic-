<?php
require_once __DIR__ . '/../../db.php';

$authContext = current_auth_context();
$accessContext = resolve_access_context($authContext);
$admin_role = normalize_admin_role($accessContext['role'] === 'super_admin' ? 'super' : 'college_admin');
$admin_college = trim((string) ($accessContext['college_name'] ?? $authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));

$doctorWhere = '';
$doctorParams = [];
$doctorTypes = '';
if ($admin_role !== 'root_admin' && !empty($admin_college)) {
    $doctorWhere = ' WHERE college = ?';
    $doctorTypes = 's';
    $doctorParams = [$admin_college];
}

$totalDoctors = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM doctors' . $doctorWhere, $doctorTypes, $doctorParams);
$approvedDoctors = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM doctors WHERE is_approved = 1' . $doctorWhere, $doctorTypes, $doctorParams);
$pendingDoctors = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM doctors WHERE is_approved = 0' . $doctorWhere, $doctorTypes, $doctorParams);
$reviewWhere = ' WHERE r.status = "approved"';
$reviewParams = [];
$reviewTypes = '';
if ($admin_role !== 'root_admin' && !empty($admin_college)) {
    $reviewWhere .= ' AND d.college = ?';
    $reviewTypes = 's';
    $reviewParams = [$admin_college];
}
$approvedReviews = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id' . $reviewWhere, $reviewTypes, $reviewParams);

$reviewWhere = ' WHERE r.status = "pending"';
$reviewParams = [];
$reviewTypes = '';
if ($admin_role !== 'root_admin' && !empty($admin_college)) {
    $reviewWhere .= ' AND d.college = ?';
    $reviewTypes = 's';
    $reviewParams = [$admin_college];
}
$pendingReviews = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id' . $reviewWhere, $reviewTypes, $reviewParams);
$adminCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM admins');
$subjectCount = db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM subjects');

$hasAnonymousProfilesTable = false;
$hasAttendanceEventsTable = false;
$hasAcademicProgramsTable = false;
if ($conn instanceof mysqli) {
    $tablesCheck = $conn->query("SHOW TABLES");
    if ($tablesCheck) {
        $existingTables = [];
        while ($tableRow = $tablesCheck->fetch_row()) {
            $existingTables[] = strtolower($tableRow[0]);
        }
        $hasAnonymousProfilesTable = in_array('anonymous_profiles', $existingTables, true);
        $hasAttendanceEventsTable = in_array('attendance_events', $existingTables, true);
        $hasAcademicProgramsTable = in_array('academic_programs', $existingTables, true);
    }
}

$anonymousProfilesCount = $hasAnonymousProfilesTable ? db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM anonymous_profiles') : 0;
$attendanceEventsCount = $hasAttendanceEventsTable ? db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM attendance_events') : 0;
$academicProgramsCount = $hasAcademicProgramsTable ? db_fetch_count($conn, 'SELECT COUNT(*) AS total FROM academic_programs') : 0;
$attendanceGroups = $hasAttendanceEventsTable ? $conn->query('SELECT course_code, COUNT(*) AS total_events FROM attendance_events GROUP BY course_code ORDER BY total_events DESC LIMIT 6') : false;
?>

<section class="admin-section-shell">
    <div class="admin-page-header">
        <div>
            <span class="section-badge section-badge--stats">📊 لوحة الإدارة</span>
            <h2>الإحصائيات</h2>
            <p class="muted-text">مراجعة سريعة لأبرز المؤشرات التشغيلية والنشاط الأكاديمي.</p>
        </div>
    </div>
    <div class="admin-analytics">
        <article><strong><?= (int) $totalDoctors ?></strong><span>إجمالي الدكاترة</span></article>
        <article><strong><?= (int) $approvedDoctors ?></strong><span>الدكاترة المعتمدة</span></article>
        <article><strong><?= (int) $pendingDoctors ?></strong><span>طلبات إضافة جديدة</span></article>
        <article><strong><?= (int) $approvedReviews ?></strong><span>تقييمات معتمدة</span></article>
        <article><strong><?= (int) $pendingReviews ?></strong><span>تقييمات معلقة</span></article>
        <article><strong><?= (int) $adminCount ?></strong><span>المشرفون</span></article>
        <article><strong><?= (int) $subjectCount ?></strong><span>المواد المسجلة</span></article>
    </div>
</section>

<section class="admin-section-shell" style="margin-top: 18px;">
    <h2>لوحة القرار الذكية</h2>
    <p class="muted-text">مؤشرات الالتزام والتقدير الذكي تساعد الإدارة على اتخاذ القرار خلال دقيقة واحدة.</p>
    <div class="admin-analytics">
        <article><strong><?= (int) $anonymousProfilesCount ?></strong><span>ملفات مجهولة</span></article>
        <article><strong><?= (int) $attendanceEventsCount ?></strong><span>أحداث حضور</span></article>
        <article><strong><?= (int) $academicProgramsCount ?></strong><span>برامج أكاديمية</span></article>
    </div>
    <div style="margin-top:16px;">
        <h3>مقارنة الالتزام بالحضور</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-top:8px;">
            <?php if ($attendanceGroups): while ($group = $attendanceGroups->fetch_assoc()): ?>
                <div class="doctor-card">
                    <strong><?= e($group['course_code'] ?? '-') ?></strong>
                    <div><?= (int) ($group['total_events'] ?? 0) ?> حدث</div>
                </div>
            <?php endwhile; endif; ?>
        </div>
    </div>
</section>