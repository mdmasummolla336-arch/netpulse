<?php
declare(strict_types=1);

define('NP_SKIP_INIT_TZ', true);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../graph_retention_lib.php';
require_once __DIR__ . '/../port_monitor_lib.php';
require_once __DIR__ . '/../optical_history_lib.php';
require_once __DIR__ . '/../sms.php';

function smoke_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smoke_assert_empty(PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $query = $driver === 'mysql'
        ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
        : "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
    smoke_assert((int)$pdo->query($query)->fetchColumn() === 0, "$driver smoke database must be empty");
}

function smoke_core_queries(PDO $pdo, string $driver): void {
    foreach ([
        'SELECT COUNT(*) FROM maps',
        'SELECT COUNT(*) FROM devices',
        'SELECT COUNT(*) FROM map_nodes',
        'SELECT COUNT(*) FROM map_links',
        'SELECT COUNT(*) FROM services',
        'SELECT COUNT(*) FROM probes',
        'SELECT COUNT(*) FROM snmp_profiles',
        'SELECT COUNT(*) FROM status_history',
        'SELECT COUNT(*) FROM traffic_history',
        'SELECT COUNT(*) FROM app_settings',
        'SELECT COUNT(*) FROM notify_queue',
        'SELECT COUNT(*) FROM login_fail',
        'SELECT d.id FROM devices d LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile LIMIT 0',
        'SELECT check_mode FROM devices LIMIT 0',
        'SELECT l.id FROM map_links l LEFT JOIN link_traffic t ON t.link_id=l.id LIMIT 0',
        'SELECT s.id FROM services s JOIN devices d ON d.id=s.device_id LIMIT 0'
    ] as $query) {
        try {
            $pdo->query($query);
        } catch (Throwable $e) {
            throw new RuntimeException("$driver core query failed: $query", 0, $e);
        }
    }
}

