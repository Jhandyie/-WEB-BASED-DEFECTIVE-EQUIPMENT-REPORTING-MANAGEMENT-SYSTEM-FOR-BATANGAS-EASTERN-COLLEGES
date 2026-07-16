<?php
/**
 * push_subscribe.php — store/remove a technician's Web Push subscription.
 *
 * POST (JSON body = PushSubscription.toJSON()) → save for the logged-in technician.
 * POST { "action": "unsubscribe", "endpoint": "…" } → remove it.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
startRoleSession('technician');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';

header('Content-Type: application/json');
ini_set('display_errors', '0');

if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit();
}
requireCsrf(true);

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true) ?: [];
$uid  = (string) $_SESSION['user_id'];

try {
    $pdo = getPgsqlPdoConnection();

    if (($data['action'] ?? '') === 'unsubscribe') {
        $endpoint = (string) ($data['endpoint'] ?? '');
        if ($endpoint !== '') {
            $st = $pdo->prepare("DELETE FROM public.push_subscriptions WHERE endpoint = :e AND user_id = :u");
            $st->execute(['e' => $endpoint, 'u' => $uid]);
        }
        echo json_encode(['ok' => true]);
        exit();
    }

    $endpoint = (string) ($data['endpoint'] ?? '');
    $p256dh   = (string) ($data['keys']['p256dh'] ?? '');
    $auth     = (string) ($data['keys']['auth'] ?? '');
    if ($endpoint === '') {
        echo json_encode(['ok' => false, 'message' => 'Missing endpoint.']);
        exit();
    }

    // One row per endpoint; re-point to this user + refresh keys if it already exists.
    $st = $pdo->prepare(
        "INSERT INTO public.push_subscriptions (user_id, endpoint, p256dh, auth)
         VALUES (:u, :e, :p, :a)
         ON CONFLICT (endpoint) DO UPDATE SET user_id = EXCLUDED.user_id, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth"
    );
    $st->execute(['u' => $uid, 'e' => $endpoint, 'p' => $p256dh, 'a' => $auth]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('push_subscribe: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error.']);
}
