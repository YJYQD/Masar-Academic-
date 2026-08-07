<?php
require_once __DIR__ . '/../inc/profile_helpers.php';

$profile = normalize_profile_row([
    'username' => 'ahmed123',
    'full_name' => 'أحمد علي',
    'email' => 'ahmed@example.com',
    'phone' => '0500000000',
    'role' => 'super_admin',
    'status' => 'active',
    'college_scope' => 'الكلية الطبية',
    'department_scope' => 'الأمراض الباطنية',
    'created_at' => '2024-01-01 00:00:00',
]);

if ($profile['display_name'] !== 'أحمد علي') {
    fwrite(STDERR, "display_name failed\n");
    exit(1);
}

if ($profile['role_label'] !== 'مشرف عام') {
    fwrite(STDERR, "role_label failed\n");
    exit(1);
}

if ($profile['status_label'] !== 'نشط') {
    fwrite(STDERR, "status_label failed\n");
    exit(1);
}

if ($profile['college_scope'] !== 'الكلية الطبية') {
    fwrite(STDERR, "college_scope failed\n");
    exit(1);
}

echo "profile helpers test passed\n";
