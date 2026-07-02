<?php
/**
 * config/sla.php — repair SLA windows per priority.
 *
 * Edit the hour values below to change how long a task may stay open (from
 * assignment, falling back to the report date) before it counts as OVERDUE.
 * Used by the technician portal's "Due in / Overdue" chips and overdue counts.
 */
if (!function_exists('becSlaHours')) {
    function becSlaHours(): array {
        return [
            'critical' => 24,   // 1 day
            'urgent'   => 24,   // 1 day
            'high'     => 72,   // 3 days
            'medium'   => 168,  // 7 days
            'low'      => 336,  // 14 days
        ];
    }

    /** SLA window in seconds for a priority (defaults to medium). */
    function becSlaSeconds(string $priority): int {
        $h = becSlaHours();
        $key = strtolower(trim($priority));
        return (int) round((($h[$key] ?? $h['medium']) * 3600));
    }
}
