<?php
/**
 * technician_budget_request.php — REMOVED.
 *
 * The technician budget-request / Finance-acknowledgment workflow was removed
 * from the system (see the manuscript's Removals checklist: budget requests and
 * the Finance office are no longer part of the maintenance flow — the PMO
 * approves and closes reports directly). This stub remains only so any stale
 * link or bookmark fails cleanly instead of hitting a missing file.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');

http_response_code(410); // Gone
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'message' => 'Budget requests have been removed from the system. Repairs are logged directly in the completion report.',
]);
exit();
