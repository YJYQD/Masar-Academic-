<?php
require_once __DIR__ . '/../send_alerts_cron.php';

$now = new DateTimeImmutable('2026-07-11 09:50:00', new DateTimeZone('Asia/Riyadh'));
$schedule = [
    'id' => 1,
    'day_of_week' => 6,
    'start_time' => '10:00:00',
];

$nextOccurrence = build_next_occurrence_for_schedule($schedule, $now);
if (!$nextOccurrence instanceof DateTimeImmutable) {
    fwrite(STDERR, "Expected next occurrence to be computed\n");
    exit(1);
}

$expected = '2026-07-11 10:00:00';
if ($nextOccurrence->format('Y-m-d H:i:s') !== $expected) {
    fwrite(STDERR, "Expected {$expected} but got {$nextOccurrence->format('Y-m-d H:i:s')}\n");
    exit(1);
}

if (!should_send_reminder($now, $nextOccurrence)) {
    fwrite(STDERR, "Expected reminder to be due at 10 minutes\n");
    exit(1);
}

fwrite(STDOUT, "send alerts cron tests passed\n");
