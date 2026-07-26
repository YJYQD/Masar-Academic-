<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

function send_telegram_text(string $chatId, string $text): bool {
    if ($chatId === '') {
        return false;
    }

    $botToken = '8266756174:AAG8jSRGVqOtQfCYRUm2MuscWURyW_ZofXA';
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);

    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $resp = curl_exec($ch);
    curl_close($ch);

    return $resp !== false;
}

function compute_absence_warning(float $attendancePercentage, string $subjectType): float {
    $defaultThreshold = 25.0;
    if ($subjectType === 'practical') {
        return max(10.0, $defaultThreshold - 5.0);
    }

    return $defaultThreshold;
}

$conn = $conn ?? null;
if (!$conn instanceof mysqli) {
    exit('DB unavailable');
}

$stmt = $conn->prepare('SELECT ss.id, ss.student_id, ss.subject_type, ss.attendance_percentage, ss.critical_absence_threshold, ss.reminder_enabled, s.telegram_chat_id, s.full_name, sub.subject_name FROM student_subjects ss INNER JOIN students s ON s.id = ss.student_id INNER JOIN subjects sub ON sub.id = ss.subject_id WHERE ss.reminder_enabled = 1 AND ss.attendance_percentage <= ss.critical_absence_threshold');
if (!$stmt) {
    exit('query failed');
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $studentSubjectId = (int) $row['id'];
    $chatId = trim((string) ($row['telegram_chat_id'] ?? ''));
    $subjectName = trim((string) ($row['subject_name'] ?? 'غير محدد'));
    $studentName = trim((string) ($row['full_name'] ?? 'طالب'));
    $subjectType = $row['subject_type'] === 'practical' ? 'practical' : 'theoretical';
    $attendance = (float) ($row['attendance_percentage'] ?? 0);
    $threshold = (float) ($row['critical_absence_threshold'] ?? compute_absence_warning($attendance, $subjectType));

    $warningText = "تنبيه حضور: {$studentName}\nالمادة: {$subjectName}\nالنسبة الحالية: {$attendance}%\nالحد الحرجي: {$threshold}%\nالرجاء مراجعة الحضور قبل فوات الأوان.";
    if ($chatId !== '') {
        send_telegram_text($chatId, $warningText);
    }

    $noteStmt = $conn->prepare('INSERT INTO attendance_notifications (student_subject_id, message_text) VALUES (?, ?)');
    if ($noteStmt) {
        $noteStmt->bind_param('is', $studentSubjectId, $warningText);
        $noteStmt->execute();
        $noteStmt->close();
    }
}
$stmt->close();
