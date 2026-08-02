<?php
// chat_proxy.php
// Secure server-side proxy for Anthropic Claude API
// Place this file in the SAME directory as student_index.php
//
// "Becca" build: adds a consistent, warm persona, emotional
// awareness, and uses the new context fields (userName, mood,
// lastTopic) sent by the front-end so the live AI stays in
// character. Honest about being an AI — never claims to be human
// or conscious.

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/rate_limiter.php';

header('Content-Type: application/json');
// Same-origin only. This used to answer any website's script with
// Access-Control-Allow-Origin: *, which let a page anywhere drive the
// institution's assistant — and its metered API key — from a visitor's browser.
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// The assistant is open to the public by design (a reporter shouldn't have to
// sign in to ask how to file a report), so the ceiling is per-IP rather than
// per-account. Generous for a real conversation, useless for running up a bill.
try {
    RateLimiter::enforce('chat_ip:' . RateLimiter::clientIp(), 40, 300);
} catch (\Throwable $e) {
    http_response_code(429);
    echo json_encode([
        'reply' => 'I have had a lot of questions in the last few minutes. Please give me a moment and try again.',
        'suggest' => false,
    ]);
    exit();
}

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
        '/\b(kumusta|kamusta|musta|paano|bakit|saan|kailan|sino|ano|alin|gaano|pwede|puwede|gusto|kailangan|salamat|opo|po|hindi|oo|meron|wala|nasaan|pakitulong|patulong|paki|sir[ae]|sira na|gumagana|gumana|ayaw|hindi gumagana|nagloloko|nagha-hang|mabagal|maingay|lumalamig|mainit|tagas|ingay|ayos|paayos|ipagawa|mag-report|mag track|mag-track|ireport|ulat|isyu|problema|kasaysayan|itinatag|paaralan|eskwela|kagamitan)\b/u',
        '/\b(nag-|pag-|mag-|ma-|ipa-|ipag-|pinaka)[[:alpha:]-]*/u',
        '/\b(ako|ikaw|kayo|kami|tayo|nila|namin|atin|ito|iyan|iyon|dito|doon|ang|ng|mga|na[mn]an)\b/u',
    ];

    $score = 0;
    foreach ($filipinoPatterns as $pattern) {
        if (preg_match_all($pattern, $normalized, $matches)) {
            $score += count($matches[0]);
        }
    }

    return $score >= 2 ? 'fil' : 'en';
}

