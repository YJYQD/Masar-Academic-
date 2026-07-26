<?php
function format_ar_time(string $timeValue): string {
    if ($timeValue === '') {
        return '';
    }

    $timestamp = strtotime($timeValue);
    if ($timestamp === false) {
        return $timeValue;
    }

    $hour = (int) date('H', $timestamp);
    $minute = (int) date('i', $timestamp);
    $suffix = $hour < 12 ? 'ص' : 'م';
    $displayHour = $hour % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return sprintf('%02d:%02d %s', $displayHour, $minute, $suffix);
}

function format_24h_time(string $timeValue): string {
    if ($timeValue === '') {
        return '';
    }

    $timestamp = strtotime($timeValue);
    if ($timestamp === false) {
        return $timeValue;
    }

    return date('H:i', $timestamp);
}

function normalize_timezone(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'Asia/Riyadh';
    }

    $allowedTimezones = [
        'Asia/Riyadh',
        'Asia/Dubai',
        'Europe/London',
        'UTC',
    ];

    return in_array($value, $allowedTimezones, true) ? $value : 'Asia/Riyadh';
}
