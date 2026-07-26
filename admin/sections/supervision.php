<?php
if (!defined('DB_HOST') && empty($_SESSION['is_admin'])) { exit('الوصول مقيد'); }

$admins = [];
$authContext = current_auth_context();
$admin_role = normalize_admin_role($authContext['role'] === 'super' ? 'super' : 'college_admin');
$college_options = array_keys(get_colleges_map());
$catalog_colleges = [];
$catalog_departments = [];
$collegeStmt = mysqli_prepare($conn, 'SELECT id, college_name FROM academic_colleges WHERE is_active = 1 ORDER BY college_name ASC');
if ($collegeStmt) {
    mysqli_stmt_execute($collegeStmt);
    $collegeRes = mysqli_stmt_get_result($collegeStmt);
    while ($row = mysqli_fetch_assoc($collegeRes)) {
        $catalog_colleges[] = $row;
    }
    mysqli_stmt_close($collegeStmt);
}
$departmentStmt = mysqli_prepare($conn, 'SELECT id, college_id, department_name FROM academic_departments WHERE is_active = 1 ORDER BY department_name ASC');
if ($departmentStmt) {
    mysqli_stmt_execute($departmentStmt);
    $departmentRes = mysqli_stmt_get_result($departmentStmt);
    while ($row = mysqli_fetch_assoc($departmentRes)) {
        $catalog_departments[] = $row;
    }
    mysqli_stmt_close($departmentStmt);
}

