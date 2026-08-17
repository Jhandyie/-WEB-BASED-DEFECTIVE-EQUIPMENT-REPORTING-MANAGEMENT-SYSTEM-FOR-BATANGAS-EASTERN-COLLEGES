<?php
/**
 * includes/ai_client.php — the one place this app talks to a language model.
 *
 * Becca ran on Anthropic Claude until August 2026 and now runs on Google Gemini's
 * free tier. The three chat proxies (chat_proxy.php, admin_chat_proxy.php,
 * technician_chat_proxy.php) each carried their own copy of the HTTP call, which
 * made a provider change the same edit written three times — and three places for
 * it to drift. They all call aiChatComplete() now.
 *
 * The free tier is rate limited (10 requests/minute per model at the time of
 * writing, with a daily cap). A 429 is therefore a normal outcome here, not an
 * exception: every caller already owns a local fallback brain, so this function
 * never throws and never echoes. It returns ok=false and lets the caller answer
 * from its own knowledge.
 *
 * Messages come in Anthropic shape ([['role' => 'user'|'assistant', 'content' =>
 * string], ...]) because that is what the callers and the front-end already speak.
 * The mapping to Gemini's shape happens here.
 */

const AI_ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

// Model IDs retire. gemini-2.5-flash was closed to new keys and started refusing
// every request with "no longer available to new users" — which, because the
// callers fall back silently, looks like "Becca got dumb" rather than an outage.
// scripts/check_ai_key.php exists to catch that; run it before a demo.
// If you would rather never touch this again, set gemini_model to
// 'gemini-flash-latest' in config/chat_secrets.php: it always resolves to the
// current Flash, at the cost of the model changing under you without warning.
const AI_DEFAULT_MODEL = 'gemini-3.6-flash';

/** Read config/chat_secrets.php once per request. */
function aiChatConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [];
    $path = dirname(__DIR__) . '/config/chat_secrets.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return $config;
}

/**
 * Environment first so a machine can override the file without editing it,
 * then the gitignored secrets file.
 */
