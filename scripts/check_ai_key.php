<?php
/**
 * scripts/check_ai_key.php — is Becca's model key alive?
 *
 * Run before a demo. Answers three questions in one pass:
 *   1. Is a key configured, and where did it come from?
 *   2. Which models will this key actually serve? (model IDs move; the configured
 *      one 404s silently into the local fallback, which looks like "the AI is dumb
 *      today" rather than an error.)
 *   3. Does a real generation round-trip work?
 *
 * Usage: c:\xampp\php\php.exe scripts\check_ai_key.php
 * Exit 0 = Becca will answer with the live model. Exit 1 = she falls back to her
 * built-in brain (which still works — it is just not the live model).
 */

require_once __DIR__ . '/../includes/ai_client.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

function checkLine(string $status, string $text): void
{
    echo str_pad($status, 8) . $text . PHP_EOL;
}

echo PHP_EOL . "Becca model check" . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

/* 1. Key present? */
$key = aiApiKey();
if ($key === '') {
    checkLine('[FAIL]', 'No key. Set gemini_api_key in config/chat_secrets.php');
    checkLine('', '       or the GEMINI_API_KEY environment variable.');
    checkLine('', '       Get one free at https://aistudio.google.com/apikey');
    echo PHP_EOL . "Becca will answer from her built-in brain only." . PHP_EOL;
    exit(1);
}

$source = getenv('GEMINI_API_KEY') ? 'GEMINI_API_KEY env var' : 'config/chat_secrets.php';
// Never print the key itself - this output gets pasted into chats and tickets.
checkLine('[ OK ]', 'Key found (' . strlen($key) . ' chars, from ' . $source . ')');

$model = aiModel();
checkLine('[INFO]', 'Configured model: ' . $model);

/* 2. Which models does this key serve? */
$ch = curl_init(AI_ENDPOINT_BASE);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key],
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$available = [];
if ($err !== '') {
    checkLine('[WARN]', 'Could not list models: ' . $err);
} elseif ($code !== 200) {
    $body = json_decode((string) $raw, true);
    $message = $body['error']['message'] ?? 'HTTP ' . $code;
    checkLine('[FAIL]', 'Key rejected when listing models: ' . $message);
    echo PHP_EOL . "Becca will answer from her built-in brain only." . PHP_EOL;
    exit(1);
} else {
    $body = json_decode((string) $raw, true);
    foreach (($body['models'] ?? []) as $entry) {
        $methods = $entry['supportedGenerationMethods'] ?? [];
        if (!in_array('generateContent', $methods, true)) {
            continue;
        }
        // The API returns "models/gemini-2.5-flash"; the request path wants the
        // bare id.
        $available[] = preg_replace('#^models/#', '', (string) ($entry['name'] ?? ''));
    }

    checkLine('[ OK ]', count($available) . ' model(s) available to this key');

    $flash = array_values(array_filter($available, static fn($m) => str_contains($m, 'flash')));
    if ($flash) {
        checkLine('[INFO]', 'Free-tier friendly: ' . implode(', ', array_slice($flash, 0, 6)));
    }

    if ($available && !in_array($model, $available, true)) {
        checkLine('[FAIL]', 'Configured model "' . $model . '" is NOT in that list.');
        checkLine('', '       Set gemini_model in config/chat_secrets.php to one above.');
        echo PHP_EOL . "Becca would silently fall back on every message." . PHP_EOL;
        exit(1);
    }
}

/* 3. Real round-trip. */
$probe = aiChatComplete(
    'You are a test harness. Reply with exactly the word: OK',
    [['role' => 'user', 'content' => 'Reply with exactly: OK']],
    ['max_tokens' => 16, 'temperature' => 0, 'timeout' => 30]
);

if (!$probe['ok']) {
    checkLine('[FAIL]', 'Generation failed (' . $probe['reason'] . '): ' . $probe['error']);
    if ($probe['reason'] === 'rate_limited') {
        checkLine('', '       Free tier is per-minute. Wait 60s and re-run.');
    }
    echo PHP_EOL . "Becca will answer from her built-in brain only." . PHP_EOL;
    exit(1);
}

checkLine('[ OK ]', 'Live generation works - model replied: ' . trim(mb_substr($probe['text'], 0, 40)));

echo PHP_EOL . "Becca is running on the live model (" . $model . ")." . PHP_EOL;
exit(0);
