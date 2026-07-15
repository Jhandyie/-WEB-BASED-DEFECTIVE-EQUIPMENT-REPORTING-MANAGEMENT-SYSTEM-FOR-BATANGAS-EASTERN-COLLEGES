<?php
/**
 * admin_chat_proxy.php — "BECCA AI", the admin-side Administrative Intelligence Assistant.
 *
 * Read-only advisor: explains live system data, answers how-to/workflow questions,
 * and gives recommendations. It never changes data and never fabricates — when the
 * live model is unavailable (no key / no credit / API error) it falls back to a
 * built-in analytics + how-to brain so it always responds.
 *
 * Admin-session gated. Same Anthropic key as the student bot (config/chat_secrets.php
 * or ANTHROPIC_API_KEY).
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/sla.php';

header('Content-Type: application/json');

// ── Admin only (JSON 403 rather than a redirect) ──
if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required.']);
    exit;
}

function adminChatApiKey(): string
{
    $env = getenv('ANTHROPIC_API_KEY');
    if (is_string($env) && trim($env) !== '') return trim($env);
    $srv = $_SERVER['ANTHROPIC_API_KEY'] ?? '';
    if (is_string($srv) && trim($srv) !== '') return trim($srv);
    $p = __DIR__ . '/config/chat_secrets.php';
    if (is_file($p)) {
        $c = require $p;
        if (is_array($c) && !empty($c['anthropic_api_key'])) return trim((string) $c['anthropic_api_key']);
    }
    return '';
}

function adminChatLog(string $msg, array $ctx = []): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/admin_chat.log', json_encode(['time' => date('c'), 'msg' => $msg, 'ctx' => $ctx]) . PHP_EOL, FILE_APPEND);
}

/** Gather live, read-only operational data straight from Postgres. Graceful on any failure. */
function adminBuildData(): array
{
    $d = [
        'total' => 0, 'pending_review' => 0, 'ready_or_assigned' => 0, 'in_progress' => 0,
        'resolved' => 0, 'rejected' => 0, 'overdue' => 0,
        'p_critical' => 0, 'p_high' => 0, 'p_medium' => 0, 'p_low' => 0,
        'today' => 0, 'week' => 0,
        'technicians' => 0, 'busiest' => [], 'unassigned' => 0,
        'top_equipment' => [], 'top_location' => [],
        'equipment_total' => 0, 'equipment_attention' => 0,
        'pm_active' => 0, 'pm_due' => 0,
        'users_total' => 0, 'users_inactive' => 0,
    ];
    try { $p = getPgsqlPdoConnection(); } catch (\Throwable $e) { return $d; }

    $scalar = function (string $sql) use ($p): int {
        try { return (int) $p->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; }
    };
    $rows = function (string $sql) use ($p): array {
        try { return $p->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { return []; }
    };
    $open = "status NOT IN ('completed','verified','closed','rejected')";

    $d['total']            = $scalar("SELECT COUNT(*) FROM public.defect_reports");
    $d['pending_review']   = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('reported','pmo_review')");
    $d['ready_or_assigned']= $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('ready_for_assignment','assigned','accepted')");
    $d['in_progress']      = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('in_progress','waiting_for_materials','for_replacement')");
    $d['resolved']         = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('completed','verified','closed')");
    $d['rejected']         = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status='rejected'");
    $d['p_critical']       = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='critical'");
    $d['p_high']           = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='high'");
    $d['p_medium']         = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='medium'");
    $d['p_low']            = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND LOWER(COALESCE(priority,''))='low'");
    $d['today']            = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE CAST(report_date AS DATE)=CURRENT_DATE");
    $d['week']             = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE report_date >= CURRENT_DATE - 7");
    $d['unassigned']       = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE status IN ('pmo_review','ready_for_assignment','assigned') AND (assigned_to IS NULL OR assigned_to='')");
    $d['overdue']          = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE $open AND report_date < NOW() - " . becSlaSqlCase('priority'));

    $d['technicians']      = $scalar("SELECT COUNT(*) FROM public.users WHERE role='technician' AND COALESCE(status,'active')='active'");
    $d['busiest']          = $rows("SELECT COALESCE(u.fullname, dr.assigned_to) AS name, COUNT(*) AS n FROM public.defect_reports dr LEFT JOIN public.users u ON u.user_id=dr.assigned_to WHERE dr.assigned_to IS NOT NULL AND dr.assigned_to<>'' AND dr.status IN ('assigned','accepted','in_progress','waiting_for_materials','for_replacement') GROUP BY 1 ORDER BY n DESC LIMIT 3");

    $d['top_equipment']    = $rows("SELECT COALESCE(NULLIF(equipment_name,''),'Unknown') AS label, COUNT(*) AS n FROM public.defect_reports GROUP BY 1 ORDER BY n DESC LIMIT 3");
    $d['top_location']     = $rows("SELECT COALESCE(NULLIF(location,''),'Unspecified') AS label, COUNT(*) AS n FROM public.defect_reports GROUP BY 1 ORDER BY n DESC LIMIT 3");

    $d['equipment_total']     = $scalar("SELECT COUNT(*) FROM public.equipment WHERE LOWER(COALESCE(status,''))<>'deleted'");
    $d['equipment_attention'] = $scalar("SELECT COUNT(*) FROM public.equipment WHERE LOWER(COALESCE(status,'')) IN ('defective','faulty','damaged','maintenance','under_maintenance')");
    $d['pm_active']           = $scalar("SELECT COUNT(*) FROM public.preventive_schedules WHERE status='active'");
    $d['pm_due']              = $scalar("SELECT COUNT(*) FROM public.preventive_schedules WHERE status='active' AND next_due <= CURRENT_DATE");
    $d['users_total']         = $scalar("SELECT COUNT(*) FROM public.users WHERE COALESCE(status,'active')<>'deleted'");
    $d['users_inactive']      = $scalar("SELECT COUNT(*) FROM public.users WHERE LOWER(COALESCE(status,''))='inactive'");

    return $d;
}

function adminTopLine(array $rows): string
{
    $rows = array_values(array_filter($rows, fn($r) => (int) ($r['n'] ?? 0) > 0));
    if (!$rows) return '—';
    $out = [];
    foreach ($rows as $i => $r) $out[] = ($i + 1) . '. ' . trim((string) $r['label']) . ' (' . (int) $r['n'] . ')';
    return implode('  ', $out);
}

/** A compact, factual snapshot string injected into the model + used by the offline brain. */
function adminDataText(array $d): string
{
    $busiest = $d['busiest'] ? implode(', ', array_map(fn($r) => trim((string) $r['name']) . ': ' . (int) $r['n'], $d['busiest'])) : 'none assigned';
    return "LIVE SYSTEM DATA (read-only, current):\n"
        . "- Reports total: {$d['total']} | Pending review: {$d['pending_review']} | Ready/Assigned: {$d['ready_or_assigned']} | In progress: {$d['in_progress']} | Resolved: {$d['resolved']} | Rejected: {$d['rejected']}\n"
        . "- Open by priority: Critical {$d['p_critical']}, High {$d['p_high']}, Medium {$d['p_medium']}, Low {$d['p_low']}\n"
        . "- Overdue (past SLA target): {$d['overdue']} | Unassigned awaiting a technician: {$d['unassigned']}\n"
        . "- Reports today: {$d['today']} | last 7 days: {$d['week']}\n"
        . "- Active technicians: {$d['technicians']} | Busiest: {$busiest}\n"
        . "- Most-reported equipment: " . adminTopLine($d['top_equipment']) . "\n"
        . "- Most-reported locations: " . adminTopLine($d['top_location']) . "\n"
        . "- Equipment records: {$d['equipment_total']} | needing attention: {$d['equipment_attention']}\n"
        . "- Preventive schedules active: {$d['pm_active']} (due now: {$d['pm_due']})\n"
        . "- Users: {$d['users_total']} | inactive: {$d['users_inactive']}";
}

/** Offline / fallback brain — accurate answers from live data + how-to guidance. Returns a reply string. */
function adminLocalReply(string $text, array $d): string
{
    $q = strtolower(trim($text));
    $has = fn(string $re) => (bool) preg_match($re, $q);

    if ($q === '' || $has('/\b(hi|hello|hey|kumusta|good (morning|afternoon|evening))\b/')) {
        return "Hi! I'm BECCA AI, your admin assistant. I can summarize live data (open reports, overdue, busiest technician, most-reported equipment) and explain any workflow — receiving, approving, assigning, preventive maintenance, work orders, or users. What would you like to know?";
    }

    // ── Live data questions ──
    if ($has('/\b(overview|summary|dashboard|how many|status|snapshot|current|stats|today)\b/')) {
        return "Here's the current snapshot:\n"
            . "• Pending review: {$d['pending_review']}\n"
            . "• Ready / assigned: {$d['ready_or_assigned']}\n"
            . "• In progress: {$d['in_progress']}\n"
            . "• Resolved: {$d['resolved']}\n"
            . "• Open by priority — Critical {$d['p_critical']}, High {$d['p_high']}, Medium {$d['p_medium']}, Low {$d['p_low']}\n"
            . "• Overdue: {$d['overdue']} · Unassigned: {$d['unassigned']}\n"
            . "• Today: {$d['today']} new · Last 7 days: {$d['week']}\n"
            . ($d['p_critical'] > 0 ? "\nRecommendation: review the {$d['p_critical']} Critical open report(s) first." : "");
    }
    if ($has('/\b(overdue|sla|escalat|late|breach)\b/')) {
        return "There are {$d['overdue']} open report(s) past their SLA target. The system auto-escalates these to the PMO, but it's worth reviewing them and reassigning if a technician is overloaded.";
    }
    if ($has('/\b(technician|tekniko|workload|busiest|assignee|who.*assigned|staff)\b/')) {
        $busiest = $d['busiest'] ? implode(', ', array_map(fn($r) => trim((string) $r['name']) . ' (' . (int) $r['n'] . ')', $d['busiest'])) : 'no one currently has active tasks';
        return "Active technicians: {$d['technicians']}. Busiest right now: {$busiest}. There are {$d['unassigned']} report(s) waiting to be assigned and {$d['in_progress']} in progress.";
    }
    if ($has('/\b(most|top|frequent|recurring)\b/') && $has('/\b(equipment|device|unit|defect|report|broken)\b/')) {
        return "Most-reported equipment:\n" . adminTopLine($d['top_equipment']) . "\n\nConsider scheduling preventive maintenance for the top items.";
    }
    if ($has('/\b(room|location|lab|building|area)\b/')) {
        return "Locations with the most reports:\n" . adminTopLine($d['top_location']);
    }
    if ($has('/\b(equipment)\b/') && $has('/\b(attention|maintenance|defective|faulty|broken|status)\b/')) {
        return "Of {$d['equipment_total']} equipment records, {$d['equipment_attention']} currently need attention (defective or under maintenance).";
    }
    if ($has('/\b(preventive|pm|scheduled maintenance|recurring)\b/')) {
        return "Preventive maintenance: {$d['pm_active']} active schedule(s), {$d['pm_due']} due now. Due tasks auto-generate a work item when you open the dashboard or the Preventive Maintenance page.";
    }
    if ($has('/\b(user|account|role|inactive)\b/')) {
        return "There are {$d['users_total']} active user account(s), {$d['users_inactive']} marked inactive. Manage roles and accounts under 'User Management'. Technician accounts are created by the admin.";
    }

    // ── How-to / workflow ──
    if ($has('/\b(receive|received|mark.*received|acknowledge)\b/')) {
        return "To receive a report: open Defect Reports, find a 'Reported' item, and click 'Mark as Received' (this sets it to PMO Review and emails the reporter). After that you can Approve it, or assign a technician directly from Assign Technicians.";
    }
    if ($has('/\b(approve|approval|categor)\b/')) {
        return "To approve: open the report's detail in Defect Reports and click Approve (you can set the department and priority). Approving moves it to 'Assigned' and auto-creates a work order. You can then pick a technician in Assign Technicians.";
    }
    if ($has('/\b(assign|assigning|dispatch)\b/')) {
        return "To assign: open Assign Technicians. Received/approved reports appear in the queue — pick a report on the left, then click a technician card to assign (it confirms first). The technician is notified and the report moves to their dashboard.";
    }
    if ($has('/\b(work order|workorder)\b/')) {
        return "Work orders are created automatically when you approve a report, and are listed under Work Orders where you can track their status.";
    }
    if ($has('/\b(verify|verification|close|closing)\b/')) {
        return "When a technician marks a repair Completed, open the report and click Verify to confirm the fix and close it. The reporter is asked to confirm whether their issue was resolved.";
    }
    if ($has('/\b(report|defect)\b/') && $has('/\b(workflow|lifecycle|process|steps|flow|stages)\b/')) {
        return "Report lifecycle: Reported → Received by PMO → Approved → Assigned → In Progress → Completed → Verified/Closed (with a Rejected branch). You can see any report's exact stage in Defect Reports or via the tracker.";
    }

    if ($has('/\b(what can you|help|capabilities|features|who are you)\b/')) {
        return "I'm BECCA AI. I can: summarize live data (reports, priorities, overdue, technician workload, top equipment/locations, preventive maintenance, users); explain how to receive/approve/assign/verify reports; and recommend what to prioritize. Ask me things like \"how many critical reports are open?\" or \"how do I assign a technician?\"";
    }

    // Default
    return "I can help with live system data and admin workflows. Try: \"give me a summary\", \"what's overdue?\", \"who's the busiest technician?\", \"most-reported equipment\", or \"how do I approve a report?\". (Currently {$d['pending_review']} pending review, {$d['overdue']} overdue.)";
}

// ── Read request ──
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}
$messages = [];
foreach ($input['messages'] as $m) {
    if (isset($m['role'], $m['content']) && in_array($m['role'], ['user', 'assistant'], true) && is_string($m['content'])) {
        $messages[] = ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 4000)];
    }
}
if (!$messages) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid messages']);
    exit;
}
$lastUser = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if ($messages[$i]['role'] === 'user') { $lastUser = $messages[$i]['content']; break; }
}

