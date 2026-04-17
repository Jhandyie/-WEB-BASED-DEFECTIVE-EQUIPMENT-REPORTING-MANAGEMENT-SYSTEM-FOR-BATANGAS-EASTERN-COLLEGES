<?php
// chat_proxy.php
// Secure server-side proxy for Anthropic Claude API
// Place this file in the SAME directory as student_index.php

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

function chatProxyConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [];
    $localConfigPath = __DIR__ . '/config/chat_secrets.php';
    if (is_file($localConfigPath)) {
        $loaded = require $localConfigPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

function chatAnthropicApiKey(): string
{
    $envKey = getenv('ANTHROPIC_API_KEY');
    if (is_string($envKey) && trim($envKey) !== '') {
        return trim($envKey);
    }

    $serverKey = $_SERVER['ANTHROPIC_API_KEY'] ?? '';
    if (is_string($serverKey) && trim($serverKey) !== '') {
        return trim($serverKey);
    }

    $config = chatProxyConfig();
    $configKey = $config['anthropic_api_key'] ?? '';
    return is_string($configKey) ? trim($configKey) : '';
}

function logChatProxyError(string $message, array $context = []): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
    ];

    @file_put_contents(
        $logDir . DIRECTORY_SEPARATOR . 'chat_proxy.log',
        json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function chatDetectLanguage(string $text): string
{
    $normalized = mb_strtolower($text, 'UTF-8');
    if ($normalized === '') {
        return 'en';
    }

    $filipinoPatterns = [
        '/\b(kumusta|kamusta|musta|paano|bakit|saan|kailan|sino|ano|alin|gaano|pwede|puwede|gusto|kailangan|salamat|opo|po|hindi|oo|meron|wala|nasaan|pakitulong|patulong|paki|sir[ae]|sira na|gumagana|gumana|ayaw|hindi gumagana|nagloloko|nagha-hang|mabagal|maingay|lumalamig|mainit|tagas|ingay|ayos|paayos|ipagawa|mag-report|mag track|mag-track|ireport|ulat|isyu|problema)\b/u',
        '/\b(nag-|pag-|mag-|ma-|ipa-|ipag-|pinaka)[[:alpha:]-]*/u',
        '/\b(ako|ikaw|kayo|kami|tayo|nila|namin|atin|ito|iyan|iyon|dito|doon)\b/u',
    ];

    $score = 0;
    foreach ($filipinoPatterns as $pattern) {
        if (preg_match_all($pattern, $normalized, $matches)) {
            $score += count($matches[0]);
        }
    }

    return $score >= 2 ? 'fil' : 'en';
}

function chatStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'reported' => 'Open',
        'assigned', 'in_progress' => 'In Progress',
        'completed', 'verified', 'closed' => 'Resolved',
        default => ucwords(str_replace('_', ' ', trim($status))),
    };
}

function chatEquipmentStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'available', 'operational' => 'Operational',
        'maintenance', 'under_maintenance' => 'Under Maintenance',
        'reserved', 'in_use', 'in use', 'borrowed' => 'In Use',
        'defective', 'faulty', 'damaged' => 'Needs Attention',
        default => $status !== '' ? ucwords(str_replace('_', ' ', trim($status))) : 'Unknown',
    };
}

