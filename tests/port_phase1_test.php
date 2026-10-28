<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../snmp_lib.php';
require_once __DIR__ . '/../port_monitor_lib.php';

function assert_true(bool $cond, string $msg): void {
    if (!$cond) throw new RuntimeException($msg);
}

function make_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

$pdo = make_pdo();
np_port_schema($pdo);
foreach (['admin_status','status_pending','status_pending_count','last_attempt','last_successful_poll','last_error'] as $col) {
    assert_true(np_column_exists($pdo, 'port_inventory', $col), "missing column $col");
}
assert_true(np_column_exists($pdo, 'port_incidents', 'state'), 'missing port_incidents state');

$legacy = make_pdo();
$legacy->exec("CREATE TABLE port_inventory(device_id INTEGER, ifindex INTEGER, ifname TEXT, alias TEXT, description TEXT, oper_status TEXT, first_seen TEXT, last_check TEXT)");
$legacy->exec("INSERT INTO port_inventory(device_id,ifindex,ifname,alias,description,oper_status,first_seen,last_check) VALUES(1,7,'eth7','al7','desc','up','2024-01-01 00:00:00','2024-01-01 00:00:00')");
np_port_schema($legacy);
assert_true((int)$legacy->query("SELECT COUNT(*) FROM port_inventory WHERE device_id=1 AND ifindex=7")->fetchColumn() === 1, 'legacy row lost');
assert_true(np_column_exists($legacy, 'port_inventory', 'admin_status'), 'legacy admin_status missing after migration');

$row = ['oper_status' => 'up', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'up'];
$first = np_port_apply_status($pdo, $row, ['ts' => '2024-01-01 00:00:00', 'oper_status' => '1', 'oper_type' => 'integer', 'outcome' => 'success'], 3);
assert_true($first['event_status'] === null, 'duplicate transition on stable up');

$row = ['oper_status' => 'up', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'up'];
$timeout = np_port_apply_status($pdo, $row, ['ts' => '2024-01-01 00:00:05', 'oper_status' => '1', 'oper_type' => 'integer', 'outcome' => 'timeout'], 3);
assert_true($timeout['oper_status'] === 'up', 'timeout should not downgrade confirmed up');
assert_true($timeout['event_status'] === null, 'timeout should not emit transition');

$row = ['oper_status' => 'up', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'up'];
$oidMissing = np_port_apply_status($pdo, $row, ['ts' => '2024-01-01 00:00:10', 'oper_status' => null, 'oper_type' => '', 'outcome' => 'missing_index'], 3);
assert_true($oidMissing['oper_status'] === 'up', 'unsupported/missing OID should keep up');
assert_true($oidMissing['event_status'] === null, 'unsupported OID should not false-down');

$down = ['oper_status' => 'up', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'up'];
$seq = [];
foreach (['2024-01-01 00:00:20','2024-01-01 00:00:25','2024-01-01 00:00:30'] as $ts) {
    $seq[] = np_port_apply_status($pdo, $down, ['ts' => $ts, 'oper_status' => '2', 'oper_type' => 'integer', 'outcome' => 'success'], 3);
    $down = ['oper_status' => $seq[count($seq)-1]['oper_status'], 'status_pending' => $seq[count($seq)-1]['status_pending'], 'status_pending_count' => $seq[count($seq)-1]['status_pending_count'], 'admin_status' => 'up'];
}
assert_true($seq[2]['event_status'] === 'down' && $seq[2]['oper_status'] === 'down', 'three down observations did not confirm down');

$downRow = ['oper_status' => 'down', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'down'];
$downTimeout = np_port_apply_status($pdo, $downRow, ['ts' => '2024-01-01 00:00:35', 'oper_status' => '2', 'oper_type' => 'integer', 'outcome' => 'timeout'], 3);
assert_true($downTimeout['oper_status'] === 'down', 'timeout should preserve confirmed down');

$upRow = ['oper_status' => 'down', 'status_pending' => '', 'status_pending_count' => 0, 'admin_status' => 'down'];
$upSeq = [];
foreach (['2024-01-01 00:00:40','2024-01-01 00:00:45','2024-01-01 00:00:50'] as $ts) {
    $upSeq[] = np_port_apply_status($pdo, $upRow, ['ts' => $ts, 'oper_status' => '1', 'oper_type' => 'integer', 'outcome' => 'success'], 3);
    $upRow = ['oper_status' => $upSeq[count($upSeq)-1]['oper_status'], 'status_pending' => $upSeq[count($upSeq)-1]['status_pending'], 'status_pending_count' => $upSeq[count($upSeq)-1]['status_pending_count'], 'admin_status' => 'down'];
}
assert_true($upSeq[2]['event_status'] === 'up' && $upSeq[2]['oper_status'] === 'up', 'three up observations did not recover');

$incidentPdo = make_pdo();
np_port_schema($incidentPdo);
np_port_open_incident($incidentPdo, 99, 12, 'down', '2024-01-01 00:00:00', 'first');
np_port_open_incident($incidentPdo, 99, 12, 'down', '2024-01-01 00:00:01', 'duplicate');
assert_true((int)$incidentPdo->query("SELECT COUNT(*) FROM port_incidents WHERE device_id=99 AND ifindex=12 AND state='open'")->fetchColumn() === 1, 'duplicate open incident created');

$authStatus = snmp_result_status(['SNMPv3 authentication failure: unknown user'], 0, false);
assert_true($authStatus === 'authentication_failure', 'auth failure classification wrong');

$missingIndex = np_port_apply_status($pdo, ['oper_status' => 'up', 'status_pending' => '', 'status_pending_count' => 0], ['ts'=>'2024-01-01 00:00:55', 'oper_status' => '2', 'oper_type' => 'integer', 'outcome' => 'missing_index'], 3);
assert_true($missingIndex['oper_status'] === 'up', 'status for missing ifIndex should not be reused');

$lockPath = sys_get_temp_dir() . '/netpulse-port-phase1.lock';
$fh1 = fopen($lockPath, 'c');
$fh2 = fopen($lockPath, 'c');
assert_true($fh1 !== false && $fh2 !== false, 'lock file creation failed');
assert_true(flock($fh1, LOCK_EX | LOCK_NB), 'primary lock failed');
assert_true(!flock($fh2, LOCK_EX | LOCK_NB), 'secondary lock unexpectedly succeeded');
@fclose($fh1); @fclose($fh2); @unlink($lockPath);

echo "Phase 1 port-state regression tests passed\n";
