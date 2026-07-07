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

    /** SLA window in days for a priority. */
    function becSlaDays(string $priority): float {
        return becSlaSeconds($priority) / 86400;
    }

    /** priority => days map (for threshold arrays). */
    function becSlaDaysMap(): array {
        $out = [];
        foreach (becSlaHours() as $p => $h) { $out[$p] = $h / 24; }
        return $out;
    }

    /** SQL CASE yielding the per-priority interval (Postgres), from this config. */
    function becSlaSqlCase(string $column = 'priority'): string {
        $h = becSlaHours();
        return "(CASE LOWER(COALESCE({$column},'medium'))"
            . " WHEN 'critical' THEN INTERVAL '{$h['critical']} hours'"
            . " WHEN 'urgent'   THEN INTERVAL '{$h['urgent']} hours'"
            . " WHEN 'high'     THEN INTERVAL '{$h['high']} hours'"
            . " WHEN 'medium'   THEN INTERVAL '{$h['medium']} hours'"
            . " ELSE INTERVAL '{$h['low']} hours' END)";
    }

    /** Human summary of the current SLA windows (for AI prompts / UI copy). */
    function becSlaSummaryText(): string {
        $h = becSlaHours();
        $d = fn($x) => rtrim(rtrim(number_format($x / 24, 1), '0'), '.');
        return 'critical/urgent ≈ ' . $d($h['critical']) . ' day(s), high ≈ ' . $d($h['high'])
            . ' day(s), medium ≈ ' . $d($h['medium']) . ' day(s), low ≈ ' . $d($h['low']) . ' day(s) from the report date';
    }
}
