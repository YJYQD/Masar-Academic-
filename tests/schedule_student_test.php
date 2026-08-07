<?php
session_id('schedtest');
session_start();
$_SESSION['user_id'] = 6;
$_SESSION['role'] = 'student';
$_SESSION['college_scope'] = '';
$_SESSION['is_admin'] = false;
$_SESSION['admin_id'] = 0;
$_SESSION['admin_role'] = 'student';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'add_to_schedule' => 1,
    'schedule_action' => 'create',
    'title' => 'اختبار جدول طالب',
    'course_code' => 'TST-1',
    'day_of_week' => 1,
    'start_time' => '08:00',
    'end_time' => '09:00',
    'location' => 'قاعة 1',
    'notes' => 'اختبار',
];
$_GET = [];
require __DIR__ . '/../schedule.php';
