<?php
require_once __DIR__ . '/../admin/includes/functions.php';

function assertSame($expected, $actual, $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$catalog = normalize_college_catalog([
    ['college_name' => 'العلوم', 'department_name' => 'الفيزياء'],
    ['college_name' => 'العلوم', 'department_name' => 'الرياضيات'],
    ['college_name' => 'الطب', 'department_name' => 'الطب'],
]);

assertSame([
    'العلوم' => ['الرياضيات', 'الفيزياء'],
    'الطب' => ['الطب'],
], $catalog, 'normalize_college_catalog should group departments by college');

echo "college catalog tests passed\n";
