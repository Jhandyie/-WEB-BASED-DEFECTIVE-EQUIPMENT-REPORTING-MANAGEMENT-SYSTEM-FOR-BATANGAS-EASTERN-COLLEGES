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
 * Generate reports for any active, due schedules. Returns the number created.
 */
function runPreventiveMaintenanceSweep(): int {
    try { $conn = getDBConnection(); } catch (\Throwable $e) { return 0; }

    $res = $conn->query("SELECT * FROM preventive_schedules WHERE status = 'active' AND next_due <= CURRENT_DATE");
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
