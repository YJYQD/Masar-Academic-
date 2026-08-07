<?php
require_once __DIR__ . '/../inc/telegram_helpers.php';

$cases = [
    ['/start 123', '123'],
    ['/start', null],
    ['/start abc', null],
    [' /start 45 ', '45'],
];

foreach ($cases as [$input, $expected]) {
    $actual = extract_start_payload_from_text((string) $input);
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$expected} but got {$actual} for {$input}\n");
        exit(1);
    }
}

$normalized = normalize_college_label('كلية علوم الحاسب وتقنية المعلومات');
if ($normalized !== 'الهندسة وعلوم الحاسب') {
    fwrite(STDERR, "Expected normalized college to be 'الهندسة وعلوم الحاسب' but got {$normalized}\n");
    exit(1);
}

$adminResolution = resolve_telegram_link_target(42, 7, true);
if ($adminResolution['table'] !== 'admins' || $adminResolution['lookup_id'] !== 7 || $adminResolution['user_id'] !== 42) {
    fwrite(STDERR, "Expected admin resolution to prefer the admin row and preserve the user id\n");
    exit(1);
}

$userResolution = resolve_telegram_link_target(42, 0, false);
if ($userResolution['table'] !== 'users' || $userResolution['lookup_id'] !== 42) {
    fwrite(STDERR, "Expected user resolution to target the users table\n");
    exit(1);
}

fwrite(STDOUT, "telegram helper tests passed\n");
