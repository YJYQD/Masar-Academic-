<?php
require_once __DIR__ . '/session_secure.php';

if (!function_exists('clear_auth_session')) {
    function clear_auth_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        @session_unset();
        @session_destroy();
        @session_start();
    }
}

if (!function_exists('set_auth_flash')) {
    function set_auth_flash(string $message, string $type = 'error'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['flash'] = [
            'type' => $type,
            'text' => $message,
        ];
    }
}

if (!function_exists('normalize_session_role')) {
    function normalize_session_role($role): string
    {
        $normalized = trim(strtolower((string) ($role ?? '')));

        if ($normalized === '' || $normalized === 'student' || $normalized === 'user' || $normalized === 'member' || $normalized === 'learner') {
            return 'student';
        }

        if (in_array($normalized, ['super', 'super_admin', 'root_admin', 'root', 'admin', 'administrator', 'god'], true)) {
            return 'super';
        }

        if (in_array($normalized, ['college_admin', 'faculty_admin', 'faculty', 'manager', 'sub_admin', 'assistant_admin', 'assistant', 'moderator'], true)) {
            return 'college_admin';
        }

        return 'student';
    }
}

if (!function_exists('synchronize_session_identity')) {
    function synchronize_session_identity(?int $userId = null, ?string $role = null, ?string $collegeScope = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $resolvedUserId = $userId ?? (int) ($_SESSION['user_id'] ?? $_SESSION['anonymous_user_id'] ?? $_SESSION['admin_id'] ?? 0);
        $resolvedRole = normalize_session_role($role ?? ($_SESSION['role'] ?? ($_SESSION['admin_role'] ?? 'student')));
        $resolvedCollegeScope = trim((string) ($collegeScope ?? ($_SESSION['college_scope'] ?? $_SESSION['admin_college'] ?? $_SESSION['college_name'] ?? '')));

        $_SESSION['user_id'] = (int) $resolvedUserId;
        $_SESSION['role'] = $resolvedRole;
        $_SESSION['college_scope'] = $resolvedCollegeScope;

        if ($resolvedRole === 'student') {
            $_SESSION['is_admin'] = false;
            $_SESSION['admin_id'] = 0;
            $_SESSION['admin_role'] = 'student';
            $_SESSION['admin_college'] = '';
        } else {
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_id'] = (int) ($_SESSION['admin_id'] ?? $resolvedUserId);
            $_SESSION['admin_role'] = $resolvedRole;
            $_SESSION['admin_college'] = $resolvedCollegeScope;
        }

        return [
            'user_id' => (int) $_SESSION['user_id'],
            'role' => $_SESSION['role'],
            'college_scope' => $_SESSION['college_scope'],
            'is_admin' => !empty($_SESSION['is_admin']),
        ];
    }
}

if (!function_exists('current_auth_context')) {
    function current_auth_context(): array
    {
        return synchronize_session_identity();
    }
}

if (!function_exists('current_authenticated_user_id')) {
    function current_authenticated_user_id(): int
    {
        $context = current_auth_context();
        return (int) $context['user_id'];
    }
}

if (!function_exists('require_admin_access')) {
    function require_admin_access(string $redirectTo = 'login.php'): void
    {
        $context = current_auth_context();
        if ($context['role'] !== 'super' && $context['role'] !== 'college_admin') {
            set_auth_flash('لا توجد لديك صلاحية للوصول إلى لوحة الإدارة.', 'error');
            $target = $redirectTo === 'login.php' || $redirectTo === '../login.php' ? 'index.php?error=unauthorized' : $redirectTo;
            if (!headers_sent()) {
                header('Location: ' . $target);
            }
            exit();
        }
    }
}

if (!function_exists('restrict_to_logged_in_users')) {
    function restrict_to_logged_in_users(string $redirectTo = 'login.php'): void
    {
        $context = current_auth_context();
        if ($context['user_id'] <= 0) {
            clear_auth_session();
            set_auth_flash('يجب تسجيل الدخول للوصول إلى هذه الصفحة.', 'error');

            if (!headers_sent()) {
                header('Location: ' . $redirectTo);
            }
            exit();
        }
    }
}

if (!function_exists('restrict_to_admins')) {
    function restrict_to_admins(string $redirectTo = 'login.php'): void
    {
        $context = current_auth_context();
        if ($context['role'] !== 'super' && $context['role'] !== 'college_admin') {
            clear_auth_session();
            set_auth_flash('لا توجد لديك صلاحية للوصول إلى لوحة الإدارة.', 'error');
            $target = $redirectTo === 'login.php' || $redirectTo === '../login.php' ? 'index.php?error=unauthorized' : $redirectTo;
            if (!headers_sent()) {
                header('Location: ' . $target);
            }
            exit();
        }
    }
}
