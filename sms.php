<?php
declare(strict_types=1);

/** Independent SMS queue; never uses or modifies the Telegram queue. */
function np_sms_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_queue (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            device_id BIGINT NOT NULL DEFAULT 0,
            recipient VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            text TEXT NOT NULL,
            created BIGINT NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            next_try BIGINT NOT NULL DEFAULT 0,
            last_error VARCHAR(160) NOT NULL DEFAULT '',
            INDEX idx_sms_queue_due(next_try, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER NOT NULL DEFAULT 0,
        recipient TEXT NOT NULL,
        status TEXT NOT NULL,
        text TEXT NOT NULL,
        created INTEGER NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_try INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sms_queue_due ON sms_queue(next_try, id)");
}

function np_sms_config(array $override = []): array {
    $get = static function(string $key, string $field, string $default = '') use ($override): string {
        if (isset($override[$field]) && trim((string)$override[$field]) !== '') {
            return trim((string)$override[$field]);
        }
        return trim((string)setting_get($key, $default));
    };
    return [
        'enabled' => !empty($override['sms_enabled'])
            ? '1' : (array_key_exists('sms_enabled', $override)
                ? '0' : (string)setting_get('sms_enabled', '0')),
        'apikey' => $get('sms_apikey', 'sms_apikey'),
        'secretkey' => $get('sms_secretkey', 'sms_secretkey'),
        'callerID' => $get('sms_callerid', 'sms_callerid'),
        'recipients' => $get('sms_recipients', 'sms_recipients'),
        'gateway_url' => array_key_exists('sms_gateway_url', $override)
            ? trim((string)$override['sms_gateway_url'])
            : trim((string)setting_get('sms_gateway_url', ''))
    ];
}

function np_sms_gateway_endpoint_valid(string $url): bool {
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    return is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['fragment']);
}

function np_sms_recipients(string $value): array {
    $items = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($items as $item) {
        $n = preg_replace('/[^\d+]/', '', $item);
        if ($n !== '' && preg_match('/^\+?\d{8,16}$/', $n)) $out[$n] = $n;
    }
    return array_values($out);
}

/** Send one SMS using the provider's HTTP(S) GET endpoint. */
function np_sms_send_one(array $cfg, string $recipient, string $message): array {
    if ($cfg['apikey'] === '' || $cfg['secretkey'] === '' || $cfg['callerID'] === '') {
        return ['ok'=>false, 'error'=>'SMS gateway settings are incomplete'];
    }
    if (!np_sms_gateway_endpoint_valid((string)($cfg['gateway_url'] ?? ''))) {
        return ['ok'=>false, 'error'=>'Configure a valid HTTP(S) SMS gateway endpoint'];
    }

    $separator = str_contains((string)$cfg['gateway_url'], '?') ? '&' : '?';
    $url = $cfg['gateway_url'] . $separator . http_build_query([
        'apikey' => $cfg['apikey'],
        'secretkey' => $cfg['secretkey'],
        'callerID' => $cfg['callerID'],
        'toUser' => $recipient,
        'messageContent' => $message
    ], '', '&', PHP_QUERY_RFC3986);

    $ctx = stream_context_create([
        'http'=>[
            'method'=>'GET',
            'timeout'=>5,
            'ignore_errors'=>true,
            'follow_location'=>0,
            'max_redirects'=>0,
            'header'=>"Accept: text/plain, application/json\r\nConnection: close\r\n"
        ],
        'ssl'=>[
            'verify_peer'=>true,
            'verify_peer_name'=>true,
            'SNI_enabled'=>true
        ]
    ]);
    $transportWarning = '';
    set_error_handler(static function(int $severity, string $message) use (&$transportWarning): bool {
        $transportWarning = $message;
        return true;
    }, E_WARNING);
    try {
        $body = file_get_contents($url, false, $ctx);
    } finally {
        restore_error_handler();
    }
    $code = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $code = (int)$m[1];
    }
    unset($url); // Never log or return a URL containing credentials.

    $gatewayBody = $body === false ? '' : trim(strip_tags((string)$body));
    foreach ([$cfg['apikey'], $cfg['secretkey']] as $secret) {
        if ($secret !== '') $gatewayBody = str_replace($secret, '[REDACTED]', $gatewayBody);
    }
    $gatewayBody = substr($gatewayBody, 0, 300);

    if ($body !== false && $code >= 200 && $code < 300) {
        return ['ok'=>true, 'http_code'=>$code, 'gateway_response'=>$gatewayBody];
    }
    if ($body === false && $code === 0) {
        $warning = strtolower($transportWarning);
        if (str_contains($warning, 'ssl') || str_contains($warning, 'certificate') ||
            str_contains($warning, 'crypto')) {
            $error = 'HTTPS connection failed; check the gateway certificate and CA certificates on the NetPulse server.';
        } elseif (str_contains($warning, 'timed out')) {
            $error = 'SMS gateway connection timed out';
        } else {
            $error = 'Could not connect to the SMS gateway; check server DNS, outbound access, and PHP HTTPS support.';
        }
        error_log('NetPulse SMS gateway transport failed; HTTPS certificate validation remains enabled.');
        return ['ok'=>false, 'http_code'=>0, 'error'=>$error, 'gateway_response'=>''];
    }
    error_log('NetPulse SMS gateway request failed; HTTP '.$code);
    return ['ok'=>false, 'error'=>'Gateway request failed (HTTP '.$code.')',
            'gateway_response'=>$gatewayBody];
}

function np_sms_enqueue(PDO $pdo, int $deviceId, string $name, ?string $ip,
                        string $status, string $ts, int $nowTs): void {
    np_sms_schema($pdo);
    $cfg = np_sms_config();
    if ($cfg['enabled'] !== '1') return;
    $recipients = np_sms_recipients($cfg['recipients']);
    if (!$recipients) return;

    $state = $status === 'down' ? 'DOWN' : 'UP';
    $message = "NetPulse: {$name} ({$ip}) is {$state}. Time: {$ts}";
    $st = $pdo->prepare("INSERT INTO sms_queue
        (device_id,recipient,status,text,created,attempts,next_try)
        VALUES(?,?,?,?,?,0,0)");
    foreach ($recipients as $recipient) {
        $st->execute([$deviceId, $recipient, $status, $message, $nowTs]);
    }
}

/** Small bounded batch so a slow SMS provider cannot stall monitoring for long. */
function np_sms_flush(PDO $pdo, int $max = 3): array {
    np_sms_schema($pdo);
    $now = time();
    $pdo->prepare('DELETE FROM sms_queue WHERE created < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT * FROM sms_queue WHERE next_try<=? ORDER BY id LIMIT ?');
    $st->bindValue(1, $now, PDO::PARAM_INT);
    $st->bindValue(2, max(1, min(5, $max)), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0; $failed = 0;

    foreach ($rows as $row) {
        $cfg = np_sms_config();
        if ($cfg['enabled'] !== '1') break;
        $r = np_sms_send_one($cfg, (string)$row['recipient'], (string)$row['text']);
        if ($r['ok']) {
            $pdo->prepare('DELETE FROM sms_queue WHERE id=?')->execute([(int)$row['id']]);
            $sent++;
        } else {
            $attempts = (int)$row['attempts'] + 1;
            $delay = min(900, 30 * (2 ** min(5, $attempts - 1)));
            $pdo->prepare('UPDATE sms_queue SET attempts=?,next_try=?,last_error=? WHERE id=?')
                ->execute([$attempts, $now + $delay, substr((string)$r['error'], 0, 160), (int)$row['id']]);
            $failed++;
        }
    }
    return [$sent, $failed];
}
