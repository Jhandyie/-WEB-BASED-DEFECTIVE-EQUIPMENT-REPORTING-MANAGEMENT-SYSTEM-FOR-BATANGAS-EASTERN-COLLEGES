<?php
/**
 * includes/webpush.php — dependency-free Web Push (VAPID) sender.
 *
 * Sends payload-less push notifications (a "tickle"): the service worker shows a
 * generic notification and the app fetches details on click. Payload-less avoids
 * the message-body encryption entirely, so all we need is a valid VAPID JWT.
 *
 * VAPID keys are a P-256 keypair stored in data/vapid.json (gitignored), created
 * on first use. Requires openssl with EC support — EC ops on XAMPP need a valid
 * OPENSSL_CONF, which this file points at automatically.
 */

if (getenv('OPENSSL_CONF') === false || getenv('OPENSSL_CONF') === '') {
    foreach (['C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf'] as $cnf) {
        if (is_file($cnf)) { putenv('OPENSSL_CONF=' . $cnf); break; }
    }
}

if (!function_exists('wpB64Url')) {
    function wpB64Url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    function wpB64UrlDecode(string $s): string {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}

if (!function_exists('wpVapidKeys')) {
    /** Load (or create once) the VAPID keypair. Returns [publicB64, privatePem, publicRaw]. */
    function wpVapidKeys(): ?array {
        $file = dirname(__DIR__) . '/data/vapid.json';
        if (is_file($file)) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j) && !empty($j['private_pem']) && !empty($j['public_b64'])) {
                return [$j['public_b64'], $j['private_pem'], wpB64UrlDecode($j['public_b64'])];
            }
        }
        // Generate a fresh P-256 keypair.
        $res = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$res) { error_log('webpush: EC keygen failed — ' . openssl_error_string()); return null; }
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res);
        if (empty($d['ec']['x']) || empty($d['ec']['y'])) { return null; }
        $public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicB64 = wpB64Url($public);
        @file_put_contents($file, json_encode(['public_b64' => $publicB64, 'private_pem' => $pem, 'created_at' => date('c')], JSON_PRETTY_PRINT));
        return [$publicB64, $pem, $public];
    }
}

if (!function_exists('wpPublicKey')) {
    /** The applicationServerKey (base64url) the browser needs to subscribe. */
    function wpPublicKey(): string {
        $k = wpVapidKeys();
        return $k ? $k[0] : '';
    }
}

if (!function_exists('wpDerToRaw')) {
    /** Convert a DER-encoded ECDSA signature to raw R||S (64 bytes) for JOSE ES256. */
    function wpDerToRaw(string $der): string {
        $o = 0;
        if (ord($der[$o++]) !== 0x30) { throw new RuntimeException('bad DER seq'); }
        $len = ord($der[$o++]);
        if ($len & 0x80) { $n = $len & 0x7f; while ($n-- > 0) { $o++; } }
        $read = function () use ($der, &$o): string {
            if (ord($der[$o++]) !== 0x02) { throw new RuntimeException('bad DER int'); }
            $l = ord($der[$o++]);
            $v = substr($der, $o, $l); $o += $l;
            return $v;
        };
        $r = ltrim($read(), "\0"); $s = ltrim($read(), "\0");
        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }
}

if (!function_exists('wpVapidJwt')) {
    /** Build a signed VAPID JWT for the given push endpoint origin. */
    function wpVapidJwt(string $audience, string $privatePem, string $contact = 'mailto:pmo@bec.edu.ph'): ?string {
        $header  = wpB64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = wpB64Url(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $contact]));
        $input = $header . '.' . $payload;
        $key = openssl_pkey_get_private($privatePem);
        if (!$key) { return null; }
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) { return null; }
        try { $raw = wpDerToRaw($der); } catch (Throwable $e) { error_log('webpush jwt: ' . $e->getMessage()); return null; }
        return $input . '.' . wpB64Url($raw);
    }
}

if (!function_exists('sendWebPush')) {
    /**
     * Send one payload-less push to a subscription.
     * @param array $sub  ['endpoint'=>..., 'p256dh'=>..., 'auth'=>...]
     * @return int  HTTP status from the push service (201 = accepted); 0 on local failure.
     */
    function sendWebPush(array $sub, int $ttl = 2419200): int {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if ($endpoint === '' || !function_exists('curl_init')) { return 0; }
        $keys = wpVapidKeys();
        if (!$keys) { return 0; }
        [$publicB64, $privatePem] = $keys;

        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $jwt = wpVapidJwt($audience, $privatePem);
        if (!$jwt) { return 0; }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: vapid t=' . $jwt . ', k=' . $publicB64,
                'TTL: ' . $ttl,
                'Content-Length: 0',
                'Urgency: normal',
            ],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
}

if (!function_exists('wpNotifyUser')) {
    /**
     * Push to every stored subscription for a user. Prunes subscriptions the push
     * service reports as gone (404/410). Returns the number accepted (201/200).
     */
    function wpNotifyUser(string $userId): int {
        if ($userId === '') { return 0; }
        try { $pdo = getPgsqlPdoConnection(); } catch (Throwable $e) { return 0; }
        try {
            $st = $pdo->prepare("SELECT endpoint, p256dh, auth FROM public.push_subscriptions WHERE user_id = :u");
            $st->execute(['u' => $userId]);
            $subs = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return 0; }

        $ok = 0;
        foreach ($subs as $sub) {
            $code = sendWebPush($sub);
            if ($code === 201 || $code === 200) { $ok++; }
            elseif ($code === 404 || $code === 410) {
                try { $pdo->prepare("DELETE FROM public.push_subscriptions WHERE endpoint = :e")->execute(['e' => $sub['endpoint']]); } catch (Throwable $e) {}
            }
        }
        return $ok;
    }
}
