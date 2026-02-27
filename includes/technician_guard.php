<?php

if (!function_exists('technicianNormalizeIdentity')) {
    function technicianNormalizeIdentity($value): string {
        return strtolower(trim((string)$value));
    }
}

if (!function_exists('technicianIdentityKeysFromSession')) {
    function technicianIdentityKeysFromSession(array $session): array {
        $keys = array_values(array_filter(array_unique([
            trim((string)($session['user_id'] ?? '')),
            trim((string)($session['user_email'] ?? '')),
            trim((string)($session['fullname'] ?? '')),
        ]), fn($v) => $v !== ''));

        if (empty($keys)) {
            return [''];
        }
        return $keys;
    }
}

if (!function_exists('technicianOwnsAssigneeValue')) {
    function technicianOwnsAssigneeValue($assigneeValue, array $technicianKeys): bool {
        $assigneeNorm = technicianNormalizeIdentity($assigneeValue);
        if ($assigneeNorm === '') {
            return false;
        }

        foreach ($technicianKeys as $key) {
            if ($assigneeNorm === technicianNormalizeIdentity($key)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('technicianResolveAssigneeColumn')) {
    function technicianResolveAssigneeColumn(array $drCols): string {
        if (isset($drCols['assigned_to'])) {
            return 'assigned_to';
        }
        if (isset($drCols['assigned_technician'])) {
            return 'assigned_technician';
        }
        return 'assigned_to';
    }
}

if (!function_exists('technicianBuildAssigneeMatchClause')) {
    function technicianBuildAssigneeMatchClause(string $columnExpr, array $technicianKeys): array {
        $keys = array_values(array_filter($technicianKeys, fn($v) => trim((string)$v) !== ''));
        if (empty($keys)) {
            return ['1=0', '', []];
        }

        $parts = array_fill(0, count($keys), $columnExpr . ' = ?');
        $clause = '(' . implode(' OR ', $parts) . ')';
        $types = str_repeat('s', count($keys));
        return [$clause, $types, $keys];
    }
}
