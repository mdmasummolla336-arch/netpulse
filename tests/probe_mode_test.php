<?php
declare(strict_types=1);

require_once __DIR__ . '/../probe_lib.php';

function assert_probe_mode(string $expected, ?string $mode, array $services): void {
    $actual = np_effective_mode($mode, $services);
    if ($actual !== $expected) {
        throw new RuntimeException("Expected $expected mode, got $actual");
    }
}

assert_probe_mode('ping', null, []);
assert_probe_mode('services', null, [['ptype' => 'tcp']]);
assert_probe_mode('ping', 'ping', [['ptype' => 'tcp']]);
assert_probe_mode('services', 'services', [['ptype' => 'icmp']]);
assert_probe_mode('ping', null, [['ptype' => 'cpu']]);
assert_probe_mode('services', 'invalid', [['ptype' => 'snmp']]);

echo "Per-device probe mode tests passed.\n";
