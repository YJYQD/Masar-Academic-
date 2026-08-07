<?php
require_once dirname(__DIR__) . '/admin/includes/functions.php';

if (!function_exists('can_manage_college_scope')) {
    fwrite(STDERR, "Missing permission helper for college scope.\n");
    exit(1);
}

if (!can_manage_college_scope('root_admin', '', 'كلية علوم الحاسب وتقنية المعلومات')) {
    fwrite(STDERR, "Root admin should access any college.\n");
    exit(1);
}

if (!can_manage_college_scope('faculty_admin', 'كلية علوم الحاسب وتقنية المعلومات', 'كلية علوم الحاسب وتقنية المعلومات')) {
    fwrite(STDERR, "Faculty admin should access their own college.\n");
    exit(1);
}

if (can_manage_college_scope('faculty_admin', 'كلية الطب', 'كلية علوم الحاسب وتقنية المعلومات')) {
    fwrite(STDERR, "Faculty admin should not access another college.\n");
    exit(1);
}

echo "College scope permissions are enforced correctly.\n";
exit(0);
