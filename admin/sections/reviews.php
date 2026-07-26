<?php
if (!defined('DB_HOST') && empty($_SESSION['is_admin'])) { exit('الوصول مقيد'); }

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$authContext = current_auth_context();
$admin_college = trim((string) ($authContext['college_scope'] ?? $_SESSION['admin_college'] ?? ''));
$admin_role = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');

$reviews = [];
if ($admin_role === 'root_admin' || empty($admin_college)) {
    $stmt = mysqli_prepare($conn, 'SELECT r.*, d.name AS doctor_name, d.college AS doctor_college, d.department AS doctor_department FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id ORDER BY r.status DESC, r.id DESC');
} else {
    $stmt = mysqli_prepare($conn, 'SELECT r.*, d.name AS doctor_name, d.college AS doctor_college, d.department AS doctor_department FROM reviews r INNER JOIN doctors d ON d.id = r.doctor_id WHERE d.college = ? ORDER BY r.status DESC, r.id DESC');
}
if ($stmt) {
    if ($admin_role !== 'root_admin' && !empty($admin_college)) {
        mysqli_stmt_bind_param($stmt, 's', $admin_college);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $reviews[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$collegeOptions = [];
$departmentOptions = [];
$statusOptions = [];
foreach ($reviews as $review) {
    if (!empty($review['doctor_college'])) {
        $collegeOptions[$review['doctor_college']] = true;
    }
    if (!empty($review['doctor_department'])) {
        $departmentOptions[$review['doctor_department']] = true;
    }
    if (!empty($review['status'])) {
        $statusOptions[$review['status']] = true;
    }
}

// عرض جميع الكليات المتاحة حتى لو لم يظهر لها تقييم بعد
$allColleges = array_keys(get_colleges_map());
foreach ($allColleges as $college) {
    $collegeOptions[$college] = true;
}

$collegeOptions = array_keys($collegeOptions);
sort($collegeOptions);
$departmentOptions = array_keys($departmentOptions);
sort($departmentOptions);
$statusOptions = array_keys($statusOptions);
?>

<section class="admin-section-shell">
    <div class="admin-page-header">
        <div>
            <span class="section-badge section-badge--reviews">📥 إدارة التقييمات</span>
            <h2>التقييمات والتعليقات</h2>
            <p class="muted-text">استخدم شريط الفلاتر الموحد لتصفية التقييمات حسب الكلية أو القسم أو الحالة.</p>
        </div>
    </div>

    <div class="admin-filters">
        <label>الكلية
            <select id="review-filter-college" class="filter-select">
                <option value="">الكل</option>
                <?php foreach ($collegeOptions as $college): ?>
                    <option value="<?= e($college) ?>"><?= e($college) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>القسم
            <select id="review-filter-department" class="filter-select">
                <option value="">الكل</option>
                <?php foreach ($departmentOptions as $department): ?>
                    <option value="<?= e($department) ?>"><?= e($department) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>الحالة
            <select id="review-filter-status" class="filter-select">
                <option value="">الكل</option>
                <?php foreach ($statusOptions as $status): ?>
                    <option value="<?= e($status) ?>"><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <?php if (empty($reviews)): ?>
        <div class="admin-empty-state">
            <strong>لا توجد تقييمات حتى الآن.</strong>
            <p class="muted-text">إذا كنت تتوقع بيانات، فتأكد من أن جدول التقييمات يحتوي على سجلات معتمدة أو معلقة.</p>
        </div>
    <?php else: ?>
        <div class="cards admin-cards" id="review-cards">
            <?php foreach ($reviews as $review): ?>
                <article class="admin-review-item review-card" data-college="<?= e($review['doctor_college']) ?>" data-department="<?= e($review['doctor_department']) ?>" data-status="<?= e($review['status']) ?>">
                    <div class="review-card__header">
                        <div>
                            <h3><?= e($review['doctor_name']) ?></h3>
                            <p class="muted-text"><?= e($review['doctor_college'] ?? '') ?> • <?= e($review['doctor_department'] ?? '') ?></p>
                        </div>
                        <span class="review-badge review-badge--<?= e($review['status']) ?>"><?= e($review['status']) ?></span>
                    </div>
                    <div class="review-card__info">
                        <p class="review-item__course"><?= e($review['course_code'] ?? '') ?> • <?= e($review['semester'] ?? '') ?></p>
                        <p class="review-item-text"><?= nl2br(e($review['comment'])) ?></p>
                    </div>
                    <div class="review-card__footer">
                        <div class="rating-stars" aria-label="تقييم <?= (int) $review['rating'] ?> من 5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="rating-stars__star <?= $i <= (int) $review['rating'] ? 'is-filled' : '' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <div class="review-card__labels">
                            <span><?= e($review['reviewer_name'] ?? 'طالب') ?></span>
                            <span><?= e($review['created_at'] ?? '') ?></span>
                        </div>
                    </div>
                    <form method="POST" action="?section=reviews" class="admin-actions review-card__actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                        <input type="hidden" name="review_action" value="approve_review">
                        <?php if (!empty($review['user_id'])): ?>
                            <a href="../profile?view_user=<?= (int) $review['user_id'] ?>" class="btn btn--dark" target="_blank" rel="noopener">الطالب</a>
                        <?php endif; ?>
                        <?php if ($review['status'] !== 'approved'): ?>
                            <button type="submit" name="review_action" value="approve_review" class="btn btn--accent" data-confirm="هل تريد اعتماد هذا التقييم فوراً؟">اعتماد</button>
                        <?php else: ?>
                            <button type="submit" name="review_action" value="unapprove_review" class="btn btn--warning">إلغاء الاعتماد</button>
                        <?php endif; ?>
                        <button type="submit" name="review_action" value="delete_review" class="btn btn--danger" data-confirm="هل أنت متأكد من حذف التقييم نهائياً؟">حذف</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
(function(){
    const collegeSelect = document.getElementById('review-filter-college');
    const deptSelect = document.getElementById('review-filter-department');
    const statusSelect = document.getElementById('review-filter-status');
    const cards = Array.from(document.querySelectorAll('.review-card'));

    function filterReviews(){
        const college = collegeSelect?.value || '';
        const dept = deptSelect?.value || '';
        const status = statusSelect?.value || '';
        cards.forEach(card => {
            const matchesCollege = !college || card.dataset.college === college;
            const matchesDept = !dept || card.dataset.department === dept;
            const matchesStatus = !status || card.dataset.status === status;
            card.style.display = (matchesCollege && matchesDept && matchesStatus) ? 'grid' : 'none';
        });
    }

    [collegeSelect, deptSelect, statusSelect].forEach(select => select && select.addEventListener('change', filterReviews));
})();
</script>
