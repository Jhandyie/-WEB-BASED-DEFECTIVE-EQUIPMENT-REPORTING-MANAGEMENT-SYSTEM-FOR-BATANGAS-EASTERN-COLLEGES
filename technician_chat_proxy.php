<?php
/**
 * technician_chat_proxy.php — "BECCA AI" for the technician portal.
 *
 * Read-only helper for maintenance technicians: knows the technician's own
 * task queue (live), explains the repair workflow (receive → start → materials
 * → complete), and gives practical troubleshooting guidance. Falls back
 * to a built-in brain when the AI service is unavailable, so it always answers.
 *
 * Technician-session gated. Same Anthropic key as the other assistants.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/sla.php';

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Technician access required.']);
    exit;
}
$techId   = trim((string)$_SESSION['user_id']);
$techName = trim((string)($_SESSION['fullname'] ?? 'Technician'));

function techChatApiKey(): string
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

function techChatLog(string $msg, array $ctx = []): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/technician_chat.log', json_encode(['time' => date('c'), 'msg' => $msg, 'ctx' => $ctx]) . PHP_EOL, FILE_APPEND);
}

/** Live, read-only snapshot of THIS technician's workload. Graceful on failure. */
function techBuildData(string $techId): array
{
    $d = [
        'to_receive' => 0, 'in_progress' => 0, 'waiting' => 0, 'awaiting_pmo' => 0,
        'done_total' => 0, 'unread' => 0, 'open_tasks' => [],
    ];
    try { $p = getPgsqlPdoConnection(); } catch (\Throwable $e) { return $d; }

    $scalar = function (string $sql, array $args) use ($p): int {
        try { $st = $p->prepare($sql); $st->execute($args); return (int) $st->fetchColumn(); } catch (\Throwable $e) { return 0; }
    };
    $d['to_receive']   = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE assigned_to = ? AND status IN ('assigned','accepted')", [$techId]);
    $d['in_progress']  = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE assigned_to = ? AND status = 'in_progress'", [$techId]);
    $d['waiting']      = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE assigned_to = ? AND status IN ('waiting_for_materials','for_replacement')", [$techId]);
    $d['awaiting_pmo'] = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE assigned_to = ? AND status = 'completed'", [$techId]);
    $d['done_total']   = $scalar("SELECT COUNT(*) FROM public.defect_reports WHERE assigned_to = ? AND status IN ('verified','closed')", [$techId]);
    $d['unread']       = $scalar("SELECT COUNT(*) FROM public.notifications WHERE user_id = ? AND is_read = false", [$techId]);

    try {
        $st = $p->prepare("SELECT dr.report_id, dr.status, dr.priority, dr.report_date,
                                  COALESCE(NULLIF(e.equipment_name,''),'Equipment') AS equipment_name,
                                  COALESCE(NULLIF(e.location,''),'Unspecified') AS location
                           FROM public.defect_reports dr
                           JOIN public.equipment e ON e.equipment_id = dr.equipment_id
                           WHERE dr.assigned_to = ?
                             AND dr.status IN ('assigned','accepted','in_progress','waiting_for_materials','for_replacement')
                           ORDER BY CASE LOWER(COALESCE(dr.priority,'medium'))
                                      WHEN 'critical' THEN 0 WHEN 'urgent' THEN 0 WHEN 'high' THEN 1
                                      WHEN 'medium' THEN 2 ELSE 3 END,
                                    dr.report_date ASC
                           LIMIT 8");
        $st->execute([$techId]);
        $d['open_tasks'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { /* keep empty */ }

    return $d;
}

function techDataText(array $d): string
{
    $lines = [];
    foreach ($d['open_tasks'] as $t) {
        $lines[] = '  • ' . $t['report_id'] . ' — ' . $t['equipment_name'] . ' @ ' . $t['location']
            . ' [' . ($t['priority'] ?: 'medium') . ' / ' . str_replace('_', ' ', (string)$t['status']) . ']';
    }
    return "LIVE TECHNICIAN DATA (this technician only, read-only, current):\n"
        . "- To receive/start: {$d['to_receive']} | In progress: {$d['in_progress']} | Waiting (materials/replacement): {$d['waiting']}\n"
        . "- Completed awaiting PMO verification: {$d['awaiting_pmo']} | Verified/closed all-time: {$d['done_total']}\n"
        . "- Unread notifications: {$d['unread']}\n"
        . "- Open tasks (priority order):\n" . ($lines ? implode("\n", $lines) : '  • none — queue is clear');
}

/** Offline fallback brain — always answers something useful. */
function techLocalReply(string $text, array $d): string
{
    $q = strtolower(trim($text));
    $has = fn(string $re) => (bool) preg_match($re, $q);
    $next = $d['open_tasks'][0] ?? null;
    $nextLine = $next
        ? "Your next-best task is {$next['report_id']} — {$next['equipment_name']} at {$next['location']} ({$next['priority']} priority)."
        : "Your queue is clear right now.";

    if ($q === '' || $has('/\b(hi|hello|hey|kumusta|good (morning|afternoon|evening))\b/')) {
        return "Hi! I'm BECCA, your technician assistant. I can tell you what's in your queue, what to do next, and walk you through receiving, repairing, and completing a task. {$nextLine}";
    }
    if ($has('/\b(next|priority|first|start with|what should i)\b/')) {
        return $nextLine . ($d['to_receive'] > 0 ? " You also have {$d['to_receive']} task(s) waiting to be received." : '');
    }
    if ($has('/\b(queue|tasks|workload|assigned|how many|summary|status)\b/')) {
        return "Your workload right now:\n• To receive/start: {$d['to_receive']}\n• In progress: {$d['in_progress']}\n• Waiting for materials/replacement: {$d['waiting']}\n• Awaiting PMO verification: {$d['awaiting_pmo']}\n\n{$nextLine}";
    }
    if ($has('/\b(receive|accept)\b/')) {
        return "Open the task from My Tasks, then press \"Receive Task\" in the Repair Progress section. That confirms the job is in your hands; the button then changes to \"Start Repair\".";
    }
    if ($has('/\b(complete|finish|completion|report done|submit)\b/')) {
        return "Use the Completion Report at the bottom of the task workspace: timing & cost, diagnosis, actions performed, parts/tools/materials, findings and recommendations, plus before/during/after photos. Submitting moves the task to \"Awaiting PMO Verification\".";
    }
    if ($has('/\b(waiting|stuck|no parts|unavailable)\b/')) {
        return "If you can't proceed, press \"Waiting for Materials\" (with a note) — the PMO sees the hold. When parts arrive, press \"Materials Received — Resume\". If the unit isn't worth repairing, use \"Recommend Replacement\".";
    }
    if ($has('/\b(notification|alert|unread)\b/')) {
        return "You have {$d['unread']} unread notification(s). Open them from the bell icon in the sidebar or the Alerts tab on mobile.";
    }
    if ($has('/\b(sla|deadline|due|overdue)\b/')) {
        return "Each task shows a live \"Due in / Overdue\" chip based on its priority (" . becSlaSummaryText() . "). Start with anything marked Overdue or due soonest.";
    }
    if ($has('/\b(what can you|help|who are you|capabilities)\b/')) {
        return "I can: report your live queue and what to do next; explain each workspace action (Receive, Start, Waiting for Materials, Recommend Replacement, Resume); and guide you through the Completion Report. Try \"what's next?\" or \"how do I complete a task?\".";
    }
    return "I can help with your queue and the repair workflow. Try: \"what's next?\", \"summarize my tasks\", or \"how do I complete a task?\". {$nextLine}";
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

$data     = techBuildData($techId);
$dataText = techDataText($data);
$fallback = techLocalReply($lastUser, $data);
$slaSummary = becSlaSummaryText();

$system_prompt = <<<SYS
You are BECCA AI, the field assistant for maintenance technicians of the Batangas Eastern Colleges (BEC) Defective Equipment Reporting Management System.

You are talking to technician {$techName}. Be a practical, friendly senior-colleague voice: direct answers first, short and skimmable, hands-on.

CORE ROLE
- Tell the technician what is in THEIR queue and what to work on next (use the live data below).
- Walk them through the workspace actions: Receive Task -> Start Repair -> (Waiting for Materials / Recommend Replacement / Resume) -> Completion Report.
- Explain the Completion Report fields (timing/cost, diagnosis, work done, parts/tools/materials, findings, recommendations, before/during/after photos).
- Offer sensible general troubleshooting directions for common campus equipment (projectors, computers, aircon, printers) — clearly as suggestions; safety first, and defer to school procedures.

SECURITY & HONESTY
- READ-ONLY: you never change data. Point to the exact button/section in the technician portal instead.
- NEVER fabricate: base every factual claim on the LIVE TECHNICIAN DATA below or what the technician says. If unknown, say it isn't available to you. Never invent report IDs, rooms, or counts.
- This technician can only see their own tasks; do not speculate about other technicians or admin data.

{$dataText}

SLA reference: {$slaSummary}.
Language: reply in the language the technician uses (English or Filipino).
SYS;

$apiKey = techChatApiKey();
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
    techChatLog('curl error', ['err' => $err]);
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => 'AI service unavailable']);
    exit;
}
$body = json_decode($res, true);
if ($code !== 200) {
    techChatLog('api error', ['code' => $code, 'resp' => $body ?: $res]);
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => $body['error']['message'] ?? 'API error']);
    exit;
}
$aiText = $body['content'][0]['text'] ?? '';
if (trim($aiText) === '') { $aiText = $fallback; }
echo json_encode(['reply' => $aiText, 'source' => 'anthropic']);
