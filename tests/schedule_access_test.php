<?php
require_once __DIR__ . '/../inc/schedule_access.php';

$session = ['role' => 'student'];
$canManage = can_manage_schedule(6, $session);

if ($canManage !== true) {
    fwrite(STDERR, "Expected student user to be able to manage their own schedule\n");
    exit(1);
}

echo "schedule access test passed\n";
