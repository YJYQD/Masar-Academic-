<?php
if (!function_exists('build_academy_live_payload')) {
    function build_academy_live_payload(array $curriculumRows = [], array $subjectRows = [], array $departmentsRows = []): array
    {
        $normalizedCurriculum = array_values(array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'academic_path' => (string) ($row['academic_path'] ?? ''),
                'college' => (string) ($row['college'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'semester' => (string) ($row['semester'] ?? ''),
                'credits' => (int) ($row['credits'] ?? 0),
                'study_level' => (string) ($row['study_level'] ?? ''),
                'objectives' => (string) ($row['objectives'] ?? ''),
            ];
        }, $curriculumRows));

        $normalizedSubjects = array_values(array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'subject_name' => (string) ($row['subject_name'] ?? ''),
                'course_code' => (string) ($row['course_code'] ?? ''),
                'credit_hours' => (int) ($row['credit_hours'] ?? 0),
                'college' => (string) ($row['college'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'level_num' => (int) ($row['level_num'] ?? 0),
                'telegram_link' => (string) ($row['telegram_link'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
        }, $subjectRows));

        $normalizedDepartments = array_values(array_map(static function ($row) {
            return [
                'college' => (string) ($row['college'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'cnt' => (int) ($row['cnt'] ?? 0),
            ];
        }, $departmentsRows));

        $signatureInput = [
            'curriculum' => $normalizedCurriculum,
            'subjects' => $normalizedSubjects,
            'departments' => $normalizedDepartments,
        ];

        return [
            'success' => true,
            'curriculum_count' => count($normalizedCurriculum),
            'subjects_count' => count($normalizedSubjects),
            'departments' => $normalizedDepartments,
            'curriculum' => $normalizedCurriculum,
            'subjects' => $normalizedSubjects,
            'signature' => sha1(json_encode($signatureInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated_at' => gmdate('c'),
        ];
    }
}
