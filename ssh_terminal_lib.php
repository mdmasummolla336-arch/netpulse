<?php
declare(strict_types=1);

function np_ssh_valid_host(string $host): bool {
    $host = trim($host);
    if ($host === '' || strlen($host) > 253) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return true;
    return (bool)preg_match(
        '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$/',
        $host
    );
}

function np_ssh_devices(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT id,name,ip,COALESCE(ssh_port,22) ssh_port,status
         FROM devices
         WHERE ip IS NOT NULL AND ip<>'' AND username IS NOT NULL AND username<>''
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_values(array_filter($rows, static fn(array $row): bool =>
        np_ssh_valid_host((string)$row['ip'])
        && (int)$row['ssh_port'] >= 1
        && (int)$row['ssh_port'] <= 65535
    ));
}

function np_ssh_device_credentials(PDO $pdo, int $deviceId): ?array {
    $st = $pdo->prepare(
        "SELECT id,name,ip,COALESCE(ssh_port,22) ssh_port,username,password
         FROM devices
         WHERE id=? AND ip IS NOT NULL AND ip<>'' AND username IS NOT NULL AND username<>''"
    );
    $st->execute([$deviceId]);
    $device = $st->fetch(PDO::FETCH_ASSOC);
    if (!$device) return null;

    $host = trim((string)$device['ip']);
    $username = trim((string)$device['username']);
    $port = (int)$device['ssh_port'];
    if (!np_ssh_valid_host($host)
        || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username)
        || $port < 1
        || $port > 65535) {
        return null;
    }

    return [
        'id'=>(int)$device['id'],
        'name'=>(string)$device['name'],
        'host'=>$host,
        'port'=>$port,
        'username'=>$username,
        'password'=>(string)($device['password'] ?? '')
    ];
}

function np_ssh_bridge_token_valid(string $provided): bool {
    $expected = getenv('NETPULSE_SSH_BRIDGE_TOKEN') ?: '';
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}
