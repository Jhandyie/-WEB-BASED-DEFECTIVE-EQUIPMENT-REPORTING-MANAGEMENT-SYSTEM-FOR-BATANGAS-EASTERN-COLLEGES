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
 * How many reports a single sweep may escalate, and how often a sweep may run.
 *
 * Both caps exist because this runs during an admin page render. Escalating one
 * report costs ~320 ms (flag + a notification per admin + an activity row + an
 * email to the reporter), so an uncapped sweep is O(backlog) on the page load:
 * measured at 96 s for a 300-report backlog, and past a few thousand the page
 * exceeded the request timeout and never rendered at all. Capped, each load pays
 * a bounded cost and a large backlog simply drains over successive loads.
 */
const SLA_MAX_ESCALATIONS_PER_RUN = 10;
const SLA_MIN_SECONDS_BETWEEN_RUNS = 300;

/** Throttle: true (and stamps the clock) only when a sweep is due. */
function slaSweepDue(): bool {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $stamp = $dir . '/sla_last_sweep';
    $now = time();
    if (is_file($stamp)) {
        $last = (int) @file_get_contents($stamp);
        if ($last > 0 && ($now - $last) < SLA_MIN_SECONDS_BETWEEN_RUNS) {
            return false;
        }
    }
    @file_put_contents($stamp, (string) $now, LOCK_EX);
    return true;
}

/**
 * Escalate open, overdue, not-yet-escalated reports. Returns the number escalated.
 *
 * At most SLA_MAX_ESCALATIONS_PER_RUN per call, oldest first, and at most one
 * sweep every SLA_MIN_SECONDS_BETWEEN_RUNS. Still idempotent: each report is
 * escalated exactly once, because sla_escalated_at is stamped before notifying.
 */
function runSlaEscalationSweep(bool $force = false): int {
    if (!$force && !slaSweepDue()) { return 0; }

    try { $conn = getDBConnection(); } catch (\Throwable $e) { return 0; }

    // Oldest first, so the most overdue reports are always escalated first.
    $res = $conn->query("SELECT report_id, status, priority, report_date, equipment_name, reported_by, reporter_email
                         FROM defect_reports
                         WHERE status NOT IN ('completed','verified','closed','rejected')
                           AND sla_escalated_at IS NULL
                         ORDER BY report_date ASC");
    if (!$res) return 0;
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        if (!slaIsOverdue($r)) continue;
        $rows[] = $r;
        if (count($rows) >= SLA_MAX_ESCALATIONS_PER_RUN) break;
    }
    if (!$rows) return 0;

    // Flag the whole batch in one statement rather than one UPDATE per report.
    $ids = [];
    foreach ($rows as $r) { $ids[] = "'" . $conn->real_escape_string((string)$r['report_id']) . "'"; }
    $conn->query("UPDATE defect_reports SET sla_escalated_at = NOW()
                  WHERE report_id IN (" . implode(',', $ids) . ") AND sla_escalated_at IS NULL");

    // Recipients: active admins + deans.
    $admins = [];
    $ar = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','dean') AND status = 'active' AND user_id IS NOT NULL AND user_id <> ''");
    if ($ar) { while ($a = $ar->fetch_assoc()) { $admins[] = (string)$a['user_id']; } }

    $count = 0;
    foreach ($rows as $r) {
        $rid = (string)$r['report_id'];
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
