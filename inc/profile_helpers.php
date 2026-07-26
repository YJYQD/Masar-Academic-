<?php
if (!function_exists('normalize_profile_role_label')) {
    function normalize_profile_role_label(?string $role): string
    {
        $normalized = trim(strtolower((string) ($role ?? '')));

        return match ($normalized) {
            'super', 'super_admin', 'root_admin', 'root', 'admin', 'administrator', 'god' => 'مشرف عام',
            'college_admin', 'faculty_admin', 'manager', 'moderator' => 'مشرف كلية',
            'sub_admin', 'assistant_admin', 'assistant' => 'مشرف فرعي',
            'student', 'user', 'member', 'learner' => 'طالب',
            default => 'مستخدم',
        };
    }
}

if (!function_exists('normalize_profile_status_label')) {
    function normalize_profile_status_label(?string $status): string
    {
        $normalized = trim(strtolower((string) ($status ?? '')));

        return match ($normalized) {
            'active', 'approved', 'enabled', 'verified' => 'نشط',
            'blocked', 'inactive', 'disabled' => 'غير نشط',
            'pending' => 'قيد المراجعة',
            default => 'غير محدد',
        };
    }
}

if (!function_exists('normalize_profile_row')) {
    function normalize_profile_row(array $row = []): array
    {
        $displayName = trim((string) ($row['full_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($row['username'] ?? ''));
        }

        $displayUsername = trim((string) ($row['username'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $collegeScope = trim((string) ($row['college_scope'] ?? ''));
        $departmentScope = trim((string) ($row['department_scope'] ?? ''));
        $specialty = trim((string) ($row['specialty'] ?? ''));
        $statusLabel = normalize_profile_status_label($row['status'] ?? '');
        $roleLabel = normalize_profile_role_label($row['role'] ?? '');

        return [
            'display_name' => $displayName !== '' ? $displayName : ($displayUsername !== '' ? $displayUsername : 'مستخدم'),
            'display_username' => $displayUsername !== '' ? $displayUsername : 'غير محدد',
            'email' => $email !== '' ? $email : 'غير مضاف',
            'phone' => $phone !== '' ? $phone : 'غير مضاف',
            'college_scope' => $collegeScope !== '' ? $collegeScope : 'غير محدد',
            'department_scope' => $departmentScope !== '' ? $departmentScope : 'غير محدد',
            'specialty' => $specialty !== '' ? $specialty : 'غير محدد',
            'role_label' => $roleLabel,
            'status_label' => $statusLabel,
            'created_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }
}
