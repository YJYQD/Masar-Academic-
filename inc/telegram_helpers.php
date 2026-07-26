<?php

if (!function_exists('extract_start_payload_from_text')) {
    function extract_start_payload_from_text(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\/start(?:\s+(.+))?$/u', $trimmed, $matches) !== 1) {
            return null;
        }

        $payload = trim((string) ($matches[1] ?? ''));
        if ($payload === '') {
            return null;
        }

        if (preg_match('/^(?:user|admin)[:\s]*\d+$/iu', $payload) === 1) {
            return $payload;
        }

        if (preg_match('/^\d+$/', $payload) === 1) {
            return $payload;
        }

        return null;
    }
}

if (!function_exists('normalize_start_payload_to_user_id')) {
    function normalize_start_payload_to_user_id(string $payload): int
    {
        $payload = trim($payload);
        if ($payload === '') {
            return 0;
        }

        if (preg_match('/^(?:user|admin)[:\s]*(\d+)$/iu', $payload, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\d+$/', $payload) === 1) {
            return (int) $payload;
        }

        if (preg_match('/^(?:user|admin)[:\s]+/iu', $payload) === 1) {
            return 0;
        }

        if (preg_match('/^[A-Za-z_\-]+$/u', $payload) === 1) {
            return 0;
        }

        return 0;
    }
}

if (!function_exists('generate_telegram_link_code')) {
    function generate_telegram_link_code(int $length = 6): string
    {
        $length = max(4, min(8, $length));
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('extract_telegram_link_code_from_payload')) {
    function extract_telegram_link_code_from_payload(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (preg_match('/(?:^|[\s:])([0-9]{4,8})$/u', $payload, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}

if (!function_exists('extract_telegram_link_code_from_text')) {
    function extract_telegram_link_code_from_text(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/(?:^|\s)(?:\/bind|bind)\s+([0-9]{4,8})$/iu', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^([0-9]{4,8})$/u', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}

if (!function_exists('normalize_college_label')) {
    function normalize_college_label(string $college): string
    {
        $normalized = trim($college);
        if ($normalized === '') {
            return '';
        }

        $map = [
            'كلية علوم الحاسب وتقنية المعلومات' => 'الهندسة وعلوم الحاسب',
            'علوم الحاسب وتقنية المعلومات' => 'الهندسة وعلوم الحاسب',
            'كلية الهندسة وعلوم الحاسب' => 'الهندسة وعلوم الحاسب',
            'الهندسة وعلوم الحاسب' => 'الهندسة وعلوم الحاسب',
            'كلية إدارة الأعمال' => 'إدارة الأعمال',
            'إدارة الأعمال' => 'إدارة الأعمال',
            'كلية العلوم' => 'العلوم',
            'العلوم' => 'العلوم',
            'كلية الطب' => 'الطب',
            'الطب' => 'الطب',
            'كلية طب الأسنان' => 'طب الأسنان',
            'طب الأسنان' => 'طب الأسنان',
            'كلية الصيدلة' => 'الصيدلة',
            'الصيدلة' => 'الصيدلة',
        ];

        return $map[$normalized] ?? $normalized;
    }
}

if (!function_exists('resolve_telegram_link_target')) {
    function resolve_telegram_link_target(int $userId, int $adminId = 0, bool $isAdmin = false): array
    {
        $lookupId = $isAdmin ? $adminId : $userId;
        $table = $isAdmin ? 'admins' : 'users';
        $resolvedUserId = $isAdmin ? $userId : $userId;

        if ($isAdmin && $adminId > 0) {
            $resolvedUserId = $userId;
        }

        return [
            'table' => $table,
            'lookup_id' => $lookupId,
            'user_id' => $resolvedUserId,
            'admin_id' => $adminId,
        ];
    }
}
