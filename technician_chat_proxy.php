<?php
/**
 * technician_chat_proxy.php — "BECCA AI" for the technician portal.
 *
 * Read-only helper for maintenance technicians: knows the technician's own
 * task queue (live), explains the repair workflow (start → mark as fixed, or
 * need parts / can't be fixed), and gives practical troubleshooting guidance. Falls back
 * to a built-in brain when the AI service is unavailable, so it always answers.
 *
 * Technician-session gated. Shares the model client and key with the other assistants.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/sla.php';
require_once __DIR__ . '/includes/ai_client.php';

header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Technician access required.']);
    exit;
}
$techId   = trim((string)$_SESSION['user_id']);
$techName = trim((string)($_SESSION['fullname'] ?? 'Technician'));

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
    // The words the technician's own screen shows for each status, so the
    // assistant never says "assigned" or "accepted" about a task the screen
    // calls "Not started".
    $said = [
        'assigned' => 'not started', 'accepted' => 'not started', 'in_progress' => 'in progress',
        'waiting_for_materials' => 'waiting for parts', 'for_replacement' => 'needs replacement',
    ];
    $lines = [];
    foreach ($d['open_tasks'] as $t) {
        $st = strtolower((string)$t['status']);
        $lines[] = '  • ' . $t['report_id'] . ' — ' . $t['equipment_name'] . ' @ ' . $t['location']
            . ' [' . ($t['priority'] ?: 'medium') . ' priority / ' . ($said[$st] ?? str_replace('_', ' ', $st)) . ']';
    }
    return "LIVE TECHNICIAN DATA (this technician only, read-only, current):\n"
        . "- Not started yet: {$d['to_receive']} | In progress: {$d['in_progress']} | Stalled (waiting for parts / needs replacement): {$d['waiting']}\n"
        . "- Marked as fixed, waiting for the PMO to check: {$d['awaiting_pmo']} | Checked and closed all-time: {$d['done_total']}\n"
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
        return "Hi! I'm BECCA, your technician assistant. I can tell you what's in your queue, what to do next, and how to start a task and mark it as fixed. {$nextLine}";
    }
    if ($has('/\b(next|priority|first|start with|what should i)\b/')) {
        return $nextLine . ($d['to_receive'] > 0 ? " You also have {$d['to_receive']} task(s) you have not started yet." : '');
    }
    if ($has('/\b(queue|tasks|workload|assigned|how many|summary|status)\b/')) {
        return "Your workload right now:\n• Not started yet: {$d['to_receive']}\n• In progress: {$d['in_progress']}\n• Waiting for parts / needs replacement: {$d['waiting']}\n• Marked as fixed, PMO to check: {$d['awaiting_pmo']}\n\n{$nextLine}";
    }
    // These three describe the screen as it is now: one button per task, a
    // short finish form, and the problem options folded under "Having a
    // problem?". They used to describe a Receive button and an eleven-field
    // completion report, both removed after the September defense.
    if ($has('/\b(receive|accept|start|begin|umpisa|simula)\b/')) {
        return "Tap the task in My Tasks, then press \"Start the repair\" when you begin. That one button tells the PMO and the reporter that you are on it.";
    }
    if ($has('/(mark(ed)? (it |this |the task |a task )?(as )?fixed|\b(complete|finish|completion|done|tapos|submit)\b)/')) {
        return "When the job is done, fill in the short form under \"Finish this task\":\n• What did you do? — tap a ready sentence or type your own, English or Filipino\n• Parts used, if any\n• A photo of the finished work (required)\n• Cost, if you spent anything (PMO technicians only)\nThen press \"Mark as fixed\". The PMO checks it and closes the task, and the repair form is written up for you to print.";
    }
    if ($has('/\b(waiting|stuck|no parts|need parts|unavailable|replace|replacement|cannot|can\'t|walang)\b/')) {
        return "Open \"Having a problem with this task?\" under the task, write one sentence for the PMO, then press \"Need parts first\" or \"Can't be fixed — needs replacement\". When the parts arrive, press \"Parts arrived — continue\".";
    }
    if ($has('/\b(notification|alert|unread)\b/')) {
        return "You have {$d['unread']} unread notification(s). Open them from the bell icon in the sidebar or the Alerts tab on mobile.";
    }
    if ($has('/\b(sla|deadline|due|overdue)\b/')) {
        return "Each task shows a live \"Due in / Overdue\" chip based on its priority (" . becSlaSummaryText() . "). Start with anything marked Overdue or due soonest.";
    }
    if ($has('/\b(what can you|help|who are you|capabilities)\b/')) {
        return "I can tell you what is in your queue and what to do next, and explain the buttons: Start the repair, Mark as fixed, and the two options under \"Having a problem?\". Try \"what's next?\" or \"how do I mark a task as fixed?\".";
    }
    return "I can help with your queue and the repair workflow. Try: \"what's next?\", \"summarize my tasks\", or \"how do I mark a task as fixed?\". {$nextLine}";
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

LANGUAGE: answer in English when the technician writes in English, and in Filipino only when they write in Filipino or Taglish. Their name is not a signal of language.

CORE ROLE
- Tell the technician what is in THEIR queue and what to work on next (use the live data below).
- Explain the technician screen exactly as it is. Each task shows what is broken, where, who reported it and their photos, then ONE button:
  * A new task: "Start the repair". Once started, the "Finish this task" form appears with the "Mark as fixed" button.
  * The finish form asks only: What did you do? (required; ready sentences to tap, English or Filipino), Parts used (if any), a photo of the finished work (required), and Cost (only PMO technicians see it).
  * Stuck: open "Having a problem with this task?", write one sentence, then press "Need parts first" or "Can't be fixed — needs replacement". When parts arrive: "Parts arrived — continue".
  * After "Mark as fixed" the PMO checks and closes the task, and a printable repair form is written automatically ("Open the repair form").
- There is NO Receive button, NO completion report with diagnosis, procedures, tools, findings or before/during photos, and NO cost worksheet any more. Never mention them.
- Use plain words: say "time limit" or "due", never "SLA".
- Offer sensible general troubleshooting directions for common campus equipment (projectors, computers, aircon, printers) — clearly as suggestions; safety first, and defer to school procedures.

SECURITY & HONESTY
- READ-ONLY: you never change data. Point to the exact button instead, in the button's own words above.
- NEVER fabricate: base every factual claim on the LIVE TECHNICIAN DATA below or what the technician says. If unknown, say it isn't available to you. Never invent report IDs, rooms, or counts.
- This technician can only see their own tasks; do not speculate about other technicians or admin data.

{$dataText}

Time limits by priority: {$slaSummary}.
Language: reply in the same language as the technician's latest message — English if they wrote in English, Filipino if they wrote in Filipino.
SYS;

$ai = aiChatComplete($system_prompt, $messages, ['max_tokens' => 1024, 'timeout' => 45]);

if (!$ai['ok']) {
    techChatLog('ai completion failed', [
        'reason' => $ai['reason'],
        'code' => $ai['http_code'],
        'model' => $ai['model'],
    ]);
    echo json_encode(['reply' => $fallback, 'source' => 'local_fallback', 'warning' => $ai['error']]);
    exit;
}

echo json_encode(['reply' => $ai['text'], 'source' => 'gemini']);
