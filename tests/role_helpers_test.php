<?php
require_once __DIR__ . '/../admin/includes/functions.php';

function assertSame($expected, $actual, $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSame('root_admin', normalize_admin_role('super'), 'super should map to root_admin');
assertSame('faculty_admin', normalize_admin_role('college_admin'), 'college_admin should map to faculty_admin');
assertSame('assistant_admin', normalize_admin_role('sub_admin'), 'sub_admin should map to assistant_admin');
assertSame('student', normalize_admin_role('student'), 'student should remain student');
assertSame(true, is_super_admin('root_admin'), 'root_admin should be treated as super');
assertSame(true, can_manage_section('root_admin', 'reviews'), 'root_admin should manage reviews');
assertSame(false, can_manage_section('assistant_admin', 'supervision'), 'assistant_admin should not manage supervision');

echo "role helper tests passed\n";
