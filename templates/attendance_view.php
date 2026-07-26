<?php
if (!defined('DB_HOST')) {
    exit('تحذير: لا يمكن الوصول المباشر لهذا الملف.');
}

$attendanceRows = [];
if ($conn && !empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $attendanceUserColumn = resolve_attendance_user_column($conn);
    $stmt = mysqli_prepare($conn, 'SELECT id, course_code, status, created_at FROM attendance_log WHERE ' . $attendanceUserColumn . ' = ? ORDER BY created_at DESC, id DESC LIMIT 200');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) {
            $attendanceRows[] = $r;
        }
        mysqli_stmt_close($stmt);
    }
}

$totalLectures = count($attendanceRows);
$presents = 0;
$absents = 0;
$lates = 0;

foreach ($attendanceRows as $row) {
    if (($row['status'] ?? '') === 'present') {
        $presents++;
    } elseif (($row['status'] ?? '') === 'absent') {
        $absents++;
    } elseif (($row['status'] ?? '') === 'late') {
        $lates++;
    }
}

$attendanceRate = $totalLectures > 0 ? round((($presents + ($lates * 0.5)) / $totalLectures) * 100, 1) : 100;
$attendanceRatePercent = min(100, max(0, (int) round($attendanceRate)));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة متابعة الحضور والغياب - منصة مسار</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css">
    <style>
        .attendance-container { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .attendance-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .attendance-card-local { background: #1e222b; border: 1px solid #2d3139; padding: 20px; border-radius: 12px; text-align: center; color: #fff; }
        .attendance-card-local h3 { font-size: 14px; color: #aaa; margin: 0 0 8px 0; }
        .attendance-card-local strong { font-size: 28px; color: #1d7bb6; }
        .attendance-progress-card { padding: 20px; display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }
        .attendance-progress-track { width: 100%; height: 10px; background: #2d3139; border-radius: 999px; overflow: hidden; }
        .attendance-progress-track > span { display:block; height:100%; background: linear-gradient(90deg, #2e7d32 0%, #1d7bb6 100%); border-radius: inherit; }
        .bot-hint-box { background: linear-gradient(135deg, #1e222b 0%, #15181f 100%); border: 1px solid #2d3139; border-right: 5px solid #0a7e8c; padding: 20px; border-radius: 12px; color: #fff; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap; }
        .table-responsive-local { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .attendance-table { width: 100%; border-collapse: collapse; text-align: right; }
        .attendance-table th, .attendance-table td { padding: 14px 15px; border-bottom: 1px solid #eee; color: #333; }
        .attendance-table th { background-color: #f8f9fa; font-weight: 700; }
        .badge-present { background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; }
        .badge-absent { background: #ffebee; color: #c62828; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; }
        .badge-late { background: #fff3e0; color: #ef6c00; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="attendance-container">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
            <h1 style="margin:0; color:#fff;">📊 لوحة الحضور والغياب</h1>
            <a class="btn btn--accent" href="index.php">⬅️ العودة للقائمة الرئيسية</a>
        </div>

        <div class="bot-hint-box">
            <div>
                <h3 style="margin: 0 0 6px 0; color: #0a7e8c; font-size: 1.15rem;">🤖 يتم تسجيل رصد الحضور حياً عبر التليجرام</h3>
                <p style="margin: 0; color: #ccc; font-size: 0.95rem;">هذه البيانات مرتبطة بحسابك الشخصي فقط، ويُرسل لك البوت ملخصاً سريعاً عند الطلب.</p>
            </div>
            <a href="https://t.me/YJYQD_bot?start=attendance:<?= (int) ($_SESSION['user_id'] ?? 0) ?>" target="_blank" class="btn btn--primary" style="text-decoration:none;">فتح البوت الرسمي 🚀</a>
        </div>

        <div class="attendance-summary-grid">
            <div class="attendance-card-local" style="border-top:4px solid #2e7d32;">
                <h3>🟢 أيام الحضور</h3>
                <strong><?= (int) $presents ?></strong>
            </div>
            <div class="attendance-card-local" style="border-top:4px solid #c62828;">
                <h3>🔴 أيام الغياب</h3>
                <strong><?= (int) $absents ?></strong>
            </div>
            <div class="attendance-card-local" style="border-top:4px solid #ef6c00;">
                <h3>🟡 مرات التأخير</h3>
                <strong><?= (int) $lates ?></strong>
            </div>
            <div class="attendance-card-local attendance-progress-card" style="border-top:4px solid #1d7bb6;">
                <h3>📊 Attendance Summary</h3>
                <strong><?= $attendanceRate ?>%</strong>
                <div class="attendance-progress-track">
                    <span style="width: <?= (int) $attendanceRatePercent ?>%"></span>
                </div>
                <small style="color:#aaa;">النسبة الحالية لالتزامك</small>
            </div>
        </div>

        <div class="table-responsive-local">
            <h3 style="margin-top: 0; margin-bottom: 15px; color: #1e222b;">📜 السجل التاريخي للمحاضرات المرصودة</h3>
            <?php if (empty($attendanceRows)): ?>
                <p style="text-align:center; color:#666; padding:24px; margin:0;">لا توجد محاضرات مرصودة لحسابك حالياً عبر البوت.</p>
            <?php else: ?>
                <table id="attendanceTable" class="attendance-table display">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>المادة</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceRows as $row): ?>
                            <tr>
                                <td style="color:#555; font-size:13px;"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                <td><strong><?= htmlspecialchars((string) ($row['course_code'] ?? 'غير محدد')) ?></strong></td>
                                <td>
                                    <?php if (($row['status'] ?? '') === 'present'): ?>
                                        <span class="badge-present">حاضر</span>
                                    <?php elseif (($row['status'] ?? '') === 'absent'): ?>
                                        <span class="badge-absent">غائب</span>
                                    <?php else: ?>
                                        <span class="badge-late">متأخر</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && window.DataTable) {
            new window.DataTable('#attendanceTable', {
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/ar.json'
                },
                order: [[0, 'desc']],
                pageLength: 10
            });
        }
    });
    </script>
</body>
</html>