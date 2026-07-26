<?php

if (!function_exists('admin_handle_fatal_error')) {
    function admin_handle_fatal_error(): void
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'text' => 'تعذر تحميل لوحة الإدارة حالياً.',
            ];
        }

        $message = 'Admin fatal error: ' . ($error['message'] ?? 'unknown error') . ' in ' . ($error['file'] ?? 'unknown file') . ' on line ' . ($error['line'] ?? 0);
        if (function_exists('log_error')) {
            log_error($message);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Location: index.php?error=internal');
        }
    }
}

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('validate_csrf')) {
    function validate_csrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';

        if (!hash_equals(csrf_token(), $token)) {
            die('CSRF ERROR');
        }
    }
}

if (!function_exists('flash_success')) {
    function flash_success(string $message): void
    {
        $_SESSION['flash'] = [
            'type' => 'success',
            'text' => $message
        ];
    }
}

if (!function_exists('flash_error')) {
    function flash_error(string $message): void
    {
        $_SESSION['flash'] = [
            'type' => 'error',
            'text' => $message
        ];
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('bind_stmt_params')) {
    function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): void
    {
        if (!$params) {
            return;
        }

        $bind_names[] = $types;

        foreach ($params as $key => $value) {
            $bind_names[] = &$params[$key];
        }

        mysqli_stmt_bind_param($stmt, ...$bind_names);
    }
}

if (!function_exists('sentiment_label_ar')) {
    function sentiment_label_ar(string $sentiment): string
    {
        return match ($sentiment) {
            'positive' => 'إيجابي',
            'negative' => 'سلبي',
            default => 'محايد',
        };
    }
}

if (!function_exists('get_default_colleges_map')) {
    function get_default_colleges_map(): array
    {
        return [
            'الهندسة وعلوم الحاسب' => [
                'علوم الحاسب', 'نظم المعلومات', 'هندسة الحاسب والشبكات', 'الهندسة الميكانيكية'
            ],
            'الطب' => ['الطب', 'الطب والجراحة العامة'],
            'طب الأسنان' => ['طب الأسنان'],
            'الصيدلة' => ['الصيدلة'],
            'العلوم الطبية التطبيقية' => ['التغذية', 'العلاج الطبيعي'],
            'التمريض' => ['التمريض'],
            'الصحة العامة' => ['الصحة العامة'],
            'العلوم' => ['الفيزياء', 'الكيمياء', 'الرياضيات', 'الأحياء'],
            'إدارة الأعمال' => ['المحاسبة', 'إدارة الأعمال', 'المالية'],
            'الشريعة والقانون' => ['الشريعة', 'القانون'],
            'الآداب والعلوم الإنسانية' => ['الآداب', 'اللغة الإنجليزية'],
            'التربية' => ['التربية'],
            'التصميم والعمارة' => ['التصميم', 'العمارة'],
            'الكلية التطبيقية' => ['التطبيقية']
        ];
    }
}

if (!function_exists('normalize_college_catalog')) {
    function normalize_college_catalog(array $rows): array
    {
        $catalog = [];
        foreach ($rows as $row) {
            $college = trim((string) ($row['college_name'] ?? $row['college'] ?? $row['name'] ?? ''));
            $department = trim((string) ($row['department_name'] ?? $row['department'] ?? $row['name'] ?? ''));
            if ($college === '' || $department === '') {
                continue;
            }
            $catalog[$college][$department] = true;
        }

        foreach ($catalog as $college => $departments) {
            $catalog[$college] = array_keys($departments);
            sort($catalog[$college], SORT_STRING);
        }

        ksort($catalog, SORT_STRING);
        return $catalog;
    }
}

if (!function_exists('load_college_catalog_from_db')) {
    function load_college_catalog_from_db($databaseConnection = null): array
    {
        $databaseConnection = $databaseConnection ?: ($GLOBALS['conn'] ?? null);
        if (!$databaseConnection instanceof mysqli) {
            return get_default_colleges_map();
        }

        $tableCheck = $databaseConnection->query("SHOW TABLES LIKE 'academic_colleges'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return get_default_colleges_map();
        }

        $result = $databaseConnection->query(
            "SELECT ac.college_name, ad.department_name FROM academic_departments ad INNER JOIN academic_colleges ac ON ac.id = ad.college_id WHERE ac.is_active = 1 AND ad.is_active = 1 ORDER BY ac.college_name ASC, ad.department_name ASC"
        );

        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        $catalog = normalize_college_catalog($rows);
        return $catalog ?: get_default_colleges_map();
    }
}

if (!function_exists('get_colleges_map')) {
    function get_colleges_map(): array
    {
        return load_college_catalog_from_db();
    }
}

if (!function_exists('admin_redirect')) {
    function admin_redirect(string $section): void
    {
        $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : 'index.php';
        header('Location: ' . $base . '?section=' . urlencode($section));
        exit();
    }
}