$stmt = mysqli_prepare($conn, 'SELECT id, username, college_responsibility, role FROM admins ORDER BY id ASC');
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $admins[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>

<section class="admin-section-shell">
    <div class="admin-page-header">
        <div>
            <span class="section-badge section-badge--supervision">👥 إدارة المشرفين</span>
            <h2>المشرفين والصلاحيات</h2>
            <p class="muted-text">اعتمد على البحث الذكي لربط الحسابات الموجودة في جدول المستخدمين وترقيتها إلى مشرفين.</p>
        </div>
    </div>

    <div class="admin-filters" style="margin-bottom: 18px;">
        <label>البحث
            <input type="text" name="promote_identity" id="promote_identity_display" placeholder="ابحث عن مستخدم بالاسم أو البريد" autocomplete="off">
        </label>
        <label>الكلية
            <select name="promote_admin_college" id="promote_admin_college">
                <option value="">الكل</option>
                <?php foreach ($college_options as $college_option): ?>
                    <option value="<?= e($college_option) ?>"><?= e($college_option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>الدور
            <select name="promote_admin_role" id="promote_admin_role">
                <option value="assistant_admin">مشرف مساعد</option>
                <option value="faculty_admin">مشرف كلية</option>
                <option value="root_admin">مدير عام</option>
            </select>
        </label>
        <div class="admin-filters__actions">
            <button id="show-all-users" type="button" class="btn btn--secondary">👥 عرض كل المستخدمين</button>
            <button id="promote-submit" type="button" class="btn btn--accent">ترقية مستخدم لمشرف</button>
        </div>
    </div>

    <div class="admin-section-card" style="margin-bottom: 18px;">
        <h3>🏛️ إدارة الكليات والتخصصات</h3>
        <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top: 12px;">
            <form method="POST" class="form-card">
                <?= csrf_field() ?>
                <input type="hidden" name="catalog_action" value="add_college">
                <label>اسم الكلية
                    <input type="text" name="college_name" required>
                </label>
                <button type="submit" class="btn btn--accent">إضافة كلية</button>
            </form>
            <form method="POST" class="form-card">
                <?= csrf_field() ?>
                <input type="hidden" name="catalog_action" value="add_department">
                <label>اختر الكلية
                    <select name="college_id" required>
                        <option value="">اختر الكلية</option>
                        <?php foreach ($catalog_colleges as $college): ?>
                            <option value="<?= (int) $college['id'] ?>"><?= e($college['college_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>اسم التخصص
                    <input type="text" name="department_name" required>
                </label>
                <button type="submit" class="btn btn--accent">إضافة تخصص</button>
            </form>
        </div>
        <div class="cards" style="margin-top: 16px;">
            <?php foreach ($catalog_colleges as $college): ?>
                <article class="admin-item" style="padding: 16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div>
                            <h3><?= e($college['college_name']) ?></h3>
                            <p class="muted-text">التخصصات المرتبطة</p>
                        </div>
                        <form method="POST" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="catalog_action" value="delete_college">
                            <input type="hidden" name="college_id" value="<?= (int) $college['id'] ?>">
                            <button type="submit" class="btn btn--danger">حذف الكلية</button>
                        </form>
                    </div>
                    <div class="cards" style="margin-top:12px;">
                        <?php foreach ($catalog_departments as $department): ?>
                            <?php if ((int) $department['college_id'] !== (int) $college['id']) continue; ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:10px;">
                                <span><?= e($department['department_name']) ?></span>
                                <form method="POST" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="catalog_action" value="delete_department">
                                    <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
                                    <button type="submit" class="btn btn--light btn--small">حذف</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-card" style="position:relative;">
        <input type="hidden" name="promote_user_id" id="promote_user_id" value="">
        <div id="promote_suggestions" class="suggestions" style="position:absolute; left:0; right:0; top:98px; z-index:50; background:#fff; border:1px solid #e2e8f0; border-radius:14px; display:none; max-height:260px; overflow:auto; box-shadow:0 18px 32px rgba(17,28,45,0.12);"></div>
        <p class="muted-text">اختر مستخدماً من النتائج لتفعيل عملية الترقية، ثم اضغط زر الترقية.</p>
    </div>

    <div id="all-users-modal" class="modal" style="display:none;">
        <div class="modal__content modal__content--wide">
            <div class="modal__header">
                <h3>جميع المستخدمين</h3>
                <button type="button" class="modal__close" id="all-users-close">×</button>
            </div>
            <div class="modal__body">
                <div class="table-wrapper">
                    <table class="data-table" id="all-users-table">
                        <thead>
                            <tr>
                                <th>الاسم</th>
                                <th>البريد الجامعي</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="?section=supervision" id="promote-form" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="promote_user" value="1">
        <input type="hidden" name="promote_user_id" id="promote_user_id_form" value="">
        <input type="hidden" name="promote_identity" id="promote_identity_form" value="">
        <input type="hidden" name="promote_admin_college" id="promote_admin_college_form" value="">
        <input type="hidden" name="promote_admin_role" id="promote_admin_role_form" value="">
    </form>

    <script>
    (function(){
        const input = document.getElementById('promote_identity_display');
        const suggestions = document.getElementById('promote_suggestions');
        const hiddenId = document.getElementById('promote_user_id');
        const submitBtn = document.getElementById('promote-submit');
        const promoteForm = document.getElementById('promote-form');
        const promoteCollege = document.getElementById('promote_admin_college');
        const promoteRole = document.getElementById('promote_admin_role');
        const promoteUserIdForm = document.getElementById('promote_user_id_form');
        const promoteIdentityForm = document.getElementById('promote_identity_form');
        const promoteCollegeForm = document.getElementById('promote_admin_college_form');
        const promoteRoleForm = document.getElementById('promote_admin_role_form');
        const showAllBtn = document.getElementById('show-all-users');
        const allUsersModal = document.getElementById('all-users-modal');
        const allUsersClose = document.getElementById('all-users-close');
        const allUsersTableBody = document.querySelector('#all-users-table tbody');
        let timer = null;

        function clearSuggestions(){ suggestions.innerHTML = ''; suggestions.style.display = 'none'; }

        function fillUserRow(user){
            const tr = document.createElement('tr');
            const usernameCell = document.createElement('td');
            usernameCell.textContent = user.username;

            const emailCell = document.createElement('td');
            emailCell.textContent = user.email;

            const actionsCell = document.createElement('td');
            actionsCell.className = 'table-actions';

            const profileLink = document.createElement('a');
            profileLink.href = '../profile?view_user=' + encodeURIComponent(user.id);
            profileLink.target = '_blank';
            profileLink.rel = 'noopener';
            profileLink.className = 'btn btn--light btn--small';
            profileLink.textContent = 'الملف الشخصي';

            const selectBtn = document.createElement('button');
            selectBtn.type = 'button';
            selectBtn.className = 'btn btn--secondary btn--small';
            selectBtn.textContent = 'اختيار للترقية';
            selectBtn.addEventListener('click', function(){
                input.value = user.username + ' ‹' + user.email + '›';
                hiddenId.value = user.id;
                closeModal();
            });

            actionsCell.appendChild(profileLink);
            actionsCell.appendChild(selectBtn);
            tr.appendChild(usernameCell);
            tr.appendChild(emailCell);
            tr.appendChild(actionsCell);
            return tr;
        }

        function showSuggestions(list){
            suggestions.innerHTML = '';
            if(!Array.isArray(list) || list.length === 0){ clearSuggestions(); return; }
            list.forEach(u=>{
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.textContent = u.username + (u.email ? ' ‹' + u.email + '›' : '');
                item.addEventListener('click', ()=>{
                    input.value = u.username + (u.email ? ' ‹' + u.email + '›' : '');
                    hiddenId.value = u.id;
                    clearSuggestions();
                });
                suggestions.appendChild(item);
            });
            suggestions.style.display = 'block';
        }

        function openModal(){
            allUsersModal.style.display = 'flex';
            allUsersModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function closeModal(){
            allUsersModal.style.display = 'none';
            allUsersModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        function loadAllUsers(){
            allUsersTableBody.innerHTML = '<tr><td colspan="3" class="text-center">جارٍ التحميل...</td></tr>';
            fetch('/admin/user_search.php?all=1', {credentials: 'same-origin'})
                .then(r=>r.ok ? r.json() : [])
                .then(users=>{
                    allUsersTableBody.innerHTML = '';
                    if (!Array.isArray(users) || users.length === 0) {
                        allUsersTableBody.innerHTML = '<tr><td colspan="3" class="text-center">لا يوجد مستخدمون مسجلون.</td></tr>';
                        return;
                    }
                    users.forEach(user => allUsersTableBody.appendChild(fillUserRow(user)));
                })
                .catch(()=>{
                    allUsersTableBody.innerHTML = '<tr><td colspan="3" class="text-center">حدث خطأ أثناء جلب المستخدمين.</td></tr>';
                });
        }

        input.addEventListener('input', function(){
            hiddenId.value = '';
            const q = this.value.trim();
            if(timer) clearTimeout(timer);
            if(q.length < 2){ clearSuggestions(); return; }
            timer = setTimeout(()=>{
                fetch('/admin/user_search.php?q='+encodeURIComponent(q), {credentials: 'same-origin'})
                    .then(r=>r.ok ? r.json() : [])
                    .then(showSuggestions)
                    .catch(()=>{ clearSuggestions(); });
            }, 240);
        });

        showAllBtn.addEventListener('click', function(){
            openModal();
            loadAllUsers();
        });

        allUsersClose.addEventListener('click', closeModal);
        allUsersModal.addEventListener('click', function(event){
            if (event.target === allUsersModal) closeModal();
        });

        submitBtn.addEventListener('click', function(){
            const identity = input.value.trim();
            if (!identity || !hiddenId.value) {
                alert('يرجى اختيار مستخدم من قائمة النتائج أولاً.');
                return;
            }
            promoteUserIdForm.value = hiddenId.value;
            promoteIdentityForm.value = identity;
            promoteCollegeForm.value = promoteCollege.value;
            promoteRoleForm.value = promoteRole.value;
            promoteForm.submit();
        });

        document.addEventListener('click', function(ev){
            if (!ev.target.closest('#promote_suggestions') && ev.target !== input) {
                clearSuggestions();
            }
        });
    })();
    </script>

    <?php if (empty($admins)): ?>
        <p class="empty">لا يوجد مشرفون مسجلون بعد.</p>
    <?php else: ?>
        <div class="cards admin-list">
            <?php foreach ($admins as $admin): ?>
                <article class="admin-item" style="padding: 20px;">
                    <div>
                        <h3><?= e($admin['username']) ?></h3>
                        <p style="color:#bbb;">الكلية: <?= e($admin['college_responsibility'] ?: 'إدارة عامة') ?></p>
                        <p style="color:#7f8c8d;">الدور: <?= e(admin_role_label_ar($admin['role'])) ?></p>
                    </div>
                    <form method="POST" action="?section=supervision" class="admin-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="edit_admin_id" value="<?= (int) $admin['id'] ?>">
                        <input type="hidden" name="update_admin_college" value="1">
                        <select name="edit_college" style="min-width:180px;">
                            <option value="">إدارة عامة</option>
                            <?php foreach ($college_options as $college_option): ?>
                                <option value="<?= e($college_option) ?>" <?= ($admin['college_responsibility'] ?? '') === $college_option ? 'selected' : '' ?>><?= e($college_option) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="edit_role">
                            <option value="assistant_admin" <?= normalize_admin_role($admin['role']) === 'assistant_admin' ? 'selected' : '' ?>>assistant_admin</option>
                            <option value="faculty_admin" <?= normalize_admin_role($admin['role']) === 'faculty_admin' ? 'selected' : '' ?>>faculty_admin</option>
                            <option value="root_admin" <?= normalize_admin_role($admin['role']) === 'root_admin' ? 'selected' : '' ?>>root_admin</option>
                        </select>
                        <button type="submit" class="btn">تحديث</button>
                    </form>
                    <form method="POST" action="?section=supervision" class="admin-actions" style="margin-top:8px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_admin_id" value="<?= (int) $admin['id'] ?>">
                        <input type="hidden" name="delete_admin_action" value="1">
                        <button type="submit" class="btn btn--danger">حذف</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
