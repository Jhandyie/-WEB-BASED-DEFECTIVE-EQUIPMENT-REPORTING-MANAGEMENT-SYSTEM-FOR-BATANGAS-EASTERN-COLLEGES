<?php
/**
 * includes/budget_finance.php — shared Finance-notification helpers for budget requests.
 * Used by admin_budget_requests.php and the technician notify endpoint. Sends a formal
 * email to the Finance office with a secure tokenized acknowledgment link (budget_ack.php).
 */
require_once __DIR__ . '/../config/finance.php';
require_once __DIR__ . '/mail_helper.php';

if (!function_exists('bfPeso')) { function bfPeso($v){ return '&#8369;' . number_format((float)$v, 2); } }

/** Absolute base URL of the app (works on localhost or a live host). */
function bfBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

function bfLoadRequest($conn, string $requestId): ?array {
    $stmt = $conn->prepare("SELECT b.request_id, b.report_id, b.technician_id, b.justification, b.total_cost,
            b.status, b.finance_token, b.finance_status, b.finance_email,
            COALESCE(u.fullname, b.technician_id) AS technician_name
        FROM budget_requests b LEFT JOIN users u ON u.user_id = b.technician_id
        WHERE b.request_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $requestId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: null;
}

function bfLoadItems($conn, string $requestId): array {
    $items = [];
    $ir = $conn->prepare("SELECT part_needed, quantity, estimated_cost, supplier FROM budget_request_items WHERE request_id = ? ORDER BY item_id");
    if ($ir) {
        $ir->bind_param('s', $requestId);
        $ir->execute();
        $res = $ir->get_result();
        while ($row = $res->fetch_assoc()) { $items[] = $row; }
        $ir->close();
    }
    return $items;
}

/**
 * Notify the Finance office about a budget request.
 * Records finance_status='awaiting', a token, and the note; then emails Finance
 * a formal summary with the acknowledgment link.
 * @return array [bool $emailSent, string $message]
 */
function bfNotifyFinance($conn, string $requestId, string $senderName, string $note = ''): array {
    $req = bfLoadRequest($conn, $requestId);
    if (!$req) return [false, 'Budget request not found.'];
    $items = bfLoadItems($conn, $requestId);

    $token = !empty($req['finance_token']) ? (string)$req['finance_token'] : bin2hex(random_bytes(20));
    $email = BEC_FINANCE_EMAIL;

    $upd = $conn->prepare("UPDATE budget_requests
        SET finance_email = ?, finance_status = 'awaiting', finance_notified_at = NOW(), finance_token = ?, finance_note = ?
        WHERE request_id = ?");
    if ($upd) { $upd->bind_param('ssss', $email, $token, $note, $requestId); $upd->execute(); $upd->close(); }

    $ackUrl  = bfBaseUrl() . '/budget_ack.php?token=' . urlencode($token);
    $subject = 'Budget Request ' . $requestId . ' — Review Requested · BEC PMO';
    $html    = bfFinanceEmailHtml($req, $items, $ackUrl, $note, $senderName);

    $sent = false;
    try { $sent = (bool) sendEmail($email, $subject, $html, null, 'admin'); } catch (\Throwable $e) { $sent = false; }

    return [$sent, $sent
        ? ('Finance has been notified at ' . $email . '.')
        : ('Recorded for Finance, but the email could not be delivered right now.')];
}

/** Human label for the finance status. */
function bfFinanceStatusLabel(?string $s): string {
    switch ((string)$s) {
        case 'awaiting':  return 'Awaiting Finance';
        case 'received':  return 'Received by Finance';
        case 'accepted':  return 'Accepted by Finance';
        default:          return 'Not yet sent to Finance';
    }
}

/** Build the formal HTML email body sent to Finance. */
function bfFinanceEmailHtml(array $req, array $items, string $ackUrl, string $note, string $senderName): string {
    $rid  = htmlspecialchars((string)$req['request_id'], ENT_QUOTES, 'UTF-8');
    $tech = htmlspecialchars((string)($req['technician_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $rep  = htmlspecialchars((string)($req['report_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $just = htmlspecialchars((string)($req['justification'] ?? ''), ENT_QUOTES, 'UTF-8');
    $sender = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
    $noteEsc = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

    $rows = '';
    foreach ($items as $it) {
        $sub = (float)$it['quantity'] * (float)$it['estimated_cost'];
        $rows .= '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)$it['part_needed'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:center;">' . (int)$it['quantity'] . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">' . bfPeso($it['estimated_cost']) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)($it['supplier'] ?: '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;">' . bfPeso($sub) . '</td>'
            . '</tr>';
    }
    if ($rows === '') { $rows = '<tr><td colspan="5" style="padding:10px;color:#888;">No line items.</td></tr>'; }

    $noteBlock = $noteEsc !== ''
        ? '<div style="margin:14px 0;padding:10px 12px;background:#FFFBEF;border-left:3px solid #C9960C;border-radius:6px;font-size:13px;color:#5C3838;"><strong>Note from ' . $sender . ':</strong> ' . $noteEsc . '</div>'
        : '';
    $justBlock = $just !== ''
        ? '<div style="margin:14px 0;padding:10px 12px;background:#F8F3EA;border-radius:6px;font-size:13px;color:#5C3838;"><strong>Justification:</strong> ' . $just . '</div>'
        : '';

    return '<div style="font-family:Segoe UI,Arial,sans-serif;background:#F4F1EC;padding:24px;">'
      . '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E8DDD0;border-radius:14px;overflow:hidden;">'
        . '<div style="background:linear-gradient(135deg,#2D0505,#7B1D1D);color:#fff;padding:20px 24px;">'
          . '<div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#F0C040;">Batangas Eastern Colleges · Property Management Office</div>'
          . '<div style="font-size:19px;font-weight:700;margin-top:4px;">Budget Request for Finance Review</div>'
        . '</div>'
        . '<div style="padding:22px 24px;color:#1C1008;">'
          . '<p style="font-size:14px;line-height:1.6;margin:0 0 4px;">Good day, ' . htmlspecialchars(BEC_FINANCE_LABEL, ENT_QUOTES, 'UTF-8') . ',</p>'
          . '<p style="font-size:14px;line-height:1.6;margin:0 0 14px;color:#5C3838;">The Property Management Office is forwarding the following technician budget request for your review and processing.</p>'
          . '<table style="width:100%;font-size:13px;color:#5C3838;margin-bottom:8px;">'
            . '<tr><td style="padding:3px 0;width:130px;color:#9E8070;">Request No.</td><td style="font-weight:700;color:#7B1D1D;">' . $rid . '</td></tr>'
            . '<tr><td style="padding:3px 0;color:#9E8070;">Requested by</td><td>' . $tech . '</td></tr>'
            . ($rep !== '' ? '<tr><td style="padding:3px 0;color:#9E8070;">Related Report</td><td>' . $rep . '</td></tr>' : '')
          . '</table>'
          . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;">'
            . '<thead><tr style="background:#F8F3EA;color:#5C3838;text-align:left;">'
              . '<th style="padding:8px 10px;">Part / Material</th><th style="padding:8px 10px;text-align:center;">Qty</th><th style="padding:8px 10px;text-align:right;">Est. Cost</th><th style="padding:8px 10px;">Supplier</th><th style="padding:8px 10px;text-align:right;">Subtotal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td colspan="4" style="padding:10px;text-align:right;font-weight:700;border-top:2px solid #E8DDD0;">Total</td><td style="padding:10px;text-align:right;font-weight:700;border-top:2px solid #E8DDD0;color:#7B1D1D;">' . bfPeso($req['total_cost']) . '</td></tr></tfoot>'
          . '</table>'
          . $justBlock . $noteBlock
          . '<div style="text-align:center;margin:22px 0 8px;">'
            . '<a href="' . htmlspecialchars($ackUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#4A0E0E,#7B1D1D);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 28px;border-radius:10px;">Review &amp; Acknowledge Request</a>'
          . '</div>'
          . '<p style="font-size:12px;color:#9E8070;text-align:center;line-height:1.5;margin:6px 0 0;">Opening the link lets you mark this request as <strong>Received</strong> or <strong>Accepted</strong>. No account or login is required.</p>'
        . '</div>'
        . '<div style="background:#F8F3EA;padding:14px 24px;font-size:11px;color:#9E8070;text-align:center;border-top:1px solid #E8DDD0;">'
          . 'Batangas Eastern Colleges · Property Management Office · This is an automated message.'
        . '</div>'
      . '</div></div>';
}