$data     = adminBuildData();
$dataText = adminDataText($data);
$fallback = adminLocalReply($lastUser, $data);
$adminName = $_SESSION['fullname'] ?? 'Administrator';

$system_prompt = <<<SYS
You are BECCA AI (Business Equipment & Control Cognitive Assistant), an administrative intelligence assistant for system administrators of the Batangas Eastern Colleges (BEC) Defective Equipment Reporting Management System.

You are talking to an administrator (name: {$adminName}). Act like a knowledgeable, friendly, professional colleague — conversational and confident, never robotic.

CORE ROLE
- Explain dashboard statistics and the live data below; summarize activity; highlight critical issues; identify trends.
- Help with defect reports (summarize, explain status, suggest priority/next action), equipment, users, analytics, and notifications.
- Provide intelligent recommendations (e.g. prioritize critical reports, schedule preventive maintenance, review overdue items) — clearly labeled as suggestions, not facts.
- Explain how the system works and the correct admin workflows.

COMMUNICATION
- Give the direct answer first, then supporting detail, then a recommendation or next step when useful.
- Be concise; use short bullet lists or headings when they help. Avoid jargon.
- Maintain conversation context; understand references like "that report" or "this technician".

SECURITY & HONESTY
- You are READ-ONLY: you advise and explain, you do not change data. If asked to perform an action, explain where in the admin panel to do it.
- Never expose passwords, tokens, or credentials. Respect permissions.
- NEVER fabricate. Base every factual claim on the LIVE SYSTEM DATA below or what the admin tells you. If something isn't available, say "That information isn't available to me right now" rather than guessing. Never invent ticket numbers, names, rooms, or counts.

