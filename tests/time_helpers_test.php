<?php
require_once __DIR__ . '/../inc/time_helpers.php';

function assertSame($expected, $actual, $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSame('09:00 ص', format_ar_time('09:00:00'), 'format_ar_time should render morning time in Arabic');
assertSame('01:00 م', format_ar_time('13:00:00'), 'format_ar_time should render evening time in Arabic');
assertSame('09:00', format_24h_time('09:00:00'), 'format_24h_time should preserve 24h output');

echo "time helper tests passed\n";
