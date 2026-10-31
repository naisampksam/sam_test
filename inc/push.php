<?php
// Phone / computer notifications (Web Push) without any outside library or account: the server signs with its own
// VAPID key (made once, kept in settings) and encrypts each message for the device (RFC 8291 / 8292).
// Devices turn it on from the account menu (assets/app.js + sw.js); tasks.php sends one when a task is given.
declare(strict_types=1);

/** Push services of the browsers (Chrome / Android, Firefox, Safari / iPhone, Edge). Nothing else is ever called. */
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'push.apple.com', 'notify.windows.com'];

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** This server's VAPID key pair (made the first time): ['private' => PEM, 'public' => base64url of the 65-byte point]. */
function vapid_keys(): array
{
    $pem = (string)setting('vapid_private', '');
    if ($pem === '' || !($key = openssl_pkey_get_private($pem))) {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        set_setting('vapid_private', $pem);
    }
    $ec = openssl_pkey_get_details($key)['ec'];
    return ['private' => $pem, 'public' => b64u("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))];
}

/** Is this a browser push service address? (A test address can be allowed with LOOMA_PUSH_TEST.) */
function push_endpoint_ok(string $url): bool
{
    $test = (string)getenv('LOOMA_PUSH_TEST');
    if ($test !== '' && str_starts_with($url, rtrim($test, '/') . '/')) {
        return true;
    }
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '') {
        return false;
    }
    foreach (PUSH_HOSTS as $h) {
        if ($host === $h || str_ends_with($host, '.' . $h)) {
            return true;
        }
    }
    return false;
}

/** Save this device for the logged-in person (the same device moves to whoever logs in on it). */
function push_subscribe(int $userId, array $sub, string $ua): bool
{
    $endpoint = (string)($sub['endpoint'] ?? '');
    $p256dh = (string)($sub['keys']['p256dh'] ?? '');
    $auth = (string)($sub['keys']['auth'] ?? '');
    if (!push_endpoint_ok($endpoint) || strlen($endpoint) > 1000 || strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
        return false;
    }
    q('INSERT INTO push_subs (user_id, endpoint_hash, endpoint, p256dh, auth, ua, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua), fails = 0',
        [$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($ua, 0, 200), now()]);
    return true;
}

function push_unsubscribe(string $endpoint): void
{
    q('DELETE FROM push_subs WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
}

/** EC public key (65-byte point) as a key OpenSSL can use. */
function push_pubkey(string $point)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/** Encrypt a message for one device (aes128gcm, RFC 8291). Returns the request body. */
function push_encrypt(string $payload, string $uaPublic, string $authSecret): string
{
    $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($local)['ec'];
    $asPublic = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive(push_pubkey($uaPublic), $local, 32);
    if ($shared === false) {
        throw new RuntimeException('Push: bad device key');
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** "vapid t=<signed JWT>, k=<public key>" for one push service (RFC 8292). */
function push_vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $u = parse_url($endpoint);
    $claims = ['aud' => $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), 'exp' => time() + 12 * 3600,
        'sub' => 'mailto:' . (setting('push_contact', '') ?: 'admin@loomaapparels.com')];
    $input = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
    openssl_sign($input, $der, openssl_pkey_get_private($keys['private']), OPENSSL_ALGO_SHA256);
    // DER (SEQUENCE of two INTEGERs) → the 64-byte r‖s that JWT wants
    $pos = 3 + (ord($der[1]) & 0x80 ? 1 : 0);
    $rLen = ord($der[$pos]);
    $r = substr($der, $pos + 1, $rLen);
    $sLen = ord($der[$pos + 2 + $rLen]);
    $s = substr($der, $pos + 3 + $rLen, $sLen);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return 'vapid t=' . $input . '.' . b64u($raw) . ', k=' . $keys['public'];
}

/**
 * Send a notification to every device of a person: ['title', 'body', 'url', 'tag']. Returns how many devices took it.
 * Devices the push service says are gone are removed. Never throws (a notification must not break saving a task).
 */
function push_to_user(int $userId, array $msg): int
{
    if (!function_exists('curl_init')) {
        return 0;
    }
    $sent = 0;
    try {
        $subs = q('SELECT * FROM push_subs WHERE user_id = ?', [$userId])->fetchAll();
    } catch (Throwable $e) {
        return 0;
    }
    $payload = json_encode($msg + ['icon' => base_url('assets/icons/icon-192.png')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($subs as $s) {
        try {
            if (!push_endpoint_ok($s['endpoint'])) {
                continue;
            }
            $body = push_encrypt($payload, b64u_dec($s['p256dh']), b64u_dec($s['auth']));
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high',
                    'Authorization: ' . push_vapid_header($s['endpoint'])]]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $sent++;
                q('UPDATE push_subs SET last_ok = ?, fails = 0 WHERE id = ?', [now(), $s['id']]);
            } elseif (in_array($code, [404, 410], true)) {
                q('DELETE FROM push_subs WHERE id = ?', [$s['id']]); // the device turned notifications off
            } else {
                q('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [$s['id']]);
                error_log("Looma push: HTTP $code from " . parse_url($s['endpoint'], PHP_URL_HOST));
            }
        } catch (Throwable $e) {
            error_log('Looma push failed: ' . $e->getMessage());
        }
    }
    return $sent;
}

/** A task was given to someone: tell them (not when people give themselves a task). */
function push_new_task(array $task, int $to, bool $reassigned = false): void
{
    $me = current_user();
    if (!$to || $to === (int)($me['id'] ?? 0)) {
        return;
    }
    $due = !empty($task['due_date']) ? ' · due ' . date('j M', strtotime($task['due_date'])) : '';
    push_to_user($to, [
        'title' => ($reassigned ? 'Task given to you' : 'New task') . ' from ' . ($me['name'] ?: $me['username']),
        'body' => trim(($task['priority'] ?? '') === 'urgent' ? '🔴 Urgent: ' : '') . $task['title'] . $due,
        'url' => base_url('tasks.php?id=' . (int)$task['id']),
        'tag' => 'task-' . (int)$task['id'],
    ]);
}
