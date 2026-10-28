<?php
declare(strict_types=1);

require_once __DIR__ . '/../ssh_terminal_lib.php';

function ssh_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE devices (
    id INTEGER PRIMARY KEY, name TEXT, ip TEXT, ssh_port INTEGER,
    username TEXT, password TEXT, status TEXT
)");
$insert = $pdo->prepare('INSERT INTO devices VALUES(?,?,?,?,?,?,?)');
$insert->execute([1, 'Router A', '192.0.2.10', 2222, 'netadmin', 'server-secret', 'up']);
$insert->execute([2, 'Router B', 'router-b.example', null, 'operator', '', 'pending']);
$insert->execute([3, 'No SSH username', '192.0.2.11', 22, '', 'unused', 'up']);
$insert->execute([4, 'Invalid host', 'router;bad.example', 22, 'operator', '', 'up']);
$insert->execute([5, 'Invalid port', '192.0.2.15', 70000, 'operator', '', 'up']);

$devices = np_ssh_devices($pdo);
ssh_test_assert(count($devices) === 2, 'SSH list should contain only eligible devices.');
ssh_test_assert((int)$devices[0]['id'] === 1, 'Eligible devices should be listed by name.');
ssh_test_assert(!array_key_exists('username', $devices[0]), 'SSH usernames must not be exposed in the device list.');
ssh_test_assert(!array_key_exists('password', $devices[0]), 'SSH passwords must not be exposed in the device list.');
ssh_test_assert((int)$devices[1]['ssh_port'] === 22, 'Missing SSH port should use port 22.');

$credentials = np_ssh_device_credentials($pdo, 1);
ssh_test_assert($credentials !== null, 'Configured SSH device credentials should be retrievable by the bridge.');
ssh_test_assert($credentials['host'] === '192.0.2.10' && $credentials['port'] === 2222, 'SSH target settings mismatch.');
ssh_test_assert($credentials['username'] === 'netadmin' && $credentials['password'] === 'server-secret', 'Backend credentials mismatch.');
ssh_test_assert(np_ssh_device_credentials($pdo, 3) === null, 'Device without an SSH username must not connect.');
ssh_test_assert(np_ssh_device_credentials($pdo, 4) === null, 'Invalid device host must not connect.');
ssh_test_assert(np_ssh_device_credentials($pdo, 5) === null, 'Invalid SSH port must not connect.');
ssh_test_assert(!np_ssh_bridge_token_valid('wrong-token'), 'Bridge token validation must reject invalid tokens.');

$token = bin2hex(random_bytes(32));
putenv('NETPULSE_SSH_BRIDGE_TOKEN=' . $token);
ssh_test_assert(np_ssh_bridge_token_valid($token), 'Bridge token validation rejected the configured token.');
putenv('NETPULSE_SSH_BRIDGE_TOKEN');

echo "SSH device and credential tests passed.\n";
