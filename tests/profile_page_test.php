<?php
$profilePath = __DIR__ . '/../profile.php';
if (!is_file($profilePath)) {
    fwrite(STDERR, "profile.php not found\n");
    exit(1);
}

$contents = file_get_contents($profilePath);
if ($contents === false) {
    fwrite(STDERR, "Unable to read profile.php\n");
    exit(1);
}

$disallowedFields = ['name="college_scope"', 'name="department_scope"', 'name="specialty"'];
foreach ($disallowedFields as $field) {
    if (str_contains($contents, $field)) {
        fwrite(STDERR, "Found disallowed field: {$field}\n");
        exit(1);
    }
}

$disallowedLabels = ['<span>الكلية</span>', '<span>القسم / التخصص</span>', '<span>التخصص</span>'];
foreach ($disallowedLabels as $label) {
    if (str_contains($contents, $label)) {
        fwrite(STDERR, "Found disallowed label: {$label}\n");
        exit(1);
    }
}

echo "profile page test passed\n";
