<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المسار الأكاديمي - منصة مسار</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap');
        .curriculum-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }
        .filter-box-local {
            background: #1e222b;
            border: 1px solid #2d3139;
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }
        .filter-box-local label {
            flex: 1;
            min-width: 180px;
            font-size: 0.9rem;
            color: #aaa;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .filter-box-local select {
            background: #252a34;
            color: #fff;
            border: 1px solid #3b4252;
            padding: 10px;
            border-radius: 8px;
            font-family: 'Cairo', sans-serif;
            outline: none;
        }
        .curriculum-grid-local {
            display: grid;
            gap: 16px;
            margin-top: 16px;
        }
        .curriculum-card-local {
            background: #1e222b;
            border: 1px solid #2d3139;
            border-right: 5px solid #1d7bb6;
            padding: 20px;
            border-radius: 4px 12px 12px 4px;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transition: transform 0.2s ease;
        }
        .curriculum-card-local:hover {
            transform: translateY(-2px);
        }
        .curriculum-card-local h2 {
            margin: 0 0 8px 0;
            color: #1d7bb6;
            font-size: 1.3rem;
            font-weight: 700;
        }
        .curriculum-meta-line {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.85rem;
            color: #aaa;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #2d3139;
        }
        .meta-tag-local {
            background: #252a34;
            padding: 4px 10px;
            border-radius: 6px;
            color: #81c784;
            font-weight: bold;
        }
        .meta-tag-blue {
            background: rgba(29, 123, 182, 0.1);
            color: #1d7bb6;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <main class="page curriculum-container">
        <section class="panel" style="background:none; box-shadow:none; padding:0;">
            <div class="profile-card__header" style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:24px;">
                <div>
                    <h1 style="margin:0; color:#fff; font-size:2rem; font-weight:800;">🧭 المسار الأكاديمي للخطة الدراسية</h1>
                    <p style="margin:6px 0 0; color:#aaa; font-size:0.95rem;">تصفح واكتشف جميع المتطلبات الأكاديمية والمواد لكل مستوى وتخصص بسهولة تامة.</p>
                </div>
                <div class="panel-actions" style="display:flex; gap:12px;">
                    <?php if ($canManageCurriculum): ?>
                        <button type="button" class="btn btn--primary" id="open-curriculum-editor">➕ إضافة عنصر للمسار</button>
                    <?php endif; ?>
                    <a class="btn btn--accent" href="index.php" style="text-decoration:none;">⬅️ القائمة الرئيسية</a>
                </div>
            </div>

            <?php if (!empty($_SESSION['flash'])): ?>
                <p class="flash flash--<?= e($_SESSION['flash']['type'] ?? 'info') ?>" style="margin-bottom:20px;"><?= e($_SESSION['flash']['text'] ?? '') ?></p>
            <?php endif; ?>

            <?php if (!$canManageCurriculum): ?>
                <div style="background:#1e222b; border:1px solid #2d3139; padding:15px; border-radius:10px; color:#aaa; font-size:0.9rem; text-align:center; margin-bottom:20px;">ℹ️ وضع التصفح العام نشط حالياً. لإضافة أو تعديل المسارات الدراسية يرجى تسجيل الدخول أولاً.</div>
            <?php endif; ?>

            <!-- صناديق الفلترة العلوية المنسقة هندسياً -->
            <?php if (!empty($rows)): ?>
                <form method="get" class="filter-box-local" id="curriculum-filter-form">
                    <label>
                        🔍 فرز حسب الكلية
                        <select id="curriculum-filter-college" name="college_filter" onchange="this.form.submit()">
                            <option value="">كل الكليات</option>
                            <?php foreach ($availableColleges as $collegeName): ?>
                                <option value="<?= e($collegeName) ?>" <?= ($selectedCollege === $collegeName) ? 'selected' : '' ?>><?= e($collegeName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        🎓 فرز حسب القسم / التخصص
                        <select id="curriculum-filter-department" name="department_filter" onchange="this.form.submit()">
                            <option value="">كل الأقسام</option>
                            <?php if ($selectedCollege !== '' && isset($availableDepartments[$selectedCollege])): ?>
                                <?php foreach ($availableDepartments[$selectedCollege] as $departmentName): ?>
                                    <option value="<?= e($departmentName) ?>" <?= ($selectedDepartment === $departmentName) ? 'selected' : '' ?>><?= e($departmentName) ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($availableColleges as $collegeName): ?>
                                    <?php foreach ($availableDepartments[$collegeName] ?? [] as $departmentName): ?>
                                        <option value="<?= e($departmentName) ?>" <?= ($selectedDepartment === $departmentName) ? 'selected' : '' ?>><?= e($departmentName) ?></option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                    <div style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn btn--primary">تطبيق</button>
                        <a class="btn btn--light" href="curriculum.php">مسح</a>
                    </div>
                </form>
            <?php endif; ?>

            <!-- شبكة عرض كروت المواد المنسقة صراحة -->
            <div class="curriculum-grid-local">
                <?php if (empty($rows)): ?>
                    <div style="background:#1e222b; border:1px solid #2d3139; padding:40px; border-radius:12px; color:#aaa; text-align:center;">لا توجد خطة أكاديمية أو مسارات منشورة حالياً في الداتابيز.</div>
                <?php else: ?>
                    <div id="curriculum-cards" style="display:grid; gap:16px;">
                        <?php foreach ($rows as $row): ?>
                            <article class="curriculum-card-local curriculum-card"
                                data-college="<?= e($row['college'] ?? '') ?>"
                                data-department="<?= e($row['department'] ?? '') ?>"
                                data-path="<?= e($row['academic_path'] ?? '') ?>"
                                data-semester="<?= e($row['semester'] ?? '') ?>"
                                data-study-level="<?= e($row['study_level'] ?? '') ?>">
                                <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
                                    <div style="flex:1; min-width:280px;">
                                        <h2>📚 <?= e($row['title'] ?? '') ?></h2>
                                        <p style="color:#ccc; font-size:0.95rem; margin:0 0 10px 0; line-height:1.6;"><?= e($row['description'] ?? 'لا يوجد وصف متاح مضاف لهذه المادة حالياً.') ?></p>
                                        
                                        <?php if (!empty($row['objectives'])): ?>
                                            <p style="color:#aaa; font-size:0.88rem; margin:5px 0;">🎯 <b>أهداف المادة:</b> <?= e($row['objectives']) ?></p>
                                        <?php endif; ?>

                                        <div class="curriculum-meta-line">
                                            <?php if (!empty($row['college'])): ?><span class="meta-tag-blue">🏛️ <?= e($row['college']) ?></span><?php endif; ?>
                                            <?php if (!empty($row['department'])): ?><span class="meta-tag-blue">🏢 القسم: <?= e($row['department']) ?></span><?php endif; ?>
                                            <?php if (!empty($row['academic_path'])): ?><span class="meta-tag-local">🛣️ المسار: <?= e($row['academic_path']) ?></span><?php endif; ?>
                                            <span>⏱️ الفصل: <?= e($row['semester'] ?? 'غير محدد') ?></span>
                                            <span>⭐ المستوى: <?= e($row['study_level'] ?? 'غير محدد') ?></span>
                                            <span style="color:#ffb74d; font-weight:bold;">⏱️ عدد الساعات: <?= (int) ($row['credits'] ?? 0) ?> س</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($canManageCurriculum): ?>
                                        <div style="display:flex; gap:8px; flex-shrink:0;">
                                            <button type="button" class="btn btn--light edit-curriculum-btn"
                                                style="padding:6px 14px; font-size:13px;"
                                                data-id="<?= (int) ($row['id'] ?? 0) ?>"
                                                data-title="<?= e($row['title'] ?? '') ?>"
                                                data-description="<?= e($row['description'] ?? '') ?>"
                                                data-academic-path="<?= e($row['academic_path'] ?? '') ?>"
                                                data-college="<?= e($row['college'] ?? '') ?>"
                                                data-department="<?= e($row['department'] ?? '') ?>"
                                                data-semester="<?= e($row['semester'] ?? '') ?>"
                                                data-credits="<?= (int) ($row['credits'] ?? 0) ?>"
                                                data-study-level="<?= e($row['study_level'] ?? '') ?>"
                                                data-objectives="<?= e($row['objectives'] ?? '') ?>">تعديل</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('هل أنت متأكد تماماً من رغبتك في حذف هذا العنصر من المسار الأكاديمي؟')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="curriculum_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                                                <button type="submit" class="btn btn--light" style="padding:6px 14px; font-size:13px; background:rgba(211,47,47,0.1); color:#d32f2f; border-color:transparent;">حذف</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- المودال الخاص بالإضافة والتعديل يظهر بشكل منسق مع الثيم الداكن للوحة -->
    <?php if ($canManageCurriculum): ?>
    <div id="curriculum-editor-modal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:2000; align-items:center; justify-content:center; padding:20px;">
        <div class="modal-card" style="background:#1e222b; border:1px solid #2d3139; padding:25px; border-radius:14px; max-width:650px; width:100%; max-height:90vh; overflow-y:auto; color:#fff;">
            <h3 style="margin-top:0; color:#1d7bb6; font-size:1.4rem;">🧭 إدارة عناصر المسار الأكاديمي</h3>
            <form method="POST" class="auth-form-grid" style="display:grid; gap:12px; margin-top:15px;">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="curriculum_id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <label style="display:flex; flex-direction:column; gap:4px;">اسم المسار أو المادة<input type="text" name="title" required style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;"></label>
                    <label style="display:flex; flex-direction:column; gap:4px;">المسار الأكاديمي<input type="text" name="academic_path" placeholder="مثال: علوم الحاسب" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;"></label>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <label style="display:flex; flex-direction:column; gap:4px;">الكلية
                        <select name="college" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;">
                            <option value="">-- اختر الكلية --</option>
                            <?php foreach ($collegeCatalog as $collegeName => $departments): ?>
                                <option value="<?= e($collegeName) ?>"><?= e($collegeName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display:flex; flex-direction:column; gap:4px;">القسم/التخصص
                        <select name="department" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;">
                            <option value="">-- اختر القسم --</option>
                            <?php foreach ($collegeCatalog as $collegeName => $departments): ?>
                                <optgroup label="<?= e($collegeName) ?>" style="background:#1e222b; color:#fff;">
                                    <?php foreach ($departments as $departmentName): ?>
                                        <option value="<?= e($departmentName) ?>"><?= e($departmentName) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                    <label style="display:flex; flex-direction:column; gap:4px;">الفصل الدراسي<input type="text" name="semester" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;"></label>
                    <label style="display:flex; flex-direction:column; gap:4px;">الساعات<input type="number" name="credits" min="0" step="1" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;"></label>
                    <label style="display:flex; flex-direction:column; gap:4px;">المستوى الدراسي<input type="text" name="study_level" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px;"></label>
                </div>

                <label style="display:flex; flex-direction:column; gap:4px;">🎯 مخرجات وأهداف المادة الرئيسية<textarea name="objectives" rows="2" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px; font-family:inherit;"></textarea></label>
                <label style="display:flex; flex-direction:column; gap:4px;">📝 وصف المادة الشامل<textarea name="description" rows="2" style="padding:10px; background:#252a34; color:#fff; border:1px solid #3b4252; border-radius:6px; font-family:inherit;"></textarea></label>
                
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:10px;">
                    <button type="button" class="btn btn--light" id="close-curriculum-editor" style="padding:10px 20px;">إلغاء</button>
                    <button type="submit" class="btn btn--primary" style="padding:10px 25px;">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const syncUrl = 'curriculum.php?api=academy_sync';

        function refreshCurriculumView() {
            fetch(syncUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('sync-failed'); }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || payload.success !== true) { return; }
                    const container = document.querySelector('main.page');
                    if (!container) { return; }
                    const existingCards = container.querySelectorAll('.curriculum-card-local');
                    existingCards.forEach(function (card) { card.remove(); });
                    
                    const root = container.querySelector('section.panel');
                    if (!root) { return; }
                    const cardsWrapper = document.createElement('div');
                    cardsWrapper.id = 'curriculum-cards';
                    cardsWrapper.style.display = 'grid';
                    cardsWrapper.style.gap = '16px';
                    
                    if (payload.curriculum && payload.curriculum.length > 0) {
                        payload.curriculum.forEach(function (row) {
                            const article = document.createElement('article');
                            article.className = 'curriculum-card-local curriculum-card';
                            article.setAttribute('data-college', row.college || '');
                            article.setAttribute('data-department', row.department || '');
                            article.setAttribute('data-path', row.academic_path || '');
                            article.setAttribute('data-semester', row.semester || '');
                            article.setAttribute('data-study-level', row.study_level || '');
                            
                            article.innerHTML = [
                                '<div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">',
                                '<div style="flex:1; min-width:280px;">',
                                '<h2 style="margin:0 0 8px 0; color:#1d7bb6; font-size:1.3rem;">📚 ' + (row.title || '') + '</h2>',
                                '<p style="color:#ccc; font-size:0.95rem; margin:0 0 10px 0; line-height:1.6;">' + (row.description || 'لا يوجد وصف متاح لهذه المادة حالياً.') + '</p>',
                                row.objectives ? '<p style="color:#aaa; font-size:0.88rem; margin:5px 0;">🎯 <b>أهداف المادة:</b> ' + row.objectives + '</p>' : '',
                                '<div class="curriculum-meta-line">',
                                row.college ? '<span class="meta-tag-blue">🏛️ ' + row.college + '</span>' : '',
                                row.department ? '<span class="meta-tag-blue">🏢 القسم: ' + row.department + '</span>' : '',
                                row.academic_path ? '<span class="meta-tag-local">      المسار: ' + row.academic_path + '</span>' : '',
                                '<span>⏱️ الفصل: ' + (row.semester || 'غير محدد') + '</span>',
                                '<span>⭐ المستوى: ' + (row.study_level || 'غير محدد') + '</span>',
                                '<span style="color:#ffb74d; font-weight:bold;">⏱️ عدد الساعات: ' + (row.credits || 0) + ' س</span>',
                                '</div>',
                                '</div>',
                                '</div>'
                            ].join('');
                            cardsWrapper.appendChild(article);
                        });
                    } else {
                        const empty = document.createElement('div');
                        empty.style.cssText = 'background:#1e222b; border:1px solid #2d3139; padding:40px; border-radius:12px; color:#aaa; text-align:center;';
                        empty.textContent = 'لا توجد خطة أكاديمية منشورة حالياً.';
                        cardsWrapper.appendChild(empty);
                    }
                    const existingWrapper = root.querySelector('#curriculum-cards');
                    if (existingWrapper) { existingWrapper.replaceWith(cardsWrapper); }
                    else { root.appendChild(cardsWrapper); }
                    curriculumCards = Array.from(document.querySelectorAll('.curriculum-card'));
                    if (filterCollege) {
                        populateDepartmentFilter(filterCollege.value || '');
                        populatePathFilter(filterCollege.value || '', filterDepartment ? filterDepartment.value : '');
                        applyCurriculumFilters();
                    }
                }).catch(function () {});
        }

        window.refreshCurriculumView = refreshCurriculumView;
        window.setInterval(refreshCurriculumView, 8000);
        
        const modal = document.getElementById('curriculum-editor-modal');
        const openBtn = document.getElementById('open-curriculum-editor');
        const closeBtn = document.getElementById('close-curriculum-editor');
        const form = modal ? modal.querySelector('form') : null;
        const filterCollege = document.getElementById('curriculum-filter-college');
        const filterDepartment = document.getElementById('curriculum-filter-department');
        const filterPath = document.getElementById('curriculum-filter-path');
        let curriculumCards = Array.from(document.querySelectorAll('.curriculum-card'));

        function populateDepartmentFilter(selectedCollege) {
            if (!filterDepartment) return;
            const previousValue = filterDepartment.value;
            filterDepartment.innerHTML = '<option value="">كل الأقسام</option>';
            const departments = Array.from(new Set(curriculumCards.filter(c => !selectedCollege || c.getAttribute('data-college') === selectedCollege).map(c => c.getAttribute('data-department') || '').filter(Boolean))).sort();
            departments.forEach(function(dep){
                const opt = document.createElement('option'); opt.value = dep; opt.textContent = dep; filterDepartment.appendChild(opt);
            });
            if (previousValue) filterDepartment.value = previousValue;
        }

        function populatePathFilter(selectedCollege, selectedDepartment) {
            if (!filterPath) return;
            const previousValue = filterPath.value;
            filterPath.innerHTML = '<option value="">كل المسارات</option>';
            const paths = curriculumCards.filter(function(card){
                const collegeMatch = !selectedCollege || (card.getAttribute('data-college') || '') === selectedCollege;
                const departmentMatch = !selectedDepartment || (card.getAttribute('data-department') || '') === selectedDepartment;
                return collegeMatch && departmentMatch;
            }).map(function(card){ return card.getAttribute('data-path') || ''; }).filter(Boolean);
            Array.from(new Set(paths)).sort().forEach(function(path){
                const opt = document.createElement('option'); opt.value = path; opt.textContent = path; filterPath.appendChild(opt);
            });
            if (previousValue) filterPath.value = previousValue;
        }

        function applyCurriculumFilters() {
            const selectedCollege = filterCollege ? filterCollege.value : '';
            const selectedDepartment = filterDepartment ? filterDepartment.value : '';
            const selectedPath = filterPath ? filterPath.value : '';
            curriculumCards.forEach(function(card){
                const matchesCollege = !selectedCollege || (card.getAttribute('data-college') || '') === selectedCollege;
                const matchesDepartment = !selectedDepartment || (card.getAttribute('data-department') || '') === selectedDepartment;
                const matchesPath = !selectedPath || (card.getAttribute('data-path') || '') === selectedPath;
                card.style.display = (matchesCollege && matchesDepartment && matchesPath) ? 'block' : 'none';
            });
        }

        if (filterCollege) {
            filterCollege.addEventListener('change', function(){
                populateDepartmentFilter(this.value);
                populatePathFilter(this.value, filterDepartment ? filterDepartment.value : '');
                applyCurriculumFilters();
            });
        }
        if (filterDepartment) {
            filterDepartment.addEventListener('change', function(){
                populatePathFilter(filterCollege ? filterCollege.value : '', this.value);
                applyCurriculumFilters();
            });
        }
        if (filterPath) { filterPath.addEventListener('change', applyCurriculumFilters); }

        if (filterCollege) {
            populateDepartmentFilter(''); populatePathFilter('', ''); applyCurriculumFilters();
        }

        function openModal(){ if (!modal) return; modal.style.display = 'flex'; }
        function closeModal(){ if (!modal) return; modal.style.display = 'none'; if (form) form.reset(); }

        openBtn?.addEventListener('click', openModal);
        closeBtn?.addEventListener('click', closeModal);

        document.querySelectorAll('.edit-curriculum-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!form) return;
                form.querySelector('input[name="curriculum_id"]').value = btn.getAttribute('data-id') || '';
                form.querySelector('input[name="title"]').value = btn.getAttribute('data-title') || '';
                form.querySelector('input[name="academic_path"]').value = btn.getAttribute('data-academic-path') || '';
                form.querySelector('select[name="college"]').value = btn.getAttribute('data-college') || '';
                form.querySelector('select[name="department"]').value = btn.getAttribute('data-department') || '';
                form.querySelector('input[name="semester"]').value = btn.getAttribute('data-semester') || '';
                form.querySelector('input[name="credits"]').value = btn.getAttribute('data-credits') || '0';
                form.querySelector('input[name="study_level"]').value = btn.getAttribute('data-study-level') || '';
                form.querySelector('textarea[name="objectives"]').value = btn.getAttribute('data-objectives') || '';
                form.querySelector('textarea[name="description"]').value = btn.getAttribute('data-description') || '';
                openModal();
            });
        });
    });
    </script>
</body>
</html>