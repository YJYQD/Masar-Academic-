<?php
require_once __DIR__ . '/../admin/includes/functions.php';

function assertSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$superContext = resolve_access_context(['role' => 'super', 'college_scope' => 'الهندسة وعلوم الحاسب']);
assertSame('super_admin', $superContext['role'], 'super role should map to super_admin');
assertSame('الهندسة وعلوم الحاسب', $superContext['college_name'], 'college scope should be preserved');

$studentContext = resolve_access_context(['role' => 'student']);
assertSame('student', $studentContext['role'], 'student role should remain student');
assertSame('', $studentContext['college_name'], 'student college should default empty');

assertSame(true, can_manage_academic_content('super_admin'), 'super_admin should manage academic content');
assertSame(true, can_manage_academic_content('college_admin', 'الهندسة وعلوم الحاسب'), 'college_admin should manage within college scope');
assertSame(false, can_manage_academic_content('student'), 'student should not manage academic content');

echo "access context tests passed\n";