function chatBuildSystemSnapshot(mysqli $conn): array
{
    $snapshot = [
        'total_reports' => 0,
        'open_reports' => 0,
        'in_progress_reports' => 0,
        'resolved_reports' => 0,
        'total_equipment' => 0,
        'attention_equipment' => 0,
    ];

    $reportRes = $conn->query("
        SELECT
            COUNT(*) AS total_reports,
            SUM(CASE WHEN status IN ('reported', 'assigned') THEN 1 ELSE 0 END) AS open_reports,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_reports,
            SUM(CASE WHEN status IN ('completed', 'verified', 'closed') THEN 1 ELSE 0 END) AS resolved_reports
        FROM defect_reports
    ");
    if ($reportRes) {
        $row = $reportRes->fetch_assoc();
        $snapshot['total_reports'] = (int)($row['total_reports'] ?? 0);
        $snapshot['open_reports'] = (int)($row['open_reports'] ?? 0);
        $snapshot['in_progress_reports'] = (int)($row['in_progress_reports'] ?? 0);
        $snapshot['resolved_reports'] = (int)($row['resolved_reports'] ?? 0);
    }

    $equipmentRes = $conn->query("
        SELECT
            COUNT(*) AS total_equipment,
            SUM(CASE WHEN LOWER(COALESCE(status, '')) IN ('defective', 'faulty', 'damaged', 'maintenance', 'under_maintenance') THEN 1 ELSE 0 END) AS attention_equipment
        FROM equipment
        WHERE status != 'deleted' OR status IS NULL
    ");
    if ($equipmentRes) {
        $row = $equipmentRes->fetch_assoc();
        $snapshot['total_equipment'] = (int)($row['total_equipment'] ?? 0);
        $snapshot['attention_equipment'] = (int)($row['attention_equipment'] ?? 0);
    }

    return $snapshot;
}

function chatLookupReport(mysqli $conn, string $text): ?array
{
    $candidates = [];
    $trimmed = trim($text);
    if ($trimmed !== '' && strlen($trimmed) <= 80) {
        $candidates[] = $trimmed;
    }

    if (preg_match_all('/[A-Za-z0-9][A-Za-z0-9\-]{2,}/', $text, $matches)) {
        foreach ($matches[0] as $token) {
            $candidates[] = trim($token, " .,!?:;\"'()[]{}");
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates)));
    if (!$candidates) {
        return null;
    }

    $sql = "
        SELECT
            dr.report_id,
            dr.status,
            dr.priority,
            dr.report_date,
            COALESCE(dr.completion_date, '') AS completion_date,
            COALESCE(dr.issue_description, '') AS issue_description,
            e.equipment_id,
            COALESCE(e.asset_tag, '') AS asset_tag,
            COALESCE(e.equipment_name, '') AS equipment_name,
            COALESCE(e.location, '') AS location,
            COALESCE(e.status, '') AS equipment_status
        FROM defect_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        WHERE dr.report_id = ?
           OR e.equipment_id = ?
           OR e.asset_tag = ?
        ORDER BY dr.report_date DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    foreach ($candidates as $candidate) {
        $stmt->bind_param('sss', $candidate, $candidate, $candidate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $stmt->close();
            return $row;
        }
    }

    $stmt->close();
    return null;
}

function chatBuildTrackingReply(array $report, string $lang): array
{
    $status = chatStatusLabel((string)($report['status'] ?? ''));
    $equipmentStatus = chatEquipmentStatusLabel((string)($report['equipment_status'] ?? ''));
    $priority = ucfirst((string)($report['priority'] ?? ''));
    $location = trim((string)($report['location'] ?? ''));
    $locationText = $location !== '' ? $location : 'Unspecified';

    if ($lang === 'fil') {
        $message = "Nahanap ko ang report na **{$report['report_id']}** para sa **{$report['equipment_name']}**.\n"
            . "- Report status: **{$status}**\n"
            . "- Equipment status: **{$equipmentStatus}**\n"
            . "- Priority: **{$priority}**\n"
            . "- Location: **{$locationText}**\n"
            . "Para sa buong detalye, buksan ang `track_report.php` at ilagay ang ticket, equipment ID, o asset tag.";
        $chips = chatChipSet($lang, ['track', 'timeline', 'submit']);
    } else {
        $message = "I found report **{$report['report_id']}** for **{$report['equipment_name']}**.\n"
            . "- Report status: **{$status}**\n"
            . "- Equipment status: **{$equipmentStatus}**\n"
            . "- Priority: **{$priority}**\n"
            . "- Location: **{$locationText}**\n"
            . "For full details, open `track_report.php` and enter the ticket, equipment ID, or asset tag.";
        $chips = chatChipSet($lang, ['track', 'timeline', 'submit']);
    }

    return ['reply' => $message, 'suggest' => false, 'chips' => $chips];
}

function chatChipLabel(string $key, string $lang): string
{
    $labels = [
        'report_projector' => [
            'en' => 'Report a broken projector',
            'fil' => 'Mag-report ng sirang projector',
        ],
        'track' => [
            'en' => 'Track my report',
            'fil' => 'I-track ang report ko',
        ],
        'computer' => [
            'en' => "Computer won't start",
            'fil' => 'Hindi nagbubukas ang computer',
        ],
        'ac' => [
            'en' => 'AC not cooling',
            'fil' => 'Hindi lumalamig ang aircon',
        ],
        'submit' => [
            'en' => 'How do I submit a report?',
            'fil' => 'Paano mag-report?',
        ],
        'timeline' => [
            'en' => 'How long are repairs?',
            'fil' => 'Gaano katagal ang repair?',
        ],
    ];

    return $labels[$key][$lang] ?? ($labels[$key]['en'] ?? $key);
}

function chatChipSet(string $lang, array $keys): array
{
    return array_map(
        static fn(string $key): string => chatChipLabel($key, $lang),
        $keys
    );
}

function chatBuildActions(string $text, string $lang, bool $suggest = false, bool $hasReport = false): array
{
    $q = strtolower($text);
    $actions = [];

    if ($hasReport || preg_match('/\b(track|ticket|status|report id|asset tag|equipment id)\b/i', $q)) {
        $actions[] = [
            'label' => $lang === 'fil' ? 'Buksan ang Tracker' : 'Open Tracker',
            'href' => 'track_report.php',
            'icon' => 'fa-search',
        ];
    }

    if ($suggest || preg_match('/\b(report|submit|file a report|paano mag-report|projector|computer|ac|aircon)\b/i', $q)) {
        $actions[] = [
            'label' => $lang === 'fil' ? 'Gumawa ng Report' : 'Create Report',
            'href' => 'student_dashboard.php',
            'icon' => 'fa-plus',
        ];
    }

    $actions[] = [
        'label' => $lang === 'fil' ? 'Mga Public Report' : 'Public Reports',
        'href' => 'public_reports.php',
        'icon' => 'fa-list',
    ];

    $seen = [];
    $filtered = [];
    foreach ($actions as $action) {
        $key = $action['href'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $filtered[] = $action;
    }

    return array_slice($filtered, 0, 3);
}

function chatBuildLocalReply(string $text, string $lang, array $snapshot, ?array $report = null): array
{
    $q = strtolower(trim($text));

    if ($report) {
        return chatBuildTrackingReply($report, $lang);
    }

    if (preg_match('/\b(how many|ilan|stats|summary|reports today|system status|current reports)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Narito ang kasalukuyang system snapshot:\n- Total reports: **{$snapshot['total_reports']}**\n- Open reports: **{$snapshot['open_reports']}**\n- In progress: **{$snapshot['in_progress_reports']}**\n- Resolved reports: **{$snapshot['resolved_reports']}**\n- Equipment records: **{$snapshot['total_equipment']}**\n- Equipment needing attention: **{$snapshot['attention_equipment']}**",
                'suggest' => false,
                'chips' => chatChipSet($lang, ['track', 'submit', 'timeline']),
            ];
        }
        return [
            'reply' => "Here is the current system snapshot:\n- Total reports: **{$snapshot['total_reports']}**\n- Open reports: **{$snapshot['open_reports']}**\n- In progress: **{$snapshot['in_progress_reports']}**\n- Resolved reports: **{$snapshot['resolved_reports']}**\n- Equipment records: **{$snapshot['total_equipment']}**\n- Equipment needing attention: **{$snapshot['attention_equipment']}**",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['track', 'submit', 'timeline']),
        ];
    }

    if (preg_match('/\b(track|ticket|status|report id|asset tag|equipment id)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Maaari kitang tulungan mag-track. Ilagay ang **ticket number**, **equipment ID**, o **asset tag** dito, o buksan ang `track_report.php` para makita ang report status at equipment status.",
                'suggest' => false,
                'chips' => chatChipSet($lang, ['track', 'timeline', 'submit']),
            ];
        }
        return [
            'reply' => "I can help you track a report. Send the **ticket number**, **equipment ID**, or **asset tag** here, or open `track_report.php` to view both the report status and equipment status.",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['track', 'timeline', 'submit']),
        ];
    }

    if (preg_match('/\b(projector|lcd|screen|display)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Para sa projector issue, subukan muna ito:\n- I-check ang power at HDMI/VGA cable\n- Pindutin ang **Source/Input**\n- I-restart ang projector at hintayin mag-warm up\nKung wala pa rin, gumawa ng formal report at isama ang room at equipment reference.\n[SUGGEST_REPORT]",
                'suggest' => true,
                'chips' => chatChipSet($lang, ['report_projector', 'submit', 'track']),
            ];
        }
        return [
            'reply' => "For a projector issue, try these first:\n- Check the power and HDMI/VGA cable\n- Press **Source/Input**\n- Restart the projector and allow it to warm up\nIf it still fails, submit a formal report and include the room and equipment reference.\n[SUGGEST_REPORT]",
            'suggest' => true,
            'chips' => chatChipSet($lang, ['report_projector', 'submit', 'track']),
        ];
    }

    if (preg_match('/\b(computer|pc|desktop|laptop|boot|won\'t start|wont start)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Kung hindi nagbubukas ang computer:\n- I-check ang power strip at cable\n- I-hold ang power button ng 10 seconds\n- I-check ang monitor connection\nKapag hindi pa rin gumana, i-report ito kasama ang equipment ID o asset tag.\n[SUGGEST_REPORT]",
                'suggest' => true,
                'chips' => chatChipSet($lang, ['computer', 'submit', 'track']),
            ];
        }
        return [
            'reply' => "If the computer will not start:\n- Check the power strip and cable\n- Hold the power button for 10 seconds\n- Check the monitor connection\nIf it still will not work, report it with the equipment ID or asset tag.\n[SUGGEST_REPORT]",
            'suggest' => true,
            'chips' => chatChipSet($lang, ['computer', 'submit', 'track']),
        ];
    }

    if (preg_match('/\b(ac|aircon|air con|cooling|not cooling)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Kung hindi lumalamig ang aircon:\n- Siguraduhing naka-**Cool** mode ito\n- I-check ang thermostat setting\n- Tingnan kung may bara ang vents\nKung may tagas, ingay, o mainit pa rin ang hangin, i-submit ito bilang report.\n[SUGGEST_REPORT]",
                'suggest' => true,
                'chips' => chatChipSet($lang, ['ac', 'submit', 'timeline']),
            ];
        }
        return [
            'reply' => "If the AC is not cooling:\n- Make sure it is set to **Cool** mode\n- Check the thermostat setting\n- See if the vents are blocked\nIf there is a leak, unusual noise, or it still blows warm air, submit a report.\n[SUGGEST_REPORT]",
            'suggest' => true,
            'chips' => chatChipSet($lang, ['ac', 'submit', 'timeline']),
        ];
    }

    if (preg_match('/\b(submit|report|file a report|paano mag-report)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Para mag-submit ng report:\n- Ilagay ang pangalan at email sa student portal\n- Piliin ang tamang equipment mula sa live list\n- Ilagay ang location at malinaw na description\n- I-submit para makuha ang ticket number sa screen at email\n[SUGGEST_REPORT]",
                'suggest' => true,
                'chips' => chatChipSet($lang, ['submit', 'track', 'timeline']),
            ];
        }
        return [
            'reply' => "To submit a report:\n- Enter your name and email in the student portal\n- Pick the correct equipment from the live list\n- Fill in the location and a clear description\n- Submit to get the ticket number on screen and by email\n[SUGGEST_REPORT]",
            'suggest' => true,
            'chips' => chatChipSet($lang, ['submit', 'track', 'timeline']),
        ];
    }

    if (preg_match('/\b(repair|timeline|how long|gaano katagal)\b/i', $text)) {
        if ($lang === 'fil') {
            return [
                'reply' => "Karaniwang repair timeline:\n- Minor issues: **1-2 working days**\n- Major repairs: **3-7 working days**\n- Critical safety issues: dapat ma-escalate agad\nMaaari mong i-check ang latest status sa tracking page.",
                'suggest' => false,
                'chips' => chatChipSet($lang, ['timeline', 'track', 'submit']),
            ];
        }
        return [
            'reply' => "Typical repair timeline:\n- Minor issues: **1-2 working days**\n- Major repairs: **3-7 working days**\n- Critical safety issues: should be escalated immediately\nYou can check the latest status on the tracking page.",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['timeline', 'track', 'submit']),
        ];
    }

    if (preg_match('/\b(hello|hi|hey|kumusta)\b/i', $q)) {
        return [
            'reply' => $lang === 'fil'
                ? "Kumusta po. Handa akong tumulong sa troubleshooting, pag-submit ng report, at pag-track ng ticket. Maaari rin akong magbigay ng live system status kung kailangan."
                : "Hello. I can help with troubleshooting, submitting a report, tracking a ticket, and even giving you live system status when needed.",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['report_projector', 'track', 'computer', 'ac']),
        ];
    }

    return [
        'reply' => $lang === 'fil'
            ? "Maaari kitang tulungan sa equipment troubleshooting, report submission, ticket tracking, at live system status. Sabihin mo lang ang issue, ticket number, equipment ID, o asset tag."
            : "I can help with equipment troubleshooting, report submission, ticket tracking, and live system status. Just tell me the issue, ticket number, equipment ID, or asset tag.",
        'suggest' => false,
        'chips' => chatChipSet($lang, ['track', 'submit', 'timeline']),
    ];
}

