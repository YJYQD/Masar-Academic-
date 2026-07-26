<?php

if (!function_exists('normalize_anonymous_alias')) {
    function normalize_anonymous_alias(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $trimmed = preg_replace('/[^A-Za-z0-9._-]/u', '', $trimmed) ?? '';
        return mb_substr($trimmed, 0, 32, 'UTF-8');
    }
}

if (!function_exists('build_anonymous_profile_id')) {
    function build_anonymous_profile_id(int $userId): string
    {
        return 'anon_' . substr(hash('sha256', (string) $userId . '|' . uniqid('', true)), 0, 12);
    }
}

if (!function_exists('hash_for_storage')) {
    function hash_for_storage(string $value): string
    {
        return hash('sha256', trim($value) . '|' . (defined('APP_SECRET') ? APP_SECRET : 'doctor-rating'));
    }
}

if (!function_exists('redact_sensitive_text')) {
    function redact_sensitive_text(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return preg_replace('/\b([A-Za-z0-9._%+-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '[محرر]', $text) ?? $text;
    }
}

if (!function_exists('sanitize_for_storage')) {
    function sanitize_for_storage(string $value): string
    {
        $value = trim($value);
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}
