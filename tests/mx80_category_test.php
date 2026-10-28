<?php
declare(strict_types=1);

require_once __DIR__ . '/../mx80_lib.php';

function setting_get(string $key, $default = null) {
    return $default;
}

function mx80_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,ip TEXT,snmp_profile INTEGER,type_id INTEGER)");
$pdo->exec("CREATE TABLE device_types(id INTEGER PRIMARY KEY,name TEXT)");
$pdo->exec("CREATE TABLE snmp_profiles(
    id INTEGER PRIMARY KEY,name TEXT,community TEXT,version INTEGER,port INTEGER,
    sec_name TEXT,auth_pass TEXT,priv_pass TEXT,auth_proto TEXT,priv_proto TEXT)");
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT)");
$pdo->exec("INSERT INTO devices(id,name,ip,snmp_profile,type_id) VALUES(1000004,'MX80','10.255.240.9',1,1)");
$pdo->exec("INSERT INTO device_types(id,name) VALUES(1,'Router')");
$pdo->exec("INSERT INTO snmp_profiles(id,name,community,version,port) VALUES(1,'fixture','fixture',0,161)");
$pdo->exec("INSERT INTO users(id,role) VALUES(5,'user')");

np_mx80_schema($pdo);
np_mx80_schema($pdo);
mx80_test_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'mx80_%'")->fetchColumn() === 5,
    'Repeated schema initialization created duplicate MX80 tables.'
);
$nameIndexes = $pdo->query("PRAGMA index_list('mx80_categories')")->fetchAll(PDO::FETCH_ASSOC);
mx80_test_assert(
    count(array_filter($nameIndexes, static fn(array $index): bool =>
        $index['name'] === 'idx_mx80_categories_name_unique' && (int)$index['unique'] === 1
    )) === 1,
    'Category-name uniqueness index was not created exactly once.'
);

$root = np_mx80_create_category($pdo, '  Core  ', null);
$duplicateRejected = false;
try {
    np_mx80_create_category($pdo, 'core', null);
} catch (InvalidArgumentException $error) {
    $duplicateRejected = str_contains($error->getMessage(), 'already exists');
}
mx80_test_assert($duplicateRejected, 'Case-insensitive duplicate category creation was accepted.');
$child = np_mx80_create_category($pdo, 'Transit', $root);
$duplicateAcrossTreeRejected = false;
try {
    np_mx80_create_category($pdo, 'transit', null);
} catch (InvalidArgumentException $error) {
    $duplicateAcrossTreeRejected = true;
}
mx80_test_assert($duplicateAcrossTreeRejected, 'A duplicate category name in another branch was accepted.');
$renameRejected = false;
try {
    np_mx80_rename_category($pdo, $child, 'Core');
} catch (InvalidArgumentException $error) {
    $renameRejected = true;
}
mx80_test_assert($renameRejected, 'Renaming to an existing category name was accepted.');
np_mx80_rename_category($pdo, $child, 'Transit East');

$walkCalls = 0;
$discovery = np_mx80_discover_interfaces($pdo, 1000004, static function (
    string $ip,
    array $profile,
    string $oid
) use (&$walkCalls): array {
    $walkCalls++;
    return $oid === '1.3.6.1.2.1.31.1.1.1.18'
        ? [9=>' Uplink ',10=>'   ',11=>'Backup']
        : [9=>'ae0.10',10=>'ae0.11',11=>'ae0.12'];
});
mx80_test_assert($walkCalls === 2, 'Discovery did not read both alias and name walks.');
mx80_test_assert($discovery === ['interfaces'=>2,'without_description'=>1], 'Blank ifAlias filtering failed.');
mx80_test_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM mx80_interfaces WHERE discovered=1 AND TRIM(description)<>''")->fetchColumn() === 2,
    'Blank-alias interfaces were saved as discovered.'
);
$blankAssignmentRejected = false;
try {
    np_mx80_save_interfaces($pdo, 1000004, [
        ['ifindex'=>10,'selected'=>1,'category_id'=>$child]
    ]);
} catch (InvalidArgumentException $error) {
    $blankAssignmentRejected = true;
}
mx80_test_assert($blankAssignmentRejected, 'An interface with a blank ifAlias was assignable.');

$saved = np_mx80_save_interfaces($pdo, 1000004, [
    ['ifindex'=>9,'selected'=>1,'category_id'=>$child],
    ['ifindex'=>9,'selected'=>1,'category_id'=>$child],
    ['ifindex'=>11,'selected'=>0,'category_id'=>$child]
]);
mx80_test_assert($saved === 2, 'Repeated interface assignment payload created duplicate assignments.');
mx80_test_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM mx80_interfaces WHERE device_id=1000004')->fetchColumn() === 2,
    'Interface identity was not kept unique.'
);

$granted = np_mx80_save_category_permissions($pdo, 5, [$child,$child]);
mx80_test_assert($granted === 1, 'Duplicate permission IDs were not normalized.');
mx80_test_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM mx80_category_permissions WHERE user_id=5')->fetchColumn() === 1,
    'Duplicate user-category permission was stored.'
);
$visible = np_mx80_visible_category_ids($pdo, 5, 'user');
mx80_test_assert(isset($visible[$child]) && !isset($visible[$root]), 'Category visibility did not match the grant.');

$polledIndices = [];
$counterSample = 0;
$poll = static function (string $ip, array $profile, array $oids) use (&$polledIndices,&$counterSample): array {
    $ifIndex = (int)substr($oids[0], strrpos($oids[0], '.') + 1);
    $polledIndices[] = $ifIndex;
    $counterSample++;
    return $counterSample === 1 ? [1000,2000,100] : [2000,4000,200];
};
$first = np_mx80_poll($pdo, $poll, 1000);
$second = np_mx80_poll($pdo, $poll, 1010);
mx80_test_assert($first['polled'] === 1 && $first['samples'] === 0, 'Initial MX80 counter baseline was incorrect.');
mx80_test_assert($second['polled'] === 1 && $second['samples'] === 1, 'MX80 traffic sample was not recorded.');
mx80_test_assert($polledIndices === [9,9], 'Unselected interfaces were polled for traffic.');
$rate = $pdo->query('SELECT rx_bps,tx_bps FROM mx80_traffic_history')->fetch(PDO::FETCH_ASSOC);
mx80_test_assert(
    abs((float)$rate['rx_bps'] - 800.0) < 0.001 && abs((float)$rate['tx_bps'] - 1600.0) < 0.001,
    'MX80 RX/TX rates were calculated incorrectly.'
);
$counterType = $pdo->query('SELECT ctype FROM mx80_counters WHERE ifindex=9')->fetchColumn();
mx80_test_assert($counterType === '32', 'SNMPv1 did not use 32-bit interface counters.');

np_mx80_delete_category($pdo, $child);
$interfaceCategory = (int)$pdo->query('SELECT category_id FROM mx80_interfaces WHERE ifindex=9')->fetchColumn();
$permissionCount = $pdo->prepare('SELECT COUNT(*) FROM mx80_category_permissions WHERE category_id=?');
$permissionCount->execute([$child]);
mx80_test_assert(
    $interfaceCategory === $root && (int)$permissionCount->fetchColumn() === 0,
    'Category deletion did not preserve the interface assignment and clear stale permissions.'
);

echo "MX80 category CRUD/uniqueness, schema idempotency, discovery, assignment, permissions, selected-only polling, and rates passed.\n";