function aiApiKey(): string
{
    $env = getenv('GEMINI_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $server = $_SERVER['GEMINI_API_KEY'] ?? '';
    if (is_string($server) && trim($server) !== '') {
        return trim($server);
    }

    $config = aiChatConfig();
    $key = $config['gemini_api_key'] ?? '';

    return is_string($key) ? trim($key) : '';
}

function aiModel(): string
{
    $config = aiChatConfig();
    $model = $config['gemini_model'] ?? '';
    $model = is_string($model) ? trim($model) : '';

    return $model !== '' ? $model : AI_DEFAULT_MODEL;
}

/**
 * Thinking costs output tokens and latency, and Becca answers short support
 * questions — so it is switched off.
 *
 * How you switch it off depends on the model family: the 2.5 series takes a token
 * budget (0 = off), the 3.x series takes a named level. Sending the wrong field is
 * a 400, so the family decides. Returns null for anything unrecognised, which
 * leaves the model on its own default rather than guessing at a field name.
 */
function aiThinkingConfig(string $model): ?array
{
    $config = aiChatConfig();
    if (isset($config['gemini_thinking']) && is_array($config['gemini_thinking'])) {
        return $config['gemini_thinking'];
    }

    if (str_starts_with($model, 'gemini-2.5')) {
        return ['thinkingBudget' => 0];
    }
    if (str_starts_with($model, 'gemini-3')) {
        return ['thinkingLevel' => 'low'];
    }

    return null;
}

function aiLog(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    @file_put_contents(
        $dir . '/ai_client.log',
        json_encode([
            'time' => date('c'),
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Convert Anthropic-shaped messages to Gemini "contents".
 *
 * Two differences that matter: the assistant role is called "model", and text
 * lives in a parts array rather than a bare string.
 */
function aiToGeminiContents(array $messages): array
{
    $contents = [];
    foreach ($messages as $message) {
        $role = $message['role'] ?? '';
        $content = $message['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }

        $contents[] = [
            'role' => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $content]],
        ];
    }

    return $contents;
}

/**
 * Pull the reply text out of a Gemini response.
 *
 * A candidate can legitimately come back with no parts at all — a safety stop, or
 * a turn that spent its whole output budget before writing anything — so this
 * returns '' rather than assuming parts[0] exists. Multiple parts are joined:
 * long replies arrive split.
 */
function aiExtractText(array $body): string
{
    $parts = $body['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        return '';
    }

    $chunks = [];
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $chunks[] = $part['text'];
        }
    }

    return trim(implode('', $chunks));
}

/**
 * Ask the model for a reply.
 *
 * @param string $systemPrompt Persona, knowledge base and live data.
 * @param array  $messages     [['role' => 'user'|'assistant', 'content' => string], ...]
 * @param array  $options      timeout (seconds), max_tokens, temperature.
 *
 * @return array{ok:bool, text:string, error:string, reason:string, http_code:int, model:string}
 *               reason is a short machine-readable tag for the caller's warning
 *               field: not_configured, unavailable, rate_limited, blocked, empty,
 *               api_error.
 */
function aiChatComplete(string $systemPrompt, array $messages, array $options = []): array
{
    $model = aiModel();
    $result = [
        'ok' => false,
        'text' => '',
        'error' => '',
        'reason' => '',
        'http_code' => 0,
        'model' => $model,
    ];

    $apiKey = aiApiKey();
    if ($apiKey === '') {
        $result['error'] = 'AI service not configured';
        $result['reason'] = 'not_configured';
        return $result;
    }

    $contents = aiToGeminiContents($messages);
    if (!$contents) {
        $result['error'] = 'No valid messages';
        $result['reason'] = 'empty';
        return $result;
    }

    $generationConfig = [
        'temperature' => $options['temperature'] ?? 0.8,
        'maxOutputTokens' => $options['max_tokens'] ?? 1024,
    ];
    $thinking = aiThinkingConfig($model);
    if ($thinking !== null) {
        $generationConfig['thinkingConfig'] = $thinking;
    }

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => $generationConfig,
    ];

    // The key rides in a header, not the query string, so it stays out of access
    // logs and out of any error page that echoes the URL.
    $ch = curl_init(AI_ENDPOINT_BASE . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw = curl_exec($ch);
    $result['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        aiLog('curl error', ['error' => $curlError, 'http_code' => $result['http_code'], 'model' => $model]);
        $result['error'] = 'AI service unavailable';
        $result['reason'] = 'unavailable';
        return $result;
    }

    $body = json_decode((string) $raw, true);
    if (!is_array($body)) {
        aiLog('unparseable response', ['http_code' => $result['http_code'], 'raw' => mb_substr((string) $raw, 0, 500)]);
        $result['error'] = 'AI service unavailable';
        $result['reason'] = 'unavailable';
        return $result;
    }

    if ($result['http_code'] !== 200) {
        $message = $body['error']['message'] ?? 'API error';
        // 429 is the expected free-tier ceiling, not a fault — log it as its own
        // thing so a demo-day burst is obvious in the log rather than buried
        // among real errors.
        $isRateLimit = $result['http_code'] === 429;
        aiLog($isRateLimit ? 'rate limited' : 'api error', [
            'http_code' => $result['http_code'],
            'model' => $model,
            'message' => $message,
        ]);
        $result['error'] = $isRateLimit
            ? 'AI is at its free-tier limit right now'
            : $message;
        $result['reason'] = $isRateLimit ? 'rate_limited' : 'api_error';
        return $result;
    }

    // A prompt can be refused before any candidate is generated.
    $blockReason = $body['promptFeedback']['blockReason'] ?? '';
    if (is_string($blockReason) && $blockReason !== '') {
        aiLog('prompt blocked', ['block_reason' => $blockReason, 'model' => $model]);
        $result['error'] = 'AI declined to answer that';
        $result['reason'] = 'blocked';
        return $result;
    }

    $text = aiExtractText($body);
    if ($text === '') {
        aiLog('empty completion', [
            'model' => $model,
            'finish_reason' => $body['candidates'][0]['finishReason'] ?? '',
        ]);
        $result['error'] = 'AI returned an empty reply';
        $result['reason'] = 'empty';
        return $result;
    }

    $result['ok'] = true;
    $result['text'] = $text;

    return $result;
}
