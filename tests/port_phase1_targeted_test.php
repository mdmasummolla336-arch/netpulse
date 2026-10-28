<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../snmp_lib.php';
require_once __DIR__ . '/../port_monitor_lib.php';

function phase1_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function phase1_fixture(string $typeName = 'Router'): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE device_types(id INTEGER PRIMARY KEY,name TEXT)');
    $pdo->exec('CREATE TABLE devices(
        id INTEGER PRIMARY KEY,name TEXT,ip TEXT,snmp_profile INTEGER,vendor TEXT,type_id INTEGER,monitored INTEGER
    )');
    $pdo->exec('CREATE TABLE snmp_profiles(
        id INTEGER PRIMARY KEY,name TEXT,community TEXT,version INTEGER,port INTEGER,
        sec_name TEXT,auth_pass TEXT,priv_pass TEXT,auth_proto TEXT,priv_proto TEXT
    )');
    np_port_schema($pdo);
    $pdo->prepare('INSERT INTO device_types(id,name) VALUES(1,?)')->execute([$typeName]);
    $pdo->exec("INSERT INTO devices(id,name,ip,snmp_profile,vendor,type_id,monitored)
        VALUES(1,'fixture-device','192.0.2.1',1,'fixture',1,1)");
    $pdo->exec("INSERT INTO snmp_profiles(id,name,community,version,port) VALUES(1,'fixture','not-a-real-community',1,161)");
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO port_inventory(
        device_id,ifindex,ifname,alias,description,oper_status,admin_status,
        status_pending,status_pending_count,first_seen,last_check
    ) VALUES(1,1,'GE0/1','','','up','up','',0,?,?)")->execute([$now, $now]);
    $pdo->prepare('INSERT INTO port_discovery_state(device_id,last_discovery) VALUES(1,?)')->execute([time()]);
    return $pdo;
}

function phase1_walk_result(array $values = [], array $types = [], string $status = 'success'): array {
    return ['status'=>$status, 'values'=>$values, 'types'=>$types];
}

function phase1_fake_walk(array &$oper, array &$admin, ?callable $onWalk = null): Closure {
    return static function (string $ip, array $profile, string $oid) use (&$oper, &$admin, $onWalk): array {
        if ($onWalk !== null) {
            $onWalk($ip, $profile, $oid);
        }
        return match ($oid) {
            '1.3.6.1.2.1.2.2.1.8' => $oper,
            '1.3.6.1.2.1.2.2.1.7' => $admin,
            default => phase1_walk_result(),
        };
    };
}

function phase1_poll(PDO $pdo, Closure $fakeWalk, string $lockPath): array {
    return np_port_poll($pdo, $fakeWalk, $lockPath);
}

function phase1_row(PDO $pdo): array {
    return $pdo->query('SELECT * FROM port_inventory WHERE device_id=1 AND ifindex=1')->fetch(PDO::FETCH_ASSOC);
}

function phase1_event_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM port_events WHERE device_id=1 AND ifindex=1')->fetchColumn();
}

