<?php

/**
 * Determine whether the current authenticated user may manage timetable entries.
 *
 * The schedule module stores entries per user, so any authenticated user can
 * manage their own rows. Admin-like roles are also allowed, but the database
 * layer still enforces ownership for create/update/delete operations.
 */
function can_manage_schedule(int $userId, array $session = []): bool
{
    if ($userId <= 0) {
        return false;
    }

    $role = strtolower((string) ($session['role'] ?? $session['admin_role'] ?? 'student'));
    if ($role === '') {
        $role = 'student';
    }

    $adminLikeRoles = ['super', 'super_admin', 'root_admin', 'admin', 'college_admin', 'faculty_admin', 'manager', 'sub_admin'];

    return $userId > 0 && ($role === 'student' || in_array($role, $adminLikeRoles, true));
}
