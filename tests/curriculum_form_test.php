<?php
$root = dirname(__DIR__);
$adminTemplate = file_get_contents($root . '/admin/sections/academy_crud.php');
$publicTemplate = file_get_contents($root . '/templates/curriculum_view.php');

$hasAdminCollegeSelector = strpos($adminTemplate, 'id="curriculum-college-select"') !== false;
$hasAdminDepartmentSelector = strpos($adminTemplate, 'id="curriculum-department-select"') !== false;
$hasPublicAcademicPathField = strpos($publicTemplate, 'name="academic_path"') !== false;
$hasPublicCollegeField = strpos($publicTemplate, 'name="college"') !== false;
$hasPublicDepartmentField = strpos($publicTemplate, 'name="department"') !== false;
$curriculumController = file_get_contents($root . '/curriculum.php');
$requiresLogin = strpos($curriculumController, 'restrict_to_logged_in_users') !== false;

if (!$hasAdminCollegeSelector || !$hasAdminDepartmentSelector || !$hasPublicAcademicPathField || !$hasPublicCollegeField || !$hasPublicDepartmentField) {
    fwrite(STDERR, "Curriculum form is missing college/department/academic path controls.\n");
    exit(1);
}

if ($requiresLogin) {
    fwrite(STDERR, "Public curriculum page should not force login for viewing.\n");
    exit(1);
}

echo "Curriculum form supports college, department and academic path fields.\n";
echo "Curriculum page is publicly viewable without login.\n";
exit(0);