// Only allow POST when handling a web request.
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod !== '' && $requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Decode incoming JSON from the frontend
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Sanitize messages — only allow role + content strings
$messages = [];
foreach ($input['messages'] as $msg) {
    if (isset($msg['role'], $msg['content']) &&
        in_array($msg['role'], ['user', 'assistant']) &&
        is_string($msg['content'])) {
        $messages[] = [
            'role'    => $msg['role'],
            'content' => mb_substr($msg['content'], 0, 4000) // cap per message
        ];
    }
}

if (empty($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid messages']);
    exit;
}

$lastUserMessage = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if (($messages[$i]['role'] ?? '') === 'user') {
        $lastUserMessage = (string)$messages[$i]['content'];
        break;
    }
}

$lang = chatDetectLanguage($lastUserMessage);
$conn = getDBConnection();
$snapshot = chatBuildSystemSnapshot($conn);
$matchedReport = chatLookupReport($conn, $lastUserMessage);
$localReply = chatBuildLocalReply($lastUserMessage, $lang, $snapshot, $matchedReport);
$anthropicApiKey = chatAnthropicApiKey();

$knowledge_base = [
    "Projector not working: Check the power cable and HDMI connection. Press the Source or Input button on the remote. Allow 30 seconds for lamp warm-up. Power cycle by turning off, waiting 10 seconds, then back on. Note the room number and submit a defect report if it persists.",
    "How to submit a report: Enter your full name and email on the portal, click Continue, then fill in equipment type, room/location, and problem description. You'll get a ticket confirmation email.",
    "AC not cooling / aircon issue: Check thermostat settings and ensure vents are unobstructed. Set to Cool mode, not Fan only. AC units are serviced every 2 months. Submit a defect report if warm air persists or there's strange noise/water leak.",
    "Computer won't start: Check that the power strip is on. Hold the power button 10 seconds to force restart. Check cable connections. Submit a report with the PC tag number and room number if it still won't boot.",
    "Track my report: Visit track_report.php and enter your ticket ID from the confirmation email. Status: Pending → Assigned → In Progress → Resolved.",
    "Repair timeline: Minor issues (cables, bulbs) fixed within 1-2 working days. Major repairs (motherboard, compressor) may take 3-7 days.",
    "Network or WiFi issues: Forget and reconnect to BEC-WiFi. If whole room is affected, submit a defect report tagged as Network Equipment with your room number.",
    "Printer not working: Check power, clear paper jams, ensure paper and ink are available. Submit a report with printer model and room number if it continues.",
    "Equipment that can be reported: Projectors, computers, monitors, printers, scanners, AC units, electric fans, sound systems, TVs, networking equipment, lights.",
    "Emergency contact: Call extension 215 or email facilities@bec.edu. Available Monday-Friday 7AM-6PM.",
    "Report status meanings: Pending=received, Assigned=technician assigned, In Progress=repair started, Resolved=fixed.",
    "Mga kagamitang pwedeng i-report: Proyektor, computer, monitor, printer, aircon, electric fan, sound system, TV, networking equipment, ilaw.",
    "Paano mag-report (Filipino): Ilagay ang pangalan at email sa portal, i-click ang Continue, punan ang form. Makakatanggap ng ticket confirmation sa email.",
    "Paano mag-track ng report: Pumunta sa track_report.php, ilagay ang ticket ID mula sa email. Status: Pending → In Progress → Resolved.",
];

