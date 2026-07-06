<?php
/**
 * includes/workflow_approvals.php — Dean & Finance email approvals for defect reports.
 *
 * Lets the Dean and the Finance office record THEIR OWN decisions through secure
 * tokenized email links (approval_ack.php) instead of the admin clicking on their
 * behalf — same trusted pattern as the budget-request acknowledgment.
 */
require_once __DIR__ . '/../config/dean.php';
require_once __DIR__ . '/../config/finance.php';
require_once __DIR__ . '/mail_helper.php';

/** Absolute base URL of the app root (works locally and hosted). */
function wfaBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // strip a trailing subfolder if the caller lives one level down (none of ours do today)
    return $scheme . '://' . $host . $dir;
}

/** Create/refresh the approval token for a stage ('dean' | 'finance'). */
function wfaIssueToken($conn, string $reportId, string $stage): string {
    $token = bin2hex(random_bytes(20));
    $st = $conn->prepare("UPDATE defect_reports SET approval_token = ?, approval_stage = ?, approval_notified_at = NOW() WHERE report_id = ?");
    if ($st) { $st->bind_param('sss', $token, $stage, $reportId); $st->execute(); $st->close(); }
    return $token;
}

/** In-app notification to all active users of a role (mirrors adminWorkflowNotifyRole). */
function wfaNotifyRole($conn, string $role, string $message, string $reportId): void {
    try {
        $res = $conn->query("SELECT user_id FROM users WHERE role = '" . $conn->real_escape_string($role) . "' AND status = 'active'");
        if (!$res) return;
        $ins = $conn->prepare("INSERT INTO notifications (notification_id, user_id, message, type, related_id, created_date) VALUES (?, ?, ?, 'workflow', ?, NOW())");
        if (!$ins) return;
        while ($row = $res->fetch_assoc()) {
            $uid = trim((string)($row['user_id'] ?? ''));
            if ($uid === '') continue;
            $nid = 'NTF-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            $ins->bind_param('ssss', $nid, $uid, $message, $reportId);
            $ins->execute();
        }
        $ins->close();
    } catch (\Throwable $e) { /* notifications best-effort */ }
}

