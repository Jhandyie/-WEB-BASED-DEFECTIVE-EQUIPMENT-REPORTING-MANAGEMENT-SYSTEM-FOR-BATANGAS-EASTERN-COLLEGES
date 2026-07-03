<?php
/**
 * technician_budget_request.php
 * Technician submits an (optional) repair-expense / budget request for a task.
 * Persists a header (budget_requests) + line items (budget_request_items),
 * notifies admins/PMO, and writes an audit entry.
 *
 * Expected POST fields:
 *   report_id, justification,
 *   part_needed[], quantity[], estimated_cost[], supplier[]   (parallel arrays)
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

header('Content-Type: application/json');
// JSON endpoint: never let warnings/notices corrupt the response body.
ini_set('display_errors', '0');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}
requireCsrf(true);

$technician_id = (string)$_SESSION['user_id'];
$report_id     = trim((string)($_POST['report_id'] ?? ''));
$work_order_id = trim((string)($_POST['work_order_id'] ?? ''));
$justification = trim((string)($_POST['justification'] ?? ''));

$parts      = $_POST['part_needed']    ?? [];
$quantities = $_POST['quantity']       ?? [];
$costs      = $_POST['estimated_cost'] ?? [];
$suppliers  = $_POST['supplier']       ?? [];

if (!is_array($parts) || count(array_filter($parts, fn($p) => trim((string)$p) !== '')) === 0) {
    echo json_encode(['success' => false, 'message' => 'Add at least one part / item to request.']);
    exit();
}

// Build clean line items + total.
$items = [];
$total = 0.0;
foreach ($parts as $i => $part) {
    $name = trim((string)$part);
    if ($name === '') { continue; }
    $qty  = max(1, (int)($quantities[$i] ?? 1));
    $cost = (float)($costs[$i] ?? 0);
    $items[] = [
        'part'     => $name,
        'qty'      => $qty,
        'cost'     => $cost,
        'supplier' => trim((string)($suppliers[$i] ?? '')),
    ];
    $total += $qty * $cost;
}

$request_id = 'BR-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$conn = getDBConnection();

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        INSERT INTO budget_requests (request_id, report_id, work_order_id, technician_id, justification, total_cost, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$stmt) { throw new Exception('Unable to prepare budget request.'); }
    $reportRef = $report_id !== '' ? $report_id : null;
    $woRef     = $work_order_id !== '' ? $work_order_id : null;
    $totalStr  = number_format($total, 2, '.', '');
    $stmt->bind_param('ssssss', $request_id, $reportRef, $woRef, $technician_id, $justification, $totalStr);
    $stmt->execute();
    $stmt->close();

    $itemStmt = $conn->prepare("
        INSERT INTO budget_request_items (request_id, part_needed, quantity, estimated_cost, supplier)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$itemStmt) { throw new Exception('Unable to prepare budget items.'); }
    foreach ($items as $it) {
        $qtyStr  = (string)$it['qty'];
        $costStr = number_format($it['cost'], 2, '.', '');
        $itemStmt->bind_param('ssiss', $request_id, $it['part'], $qtyStr, $costStr, $it['supplier']);
        $itemStmt->execute();
    }
    $itemStmt->close();

    // Notify admins / PMO.
    $admins = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','pmo') AND status = 'active'");
    if ($admins) {
        while ($row = $admins->fetch_assoc()) {
            $uid = trim((string)($row['user_id'] ?? ''));
            if ($uid === '') { continue; }
            $nid = 'NTF-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            $msg = 'New budget request ' . $request_id . ' (₱' . $totalStr . ') submitted for review.';
            $ns = $conn->prepare("
                INSERT INTO notifications (notification_id, user_id, message, type, related_id, created_date)
                VALUES (?, ?, ?, 'budget_request', ?, NOW())
            ");
            if ($ns) {
                $ns->bind_param('ssss', $nid, $uid, $msg, $request_id);
                $ns->execute();
                $ns->close();
            }
        }
    }

    $conn->commit();
    logActivity($technician_id, 'budget.request', 'Submitted budget request ' . $request_id . ' total ₱' . $totalStr);

    // Finance handles all campus budgeting — every budget request notifies the
    // Finance office by email (mandatory, not optional).
    $financeMsg = '';
    try {
        require_once __DIR__ . '/includes/budget_finance.php';
        $techName = (string)($_SESSION['fullname'] ?? 'Technician');
        [$fsent, $fmsg] = bfNotifyFinance($conn, $request_id, $techName, '');
        $financeMsg = ' ' . $fmsg;
    } catch (\Throwable $e) {
        error_log('budget finance notify failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Budget request submitted for approval.' . $financeMsg,
        'request_id' => $request_id,
        'total_cost' => $totalStr,
    ]);
} catch (Exception $e) {
    $conn->rollback();
    error_log('technician_budget_request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error submitting budget request.']);
}
exit();
