# XMPP Integration (PHP)

This folder contains a lightweight wrapper around the `fabiang/xmpp` library and an example script.

Quick setup

1. Install the PHP XMPP client via Composer in the project root:

```bash
composer require fabiang/xmpp
```

2. Create a `config/xmpp.php` based on `config/xmpp.example.php` and fill in XMPP server credentials.

3. Start an XMPP server if you don't already have one (Prosody, ejabberd, etc.). Create a bot account (e.g., `bot@your-domain`).

4. Send a message (CLI):

```bash
php xmpp/example_send.php user@your-domain "Test message from BEC bot"
```

Usage from PHP

```php
require_once __DIR__ . '/xmpp/xmpp_client.php';
$res = xmppSendMessage('user@your-domain', 'Hello from BEC');
if (!$res['ok']) {
    error_log('XMPP send failed: ' . ($res['error'] ?? 'unknown'));
}
```

Notes
- The wrapper checks for `vendor/autoload.php` and returns a helpful error if the library is not installed.
- Configure domain, credentials, and options in `config/xmpp.php` or via `XMPP_*` environment variables.