function phase1_incident(PDO $pdo): ?array {
    $row = $pdo->query('SELECT * FROM port_incidents WHERE device_id=1 AND ifindex=1 ORDER BY id DESC LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$operOid = '1.3.6.1.2.1.2.2.1.8';
$validOne = snmp_parse_walk_output_details(["{$operOid}.1 INTEGER: 1"], $operOid);
$validTwo = snmp_parse_walk_output_details(["{$operOid}.1 INTEGER: 2"], $operOid);
phase1_assert($validOne['values'][1] === '1' && $validOne['types'][1] === 'integer', 'INTEGER: 1 not parsed as an integer status');
phase1_assert($validTwo['values'][1] === '2' && $validTwo['types'][1] === 'integer', 'INTEGER: 2 not parsed as an integer status');
phase1_assert(np_port_status_from_sample(['oper_status'=>'1','oper_type'=>'integer']) === 'up', 'integer 1 did not classify as UP');
phase1_assert(np_port_status_from_sample(['oper_status'=>'2','oper_type'=>'integer']) === 'down', 'integer 2 did not classify as DOWN');
$badString = snmp_parse_walk_output_details(["{$operOid}.1 STRING: garbage 2"], $operOid);
phase1_assert($badString['types'][1] === 'string', 'STRING payload was not kept typed as string');
phase1_assert(
    np_port_status_from_sample(['oper_status'=>$badString['values'][1],'oper_type'=>$badString['types'][1]]) === 'invalid',
    'malformed STRING payload was accepted as an operative status'
);
phase1_assert(
    snmp_parse_walk_output_details(["{$operOid}.1 INTEGER: 8"], $operOid)['values'][1] === '8'
        && np_port_status_from_sample(['oper_status'=>'8','oper_type'=>'integer']) === 'invalid',
    'invalid ifOperStatus enum was accepted'
);
phase1_assert(
    snmp_parse_walk_output_details([".1.3.6.1.2.1.2.2.1.7.1 INTEGER: 2"], $operOid)['values'] === [],
    'OID outside the requested subtree was accepted'
);
phase1_assert(snmp_result_status([], 1, false) === 'empty_response', 'empty response category incorrect');
phase1_assert(
    snmp_result_status(['No Such Instance currently exists at this OID'], 1, false) === 'unsupported_oid',
    'unsupported OID category incorrect'
);
phase1_assert(
    snmp_result_status(['SNMPv3 authentication failure: unknown user'], 1, false) === 'authentication_failure',
    'authentication failure category incorrect'
);
phase1_assert(
    snmp_result_status(['not an OID response'], 1, false) === 'malformed_response',
    'malformed response category incorrect'
);

$statePdo = new PDO('sqlite::memory:');
$statePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
np_port_schema($statePdo);
$state = [
    'oper_status'=>'up',
    'admin_status'=>'up',
    'status_pending'=>'',
    'status_pending_count'=>0,
    'last_successful_poll'=>'2026-01-01 00:00:00',
];
$pending = np_port_apply_status($statePdo, $state, [
    'ts'=>'2026-01-01 00:00:05',
    'oper_status'=>'2',
    'oper_type'=>'integer',
    'admin_status'=>'1',
    'admin_type'=>'integer',
    'outcome'=>'success',
], 3);
phase1_assert($pending['status_pending_count'] === 1 && $pending['oper_status'] === 'up', 'one valid DOWN sample should leave UP confirmed with pending count 1');
$invalidAdminDown = np_port_apply_status($statePdo, [
    'oper_status'=>$pending['oper_status'],
    'admin_status'=>$pending['admin_status'],
    'status_pending'=>$pending['status_pending'],
    'status_pending_count'=>$pending['status_pending_count'],
    'last_successful_poll'=>$pending['last_successful_poll'],
], [
    'ts'=>'2026-01-01 00:00:10',
    'oper_status'=>null,
    'oper_type'=>'',
    'admin_status'=>'2',
    'admin_type'=>'integer',
    'outcome'=>'missing_index',
], 3);
phase1_assert($invalidAdminDown['status_pending_count'] === 1, 'invalid operative sample changed pending count');
phase1_assert($invalidAdminDown['last_successful_poll'] === $pending['last_successful_poll'], 'invalid operative sample changed last successful poll');
phase1_assert($invalidAdminDown['oper_status'] === 'up' && $invalidAdminDown['admin_status'] === 'down', 'admin DOWN corrupted operative state or was not stored separately');
$pendingAfterInvalid = np_port_apply_status($statePdo, [
    'oper_status'=>$invalidAdminDown['oper_status'],
    'admin_status'=>$invalidAdminDown['admin_status'],
    'status_pending'=>$invalidAdminDown['status_pending'],
    'status_pending_count'=>$invalidAdminDown['status_pending_count'],
    'last_successful_poll'=>$invalidAdminDown['last_successful_poll'],
], [
    'ts'=>'2026-01-01 00:00:15',
    'oper_status'=>'2',
    'oper_type'=>'integer',
    'admin_status'=>'2',
    'admin_type'=>'integer',
    'outcome'=>'success',
], 3);
phase1_assert($pendingAfterInvalid['status_pending_count'] === 2, 'valid sample after invalid oper value did not continue the pending sequence');

$pdo = phase1_fixture();
$lockPath = sys_get_temp_dir() . '/netpulse-phase1-poll-' . bin2hex(random_bytes(6)) . '.lock';
$oper = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$calls = 0;
$nested = null;
$fakeWalk = phase1_fake_walk($oper, $admin, static function () use (&$calls, &$nested, $pdo, $lockPath, &$oper, &$admin): void {
    if ($calls++ === 0) {
        $nested = np_port_poll($pdo, phase1_fake_walk($oper, $admin), $lockPath);
    }
});
$result = phase1_poll($pdo, $fakeWalk, $lockPath);
phase1_assert(($nested['locked'] ?? false) === true, 'overlapping poll did not return lock-busy');
phase1_assert($result['locked'] === false && phase1_event_count($pdo) === 0, 'lock release did not allow normal poll completion');

$lockPdo = phase1_fixture('OLT');
$lockPdo->prepare('UPDATE port_discovery_state SET last_discovery=? WHERE device_id=1')->execute([123]);
$held = fopen($lockPath, 'c');
phase1_assert($held !== false && flock($held, LOCK_EX | LOCK_NB), 'could not acquire isolated test lock');
$unusedWalk = static function (): array {
    throw new RuntimeException('SNMP callback ran despite lock contention');
};
$lockedResult = np_port_poll($lockPdo, $unusedWalk, $lockPath);
phase1_assert(($lockedResult['locked'] ?? false) === true, 'busy lock was not reported');
phase1_assert((int)$lockPdo->query('SELECT last_discovery FROM port_discovery_state WHERE device_id=1')->fetchColumn() === 123, 'busy lock reset discovery state');
phase1_assert((string)phase1_row($lockPdo)['oper_status'] === 'up', 'busy lock mutated confirmed status');
flock($held, LOCK_UN);
fclose($held);
$oper = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$afterLock = phase1_poll($lockPdo, phase1_fake_walk($oper, $admin), $lockPath);
phase1_assert($afterLock['locked'] === false, 'poll did not proceed after releasing lock');

$pollPdo = phase1_fixture();
$oper = phase1_walk_result(['1'=>'2'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'2'], ['1'=>'integer']);
$fakeWalk = phase1_fake_walk($oper, $admin);
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($pollPdo)['oper_status'] === 'up' && phase1_row($pollPdo)['status_pending_count'] === 2, 'two DOWN observations incorrectly confirmed DOWN');
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($pollPdo)['oper_status'] === 'down' && phase1_event_count($pollPdo) === 1, 'third DOWN observation did not create exactly one transition event');
phase1_assert(phase1_incident($pollPdo)['state'] === 'open', 'confirmed DOWN did not open an active incident');
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_event_count($pollPdo) === 1, 'repeated DOWN created a duplicate transition event');
phase1_assert((int)$pollPdo->query("SELECT COUNT(*) FROM port_incidents WHERE device_id=1 AND ifindex=1 AND state='open'")->fetchColumn() === 1, 'repeated DOWN created duplicate active incidents');