function smoke_schema(PDO $pdo): void {
    smoke_assert_empty($pdo);
    np_initialize_schema($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    smoke_assert(
        np_column_exists($pdo, 'devices', 'id')
            && np_column_exists($pdo, 'devices', 'ping_fail_count')
            && np_column_exists($pdo, 'devices', 'check_mode')
            && !np_column_exists($pdo, 'devices', 'netpulse_missing_column'),
        "$driver column metadata lookup did not use the PDO connection driver"
    );
    np_ensure_index($pdo, 'devices', 'idx_schema_smoke_driver', 'id');
    $indexExists = false;
    if ($driver === 'mysql') {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'
        );
        $st->execute(['devices', 'idx_schema_smoke_driver']);
        $indexExists = (bool)$st->fetchColumn();
    } else {
        foreach ($pdo->query("PRAGMA index_list('devices')")->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['name'] === 'idx_schema_smoke_driver') $indexExists = true;
        }
    }
    smoke_assert($indexExists, "$driver index metadata lookup did not use the PDO connection driver");
    smoke_core_queries($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    np_initialize_schema($pdo);
    smoke_core_queries($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    smoke_assert(np_sms_gateway_endpoint_valid('https://sms.example/sendtext'), 'HTTPS gateway URL rejected');
    smoke_assert(np_sms_gateway_endpoint_valid('http://sms.example/sendtext'), 'HTTP gateway URL rejected');
    smoke_assert(!np_sms_gateway_endpoint_valid('https://user:pass@sms.example/sendtext'), 'Credential-bearing gateway URL accepted');
    smoke_assert(!np_sms_gateway_endpoint_valid('http://sms.example/sendtext#fragment'), 'SMS URL fragment accepted');
    np_graph_retention_schema($pdo);
    np_graph_permissions_schema($pdo);
    np_port_schema($pdo);
    op_history_schema($pdo);
    np_sms_schema($pdo);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tables = $driver === 'mysql'
        ? "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()"
        : "SELECT name FROM sqlite_master WHERE type='table'";
    $existing = array_fill_keys($pdo->query($tables)->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ([
        'graph_favorites',
        'user_graph_permissions',
        'user_graph_interface_selections',
        'port_inventory',
        'port_events',
        'port_counters',
        'port_traffic_history',
        'port_discovery_state',
        'optical_power_history',
        'sms_queue',
    ] as $table) {
        smoke_assert(isset($existing[$table]), "$driver missing table $table");
    }

    $suffix = bin2hex(random_bytes(8));
    $source = 'smoke:' . $suffix;
    $deviceId = random_int(100000000, 2000000000);
    $now = date('Y-m-d H:i:s');
    $mysql = $driver === 'mysql';
    $linkId = $deviceId;
    $unfavoritedLinkId = $deviceId + 1;
    $old = '2000-01-01 00:00:00';
    try {
        $st = $pdo->prepare('INSERT INTO graph_favorites(source_id,created_at) VALUES(?,?)');
        $st->execute([$source, $now]);
        $st = $pdo->prepare('INSERT INTO user_graph_permissions(user_id,source_id) VALUES(?,?)');
        $st->execute([$deviceId, $source]);
        $pdo->prepare('INSERT INTO map_links(id,snmp_device,snmp_ifindex) VALUES(?,?,?)')
            ->execute([$linkId, $deviceId, 1]);
        $pdo->prepare('INSERT INTO graph_favorites(source_id,created_at) VALUES(?,?)')
            ->execute([(string)$linkId, $now]);
        $pdo->prepare('INSERT INTO user_graph_permissions(user_id,source_id) VALUES(?,?)')
            ->execute([$deviceId, (string)$linkId]);
        $pdo->prepare('INSERT INTO traffic_history(link_id,ts) VALUES(?,?)')->execute([$linkId, $old]);
        $pdo->prepare('INSERT INTO traffic_history(link_id,ts) VALUES(?,?)')->execute([$unfavoritedLinkId, $old]);
        $pdo->prepare('INSERT INTO link_traffic(link_id) VALUES(?)')->execute([$linkId]);
        $st = $pdo->prepare(
            "INSERT INTO port_inventory(device_id,ifindex,ifname,alias,description,oper_status,first_seen,last_check)
             VALUES(?,?,?,?,?,'unknown',?,?)"
        );
        $st->execute([$deviceId, 1, 'smoke0', 'smoke alias', 'smoke description', $now, $now]);
        $st = $pdo->prepare('SELECT description FROM port_inventory WHERE device_id=? AND ifindex=1');
        $st->execute([$deviceId]);
        smoke_assert($st->fetchColumn() === 'smoke description', "$driver port inventory lost interface description");
        $st = $pdo->prepare('INSERT INTO port_counters(device_id,ifindex,in_oct,out_oct,ts_unix) VALUES(?,?,?,?,?)');
        $st->execute([$deviceId, 1, 10, 20, time()]);
        $st = $pdo->prepare('INSERT INTO port_discovery_state(device_id,last_discovery) VALUES(?,?)');
        $st->execute([$deviceId, time()]);
        $st = $pdo->prepare(
            'INSERT INTO optical_power_history(sampled_at,device_id,device_name,ip,sensor_index,ifname,direction,description,dbm)
             VALUES(?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([gmdate('Y-m-d\TH:i:s\Z'), $deviceId, 'smoke', '127.0.0.1', 1, 'smoke0', 'rx', 'smoke description', -10.0]);
        $st->execute([gmdate('Y-m-d\TH:i:s\Z'), $deviceId, 'smoke', '127.0.0.1', 2, 'PON1', 'rx', 'PON description', -20.0]);
        $st = $pdo->prepare('SELECT description FROM optical_power_history WHERE device_id=? AND ifname=?');
        $st->execute([$deviceId, 'smoke0']);
        smoke_assert($st->fetchColumn() === 'smoke description', "$driver optical history did not preserve interface descriptions");
        $historicalRows = [
            ['ifname'=>'smoke0'],
            ['ifname'=>'PON1']
        ];
        smoke_assert(
            count(np_filter_olt_interfaces('OLT', $historicalRows, ['smoke0'])) === 1,
            "$driver OLT view did not hide a historical excluded interface"
        );
        $st = $pdo->prepare('SELECT COUNT(*) FROM optical_power_history WHERE device_id=?');
        $st->execute([$deviceId]);
        smoke_assert((int)$st->fetchColumn() === 2, "$driver OLT filtering deleted historical optical readings");
        $st = $pdo->prepare(
            'INSERT INTO sms_queue(device_id,recipient,status,text,created,attempts,next_try)
             VALUES(?,?,?,?,?,0,0)'
        );
        $st->execute([$deviceId, '+10000000000', 'up', 'schema smoke test', time()]);

        $counts = np_graph_retention_counts($pdo, 1);
        smoke_assert(($counts['traffic_history'] ?? null) === 1, "$driver favorite-aware retention count failed");
        $deleted = np_graph_retention_cleanup($pdo, 3.0, 1);
        smoke_assert(($deleted['traffic_history'] ?? null) === 1, "$driver traffic retention cleanup failed");
        $st = $pdo->prepare('SELECT COUNT(*) FROM traffic_history WHERE link_id=?');
        $st->execute([$linkId]);
        smoke_assert((int)$st->fetchColumn() === 1, "$driver retention removed favorite graph history");

        np_delete_link_graph_data($pdo, [$linkId]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM graph_favorites WHERE source_id=?');
        $st->execute([(string)$linkId]);
        smoke_assert((int)$st->fetchColumn() === 0, "$driver link deletion left graph favorites");
        $st = $pdo->prepare('SELECT COUNT(*) FROM traffic_history WHERE link_id=?');
        $st->execute([$linkId]);
        smoke_assert((int)$st->fetchColumn() === 0, "$driver link deletion left traffic history");
    } finally {
        foreach ([
            ['DELETE FROM graph_favorites WHERE source_id=?', [$source]],
            ['DELETE FROM graph_favorites WHERE source_id=?', [(string)$linkId]],
            ['DELETE FROM user_graph_permissions WHERE user_id=? AND source_id=?', [$deviceId, $source]],
            ['DELETE FROM user_graph_permissions WHERE user_id=? AND source_id=?', [$deviceId, (string)$linkId]],
            ['DELETE FROM port_inventory WHERE device_id=?', [$deviceId]],
            ['DELETE FROM port_counters WHERE device_id=?', [$deviceId]],
            ['DELETE FROM port_discovery_state WHERE device_id=?', [$deviceId]],
            ['DELETE FROM optical_power_history WHERE device_id=?', [$deviceId]],
            ['DELETE FROM sms_queue WHERE device_id=?', [$deviceId]],
            ['DELETE FROM traffic_history WHERE link_id IN (?,?)', [$linkId, $unfavoritedLinkId]],
            ['DELETE FROM link_traffic WHERE link_id=?', [$linkId]],
            ['DELETE FROM map_links WHERE id=?', [$linkId]]
        ] as [$sql, $params]) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }
    }
}

smoke_assert(!np_olt_interface_is_allowed('OLT', 'EPON0/1:22', ['EPON0/1:22']), 'OLT EPON interface was not excluded');
smoke_assert(np_olt_interface_is_allowed('Router', 'EPON0/1:22', []), 'Non-OLT interface filtering changed');
smoke_assert(np_port_num('ethernetCsmacd(6)') === 6.0, 'SNMP ifType enumeration was not parsed.');

$legacyPorts = new PDO('sqlite::memory:');
$legacyPorts->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPorts->exec("CREATE TABLE port_inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL,
    ifindex INTEGER NOT NULL,
    ifname TEXT NOT NULL DEFAULT '',
    alias TEXT NOT NULL DEFAULT '',
    oper_status TEXT NOT NULL DEFAULT 'unknown',
    first_seen TEXT NOT NULL,
    last_check TEXT NOT NULL,
    last_change TEXT,
    UNIQUE(device_id,ifindex)
)");
$legacyPorts->exec('CREATE TABLE device_types(id INTEGER PRIMARY KEY,name TEXT)');
$legacyPorts->exec("INSERT INTO device_types VALUES(1,'OLT'),(2,'Router')");
$legacyPorts->exec('CREATE TABLE devices(id INTEGER PRIMARY KEY,type_id INTEGER)');
$legacyPorts->exec('INSERT INTO devices VALUES(1,1),(2,2)');
$legacyPorts->exec('CREATE TABLE port_discovery_state(device_id INTEGER PRIMARY KEY,last_discovery INTEGER NOT NULL)');
$legacyPorts->exec('INSERT INTO port_discovery_state VALUES(1,123),(2,456)');
$legacyPorts->exec("INSERT INTO port_inventory(device_id,ifindex,ifname,first_seen,last_check)
    VALUES(1,1,'GE0/1','2026-01-01','2026-01-01')");
np_port_schema($legacyPorts);
$discoveryStates = $legacyPorts->query(
    'SELECT device_id,last_discovery FROM port_discovery_state ORDER BY device_id'
)->fetchAll(PDO::FETCH_KEY_PAIR);
smoke_assert(
    np_column_exists($legacyPorts, 'port_inventory', 'description')
        && np_column_exists($legacyPorts, 'port_inventory', 'iftype')
        && (int)$legacyPorts->query('SELECT COUNT(*) FROM port_inventory')->fetchColumn() === 1
    && (int)$discoveryStates[1] === 123 && (int)$discoveryStates[2] === 456,
    'SQLite port schema initialization changed existing inventory or discovery state'
);

$legacyOptical = new PDO('sqlite::memory:');
$legacyOptical->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyOptical->exec("CREATE TABLE optical_power_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sampled_at TEXT NOT NULL,
    device_id INTEGER NOT NULL,
    device_name TEXT NOT NULL,
    ip TEXT NOT NULL,
    sensor_index INTEGER NOT NULL,
    ifname TEXT NOT NULL,
    direction TEXT NOT NULL CHECK(direction IN ('rx','tx')),
    dbm REAL NOT NULL,
    sensor_status INTEGER,
    raw_value REAL,
    scale INTEGER,
    `precision` INTEGER
)");
$legacyOptical->exec("INSERT INTO optical_power_history
    (sampled_at,device_id,device_name,ip,sensor_index,ifname,direction,dbm)
    VALUES ('2026-01-01T00:00:00Z',1,'legacy','127.0.0.1',1,'EPON0/1','rx',-12.5)");
op_history_schema($legacyOptical);
$legacyRow = $legacyOptical->query(
    'SELECT description,dbm FROM optical_power_history WHERE device_id=1'
)->fetch(PDO::FETCH_ASSOC);
smoke_assert(
    $legacyRow !== false && $legacyRow['description'] === '' && (float)$legacyRow['dbm'] === -12.5,
    'SQLite optical history description migration did not preserve existing readings'
);

$sqlitePath = tempnam(sys_get_temp_dir(), 'netpulse-sqlite-smoke-');
if ($sqlitePath === false) {
    throw new RuntimeException('Unable to create a temporary SQLite database file.');
}
try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    smoke_schema($sqlite);
    $sqlite = null;
    echo "SQLite fresh-database schema smoke test passed.\n";
} finally {
    unset($sqlite);
    if (is_file($sqlitePath) && !unlink($sqlitePath)) {
        throw new RuntimeException('Unable to remove temporary SQLite smoke database.');
    }
}

$mysqlDsn = getenv('NETPULSE_TEST_MYSQL_DSN') ?: '';
if ($mysqlDsn === '') {
    echo "MySQL schema smoke test skipped (set NETPULSE_TEST_MYSQL_DSN to an isolated test database).\n";
    exit(0);
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('MySQL DSN configured but pdo_mysql is not installed.');
}
$mysql = new PDO(
    $mysqlDsn,
    getenv('NETPULSE_TEST_MYSQL_USER') ?: '',
    getenv('NETPULSE_TEST_MYSQL_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$initialTables = array_fill_keys($mysql->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN), true);
try {
    smoke_schema($mysql);
    echo "MySQL/MariaDB fresh-database schema smoke test passed.\n";
} finally {
    $tables = $mysql->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (isset($initialTables[$table])) continue;
        $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
        $mysql->exec("DROP TABLE $quoted");
    }
}
