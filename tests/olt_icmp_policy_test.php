<?php
declare(strict_types=1);

require_once __DIR__ . '/../olt_monitor_lib.php';
require_once __DIR__ . '/../port_monitor_lib.php';
require_once __DIR__ . '/../optical_poll.php';
require_once __DIR__ . '/../mx80_lib.php';

function olt_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

olt_test_assert(np_olt_monitoring_enabled(1), 'Selected OLT was not considered enabled.');
olt_test_assert(np_olt_monitoring_enabled(null), 'Legacy default-enabled device behavior changed.');
olt_test_assert(!np_olt_monitoring_enabled(0), 'Unselected OLT was considered enabled.');

$status = 'up';
$failures = 0;
$downSince = null;
$downTs = null;
foreach ([100, 110] as $nowTs) {
    $result = np_olt_icmp_transition(
        $status, $failures, $downSince, $downTs, true, false,
        gmdate('Y-m-d H:i:s', $nowTs), $nowTs
    );
    olt_test_assert($result['status'] === 'pending', 'OLT went down before three failed checks.');
    $status = $result['status'];
    $failures = $result['ping_fail_count'];
    $downSince = $result['down_since'];
    $downTs = $result['down_ts'];
}
$result = np_olt_icmp_transition(
    $status, $failures, $downSince, $downTs, true, false,
    gmdate('Y-m-d H:i:s', 120), 120
);
olt_test_assert(
    $result['status'] === 'down' && $result['ping_fail_count'] === 3,
    'Three consecutive failed ping checks did not confirm DOWN.'
);
$result = np_olt_icmp_transition(
    $result['status'], $result['ping_fail_count'], $result['down_since'], $result['down_ts'],
    true, true, gmdate('Y-m-d H:i:s', 130), 130
);
olt_test_assert(
    $result['status'] === 'up' && $result['ping_fail_count'] === 0
        && $result['down_since'] === null && $result['down_ts'] === null,
    'Valid ping did not restore UP and clear the consecutive-failure state.'
);
$unchanged = np_olt_icmp_transition(
    'down', 3, '2026-01-01 00:00:00', 1767225600, false, false,
    '2026-01-01 00:00:10', 1767225610
);
olt_test_assert(
    !$unchanged['valid'] && $unchanged['status'] === 'down' && $unchanged['ping_fail_count'] === 3,
    'A cycle without an ICMP check changed the confirmed OLT state.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE device_types(id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec('INSERT INTO device_types VALUES(1,\'OLT\')');
$pdo->exec('CREATE TABLE devices(
    id INTEGER PRIMARY KEY,name TEXT,ip TEXT,vendor TEXT,type_id INTEGER,snmp_profile INTEGER,
    monitored INTEGER,status TEXT DEFAULT \'unknown\'
)');
$pdo->exec('CREATE TABLE snmp_profiles(
    id INTEGER PRIMARY KEY,name TEXT,community TEXT,version INTEGER,port INTEGER,
    sec_name TEXT,auth_pass TEXT,priv_pass TEXT,auth_proto TEXT,priv_proto TEXT
)');
$pdo->exec("INSERT INTO devices(id,name,ip,vendor,type_id,snmp_profile,monitored)
    VALUES(1,'staging-olt','192.0.2.50','other',1,1,1)");
$pdo->exec("INSERT INTO devices(id,name,ip,vendor,type_id,snmp_profile,monitored)
    VALUES(1000004,'MX80','10.255.240.9','other',1,1,1)");
$pdo->exec("INSERT INTO snmp_profiles VALUES(1,'fixture','not-a-real-community',1,161,'','','','MD5','DES')");
np_port_schema($pdo);
$now = '2026-01-01 00:00:00';
$pdo->prepare(
    "INSERT INTO port_inventory(device_id,ifindex,ifname,first_seen,last_check,oper_status)
     VALUES(1,1,'GE0/1',?,?, 'up')"
)->execute([$now, $now]);
$pdo->prepare('INSERT INTO port_discovery_state(device_id,last_discovery) VALUES(1,987654)')
    ->execute();
$pdo->exec(
    "INSERT INTO port_events(ts,device_id,ifindex,status,message)
     VALUES('2026-01-01 00:00:00',1,1,'up','preserved fixture history')"
);
$walkCalls = 0;
$lockPath = sys_get_temp_dir() . '/netpulse-olt-policy-' . bin2hex(random_bytes(6)) . '.lock';
$poll = np_port_poll(
    $pdo,
    static function () use (&$walkCalls): array {
        $walkCalls++;
        throw new RuntimeException('OLT SNMP walk must not be called.');
    },
    $lockPath
);
olt_test_assert($poll['interfaces'] === 0 && $walkCalls === 0, 'Port poller queried an OLT.');
olt_test_assert(
    (int)$pdo->query('SELECT last_discovery FROM port_discovery_state WHERE device_id=1')->fetchColumn() === 987654,
    'OLT discovery timestamp changed despite SNMP polling being disabled.'
);
olt_test_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM port_inventory WHERE device_id=1')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM port_events WHERE device_id=1')->fetchColumn() === 1,
    'Existing OLT inventory or port event history was modified.'
);
@unlink($lockPath);

olt_test_assert(op_collect($pdo) === [], 'Optical collector returned OLT data.');
try {
    np_mx80_profile($pdo, 1000004);
    olt_test_assert(false, 'MX80 SNMP profile allowed an OLT device.');
} catch (InvalidArgumentException $error) {
}

$pdo->exec('CREATE TABLE maps(id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec('CREATE TABLE map_nodes(
    id INTEGER PRIMARY KEY,map_id INTEGER,kind TEXT,device_id INTEGER,x INTEGER,y INTEGER,label TEXT
)');
$pdo->exec("INSERT INTO maps VALUES(1,'Existing map')");
$pdo->exec("INSERT INTO map_nodes VALUES(7,1,'device',1,123,456,'OLT label')");
$node = $pdo->query(
    'SELECT n.id,n.x,n.y,n.label,d.name,d.status FROM map_nodes n JOIN devices d ON d.id=n.device_id WHERE n.id=7'
)->fetch(PDO::FETCH_ASSOC);
olt_test_assert(
    $node !== false && (int)$node['x'] === 123 && (int)$node['y'] === 456
        && $node['label'] === 'OLT label',
    'Existing OLT Map node identity, layout, or label was not retained.'
);

echo "OLT ICMP-only policy and poller exclusion tests passed.\n";