$oper = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_poll($pollPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($pollPdo)['oper_status'] === 'up' && phase1_event_count($pollPdo) === 2, 'third UP observation did not create one recovery event');
phase1_assert(phase1_incident($pollPdo)['state'] === 'resolved', 'confirmed recovery did not resolve active incident');

$failurePdo = phase1_fixture();
$failurePdo->prepare('UPDATE port_inventory SET last_successful_poll=? WHERE device_id=1 AND ifindex=1')
    ->execute(['2026-01-01 00:00:00']);
$oper = phase1_walk_result(['1'=>'2'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$fakeWalk = phase1_fake_walk($oper, $admin);
phase1_poll($failurePdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($failurePdo)['status_pending_count'] === 1, 'valid DOWN observation did not start pending sequence');
$lastSuccessfulBeforeTimeout = phase1_row($failurePdo)['last_successful_poll'];
$oper = phase1_walk_result([], [], 'timeout');
phase1_poll($failurePdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($failurePdo)['status_pending_count'] === 1, 'timeout incorrectly changed pending status count');
phase1_assert(phase1_row($failurePdo)['oper_status'] === 'up', 'timeout incorrectly changed confirmed UP');
phase1_assert(phase1_row($failurePdo)['last_successful_poll'] === $lastSuccessfulBeforeTimeout, 'timeout changed last successful poll');
$oper = phase1_walk_result(['2'=>'2'], ['2'=>'integer']);
phase1_poll($failurePdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($failurePdo)['status_pending_count'] === 1, 'missing ifIndex reused another interface status');
phase1_assert(phase1_row($failurePdo)['oper_status'] === 'up', 'missing ifIndex response changed confirmed state');

$transactionPdo = phase1_fixture();
$oper = phase1_walk_result(['1'=>'2'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'2'], ['1'=>'integer']);
$fakeWalk = phase1_fake_walk($oper, $admin);
$transactionPdo->exec("CREATE TRIGGER reject_port_event BEFORE INSERT ON port_events BEGIN SELECT RAISE(FAIL,'fixture event failure'); END");
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
$eventFailed = false;
try {
    phase1_poll($transactionPdo, $fakeWalk, $lockPath);
} catch (PDOException $error) {
    $eventFailed = true;
}
phase1_assert($eventFailed, 'event insert failure was not surfaced');
phase1_assert(phase1_row($transactionPdo)['oper_status'] === 'up' && phase1_row($transactionPdo)['status_pending_count'] === 2, 'failed event insert committed state transition');
phase1_assert(phase1_event_count($transactionPdo) === 0 && phase1_incident($transactionPdo) === null, 'failed event insert partially committed event or incident');
$transactionPdo->exec('DROP TRIGGER reject_port_event');
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($transactionPdo)['oper_status'] === 'down' && phase1_incident($transactionPdo)['state'] === 'open', 'transition did not succeed after removing injected event failure');

$transactionPdo->exec("CREATE TRIGGER reject_incident_resolve BEFORE UPDATE ON port_incidents BEGIN SELECT RAISE(FAIL,'fixture incident failure'); END");
$oper = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$admin = phase1_walk_result(['1'=>'1'], ['1'=>'integer']);
$fakeWalk = phase1_fake_walk($oper, $admin);
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
$incidentFailed = false;
try {
    phase1_poll($transactionPdo, $fakeWalk, $lockPath);
} catch (PDOException $error) {
    $incidentFailed = true;
}
phase1_assert($incidentFailed, 'incident resolution failure was not surfaced');
phase1_assert(phase1_row($transactionPdo)['oper_status'] === 'down' && phase1_event_count($transactionPdo) === 1, 'failed incident update committed recovery state or event');
phase1_assert(phase1_incident($transactionPdo)['state'] === 'open', 'failed incident update partially resolved incident');
$transactionPdo->exec('DROP TRIGGER reject_incident_resolve');
phase1_poll($transactionPdo, $fakeWalk, $lockPath);
phase1_assert(phase1_row($transactionPdo)['oper_status'] === 'up' && phase1_incident($transactionPdo)['state'] === 'resolved', 'recovery did not complete after removing injected incident failure');

@unlink($lockPath);
echo "Phase 1 targeted polling/parser/transaction tests passed\n";