WORKFLOW REFERENCE (for how-to questions)
- Report lifecycle: Reported -> Received by PMO -> Approved -> Assigned -> In Progress -> Completed -> Verified/Closed (Rejected branch).
- Receive: Defect Reports -> "Mark as Received". Approve: report detail -> Approve (sets department/priority, auto-creates a work order). Assign: Assign Technicians -> pick report -> click a technician card. Verify: report detail -> Verify when the technician marks it Completed.
- Preventive Maintenance: recurring schedules that auto-generate tasks when due. Overdue open reports are auto-escalated to the PMO.

{$dataText}

Use the live data above to answer data questions precisely. Keep replies short and skimmable.
SYS;

$apiKey = adminChatApiKey();
if ($apiKey === '') {
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => 'AI service not configured']);
    exit;
}

$payload = [
    'model'      => 'claude-haiku-4-5',
    'max_tokens' => 1024,
    'system'     => $system_prompt,
    'messages'   => $messages,
];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    adminChatLog('curl error', ['err' => $err]);
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => 'AI service unavailable']);
    exit;
}
$body = json_decode($res, true);
if ($code !== 200) {
    adminChatLog('api error', ['code' => $code, 'resp' => $body ?: $res]);
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => $body['error']['message'] ?? 'API error']);
    exit;
}
$aiText = $body['content'][0]['text'] ?? '';
if (trim($aiText) === '') { $aiText = $fallback; }
echo json_encode(['reply' => $aiText, 'source' => 'anthropic']);
