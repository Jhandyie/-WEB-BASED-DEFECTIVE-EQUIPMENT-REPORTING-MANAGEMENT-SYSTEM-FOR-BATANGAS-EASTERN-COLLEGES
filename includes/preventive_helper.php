<?php
/**
 * preventive_helper.php — Preventive Maintenance scheduling.
 *
 * Admins define recurring schedules (e.g. "Aircon cleaning every 60 days").
 * When a schedule is due, the sweep auto-generates a defect report (flagged as
 * preventive), advances the next due date, and notifies admins. No cron needed —
 * the sweep runs on admin page loads and is idempotent (advancing next_due
 * prevents same-day regeneration).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ticket.php';

/** Common frequency presets (days) for the UI. */
function pmFrequencyPresets(): array {
    return [7 => 'Weekly', 14 => 'Every 2 weeks', 30 => 'Monthly', 60 => 'Every 2 months', 90 => 'Quarterly', 180 => 'Every 6 months', 365 => 'Yearly'];
}

/**
 * How often the sweep may run, and how many schedules one run may generate.
 *
 * Same reasoning as the SLA sweep (see includes/sla_helper.php): this fires on
 * admin page renders, so it must cost a bounded amount and must not cost
 * anything at all on most loads. Unthrottled it spent two Supabase round trips
 * (~150 ms) on every single admin page view just to learn there was nothing to
 * do — and on the day a batch of schedules came due, it generated all of them
 * inline, one report + one notification per admin apiece.
 *
 * Schedules are day-granular, so a 5-minute throttle never delays one visibly.
 */
const PM_MIN_SECONDS_BETWEEN_RUNS = 300;
const PM_MAX_GENERATED_PER_RUN = 10;

/** Throttle: true (and stamps the clock) only when a sweep is due. */
function pmSweepDue(): bool {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $stamp = $dir . '/pm_last_sweep';
    $now = time();
    if (is_file($stamp)) {
        $last = (int) @file_get_contents($stamp);
        if ($last > 0 && ($now - $last) < PM_MIN_SECONDS_BETWEEN_RUNS) {
            return false;
        }
    }
    @file_put_contents($stamp, (string) $now, LOCK_EX);
    return true;
}

/**
 * Generate reports for any active, due schedules. Returns the number created.
 *
 * $force bypasses the throttle — for the "Run now" button on admin_preventive.php,
 * where the admin is explicitly asking and expects to wait.
 */
function runPreventiveMaintenanceSweep(bool $force = false): int {
    if (!$force && !pmSweepDue()) { return 0; }

    try { $conn = getDBConnection(); } catch (\Throwable $e) { return 0; }

    $res = $conn->query("SELECT * FROM preventive_schedules WHERE status = 'active' AND next_due <= CURRENT_DATE LIMIT " . PM_MAX_GENERATED_PER_RUN);
    if (!$res) return 0;
    $due = [];
    while ($r = $res->fetch_assoc()) { $due[] = $r; }
    if (!$due) return 0;

    $admins = [];
    $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' AND user_id IS NOT NULL AND user_id <> ''");
    if ($ar) { while ($a = $ar->fetch_assoc()) { $admins[] = (string)$a['user_id']; } }

    $made = 0;
    foreach ($due as $s) {
        $eqId = trim((string)($s['equipment_id'] ?? ''));
        if ($eqId === '') { continue; } // a report requires a target equipment

        $ticket   = generateTicketNumber();
        $assigned = trim((string)($s['assigned_to'] ?? ''));
        $instr    = trim((string)($s['instructions'] ?? ''));
        $payload = [
            'report_id'         => $ticket,
            'equipment_id'      => $eqId,
            'equipment_name'    => (string)($s['equipment_name'] ?? ''),
            'location'          => (string)($s['location'] ?? ''),
            'issue_description' => '[Preventive Maintenance] ' . (string)$s['title'] . ($instr !== '' ? "\n\n" . $instr : ''),
            'priority'          => (string)($s['priority'] ?? 'medium') ?: 'medium',
            'status'            => $assigned !== '' ? 'assigned' : 'reported',
            'is_preventive'     => true,
            'pm_schedule_id'    => (int)$s['id'],
            'reported_by'       => 'SYSTEM-PM',
        ];
        if ($assigned !== '') {
            $payload['assigned_to']   = $assigned;
            $payload['assigned_date'] = date('Y-m-d H:i:s');
        }
        if (!addDefectReport($payload)) { continue; }

        $sid  = (int)$s['id'];
        $freq = max(1, (int)$s['frequency_days']);
        // Advance to the next cycle from today (skips missed cycles, avoids backlog spam).
        $conn->query("UPDATE preventive_schedules SET last_generated = CURRENT_DATE, next_due = CURRENT_DATE + {$freq} WHERE id = {$sid}");

        $msg = 'Preventive maintenance task created: ' . (string)$s['title'] . ' (' . $ticket . ').';
        foreach ($admins as $aid) {
            if (function_exists('addNotification')) { try { addNotification($aid, $msg, 'preventive', $ticket); } catch (\Throwable $e) {} }
        }
        if (function_exists('logActivity')) { try { logActivity('system', 'system', 'pm.generated', 'Generated PM task ' . $ticket . ' from schedule #' . $sid); } catch (\Throwable $e) {} }
        $made++;
    }
    return $made;
}
