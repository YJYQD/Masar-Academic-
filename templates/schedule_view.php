<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الجدول الأكاديمي - منصة مسار</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap');
        .schedule-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .schedule-header-local {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .form-schedule-local {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #2d3139;
            border-radius: 12px;
            background: #1e222b;
            color: #fff;
        }
        .form-schedule-local label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-weight: 700;
            font-size: 0.9rem;
            color: #aaa;
        }
        .form-schedule-local input, .form-schedule-local select {
            background: #252a34;
            color: #fff;
            border: 1px solid #3b4252;
            padding: 10px;
            border-radius: 8px;
            font-family: 'Cairo', sans-serif;
            outline: none;
        }
        .days-grid-local {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            align-items: start;
        }
        .day-section-local {
            border: 1px solid #2d3139;
            border-radius: 12px;
            padding: 16px;
            background: #1e222b;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        .day-section-local h2 {
            margin: 0 0 4px 0;
            color: #1d7bb6;
            font-size: 1.25rem;
            font-weight: 700;
        }
        .lecture-item-local {
            background: #252a34;
            border-right: 4px solid #1d7bb6;
            padding: 12px;
            border-radius: 4px 8px 8px 4px;
            margin-top: 12px;
            list-style: none;
        }
        .lecture-time-local {
            font-size: 0.82rem;
            color: #ffb74d;
            margin: 4px 0;
            font-weight: bold;
        }
        .lecture-loc-local {
            font-size: 0.82rem;
            color: #81c784;
            margin-bottom: 6px;
        }
        .stack-list-local {
            padding: 0;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="schedule-container">
        <div class="schedule-header-local">
            <div>
                <h1 style="margin:0; color:#fff; font-weight:800;">📅 الجدول الدراسي الأكاديمي</h1>
                <p style="margin:6px 0 0; color:#aaa; font-size:0.9rem;">المنطقة الزمنية المعتمدة: <?= htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a class="btn btn--accent" href="index.php" style="text-decoration:none;">⬅️ العودة للقائمة الرئيسية</a>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="flash flash--<?= htmlspecialchars($_SESSION['flash']['type'] ?? 'success', ENT_QUOTES, 'UTF-8') ?>" style="margin-bottom:20px;">
                <?= htmlspecialchars($_SESSION['flash']['text'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <?php if ($canManageSchedule): ?>
        <form id="schedule-form" method="post" action="schedule.php" class="form-schedule-local">
            <input type="hidden" name="add_to_schedule" value="1">
            <input type="hidden" name="schedule_action" value="create">
            <input type="hidden" name="schedule_id" value="">
            <label>اسم المقرر
                <input type="text" name="title" placeholder="مثال: تراكيب البيانات" required>
            </label>
            <label>رمز المقرر
                <input type="text" name="course_code" placeholder="مثال: CS201">
            </label>
            <label>اليوم
                <select name="day_of_week">
                    <option value="0">الأحد</option>
                    <option value="1">الاثنين</option>
                    <option value="2">الثلاثاء</option>
                    <option value="3">الأربعاء</option>
                    <option value="4">الخميس</option>
                    <option value="5">الجمعة</option>
                    <option value="6">السبت</option>
                </select>
            </label>
            <label>الوقت من
                <input type="time" name="start_time" required>
            </label>
            <label>الوقت إلى
                <input type="time" name="end_time" required>
            </label>
            <label>المكان
                <input type="text" name="location" placeholder="مثال: قاعة 101 المعمل">
            </label>
            <label>ملاحظات
                <input type="text" name="notes" placeholder="اختياري">
            </label>
            <div style="display:flex; align-items:end; gap:8px; flex-wrap:wrap; margin-top:5px;">
                <button type="submit" class="btn btn--primary" id="schedule-submit-btn" style="flex:1; white-space:nowrap; padding:10px 15px;">إضافة للجدول</button>
                <button type="button" class="btn btn--light" id="schedule-cancel-btn" style="display:none; padding:10px 15px;">إلغاء</button>
            </div>
        </form>
        <?php else: ?>
            <div style="background:#1e222b; border:1px solid #2d3139; padding:14px; border-radius:10px; color:#aaa; margin-bottom:20px;">📖 وضع القراءة فقط: لا توجد أزرار تعديل أو حذف لأن الحساب الحالي موجه للطالب.</div>
        <?php endif; ?>

        <!-- شبكة عرض أيام الأسبوع بشكل عرضي ومريح جنبًا إلى جنب -->
        <div class="days-grid-local">
            <?php foreach ($days as $day): ?>
                <section class="day-section-local">
                    <h2><?= htmlspecialchars($day['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p style="color:#aaa; font-size:0.85rem; margin:0 0 10px 0;"><?= htmlspecialchars($day['date'], ENT_QUOTES, 'UTF-8') ?></p>
                    
                    <?php $items = $byDay[$day['day_index']] ?? []; ?>
                    <?php if (!empty($items)): ?>
                        <ul class="stack-list-local">
                            <?php foreach ($items as $item): ?>
                                <li class="lecture-item-local">
                                    <strong style="font-size:1rem; color:#fff; display:block;"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div style="font-size:0.85rem; color:#aaa; margin-top:2px;"><?= htmlspecialchars($item['course_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="lecture-time-local">⏱️ <?= format_ar_time($item['start_time']) ?> - <?= format_ar_time($item['end_time']) ?></div>
                                    <?php if (!empty($item['location'])): ?>
                                        <div class="lecture-loc-local">📍 <?= htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['notes'])): ?>
                                        <div style="font-size:0.8rem; color:#888; font-style:italic; margin-bottom:6px;">📝 <?= htmlspecialchars($item['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($canManageSchedule): ?>
                                    <div style="margin-top:10px; display:flex; gap:6px; flex-wrap:wrap;">
                                        <button type="button" class="btn btn--light edit-schedule-btn" style="padding:4px 10px; font-size:12px;"
                                            data-id="<?= (int) $item['id'] ?>"
                                            data-title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-course-code="<?= htmlspecialchars($item['course_code'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-day-of-week="<?= (int) $item['day_of_week'] ?>"
                                            data-start-time="<?= htmlspecialchars($item['start_time'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-end-time="<?= htmlspecialchars($item['end_time'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-location="<?= htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-notes="<?= htmlspecialchars($item['notes'], ENT_QUOTES, 'UTF-8') ?>">تعديل</button>
                                        <form method="post" action="schedule.php" style="display:inline;" onsubmit="return confirm('هل تريد حذف هذا المقرر من الجدول الدراسي نهائياً؟');">
                                            <input type="hidden" name="schedule_action" value="delete">
                                            <input type="hidden" name="schedule_id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit" class="btn btn--light" style="padding:4px 10px; font-size:12px; background:rgba(211,47,47,0.1); color:#d32f2f; border-color:transparent;">حذف</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color:#666; font-size:0.88rem; margin:10px 0 0 0; text-align:center; font-style:italic;">لا توجد محاضرات لهذا اليوم 🏖️</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- الإبقاء على دوال الـ JavaScript كما هي تماماً لضمان تعبئة التعديل الفوري -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('schedule-form');
        if (!form) { return; }

        const actionInput = form.querySelector('input[name="schedule_action"]');
        const idInput = form.querySelector('input[name="schedule_id"]');
        const submitBtn = document.getElementById('schedule-submit-btn');
        const cancelBtn = document.getElementById('schedule-cancel-btn');

        function resetForm() {
            form.reset();
            if (actionInput) { actionInput.value = 'create'; }
            if (idInput) { idInput.value = ''; }
            if (submitBtn) { submitBtn.textContent = 'إضافة إلى الجدول'; }
            if (cancelBtn) { cancelBtn.style.display = 'none'; }
        }

        cancelBtn?.addEventListener('click', resetForm);
        resetForm();

        document.querySelectorAll('.edit-schedule-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                if (actionInput) { actionInput.value = 'update'; }
                if (idInput) { idInput.value = button.getAttribute('data-id') || ''; }
                form.querySelector('input[name="title"]').value = button.getAttribute('data-title') || '';
                form.querySelector('input[name="course_code"]').value = button.getAttribute('data-course-code') || '';
                form.querySelector('select[name="day_of_week"]').value = button.getAttribute('data-day-of-week') || '0';
                form.querySelector('input[name="start_time"]').value = button.getAttribute('data-start-time') || '';
                form.querySelector('input[name="end_time"]').value = button.getAttribute('data-end-time') || '';
                form.querySelector('input[name="location"]').value = button.getAttribute('data-location') || '';
                form.querySelector('input[name="notes"]').value = button.getAttribute('data-notes') || '';
                if (submitBtn) { submitBtn.textContent = 'حفظ التعديل ✨'; }
                if (cancelBtn) { cancelBtn.style.display = 'inline-block'; }
                form.querySelector('input[name="title"]').focus();
            });
        });
    });
    </script>
</body>
</html>