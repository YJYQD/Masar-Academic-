<?php
require_once __DIR__ . '/../inc/academy_sync.php';

$payload = build_academy_live_payload(
    [
        ['title' => 'مقدمة في البرمجة', 'description' => 'الأساسيات', 'academic_path' => 'هندسة البرمجيات', 'college' => 'الحاسبات', 'department' => 'برمجيات', 'semester' => 'الفصل الأول', 'credits' => 3, 'study_level' => 'الأول', 'objectives' => 'تعليم الأساسيات'],
    ],
    [
        ['subject_name' => 'برمجة', 'course_code' => 'CS101', 'credit_hours' => 3, 'college' => 'الحاسبات', 'department' => 'برمجيات', 'level_num' => 1],
    ],
    [
        ['college' => 'الحاسبات', 'department' => 'برمجيات', 'cnt' => 1],
    ]
);

if (!isset($payload['curriculum_count']) || $payload['curriculum_count'] !== 1) {
    fwrite(STDERR, "curriculum count mismatch\n");
    exit(1);
}

if (!isset($payload['subjects_count']) || $payload['subjects_count'] !== 1) {
    fwrite(STDERR, "subjects count mismatch\n");
    exit(1);
}

if (!isset($payload['departments'][0]['department']) || $payload['departments'][0]['department'] !== 'برمجيات') {
    fwrite(STDERR, "department payload mismatch\n");
    exit(1);
}

echo "academy live refresh payload test passed\n";