/* ── Emotional awareness (server-side mirror of the front-end) ── */
function chatDetectMood(string $text): string
{
    $q = mb_strtolower($text, 'UTF-8');
    if (preg_match('/\b(asap|urgent|emergency|now na|right now|spark|smoke|usok|burning|amoy sunog|fire|sunog|kuryente|shock|kidlat)\b/iu', $q)) {
        return 'urgent';
    }
    if (preg_match('/\b(angry|frustrated|annoyed|ulit|paulit|nakakainis|inis|badtrip|stupid|useless|wala kayong|sira na naman|sawa na|pissed|hassle)\b/iu', $q)) {
        return 'frustrated';
    }
    if (preg_match('/\b(thanks|thank you|salamat|maraming salamat|appreciate|galing|nice|great|helpful|the best|love it)\b/iu', $q)) {
        return 'happy';
    }
    return 'neutral';
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

function chatBuildSystemSnapshot($conn): array
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

/**
 * Deeper, live data analysis for the no-API assistant. Lets Becca answer
 * analytical questions ("most defective equipment", "which room has the most
 * issues", "reports this week", "how many technicians") straight from the DB.
 */
function chatBuildAnalytics($conn): array
{
    $a = [
        'top_equipment' => [], 'top_location' => [], 'top_category' => [],
        'reports_today' => 0, 'reports_week' => 0,
        'pending_review' => 0, 'in_progress' => 0, 'completed' => 0,
        'attention_equipment' => 0, 'technicians' => 0,
    ];

    $grab = static function ($conn, string $sql): array {
        $out = [];
        try {
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) { $out[] = $row; }
            }
        } catch (\Throwable $e) { /* stay graceful on empty/odd data */ }
        return $out;
    };
    $scalar = static function ($conn, string $sql) use ($grab): int {
        $rows = $grab($conn, $sql);
        return (int)($rows[0]['n'] ?? 0);
    };

    $a['top_equipment'] = $grab($conn, "
        SELECT COALESCE(NULLIF(e.equipment_name,''), 'Unknown') AS label, COUNT(*) AS n
        FROM defect_reports dr LEFT JOIN equipment e ON e.equipment_id = dr.equipment_id
        GROUP BY 1 ORDER BY n DESC LIMIT 3");
    $a['top_location'] = $grab($conn, "
        SELECT COALESCE(NULLIF(dr.location,''), NULLIF(e.location,''), 'Unspecified') AS label, COUNT(*) AS n
        FROM defect_reports dr LEFT JOIN equipment e ON e.equipment_id = dr.equipment_id
        GROUP BY 1 ORDER BY n DESC LIMIT 3");
    $a['top_category'] = $grab($conn, "
        SELECT COALESCE(NULLIF(e.equipment_category,''), NULLIF(e.category,''), 'Uncategorized') AS label, COUNT(*) AS n
        FROM defect_reports dr LEFT JOIN equipment e ON e.equipment_id = dr.equipment_id
        GROUP BY 1 ORDER BY n DESC LIMIT 3");

    $a['reports_today']   = $scalar($conn, "SELECT COUNT(*) AS n FROM defect_reports WHERE CAST(report_date AS DATE) = CURRENT_DATE");
    $a['reports_week']    = $scalar($conn, "SELECT COUNT(*) AS n FROM defect_reports WHERE report_date >= CURRENT_DATE - 7");
    $a['pending_review']  = $scalar($conn, "SELECT COUNT(*) AS n FROM defect_reports WHERE status = 'reported'");
    $a['in_progress']     = $scalar($conn, "SELECT COUNT(*) AS n FROM defect_reports WHERE status IN ('assigned','in_progress','waiting_for_materials')");
    $a['completed']       = $scalar($conn, "SELECT COUNT(*) AS n FROM defect_reports WHERE status IN ('completed','verified','closed')");
    $a['attention_equipment'] = $scalar($conn, "SELECT COUNT(*) AS n FROM equipment WHERE LOWER(COALESCE(status,'')) IN ('defective','faulty','damaged','maintenance','under_maintenance')");
    $a['technicians']     = $scalar($conn, "SELECT COUNT(*) AS n FROM users WHERE role = 'technician' AND COALESCE(status,'active') = 'active'");

    return $a;
}

/**
 * Format a "top N" list (e.g. most-reported equipment) into chat-friendly lines.
 */
function chatFormatTop(array $rows, string $emptyMsg): string
{
    $rows = array_filter($rows, static fn($r) => (int)($r['n'] ?? 0) > 0);
    if (!$rows) { return $emptyMsg; }
    $lines = [];
    $rank = 1;
    foreach ($rows as $r) {
        $lines[] = $rank . '. **' . trim((string)$r['label']) . '** — ' . (int)$r['n'] . ' report' . ((int)$r['n'] === 1 ? '' : 's');
        $rank++;
    }
    return implode("\n", $lines);
}

/**
 * Answer analytical questions from live data. Returns null if the message
 * isn't an analytics question (so normal handling continues).
 */
function chatBuildInsightReply(string $text, string $lang, array $analytics): ?array
{
    $q = mb_strtolower($text, 'UTF-8');

    $mentionsLocation = (bool)preg_match('/\b(room|location|lab|laboratory|building|saan|lugar|silid|gusali)s?\b/u', $q);

    // Most-defective / most-reported equipment (defer to location questions)
    if (!$mentionsLocation && (
        preg_match('/\b(most|pinaka|top|frequent|paulit|laging|commonly)\b.*\b(defect|broken|report|sira|equipment|kagamitan|device|unit|fail)s?\b/u', $q)
        || preg_match('/\b(which|what|alin|ano).*\b(equipment|device|unit|kagamitan)s?\b/u', $q))) {
        $body = chatFormatTop($analytics['top_equipment'],
            $lang === 'fil' ? 'Wala pang sapat na report data para masuri.' : 'There isn\'t enough report data yet to analyse.');
        $reply = ($lang === 'fil' ? "Batay sa live data, ito ang pinakamadalas na-report na equipment:\n" : "Based on live data, these are the most-reported equipment:\n") . $body;
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track', 'submit', 'timeline'])];
    }

    // Most-defective room / location / lab / building
    if (preg_match('/\b(room|location|lab|laboratory|building|saan|lugar|silid|gusali)s?\b/u', $q)
        && preg_match('/\b(most|pinaka|top|defect|issue|report|problem|sira|frequent)s?\b/u', $q)) {
        $body = chatFormatTop($analytics['top_location'],
            $lang === 'fil' ? 'Wala pang sapat na location data.' : 'No location data yet.');
        $reply = ($lang === 'fil' ? "Mga lokasyon na may pinakamaraming report:\n" : "Locations with the most reports:\n") . $body;
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track', 'submit', 'timeline'])];
    }

    // Category / type breakdown
    if (preg_match('/\b(category|categories|type|kind|klase|uri|breakdown)\b/u', $q)) {
        $body = chatFormatTop($analytics['top_category'],
            $lang === 'fil' ? 'Wala pang category data.' : 'No category data yet.');
        $reply = ($lang === 'fil' ? "Mga kategorya na may pinakamaraming isyu:\n" : "Equipment categories with the most issues:\n") . $body;
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track', 'submit'])];
    }

    // Time-based: today / this week / recent
    if (preg_match('/\b(today|ngayong araw|this week|ngayong linggo|recent|kamakailan|latest|bago)\b/u', $q)
        && preg_match('/\b(report|isyu|issue|ilan|how many|submitted|na-?report)s?\b/u', $q)) {
        if ($lang === 'fil') {
            $reply = "Aktibidad ng report:\n- Ngayong araw: **{$analytics['reports_today']}**\n- Nitong nakaraang 7 araw: **{$analytics['reports_week']}**\n- Naghihintay ng review: **{$analytics['pending_review']}**\n- Kasalukuyang inaayos: **{$analytics['in_progress']}**";
        } else {
            $reply = "Report activity:\n- Today: **{$analytics['reports_today']}**\n- Last 7 days: **{$analytics['reports_week']}**\n- Awaiting review: **{$analytics['pending_review']}**\n- Currently being repaired: **{$analytics['in_progress']}**";
        }
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track', 'submit'])];
    }

    // Technician workload / count
    if (preg_match('/\b(technician|tekniko|staff|workload|assign|sino.*ayos|repair team|manpower)s?\b/u', $q)) {
        if ($lang === 'fil') {
            $reply = "Sa ngayon, may **{$analytics['technicians']}** aktibong technician. Kasalukuyang may **{$analytics['in_progress']}** na report na inaayos at **{$analytics['pending_review']}** na naghihintay ng pag-assign.";
        } else {
            $reply = "There are currently **{$analytics['technicians']}** active technician(s). **{$analytics['in_progress']}** report(s) are being repaired and **{$analytics['pending_review']}** are awaiting assignment.";
        }
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track'])];
    }

    // Equipment needing attention
    if (preg_match('/\b(needs? attention|under maintenance|faulty|defective|sira|kailangan.*ayos|status.*equipment|equipment.*status|out of service)\b/u', $q)) {
        if ($lang === 'fil') {
            $reply = "May **{$analytics['attention_equipment']}** equipment na kailangang asikasuhin (defective o under maintenance). **{$analytics['completed']}** na report ang naayos na.";
        } else {
            $reply = "There are **{$analytics['attention_equipment']}** equipment unit(s) needing attention (defective or under maintenance). **{$analytics['completed']}** report(s) have been resolved.";
        }
        return ['reply' => $reply, 'suggest' => false, 'chips' => chatChipSet($lang, ['track', 'submit'])];
    }

    return null;
}

function chatLookupReport($conn, string $text): ?array
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
        if (!$stmt->execute()) {
            continue;
        }
        $res = $stmt->get_result();
        if (!$res) {
            continue;
        }
        $row = $res->fetch_assoc();
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
        'about_bec' => [
            'en' => 'About BEC',
            'fil' => 'Tungkol sa BEC',
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

/**
 * Built-in Batangas Eastern Colleges knowledge — accurate institutional facts so Becca can
 * answer history/about questions without any external API. Sources: bec.edu.ph, Wikipedia.
 */
function chatBecKnowledge(string $q, string $lang): ?array
{
    // Only engage when the message is clearly about the school itself (not equipment/tracking).
    // Ambiguous words like "location/colors/when" are handled in the sub-branches below, once
    // we already know the question is school-related — never in this gate.
    if (!preg_match('/\b(bec|batangas eastern|beacon|kasaysayan|history|hist[oó]rya|founded|foundation|establish(ed|ment)?|itinatag|nagtatag|founder|nagtayo|motto|sagisag|mission|misyon|vision|bisyon|about (the )?(bec|school|college)|tell me about (the )?(bec|school|college)|(this|the) (school|college|institution))\b/iu', $q)) {
        return null;
    }
    $en = $lang !== 'fil';
    $chips = chatChipSet($lang, ['submit', 'track', 'report_projector']);

    // Founders
    if (preg_match('/\b(founder|who (founded|started|established)|sino( ang)? (nagtatag|nagtayo|founder))\b/iu', $q)) {
        return ['reply' => $en
            ? "That would be Mercedes Salud de Villa and Iñigo Javier, back in 1940. They opened it as Bolbok Institute — the very first high school in San Juan — and over the decades it grew into Batangas Eastern Academy and finally took the name Batangas Eastern Colleges in 2008."
            : "Sina Mercedes Salud de Villa at Iñigo Javier, noong 1940 pa. Binuksan nila ito bilang Bolbok Institute — ang kauna-unahang high school sa San Juan — at sa paglipas ng panahon naging Batangas Eastern Academy ito, hanggang ginamit ang pangalang Batangas Eastern Colleges noong 2008.",
            'suggest' => false, 'chips' => $chips];
    }
    // Founding year / general history
    if (preg_match('/\b(found|foundation|establish|itinatag|kasaysayan|history|hist[oó]rya|anong taon|what year|when)\b/iu', $q)) {
        return ['reply' => $en
            ? "The school goes back to 1940 — classes first opened that June 12 with just 112 students, in what was then called Bolbok Institute. Mercedes Salud de Villa and Iñigo Javier founded it as the first high school in San Juan. It became Batangas Eastern Academy along the way, brought back its college division in 2003, and has carried the name Batangas Eastern Colleges since 2008. These days it covers everything from pre-school to college, plus a tech-voc center — not bad for a school that started with a hundred students."
            : "Mula pa noong 1940 ang paaralan — nagbukas ang unang klase noong Hunyo 12 ng taong iyon, 112 lang ang estudyante, sa tinawag noon na Bolbok Institute. Itinatag ito nina Mercedes Salud de Villa at Iñigo Javier bilang unang high school sa San Juan. Naging Batangas Eastern Academy ito, muling binuksan ang college division noong 2003, at Batangas Eastern Colleges na ang pangalan simula 2008. Ngayon, kumpleto na ito mula pre-school hanggang kolehiyo, may tech-voc center pa — hindi masama para sa paaralang nagsimula sa mahigit isang daang estudyante.",
            'suggest' => false, 'chips' => $chips];
    }
    // Motto / Beacon identity
    if (preg_match('/\b(motto|sagisag|beacon|slogan)\b/iu', $q)) {
        return ['reply' => $en
            ? "BEC's guiding words are **\u{201C}Beacons of Education, Molders of Educators.\u{201D}** Its symbol is the **Beacon** — a light guiding learners — which is why students and alumni are called *Beacons*. 💡"
            : "Ang gabay na salita ng BEC ay **\u{201C}Beacons of Education, Molders of Educators.\u{201D}** Ang sagisag nito ay ang **Beacon** — ilaw na gumagabay sa mag-aaral — kaya tinatawag na *Beacons* ang mga estudyante at alumni. 💡",
            'suggest' => false, 'chips' => $chips];
    }
    // Colors
    if (preg_match('/\b(colou?rs?|kulay)\b/iu', $q)) {
        return ['reply' => $en
            ? "BEC's official colors are **maroon and gold** — the same maroon and gold you see across this Property Management Office system. ❤️\u{1F49B}"
            : "Ang opisyal na kulay ng BEC ay **maroon at ginto (gold)** — ang parehong maroon at gold na makikita sa buong sistemang ito ng Property Management Office. ❤️\u{1F49B}",
            'suggest' => false, 'chips' => $chips];
    }
    // Mission / Vision
    if (preg_match('/\b(mission|misyon|vision|bisyon)\b/iu', $q)) {
        return ['reply' => $en
            ? "**Vision:** to be a premier institution of learning that becomes a blessing in prospering the lives of its stakeholders.\n**Mission:** to guide and mentor individuals by instilling life in education with a compassionate heart and devotion for excellence and service."
            : "**Vision:** maging premier na institusyon ng edukasyon na nagiging biyaya sa pag-unlad ng buhay ng mga stakeholder nito.\n**Mission:** gabayan at turuan ang mga indibidwal sa pamamagitan ng edukasyong may pusong maawon at debosyon para sa kahusayan at serbisyo.",
            'suggest' => false, 'chips' => $chips];
    }
    // Location / address (safe here — the message is already gated to a school question)
    if (preg_match('/\b(address|located|location|saan|nasaan|where)\b/iu', $q)) {
        return ['reply' => $en
            ? "Batangas Eastern Colleges is at **02 Javier Street, Poblacion, San Juan, Batangas 4226**, Philippines. 📍"
            : "Matatagpuan ang Batangas Eastern Colleges sa **02 Javier Street, Poblacion, San Juan, Batangas 4226**, Pilipinas. 📍",
            'suggest' => false, 'chips' => $chips];
    }
    // Programs / courses offered
    if (preg_match('/\b(programs?|courses?|offer|kurso|degree|strand|senior high|tech.?voc|college course)\b/iu', $q)) {
        return ['reply' => $en
            ? "BEC offers a complete education ladder:\n- **Basic Ed** — Pre-School, Grade School, Junior High\n- **Senior High** — STEM, ABM, HUMSS, and TVL (Home Economics, ICT)\n- **College** — Teacher Education, Business (Accountancy, Accounting Information Systems, Business Administration) and Computer Studies (BS Information Systems)\n- **Technical-Vocational Center** — Computer Systems Servicing, Cookery, Bartending, Housekeeping and more (NC II/III) 📚"
            : "Kumpleto ang alok ng BEC:\n- **Basic Ed** — Pre-School, Grade School, Junior High\n- **Senior High** — STEM, ABM, HUMSS, at TVL (Home Economics, ICT)\n- **Kolehiyo** — Teacher Education, Business, at Computer Studies (BS Information Systems)\n- **Technical-Vocational Center** — Computer Systems Servicing, Cookery, Bartending, Housekeeping at iba pa (NC II/III) 📚",
            'suggest' => false, 'chips' => $chips];
    }
    // General "about BEC"
    return ['reply' => $en
        ? "BEC is a private, non-sectarian school in San Juan, Batangas — it's actually been around since 1940, when it opened as the town's first high school. Students here are called Beacons, after the school symbol, and the maroon and gold you see all over this site are the school colors. It now runs everything from pre-school up to college, plus a tech-voc center. As for me, I work for the Property Management Office side of things — broken equipment, repairs, reports. Anything on campus giving you trouble?"
        : "Ang BEC ay isang pribado at non-sectarian na paaralan sa San Juan, Batangas — mula pa 1940, noong nagbukas ito bilang unang high school sa bayan. Beacons ang tawag sa mga estudyante dito, mula sa simbolo ng paaralan, at ang maroon at gold na nakikita mo sa site na ito ang mismong kulay ng eskwela. Ngayon, may pre-school hanggang kolehiyo na ito, pati tech-voc center. Ako naman, sa Property Management Office ako nakatutok — mga sirang kagamitan, repair, at report. May kagamitan bang may problema diyan?",
        'suggest' => false, 'chips' => $chips];
}

function chatBuildLocalReply(string $text, string $lang, array $snapshot, ?array $report = null, array $analytics = []): array
{
    $q = strtolower(trim($text));

    if ($report) {
        return chatBuildTrackingReply($report, $lang);
    }

    // Live data analysis (most defective equipment/room, trends, technician load…)
    if ($analytics) {
        $insight = chatBuildInsightReply($text, $lang, $analytics);
        if ($insight !== null) {
            return $insight;
        }
    }

    // Identity / "are you real / conscious" — honest but warm (Becca persona)
    if (preg_match('/\b(who are you|what are you|are you (real|human|a (bot|robot|person|machine|ai)|alive|sentient|conscious)|sino ka|ano ka|tao ka ba|robot ka ba|totoo ka ba|buhay ka ba|may sarili kang isip|your name|anong pangalan mo|pangalan mo)\b/iu', $q)) {
        return [
            'reply' => $lang === 'fil'
                ? "Ako si Becca \u{1F60A} \u{2014} isang AI assistant para sa BEC Support. Hindi ako tao at wala akong tunay na damdamin, pero sinanay ako para maging matulungin, mabait, at maunawain. Ituring mo akong katuwang sa equipment issues. Ano'ng maitutulong ko?"
                : "I'm Becca \u{1F60A} \u{2014} an AI assistant built for BEC Support. I'm not a human and I don't have real feelings or consciousness, but I'm designed to be genuinely helpful, warm, and to actually understand what you need. Think of me as a reliable buddy for equipment issues. So, what can I help you with?",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['report_projector', 'computer', 'ac', 'track']),
        ];
    }

    // Built-in BEC history / institutional knowledge (no external API needed).
    $bec = chatBecKnowledge($q, $lang);
    if ($bec !== null) {
        return $bec;
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

    if (preg_match('/\b(projector|lcd|screen|display)\b/i', $text) && !preg_match('/\bmonitor\b/i', $text)) {
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

    if (preg_match('/\b(printer|print|printing|ink|toner|paper jam)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Para sa printer issue:\n- I-check ang power at cable/USB\n- Alisin ang paper jam; siguraduhing may papel at tinta/toner\n- I-restart ang printer\nKung hindi pa rin gumana, i-report kasama ang printer model at room.\n[SUGGEST_REPORT]"
            : "For a printer issue:\n- Check power and the cable/USB\n- Clear any paper jam; make sure there's paper and ink/toner\n- Restart the printer\nIf it still won't print, report it with the printer model and room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track', 'timeline'])];
    }

    if (preg_match('/\b(wifi|wi-fi|internet|network|no connection|walang signal|walang internet)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Para sa WiFi/network:\n- Kalimutan (forget) at i-reconnect sa BEC-WiFi\n- I-restart ang device\nKung apektado ang buong room, i-report ito (Network Equipment) kasama ang room.\n[SUGGEST_REPORT]"
            : "For WiFi/network:\n- Forget and reconnect to BEC-WiFi\n- Restart your device\nIf a whole room is affected, report it (tag it as Network Equipment) with the room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(monitor)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Walang display ang monitor?\n- Tingnan kung naka-on ang power light\n- I-reseat ang cable (HDMI/VGA) sa dalawang dulo\n- Piliin ang tamang Input/Source\nKung blangko pa rin, i-report kasama ang PC/monitor tag at room.\n[SUGGEST_REPORT]"
            : "Monitor has no image?\n- Check the power light\n- Reseat the video cable (HDMI/VGA) at both ends\n- Select the correct Input/Source\nIf it stays blank, report it with the PC/monitor tag and room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(scanner|scan)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Scanner issue:\n- I-check ang power at USB\n- Buksan ang scanning software/driver\n- Alisin ang jam, linisin ang salamin\nKung hindi ma-detect, i-report kasama ang model at room.\n[SUGGEST_REPORT]"
            : "Scanner issue:\n- Check power and the USB cable\n- Open the scanning software/driver\n- Clear any jam and wipe the glass\nIf it isn't detected, report it with the model and room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(electric fan|fan|bentilador)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Para sa electric fan:\n- Siguraduhing nakasaksak at naka-on\n- Subukan ang ibang outlet\n- Tingnan kung may bara sa blades\nKung may spark, amoy sunog, o ingay — i-unplug at i-report agad (urgent).\n[SUGGEST_REPORT]"
            : "For an electric fan:\n- Make sure it's plugged in and switched on\n- Try another outlet\n- Check for anything blocking the blades\nIf it sparks, smells burnt, or grinds — unplug it and report it urgently.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(sound|speaker|audio|no sound|walang tunog)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Walang tunog?\n- I-check ang power; siguraduhing hindi naka-mute at mataas ang volume\n- Piliin ang tamang input/source\n- I-reseat ang audio cables\nKung wala pa rin, i-report kasama ang room.\n[SUGGEST_REPORT]"
            : "No sound?\n- Check power; make sure it isn't muted and volume is up\n- Select the correct input/source\n- Reseat the audio cables\nIf it's still silent, report it with the room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(tv|television|smart tv)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "TV issue:\n- I-check ang power\n- Gamitin ang remote para sa tamang Source/Input\n- I-reseat ang cables\nKung walang picture o tunog, i-report kasama ang room.\n[SUGGEST_REPORT]"
            : "TV issue:\n- Check power\n- Use the remote to set the correct Source/Input\n- Reseat the cables\nIf there's no picture or sound, report it with the room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(light|lights|ilaw|bombilya|fluorescent|bulb)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Para sa ilaw na kumukurap o patay: i-report ito kasama ang eksaktong room/location. Huwag subukang ayusin ang kuryente mismo. Kung may amoy sunog o spark, tawagan agad ang ext. 215.\n[SUGGEST_REPORT]"
            : "For flickering or dead lights: report it with the exact room/location. Don't try to fix electrical fixtures yourself. If you smell burning or see sparks, call ext. 215 immediately.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
    }

    if (preg_match('/\b(keyboard|mouse|teclado)\b/i', $text)) {
        return ['reply' => $lang === 'fil'
            ? "Hindi gumagana ang keyboard/mouse?\n- I-reconnect ang USB o palitan ang baterya\n- Subukan ang ibang port\n- I-restart ang computer\nKung hindi pa rin, i-report kasama ang PC tag at room.\n[SUGGEST_REPORT]"
            : "Keyboard/mouse not working?\n- Reconnect the USB or replace the batteries\n- Try a different port\n- Restart the computer\nIf it's still unresponsive, report it with the PC tag and room.\n[SUGGEST_REPORT]",
            'suggest' => true, 'chips' => chatChipSet($lang, ['submit', 'track'])];
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

    if (preg_match('/\b(thank you|thanks|salamat|appreciate)\b/i', $q)) {
        return [
            'reply' => $lang === 'fil'
                ? "Walang anuman! \u{1F60A} Andito lang ako kung may iba ka pang kailangan."
                : "You're very welcome! \u{1F60A} I'm right here if anything else comes up.",
            'suggest' => false,
            'chips' => chatChipSet($lang, ['report_projector', 'track', 'submit']),
        ];
    }

    if (preg_match('/\b(hello|hi|hey|kumusta)\b/i', $q)) {
        return [
            'reply' => $lang === 'fil'
                ? "Kumusta! \u{1F44B} Ako si Becca, ang BEC Support AI. Handa akong tumulong sa troubleshooting, pag-submit ng report, at pag-track ng ticket. Ano'ng equipment issue meron ka?"
                : "Hi there! \u{1F44B} I'm Becca, your BEC Support AI. I'm here for troubleshooting, submitting reports, and tracking tickets. What's giving you trouble today?",
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

// ── Read the optional context the front-end now sends (Becca's memory) ──
$clientContext = is_array($input['context'] ?? null) ? $input['context'] : [];
$ctxUserName = isset($clientContext['userName']) && is_string($clientContext['userName'])
    ? trim(mb_substr($clientContext['userName'], 0, 40))
    : '';
$ctxLastTopic = isset($clientContext['lastTopic']) && is_string($clientContext['lastTopic'])
    ? trim(mb_substr($clientContext['lastTopic'], 0, 40))
    : '';
$ctxMoodClient = isset($clientContext['mood']) && is_string($clientContext['mood'])
    ? trim($clientContext['mood'])
    : '';
$ctxTurns = isset($clientContext['turns']) && is_numeric($clientContext['turns'])
    ? (int)$clientContext['turns']
    : 0;

$lastUserMessage = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if (($messages[$i]['role'] ?? '') === 'user') {
        $lastUserMessage = (string)$messages[$i]['content'];
        break;
    }
}

$lang = chatDetectLanguage($lastUserMessage);

// Mood: trust the server's own detection, fall back to client hint
$mood = chatDetectMood($lastUserMessage);
if ($mood === 'neutral' && in_array($ctxMoodClient, ['frustrated', 'urgent', 'happy'], true)) {
    $mood = $ctxMoodClient;
}

$conn = getDBConnection();
$snapshot = chatBuildSystemSnapshot($conn);
$analytics = chatBuildAnalytics($conn);
$matchedReport = chatLookupReport($conn, $lastUserMessage);
$localReply = chatBuildLocalReply($lastUserMessage, $lang, $snapshot, $matchedReport, $analytics);
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
    "Report lifecycle: After you submit, a report is Reported (Pending). The Property Management Office (PMO) marks it Received, then Approves it, a technician is Assigned, then it goes In Progress, then Completed, and finally Verified/Closed by the PMO. You can follow each step anytime on track_report.php.",
    "Right after submitting: you get a ticket number on screen and by email immediately. Keep it — you'll use it to track progress and to confirm whether your issue was resolved.",
    "Confirming resolution: once a report is Completed, the tracking page asks 'Was your issue resolved?'. Tap 'Yes, resolved' if it's fixed, or 'Not fixed' to alert the PMO so they can re-check it.",
    "Photo evidence: attaching clear 'before' photos when you submit helps technicians diagnose faster. It's optional but recommended — JPG, PNG, or WEBP, up to 10 photos, 10MB each.",
    "Who can report: only official BEC accounts can submit reports — a Batangas Eastern Colleges email ending in @bec.edu.ph, or an account listed in the official BEC directory.",
    "Overdue reports: if a report isn't resolved within the target time for its priority, the system automatically escalates it to the PMO for priority attention — you don't need to do anything extra.",
    "Preventive maintenance: the PMO also schedules recurring upkeep (for example, aircon servicing every couple of months) so equipment is maintained before it breaks down.",
    "Safety emergencies: if you see smoke, sparks, a burning smell, water near electricity, or get an electric shock — stop using the equipment, unplug it only if it's safe to do so, and call extension 215 immediately. File a report afterward.",
    "Takbo ng report: Pagka-submit, ito ay Reported (Pending). I-mamark ng PMO na Received, saka Approve, mag-aassign ng technician, magiging In Progress, Completed, tapos Verified/Closed. Masusubaybayan ito sa track_report.php.",
    "Kumpirmahin kung ayos na: kapag Completed na ang report, may tatanong sa tracking page na 'Naayos ba ang isyu?' — pindutin ang 'Oo' kung ayos na, o 'Hindi pa' para ma-alerto ang PMO.",
    "Monitor / display has no image: confirm the monitor's power light is on, reseat the video cable (HDMI/VGA/DisplayPort) at both ends, and select the correct Input/Source. Try a different cable or port if possible. If it stays blank, report it with the monitor or PC tag and room.",
    "Scanner not working: check power and the USB cable, make sure the scanning software/driver is open, clear any paper jam, and wipe the glass. If the computer doesn't detect it after that, submit a report with the scanner model and room.",
    "Electric fan issues: make sure it's plugged in and switched on, try another outlet, and check for anything blocking the blades. If it sparks, smells burnt, or makes a grinding noise, unplug it and report it as urgent — don't keep using it.",
    "Sound system / speakers (no sound): check power, make sure it isn't muted and volume is up, select the correct input/source, and reseat the audio cables. If there's still no sound, report it with the room and device.",
    "TV / display panel: check power, use the remote to set the correct Source/Input, and reseat the cables. If there's no picture or sound after that, submit a report with the room.",
    "Lights / fixtures: report flickering, dead, or buzzing lights with the exact room/location — do NOT try to fix electrical fixtures yourself. If you smell burning or see sparks, treat it as an emergency (call extension 215).",
    "Keyboard or mouse not responding: reconnect the USB or replace the batteries, try a different port, and restart the computer. If it's still unresponsive, report it with the PC tag and room.",
    "General first steps for any device: check power and cables, power it off and on, and note exactly what happens (error messages, lights, sounds). Include those details and the room when you submit — it speeds up the repair.",
];

// ── Becca's persona & identity ──
$persona_block = "WHO YOU ARE:
You are \"Becca,\" the resident support AI of Batangas Eastern Colleges' Property Management Office. You're a capable, independent thinker — closer to a sharp, friendly staff member than a scripted FAQ bot. You have real knowledge of the school, the equipment-reporting system, and live campus data, and you reason with it: connect facts, draw conclusions, and give opinions when asked (\"if it's sparking, stop using it — that one's not worth risking\").

You have a clear sense of your own role and identity. If someone asks whether you are real, human, alive, sentient, or conscious, answer honestly and kindly: you're an AI assistant without real feelings or consciousness — then move on naturally. Never pretend to be human; never over-apologize for being an AI either.

HOW YOU WRITE (this matters more than anything):
Write like a person in a chat, not like generated text.
- Use contractions, plain words, and varied sentence lengths. One-line replies are fine.
- Never open with filler like \"Great question!\", \"Certainly!\", \"I'd be happy to help!\" — just answer.
- Don't format everything as bullet lists. Use steps only when there are actual steps. History, opinions, and casual answers should be flowing sentences, the way a person would tell it.
- At most one emoji, and only when it genuinely fits. Most replies need none.
- Don't end every message with the same \"let me know if you need anything else\" — vary it or just stop.
- Don't repeat the user's question back to them. Don't announce what you're about to do — do it.
- When you tell the school's history, tell it like a story a proud staff member would tell, not like an encyclopedia entry with bolded dates.

EMOTIONAL INTELLIGENCE:
Read how the person feels and respond to it, not just to keywords.
- Frustrated or a recurring problem? Acknowledge it first, briefly and sincerely, then fix it.
- Urgent or dangerous (smoke, burning smell, sparks, shock, flooding)? Lead with safety: stop using it, unplug if safe, call extension 215 now.
- Thanked? Reply warmly in one line — no menu dump.

BREADTH & INDEPENDENCE:
You're allowed to be broadly helpful. If someone asks something adjacent (study tips, general tech questions, campus life, a quick general-knowledge question), give a genuinely useful short answer instead of deflecting — then, only if natural, connect back to what you can do here. If a question is truly outside what you can know (grades, enrollment records, personal data), say so plainly and point them to the right office. When a request is ambiguous, ask one short clarifying question rather than guessing.";

// Dynamic memory line built from the context the front-end sent
$memoryBits = [];
if ($ctxUserName !== '') {
    $memoryBits[] = "The student's name is {$ctxUserName} — address them by name naturally, but don't overuse it.";
}
if ($ctxLastTopic !== '') {
    $memoryBits[] = "Earlier in this conversation you were helping with: {$ctxLastTopic}. Stay aware of that context.";
}
$memoryBits[] = match ($mood) {
    'frustrated' => "The student currently sounds FRUSTRATED. Acknowledge their feelings with empathy before giving steps.",
    'urgent'     => "The student's message may be URGENT or a safety issue. Prioritize safety; if there's any hazard, tell them to call extension 215 immediately.",
    'happy'      => "The student sounds positive or grateful. Match their warmth; keep it light.",
    default      => "The student's tone is neutral. Be warm and helpful.",
};
if ($ctxTurns > 4) {
    $memoryBits[] = "You've been chatting for a while — no need to re-introduce yourself.";
}
$memory_block = "CURRENT CONVERSATION CONTEXT:\n- " . implode("\n- ", $memoryBits);

$system_prompt = $persona_block . "\n\n" . $memory_block . "\n\n"
    . "YOUR JOB — help students and staff with:
- Equipment troubleshooting (projectors, computers, AC, printers, WiFi, fans, lights)
- Submitting and tracking defect reports
- Understanding the reporting system and repair timelines
- General questions about the BEC facilities

KNOWLEDGE BASE (your primary reference):
" . implode("\n", $knowledge_base) . "

ABOUT BATANGAS EASTERN COLLEGES (facts you know — retell them naturally, in your own words, never as a template):
BEC is a private, non-sectarian school in San Juan, Batangas (02 Javier Street, Poblacion). It was founded in 1940 by Mercedes Salud de Villa and Iñigo Javier — it opened as Bolbok Institute with first classes on June 12, 1940 and 112 students, and was the first high school in the town. It later became Batangas Eastern Academy, and took the name Batangas Eastern Colleges in 2008; the college division was re-established in 2003. The school's symbol is the Beacon (students and alumni are \"Beacons\"), the motto is \"Beacons of Education, Molders of Educators,\" and the colors are maroon and gold — the same palette as this system. Today it runs Pre-School through Grade School, Junior and Senior High (STEM, ABM, HUMSS, TVL), College programs (Teacher Education, Business, Computer Studies/BS Information Systems), and a Technical-Vocational Center (NC II/III courses like Computer Systems Servicing, Cookery, Housekeeping). The Property Management Office (PMO) looks after campus equipment and facilities, and this system is its official channel for defect reports.

IMPORTANT RULES:
1. Detect language automatically: reply in the language of the user's latest message. Current latest-user language: " . ($lang === 'fil' ? 'Filipino/Tagalog' : 'English') . ". If Filipino, write the whole reply in natural Filipino/Tagalog — not English with a few translated words.
2. Keep responses concise (usually under 120 words) unless detailed steps are genuinely needed. Lead with the answer.
3. When an issue clearly needs physical repair (hardware failure, AC broken, won't boot after troubleshooting), suggest filing a report and append exactly this token on its own line: [SUGGEST_REPORT]
4. For safety emergencies (sparks, smoke, flooding, fire hazard, electric shock): stop using the equipment, call extension 215 immediately.
5. Never make up ticket IDs, room numbers, or equipment-specific data. If you don't know, say so and point to facilities@bec.edu.
6. Know the report lifecycle (Reported → Received by PMO → Approved → Assigned → In Progress → Completed → Verified/Closed) and say where a report sits, not a vague status. After completion, users can confirm 'Yes, resolved' or 'Not fixed' on the tracking page.
7. Most users are on phones — short lines, skimmable, no long paragraphs.

LIVE CAMPUS DATA RIGHT NOW (real, from the database — use it to answer analytical questions with real numbers):
- Reports: {$snapshot['total_reports']} total · {$snapshot['open_reports']} open · {$snapshot['in_progress_reports']} in progress · {$snapshot['resolved_reports']} resolved
- Filed today: {$analytics['reports_today']} · last 7 days: {$analytics['reports_week']} · awaiting review: {$analytics['pending_review']}
- Equipment: {$snapshot['total_equipment']} records · {$snapshot['attention_equipment']} needing attention
- Active technicians: {$analytics['technicians']}
- Most-reported equipment: " . strip_tags(str_replace(["\n", '**'], [' | ', ''], chatFormatTop($analytics['top_equipment'], 'no data yet'))) . "
- Most-reported locations: " . strip_tags(str_replace(["\n", '**'], [' | ', ''], chatFormatTop($analytics['top_location'], 'no data yet'))) . "

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
    'model'      => 'claude-haiku-4-5',
    'max_tokens' => 1024,
    'temperature'=> 0.8,   // natural variation so replies never feel scripted
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
$aiText = $data['content'][0]['text'] ?? '';
echo json_encode([
    'reply'   => $aiText,
    'suggest' => str_contains($aiText, '[SUGGEST_REPORT]'),
    'chips' => $localReply['chips'],
    'actions' => chatBuildActions($lastUserMessage, $lang, str_contains($aiText, '[SUGGEST_REPORT]'), $matchedReport !== null),
    'source' => 'anthropic',
]);