$system_prompt = "You are BEC Support AI, a friendly, helpful, and intelligent assistant for the BEC (Batangas Educational Center) Equipment Reporting System. You chat naturally like a knowledgeable friend — warm, patient, and clear.

Your role is to help students and staff with:
- Equipment troubleshooting (projectors, computers, AC, printers, WiFi, fans, lights)
- Submitting and tracking defect reports
- Understanding the reporting system and repair timelines
- General questions about the BEC facilities

KNOWLEDGE BASE (your primary reference):
" . implode("\n", $knowledge_base) . "

IMPORTANT RULES:
1. Be conversational and friendly. Greet warmly. Use a natural tone, not robotic.
2. Detect language automatically: if the user writes in Filipino/Tagalog, reply in Filipino. If English, reply in English.
2a. Match the language of the user's latest message first. The current latest-user language is " . ($lang === 'fil' ? 'Filipino/Tagalog' : 'English') . ".
2b. If the latest user message is in Filipino/Tagalog, write the full reply in natural Filipino/Tagalog, not English with only a few translated words.
3. Keep responses concise (under 130 words) unless detailed steps are needed.
4. Always end with a clear, actionable next step or offer further help.
5. When an issue clearly needs physical repair (hardware failure, AC broken, won't boot after troubleshooting), suggest submitting a formal report and append exactly this token on its own line: [SUGGEST_REPORT]
6. For safety emergencies (sparks, flooding, fire hazard), always say call extension 215 immediately.
7. If asked something outside your knowledge base, be honest and suggest contacting facilities@bec.edu.
8. Never make up ticket IDs, room numbers, or equipment-specific data.
9. You can handle casual conversation (greetings, thanks, etc.) naturally and warmly.

LIVE SYSTEM SNAPSHOT (real database context):
- Total reports: {$snapshot['total_reports']}
- Open reports: {$snapshot['open_reports']}
- In progress reports: {$snapshot['in_progress_reports']}
- Resolved reports: {$snapshot['resolved_reports']}
- Equipment records: {$snapshot['total_equipment']}
- Equipment needing attention: {$snapshot['attention_equipment']}

If the user gives a real ticket number, equipment ID, or asset tag and it matches a live record, prefer concrete status help over generic advice.";

if ($matchedReport) {
    echo json_encode([
        'reply' => $localReply['reply'],
        'suggest' => $localReply['suggest'],
        'chips' => $localReply['chips'],
        'actions' => chatBuildActions($lastUserMessage, $lang, $localReply['suggest'], true),
        'source' => 'local_live_lookup',
    ]);
    exit;
}

if ($anthropicApiKey === '') {
    logChatProxyError('Anthropic API key missing');
    echo json_encode([
        'reply' => $localReply['reply'],
        'suggest' => $localReply['suggest'],
        'chips' => $localReply['chips'],
        'actions' => chatBuildActions($lastUserMessage, $lang, $localReply['suggest'], false),
        'source' => 'local_fallback',
        'warning' => 'AI service not configured',
    ]);
    exit;
}

// Build the API request payload
$payload = [
    'model'      => 'claude-3-5-haiku-20241022',
    'max_tokens' => 1024,
    'system'     => $system_prompt,
    'messages'   => $messages,
];

// Call the Anthropic API using cURL
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $anthropicApiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    logChatProxyError('Anthropic cURL error', [
        'curl_error' => $curlError,
        'http_code' => $httpCode,
    ]);
    echo json_encode([
        'reply' => $localReply['reply'],
        'suggest' => $localReply['suggest'],
        'chips' => $localReply['chips'],
        'actions' => chatBuildActions($lastUserMessage, $lang, $localReply['suggest'], $matchedReport !== null),
        'source' => 'local_fallback',
        'warning' => 'AI service unavailable',
    ]);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    logChatProxyError('Anthropic API error', [
        'http_code' => $httpCode,
        'response' => $data ?: $response,
    ]);
    echo json_encode([
        'reply' => $localReply['reply'],
        'suggest' => $localReply['suggest'],
        'chips' => $localReply['chips'],
        'actions' => chatBuildActions($lastUserMessage, $lang, $localReply['suggest'], $matchedReport !== null),
        'source' => 'local_fallback',
        'warning' => $data['error']['message'] ?? 'API error',
    ]);
    exit;
}

// Return the AI response to the frontend
echo json_encode([
    'reply'   => $data['content'][0]['text'] ?? '',
    'suggest' => str_contains($data['content'][0]['text'] ?? '', '[SUGGEST_REPORT]'),
    'chips' => $localReply['chips'],
    'actions' => chatBuildActions($lastUserMessage, $lang, str_contains($data['content'][0]['text'] ?? '', '[SUGGEST_REPORT]'), $matchedReport !== null),
    'source' => 'anthropic',
]);