/** Branded decision-request email with the action link. */
function wfaSendDecisionEmail(array $report, string $stage): bool {
    $isDean = $stage === 'dean';
    $to     = $isDean ? BEC_DEAN_EMAIL : BEC_FINANCE_EMAIL;
    $label  = $isDean ? BEC_DEAN_LABEL : BEC_FINANCE_LABEL;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $rid  = htmlspecialchars((string)($report['report_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $eq   = htmlspecialchars((string)($report['equipment_name'] ?? 'Equipment'), ENT_QUOTES, 'UTF-8');
    $loc  = htmlspecialchars((string)($report['location'] ?? 'Unspecified'), ENT_QUOTES, 'UTF-8');
    $pri  = htmlspecialchars(ucfirst((string)($report['priority'] ?? 'medium')), ENT_QUOTES, 'UTF-8');
    $iss  = htmlspecialchars(mb_strimwidth((string)($report['issue_description'] ?? ''), 0, 260, '…'), ENT_QUOTES, 'UTF-8');
    $link = wfaBaseUrl() . '/approval_ack.php?token=' . urlencode((string)$report['approval_token']);

    $what = $isDean
        ? 'This equipment defect report has completed PMO review and requires the <strong>Dean\'s approval</strong> to proceed.'
        : 'This report has been approved by the Dean and now requires the <strong>Finance office\'s budget clearance</strong>.';
    $btn  = $isDean ? 'Review & Decide (Approve / Reject)' : 'Review & Decide (Approve / Hold)';
    $subject = ($isDean ? 'Dean Approval Required — ' : 'Finance Clearance Required — ') . 'Report ' . ($report['report_id'] ?? '') . ' · BEC PMO';

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#F4F1EC;padding:24px;">'
      . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:14px;overflow:hidden;">'
        . '<div style="background:linear-gradient(135deg,#2D0505,#7B1D1D);color:#fff;padding:20px 24px;">'
          . '<div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#F0C040;">Batangas Eastern Colleges · Property Management Office</div>'
          . '<div style="font-size:19px;font-weight:700;margin-top:4px;">' . ($isDean ? 'Dean Approval Requested' : 'Finance Clearance Requested') . '</div>'
        . '</div>'
        . '<div style="padding:22px 24px;color:#1C1008;">'
          . '<p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Good day, ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ',</p>'
          . '<p style="font-size:14px;line-height:1.6;margin:0 0 14px;color:#5C3838;">' . $what . '</p>'
          . '<table style="width:100%;font-size:13px;color:#5C3838;">'
            . '<tr><td style="padding:3px 0;width:110px;color:#9E8070;">Report</td><td style="font-weight:700;color:#7B1D1D;">' . $rid . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Equipment</td><td>' . $eq . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Location</td><td>' . $loc . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Priority</td><td>' . $pri . '</td></tr>'
            . ($iss !== '' ? '<tr><td style="padding:3px 0;color:#9E8070;vertical-align:top;">Issue</td><td>' . $iss . '</td></tr>' : '')
          . '</table>'
          . '<div style="text-align:center;margin:22px 0 6px;">'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">' . $btn . '</a>'
          . '</div>'
          . '<p style="font-size:12px;color:#9E8070;text-align:center;margin:6px 0 0;">The link opens a secure decision page — no account or login is required. Your decision is recorded with your office\'s name and timestamp.</p>'
        . '</div>'
        . '<div style="background:#F8F3EA;padding:14px 24px;font-size:11px;color:#9E8070;text-align:center;border-top:1px solid #E8DDD0;">Batangas Eastern Colleges · Property Management Office · Automated message</div>'
      . '</div></div>';

    try { return (bool) sendEmail($to, $subject, $html, null, $isDean ? 'dean' : 'finance'); }
    catch (\Throwable $e) { error_log('wfa decision email failed: ' . $e->getMessage()); return false; }
}

/** Issue a token for the stage and email the decision request. */
function wfaRequestDecision($conn, string $reportId, string $stage): bool {
    $token = wfaIssueToken($conn, $reportId, $stage);
    $report = getDefectReportById($reportId);
    if (!$report) return false;
    $report['approval_token'] = $token;
    return wfaSendDecisionEmail($report, $stage);
}

/**
 * Apply a Dean decision (mirrors the admin-side handler exactly).
 * @return array [ok(bool), message(string)]
 */
function wfaDeanDecide($conn, array $report, bool $approve, string $notes): array {
    $reportId = (string)$report['report_id'];
    $actor = 'DEAN-EMAIL';
    $ok = updateDefectReport($reportId, [
        'status'               => $approve ? 'finance_review' : 'rejected',
        'dean_approval_status' => $approve ? 'approved' : 'rejected',
        'dean_approved_by'     => $actor,
        'dean_approved_at'     => date('Y-m-d H:i:s'),
        'dean_notes'           => $notes,
        'approval_token'       => null,
        'approval_stage'       => null,
    ]);
    if (!$ok) return [false, 'Unable to record the decision. Please contact the PMO.'];

    if (function_exists('logActivity')) { try { logActivity('dean', 'report.dean_' . ($approve ? 'approve' : 'reject'), ($approve ? 'Approved' : 'Rejected') . ' via email link — ' . $reportId); } catch (\Throwable $e) {} }
    wfaNotifyRole($conn, 'pmo',   'Dean ' . ($approve ? 'approved' : 'rejected') . ' report ' . $reportId . ' (via email decision).', $reportId);
    wfaNotifyRole($conn, 'admin', 'Dean ' . ($approve ? 'approved' : 'rejected') . ' report ' . $reportId . ' (via email decision).', $reportId);

    if ($approve) {
        // Chain: dean approval routes the report onward to Finance clearance.
        try { wfaRequestDecision($conn, $reportId, 'finance'); } catch (\Throwable $e) { error_log('wfa finance chain failed: ' . $e->getMessage()); }
        return [true, 'Approval recorded. The report has been routed to the Finance office for budget clearance.'];
    }
    return [true, 'Rejection recorded. The PMO has been notified.'];
}

/** Apply a Finance decision (approve → ready for assignment; hold → budget hold). */
function wfaFinanceDecide($conn, array $report, bool $approve, string $notes): array {
    $reportId = (string)$report['report_id'];
    $actor = 'FINANCE-EMAIL';
    $ok = updateDefectReport($reportId, [
        'status'                  => $approve ? 'ready_for_assignment' : 'on_hold_budget',
        'finance_approval_status' => $approve ? 'approved' : 'on_hold',
        'finance_approved_by'     => $actor,
        'finance_approved_at'     => date('Y-m-d H:i:s'),
        'finance_notes'           => $notes,
        'budget_status'           => $approve ? 'approved' : 'on_hold',
        'approval_token'          => null,
        'approval_stage'          => null,
    ]);
    if (!$ok) return [false, 'Unable to record the decision. Please contact the PMO.'];

    if (function_exists('logActivity')) { try { logActivity('finance', 'report.finance_' . ($approve ? 'approve' : 'hold'), ($approve ? 'Cleared' : 'Held') . ' via email link — ' . $reportId); } catch (\Throwable $e) {} }
    wfaNotifyRole($conn, 'pmo',   'Finance ' . ($approve ? 'cleared report ' . $reportId . ' — ready for technician assignment.' : 'placed report ' . $reportId . ' on budget hold.'), $reportId);
    wfaNotifyRole($conn, 'admin', 'Finance ' . ($approve ? 'cleared report ' . $reportId . ' — ready for technician assignment.' : 'placed report ' . $reportId . ' on budget hold.'), $reportId);

    return [true, $approve
        ? 'Budget clearance recorded. The PMO can now assign a technician.'
        : 'Budget hold recorded. The PMO has been notified and will follow up when funds are available.'];
}
