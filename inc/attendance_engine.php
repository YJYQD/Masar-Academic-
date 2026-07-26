<?php

if (!function_exists('attendance_risk_band')) {
    function attendance_risk_band(float $rate): string
    {
        if ($rate >= 90.0) {
            return 'safe';
        }

        if ($rate >= 75.0) {
            return 'medium';
        }

        return 'high';
    }
}

if (!function_exists('attendance_risk_label_ar')) {
    function attendance_risk_label_ar(string $band): string
    {
        return match ($band) {
            'safe' => 'آمن',
            'medium' => 'متوسط',
            'high' => 'مرتفع',
            default => 'غير محدد',
        };
    }
}

if (!function_exists('calculate_review_weight')) {
    function calculate_review_weight(float $attendanceRate): float
    {
        if ($attendanceRate >= 90.0) {
            return 1.35;
        }

        if ($attendanceRate >= 75.0) {
            return 1.10;
        }

        if ($attendanceRate >= 60.0) {
            return 0.95;
        }

        return 0.80;
    }
}

if (!function_exists('build_attendance_snapshot')) {
    function build_attendance_snapshot(mysqli $conn, int $studentId, string $courseCode = ''): array
    {
        $courseFilter = '';
        $params = [$studentId];
        $types = 'i';
        if ($courseCode !== '') {
            $courseFilter = ' AND course_code = ?';
            $params[] = $courseCode;
            $types .= 's';
        }

        $userColumn = resolve_attendance_user_column($conn);
        $sql = 'SELECT status FROM attendance_log WHERE ' . $userColumn . ' = ?' . $courseFilter . ' ORDER BY id DESC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['attendance_rate' => 0.0, 'risk_band' => 'high', 'review_weight' => 0.8, 'total_records' => 0, 'present_records' => 0];
        }

        $bindParams = [$types];
        foreach ($params as $index => $value) {
            $bindParams[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $totalRecords = count($rows);
        $presentRecords = 0;
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? 'absent'));
            if ($status === 'present' || $status === 'attended' || $status === 'yes') {
                $presentRecords++;
            }
        }

        $attendanceRate = $totalRecords > 0 ? round(($presentRecords / $totalRecords) * 100, 2) : 0.0;
        $riskBand = attendance_risk_band($attendanceRate);
        $reviewWeight = calculate_review_weight($attendanceRate);

        return [
            'attendance_rate' => $attendanceRate,
            'risk_band' => $riskBand,
            'review_weight' => $reviewWeight,
            'total_records' => $totalRecords,
            'present_records' => $presentRecords,
        ];
    }
}
