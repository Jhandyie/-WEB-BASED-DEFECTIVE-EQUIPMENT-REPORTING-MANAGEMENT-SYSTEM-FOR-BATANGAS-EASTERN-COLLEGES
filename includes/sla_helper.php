<?php
/**
 * sla_helper.php — Service-Level-Agreement (SLA) thresholds + auto-escalation.
 *
 * Each report has a resolution target based on its priority. When an OPEN report
 * passes that target, it is auto-escalated: flagged (so it isn't re-alerted) and
 * the PMO/Dean are notified in-app, with the reporter informed it was escalated.
 *
 * There is no cron in this environment, so runSlaEscalationSweep() is called on
 * admin page loads. It is idempotent (escalates each report at most once).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sla.php';

/** Resolution target in days, by priority — single source: config/sla.php. */
function slaThresholdDays(string $priority): float {
    return becSlaDays($priority);
}

/** Days a report has been open since it was filed (or null if unknown). */
function slaAgeDays(array $report): ?float {
    $d = strtotime((string)($report['report_date'] ?? ''));
    return $d ? (time() - $d) / 86400 : null;
}

function slaIsOpenStatus(string $status): bool {
    return !in_array(strtolower(trim($status)), ['completed', 'verified', 'closed', 'rejected'], true);
}

/** True when an open report has passed its priority-based SLA target. */
function slaIsOverdue(array $report): bool {
    if (!slaIsOpenStatus((string)($report['status'] ?? ''))) return false;
    $age = slaAgeDays($report);
    return $age !== null && $age > slaThresholdDays((string)($report['priority'] ?? 'medium'));
}

/**
 * Escalate any open, overdue, not-yet-escalated reports. Returns the number escalated.
 */
function runSlaEscalationSweep(): int {
    try { $conn = getDBConnection(); } catch (\Throwable $e) { return 0; }

    $res = $conn->query("SELECT report_id, status, priority, report_date, equipment_name, reported_by, reporter_email
                         FROM defect_reports
                         WHERE status NOT IN ('completed','verified','closed','rejected')
                           AND sla_escalated_at IS NULL");
    if (!$res) return 0;
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    if (!$rows) return 0;

    // Recipients: active admins + deans.
    $admins = [];
    $ar = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','dean') AND status = 'active' AND user_id IS NOT NULL AND user_id <> ''");
    if ($ar) { while ($a = $ar->fetch_assoc()) { $admins[] = (string)$a['user_id']; } }

    $count = 0;
    foreach ($rows as $r) {
        if (!slaIsOverdue($r)) continue;
        $rid = (string)$r['report_id'];
        $rid_e = $conn->real_escape_string($rid);
        // Flag first so concurrent loads don't double-notify.
        $conn->query("UPDATE defect_reports SET sla_escalated_at = NOW() WHERE report_id = '{$rid_e}' AND sla_escalated_at IS NULL");

        $msg = 'SLA breach: Ticket ' . $rid . ' (' . ucfirst((string)$r['priority']) . ' priority) is overdue and needs attention.';
        foreach ($admins as $aid) {
            if (function_exists('addNotification')) { try { addNotification($aid, $msg, 'sla_escalation', $rid); } catch (\Throwable $e) {} }
        }
        if (function_exists('logActivity')) { try { logActivity('system', 'system', 'report.sla_escalated', 'Auto-escalated overdue ticket ' . $rid); } catch (\Throwable $e) {} }
        if (function_exists('notifyReporter')) {
            try {
                notifyReporter(
                    $r,
                    'Your report ' . $rid . ' is taking longer than expected and has been escalated to the PMO for priority handling.',
                    'Report Escalated for Priority Handling',
                    "We noticed your report has not yet been resolved within the expected time, so it has been escalated to the Property Management Office for priority attention.\n\nThank you for your patience — we are on it."
                );
            } catch (\Throwable $e) {}
        }
        $count++;
    }
    return $count;
}