if (!function_exists('normalize_admin_role')) {
    function normalize_admin_role(?string $role): string
    {
        $role = trim(strtolower((string) ($role ?? '')));

        return match ($role) {
            'super', 'super_admin', 'root_admin', 'root', 'admin' => 'root_admin',
            'college_admin', 'faculty_admin', 'faculty', 'manager' => 'faculty_admin',
            'sub_admin', 'assistant_admin', 'assistant', 'moderator' => 'assistant_admin',
            'student', 'user', 'member', 'learner' => 'student',
            default => 'student',
        };
    }
}

if (!function_exists('resolve_access_context')) {
    function resolve_access_context(?array $sessionData = null): array
    {
        $sessionData = is_array($sessionData) ? $sessionData : [];
        $sessionRole = strtolower((string) ($sessionData['role'] ?? $_SESSION['role'] ?? ''));
        if ($sessionRole === '') {
            $sessionRole = 'student';
        }

        if (in_array($sessionRole, ['super', 'super_admin', 'root_admin', 'admin'], true)) {
            $role = 'super_admin';
        } elseif (in_array($sessionRole, ['college_admin', 'faculty_admin', 'manager'], true)) {
            $role = 'college_admin';
        } else {
            $role = 'student';
        }

        $collegeName = trim((string) ($sessionData['college_scope'] ?? $sessionData['admin_college'] ?? $sessionData['college_name'] ?? $sessionData['college'] ?? $_SESSION['college_scope'] ?? $_SESSION['admin_college'] ?? $_SESSION['college_name'] ?? $_SESSION['college'] ?? ''));

        return [
            'role' => $role,
            'college_name' => $collegeName,
        ];
    }
}

if (!function_exists('can_manage_academic_content')) {
    function can_manage_academic_content(?string $role, ?string $collegeName = ''): bool
    {
        $context = resolve_access_context([
            'role' => $role,
            'college_scope' => $collegeName,
        ]);

        if ($context['role'] === 'super_admin') {
            return true;
        }

        return $context['role'] === 'college_admin' && trim((string) $collegeName) !== '';
    }
}

if (!function_exists('build_access_scope_filters')) {
    function build_access_scope_filters(string $effectiveRole, string $accessCollegeName, int $userId): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        if ($effectiveRole === 'college_admin' && $accessCollegeName !== '') {
            $conditions[] = 'college = ?';
            $params[] = $accessCollegeName;
            $types = 's';
        } elseif ($effectiveRole === 'student') {
            $conditions[] = 'user_id = ?';
            $params[] = $userId;
            $types = 'i';
        }

        return [
            'conditions' => $conditions,
            'params' => $params,
            'types' => $types,
        ];
    }
}

if (!function_exists('db_admin_role')) {
    function db_admin_role(?string $role): string
    {
        return match (normalize_admin_role($role)) {
            'root_admin' => 'super',
            'faculty_admin' => 'college_admin',
            'assistant_admin' => 'sub_admin',
            default => 'sub_admin',
        };
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(?string $role): bool
    {
        return normalize_admin_role($role) === 'root_admin';
    }
}

if (!function_exists('can_manage_section')) {
    function can_manage_section(?string $role, string $section = 'dashboard'): bool
    {
        $role = normalize_admin_role($role);

        if ($role === 'root_admin') {
            return true;
        }

        if ($role === 'faculty_admin') {
            return in_array($section, ['dashboard', 'reviews', 'doctors', 'subjects', 'supervision', 'ai_report'], true);
        }

        if ($role === 'assistant_admin') {
            return in_array($section, ['dashboard', 'reviews', 'doctors', 'subjects', 'supervision'], true);
        }

        return false;
    }
}

if (!function_exists('can_manage_admins')) {
    function can_manage_admins(?string $role): bool
    {
        $normalizedRole = normalize_admin_role($role);
        return $normalizedRole === 'root_admin' || $normalizedRole === 'faculty_admin';
    }
}

if (!function_exists('can_manage_college_scope')) {
    function can_manage_college_scope(?string $role, ?string $adminCollege, ?string $targetCollege): bool
    {
        $role = normalize_admin_role($role);
        if ($role === 'root_admin') {
            return true;
        }

        if ($role !== 'faculty_admin') {
            return false;
        }

        $adminCollege = trim((string) $adminCollege);
        $targetCollege = trim((string) $targetCollege);
        if ($adminCollege === '' || $targetCollege === '') {
            return false;
        }

        return $adminCollege === $targetCollege;
    }
}

if (!function_exists('admin_role_label_ar')) {
    function admin_role_label_ar(?string $role): string
    {
        return match (normalize_admin_role($role)) {
            'root_admin' => 'مدير عام',
            'faculty_admin' => 'مشرف كلية',
            'assistant_admin' => 'مشرف مساعد',
            default => 'طالب',
        };
    }
}