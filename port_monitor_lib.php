<?php
declare(strict_types=1);

require_once __DIR__ . '/olt_interface_policy.php';

function np_port_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_inventory (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            device_id BIGINT NOT NULL,
            ifindex INT NOT NULL,
            ifname VARCHAR(255) NOT NULL DEFAULT '',
            alias VARCHAR(255) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            iftype INT NULL,
            admin_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
            oper_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
            status_pending VARCHAR(16) NOT NULL DEFAULT '',
            status_pending_count INT NOT NULL DEFAULT 0,
            last_attempt DATETIME NULL,
            last_successful_poll DATETIME NULL,
            last_error VARCHAR(32) NOT NULL DEFAULT '',
            first_seen DATETIME NOT NULL,
            last_check DATETIME NOT NULL,
            last_change DATETIME NULL,
            UNIQUE KEY uq_port_inventory_device_ifindex(device_id,ifindex)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            device_id BIGINT NOT NULL,
            ifindex INT NOT NULL,
            ifname VARCHAR(255),
            alias VARCHAR(255),
            status VARCHAR(16) NOT NULL,
            message TEXT NOT NULL,
            INDEX idx_port_events_ts(ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_counters (
            device_id BIGINT NOT NULL,
            ifindex INT NOT NULL,
            in_oct DOUBLE,
            out_oct DOUBLE,
            ts_unix BIGINT NOT NULL,
            PRIMARY KEY(device_id,ifindex)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_traffic_history (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            device_id BIGINT NOT NULL,
            ifindex INT NOT NULL,
            rx_bps DOUBLE,
            tx_bps DOUBLE,
            INDEX idx_port_traffic(device_id,ifindex,ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_discovery_state (
            device_id BIGINT PRIMARY KEY,
            last_discovery BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_incidents (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            device_id BIGINT NOT NULL,
            ifindex INT NOT NULL,
            status VARCHAR(16) NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            last_seen_at DATETIME NOT NULL,
            message TEXT NOT NULL,
            INDEX idx_port_incidents_open(device_id,ifindex,state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            ifindex INTEGER NOT NULL,
            ifname TEXT NOT NULL DEFAULT '',
            alias TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            iftype INTEGER,
            admin_status TEXT NOT NULL DEFAULT 'unknown',
            oper_status TEXT NOT NULL DEFAULT 'unknown',
            status_pending TEXT NOT NULL DEFAULT '',
            status_pending_count INTEGER NOT NULL DEFAULT 0,
            last_attempt TEXT,
            last_successful_poll TEXT,
            last_error TEXT NOT NULL DEFAULT '',
            first_seen TEXT NOT NULL,
            last_check TEXT NOT NULL,
            last_change TEXT,
            UNIQUE(device_id,ifindex)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            device_id INTEGER NOT NULL,
            ifindex INTEGER NOT NULL,
            ifname TEXT,
            alias TEXT,
            status TEXT NOT NULL,
            message TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_counters (
            device_id INTEGER NOT NULL,
            ifindex INTEGER NOT NULL,
            in_oct REAL,
            out_oct REAL,
            ts_unix INTEGER NOT NULL,
            PRIMARY KEY(device_id,ifindex)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_traffic_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            device_id INTEGER NOT NULL,
            ifindex INTEGER NOT NULL,
            rx_bps REAL,
            tx_bps REAL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_port_events_ts ON port_events(ts)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_port_traffic ON port_traffic_history(device_id,ifindex,ts)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_discovery_state (
            device_id INTEGER PRIMARY KEY,
            last_discovery INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS port_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id INTEGER NOT NULL,
            ifindex INTEGER NOT NULL,
            status TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT 'open',
            opened_at TEXT NOT NULL,
            resolved_at TEXT,
            last_seen_at TEXT NOT NULL,
            message TEXT NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_port_incidents_open ON port_incidents(device_id,ifindex,state)");
    }

    foreach (['description', 'iftype', 'admin_status', 'status_pending', 'status_pending_count', 'last_attempt', 'last_successful_poll', 'last_error'] as $column) {
        if (!np_column_exists($pdo, 'port_inventory', $column)) {
            $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = match ($column) {
                'description' => $mysql
                    ? "ALTER TABLE port_inventory ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''"
                    : "ALTER TABLE port_inventory ADD COLUMN description TEXT NOT NULL DEFAULT ''",
                'iftype' => $mysql
                    ? 'ALTER TABLE port_inventory ADD COLUMN iftype INT NULL'
                    : 'ALTER TABLE port_inventory ADD COLUMN iftype INTEGER',
                'admin_status' => $mysql
                    ? "ALTER TABLE port_inventory ADD COLUMN admin_status VARCHAR(16) NOT NULL DEFAULT 'unknown'"
                    : "ALTER TABLE port_inventory ADD COLUMN admin_status TEXT NOT NULL DEFAULT 'unknown'",
                'status_pending' => $mysql
                    ? "ALTER TABLE port_inventory ADD COLUMN status_pending VARCHAR(16) NOT NULL DEFAULT ''"
                    : "ALTER TABLE port_inventory ADD COLUMN status_pending TEXT NOT NULL DEFAULT ''",
                'status_pending_count' => $mysql
                    ? 'ALTER TABLE port_inventory ADD COLUMN status_pending_count INT NOT NULL DEFAULT 0'
                    : 'ALTER TABLE port_inventory ADD COLUMN status_pending_count INTEGER NOT NULL DEFAULT 0',
                'last_attempt' => $mysql
                    ? 'ALTER TABLE port_inventory ADD COLUMN last_attempt DATETIME NULL'
                    : 'ALTER TABLE port_inventory ADD COLUMN last_attempt TEXT',
                'last_successful_poll' => $mysql
                    ? 'ALTER TABLE port_inventory ADD COLUMN last_successful_poll DATETIME NULL'
                    : 'ALTER TABLE port_inventory ADD COLUMN last_successful_poll TEXT',
                'last_error' => $mysql
                    ? "ALTER TABLE port_inventory ADD COLUMN last_error VARCHAR(32) NOT NULL DEFAULT ''"
                    : "ALTER TABLE port_inventory ADD COLUMN last_error TEXT NOT NULL DEFAULT ''",
                default => null,
            };
            if ($sql !== null) {
                $pdo->exec($sql);
            }
        }
    }

}

function np_port_num($v): ?float {
    if ($v === null || $v === '') return null;
    $s = trim((string)$v);
    if (preg_match('/\((-?\d+)\)\s*$/', $s, $m)) return (float)$m[1];
    if (preg_match('/: *(-?\d+(?:\.\d+)?)/', $s, $m)) return (float)$m[1];
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*$/', $s, $m)) return (float)$m[1];
    return null;
}

function np_port_status($v): string {
    $status = np_port_enum_status($v, 'integer', 'oper');
    return $status === 'invalid' ? 'unknown' : $status;
}

function np_port_enum_status($value, string $type, string $interface): string {
    $validText = $interface === 'oper'
        ? ['up','down','testing','unknown','dormant','notpresent','lowerlayerdown']
        : ['up','down','testing'];
    if ($type === 'enum_text' && is_string($value)) {
        $text = strtolower(trim($value));
        if (!in_array($text, $validText, true)) return 'invalid';
        return in_array($text, ['up','down'], true) ? $text : 'unknown';
    }
    if ($type !== 'integer' || (!is_int($value) && !is_float($value) && !is_string($value))) {
        return 'invalid';
    }
    $text = trim((string)$value);
    if (!preg_match('/^\d+$/', $text, $match)) return 'invalid';
    $number = (int)$match[0];
    if ($interface === 'oper') {
        return match ($number) {
            1 => 'up',
            2 => 'down',
            3, 4, 5, 6, 7 => 'unknown',
            default => 'invalid',
        };
    }
    return match ($number) {
        1 => 'up',
        2 => 'down',
        3 => 'unknown',
        default => 'invalid',
    };
}

function np_port_status_from_sample(array $sample): string {
    return np_port_enum_status(
        $sample['oper_status'] ?? null,
        (string)($sample['oper_type'] ?? ''),
        'oper'
    );
}

function np_port_open_incident(PDO $pdo, int $deviceId, int $ifIndex, string $status, string $ts, string $message): void {
    $st = $pdo->prepare('SELECT id FROM port_incidents WHERE device_id=? AND ifindex=? AND state=? ORDER BY id DESC LIMIT 1');
    $st->execute([$deviceId, $ifIndex, 'open']);
    $id = $st->fetchColumn();
    if ($id !== false && $id !== null) {
        $pdo->prepare('UPDATE port_incidents SET last_seen_at=?, message=? WHERE id=?')->execute([$ts, $message, $id]);
        return;
    }
    $pdo->prepare('INSERT INTO port_incidents(device_id,ifindex,status,state,opened_at,resolved_at,last_seen_at,message) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$deviceId, $ifIndex, $status, 'open', $ts, null, $ts, $message]);
}

function np_port_resolve_incident(PDO $pdo, int $deviceId, int $ifIndex, string $ts, string $message): void {
    $st = $pdo->prepare('SELECT id FROM port_incidents WHERE device_id=? AND ifindex=? AND state=? ORDER BY id DESC LIMIT 1');
    $st->execute([$deviceId, $ifIndex, 'open']);
    $id = $st->fetchColumn();
    if ($id === false || $id === null) return;
    $pdo->prepare('UPDATE port_incidents SET state=?, resolved_at=?, last_seen_at=?, message=? WHERE id=?')->execute(['resolved', $ts, $ts, $message, $id]);
}

function np_port_apply_status(PDO $pdo, array $row, array $sample, int $confirmThreshold = 3): array {
    $now = (string)($sample['ts'] ?? date('Y-m-d H:i:s'));
    $confirmed = trim((string)($row['oper_status'] ?? 'unknown')) ?: 'unknown';
    $pending = trim((string)($row['status_pending'] ?? '')) ?: $confirmed;
    $count = max(0, (int)($row['status_pending_count'] ?? 0));
    $lastAttempt = $now;
    $lastSuccess = $row['last_successful_poll'] ?? null;
    $lastError = '';
    $eventStatus = null;
    $changed = false;
    $observe = np_port_status_from_sample($sample);
    $outcome = (string)($sample['outcome'] ?? 'success');
    $adminStatus = np_port_enum_status(
        $sample['admin_status'] ?? null,
        (string)($sample['admin_type'] ?? ''),
        'admin'
    );
    if ($adminStatus === 'invalid') {
        $adminStatus = trim((string)($row['admin_status'] ?? 'unknown')) ?: 'unknown';
    }

    if ($outcome !== 'success') {
        $lastError = $outcome;
        return [
            'oper_status' => $confirmed,
            'admin_status' => $adminStatus,
            'status_pending' => $pending,
            'status_pending_count' => $count,
            'last_attempt' => $lastAttempt,
            'last_successful_poll' => $lastSuccess,
            'last_error' => $lastError,
            'changed' => false,
            'event_status' => null,
        ];
    }

    if ($observe === 'unknown') {
        return [
            'oper_status' => $confirmed,
            'admin_status' => $adminStatus,
            'status_pending' => '',
            'status_pending_count' => 0,
            'last_attempt' => $lastAttempt,
            'last_successful_poll' => $now,
            'last_error' => '',
            'changed' => false,
            'event_status' => null,
        ];
    }
    if ($observe === 'invalid') {
        return [
            'oper_status' => $confirmed,
            'admin_status' => $adminStatus,
            'status_pending' => $pending,
            'status_pending_count' => $count,
            'last_attempt' => $lastAttempt,
            'last_successful_poll' => $lastSuccess,
            'last_error' => 'invalid_status',
            'changed' => false,
            'event_status' => null,
        ];
    }

    $lastSuccess = $now;
    if ($observe === $pending) {
        $count++;
    } else {
        $pending = $observe;
        $count = 1;
    }

    if ($count >= max(1, (int)$confirmThreshold)) {
        if ($confirmed !== $observe) {
            $changed = true;
            $eventStatus = $observe;
            $confirmed = $observe;
        }
        $pending = '';
        $count = 0;
    }

    return [
        'oper_status' => $confirmed,
        'admin_status' => $adminStatus,
        'status_pending' => $pending,
        'status_pending_count' => $count,
        'last_attempt' => $lastAttempt,
        'last_successful_poll' => $lastSuccess,
        'last_error' => '',
        'changed' => $changed,
        'event_status' => $eventStatus,
    ];
}

function np_port_persist_status(PDO $pdo, array $statusUpdate, array $event): void {
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare(
            'UPDATE port_inventory SET admin_status=?, oper_status=?, status_pending=?, status_pending_count=?, last_attempt=?, last_successful_poll=?, last_error=?, last_check=?, last_change=CASE WHEN ? THEN ? ELSE last_change END WHERE device_id=? AND ifindex=?'
        );
        $q->execute([
            $statusUpdate['admin_status'],
            $statusUpdate['oper_status'],
            $statusUpdate['status_pending'],
            $statusUpdate['status_pending_count'],
            $statusUpdate['last_attempt'],
            $statusUpdate['last_successful_poll'],
            $statusUpdate['last_error'],
            $event['now'],
            $statusUpdate['changed'] ? 1 : 0,
            $event['now'],
            $event['device_id'],
            $event['ifindex'],
        ]);
        if ($statusUpdate['event_status'] !== null) {
            $message = $event['message'];
            $q = $pdo->prepare('INSERT INTO port_events(ts,device_id,ifindex,ifname,alias,status,message) VALUES(?,?,?,?,?,?,?)');
            $q->execute([
                $event['now'],
                $event['device_id'],
                $event['ifindex'],
                $event['ifname'],
                $event['alias'],
                $statusUpdate['event_status'],
                $message,
            ]);
            if ($statusUpdate['event_status'] === 'down') {
                np_port_open_incident($pdo, $event['device_id'], $event['ifindex'], 'down', $event['now'], $message);
            } else {
                np_port_resolve_incident($pdo, $event['device_id'], $event['ifindex'], $event['now'], $message);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function np_port_poll(PDO $pdo, ?callable $walkResult = null, ?string $lockPath = null): array {
    require_once __DIR__ . '/snmp_lib.php';
    require_once __DIR__ . '/telegram.php';

    $lockDir = __DIR__ . '/data';
    if ($lockPath === null) {
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0770, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Unable to prepare port monitor lock directory.');
        }
        $lockPath = $lockDir . '/port_monitor.lock';
    }
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Unable to open port monitor lock file.');
    }
    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        @fclose($lockHandle);
        return ['interfaces' => 0, 'events' => 0, 'locked' => true];
    }

    $discoverCount = 0;
    $eventCount = 0;
    try {
        np_port_schema($pdo);
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $walkResult ??= static fn(string $ip, array $profile, string $oid): array =>
            snmp_walk_result($ip, $profile, $oid);
        $now = date('Y-m-d H:i:s');
        $epoch = time();
        $discoveryInterval = 900;
        $devices = $pdo->query(
            "SELECT d.id,d.name,d.ip,d.snmp_profile,d.vendor,dt.name AS type_name,
                    sp.community,sp.version,sp.port,sp.sec_name,sp.auth_pass,
                    sp.priv_pass,sp.auth_proto,sp.priv_proto
             FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id
             LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile
             WHERE COALESCE(d.monitored,1)=1
               AND UPPER(TRIM(COALESCE(dt.name,'')))<>'OLT'
               AND d.id<>1000004
               AND d.ip IS NOT NULL AND d.ip<>''"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($devices as $d) {
            if ($d['version'] === null || (int)$d['version'] < 0) continue;

            $deviceId = (int)$d['id'];
            $deviceType = (string)($d['type_name'] ?? '');
            $allowlist = np_olt_interface_allowlist(
                $deviceId, $deviceType, (string)$d['ip'], (string)$d['vendor']
            );
            $prof = [
                'version' => (int)$d['version'],
                'port' => (int)($d['port'] ?? 161),
                'community' => $d['community'],
                'sec_name' => $d['sec_name'],
                'auth_pass' => $d['auth_pass'],
                'priv_pass' => $d['priv_pass'],
                'auth_proto' => $d['auth_proto'],
                'priv_proto' => $d['priv_proto'],
            ];

            $q = $pdo->prepare('SELECT last_discovery FROM port_discovery_state WHERE device_id=?');
            $q->execute([$deviceId]);
            $lastDiscovery = $q->fetchColumn();
            $q = $pdo->prepare('SELECT COUNT(*) FROM port_inventory WHERE device_id=?');
            $q->execute([$deviceId]);
            $hasInventory = (int)$q->fetchColumn() > 0;
            $discoveryDue = !$hasInventory || $lastDiscovery === false || ($epoch - (int)$lastDiscovery) >= $discoveryInterval;

            if ($discoveryDue) {
                $names = snmp_walk($d['ip'], $prof, '1.3.6.1.2.1.31.1.1.1.1');
                if (!$names) $names = snmp_walk($d['ip'], $prof, '1.3.6.1.2.1.2.2.1.2');
                if ($names) {
                    $aliases = snmp_walk($d['ip'], $prof, '1.3.6.1.2.1.31.1.1.1.18');
                    $descriptions = np_is_olt_type($deviceType)
                        ? snmp_walk($d['ip'], $prof, '1.3.6.1.2.1.2.2.1.2')
                        : [];
                    $iftypes = np_is_olt_type($deviceType)
                        ? snmp_walk($d['ip'], $prof, '1.3.6.1.2.1.2.2.1.3')
                        : [];
                    $inventoryUpsert = $mysql
                        ? "INSERT INTO port_inventory (device_id,ifindex,ifname,alias,description,iftype,admin_status,oper_status,first_seen,last_check) VALUES(?,?,?,?,?,?, 'unknown','unknown', ?, ?) ON DUPLICATE KEY UPDATE ifname=VALUES(ifname), alias=VALUES(alias), description=VALUES(description), iftype=VALUES(iftype), last_check=VALUES(last_check)"
                        : "INSERT INTO port_inventory (device_id,ifindex,ifname,alias,description,iftype,admin_status,oper_status,first_seen,last_check) VALUES(?,?,?,?,?,?, 'unknown','unknown', ?, ?) ON CONFLICT(device_id,ifindex) DO UPDATE SET ifname=excluded.ifname, alias=excluded.alias, description=excluded.description, iftype=excluded.iftype, last_check=excluded.last_check";
                    $upsert = $pdo->prepare($inventoryUpsert);
                    foreach ($names as $idx => $rawName) {
                        $idx = (int)$idx;
                        if ($idx < 1) continue;
                        $ifname = trim((string)$rawName);
                        $alias = trim((string)($aliases[$idx] ?? ''));
                        $description = trim((string)($descriptions[$idx] ?? ''));
                        if ($description === $ifname) $description = '';
                        $iftype = np_is_olt_type($deviceType) ? np_port_num($iftypes[$idx] ?? null) : null;
                        $interface = ['ifindex' => $idx, 'ifname' => $ifname, 'alias' => $alias, 'description' => $description, 'iftype' => $iftype];
                        if (!np_olt_interface_is_allowed($deviceType, $ifname, $allowlist) && !np_olt_port_is_graph_candidate($interface)) continue;
                        $upsert->execute([$deviceId, $idx, $ifname, $alias, $description, $iftype === null ? null : (int)$iftype, $now, $now]);
                    }
                    $discoveryUpsert = $mysql
                        ? 'INSERT INTO port_discovery_state(device_id,last_discovery) VALUES(?,?) ON DUPLICATE KEY UPDATE last_discovery=VALUES(last_discovery)'
                        : 'INSERT INTO port_discovery_state(device_id,last_discovery) VALUES(?,?) ON CONFLICT(device_id) DO UPDATE SET last_discovery=excluded.last_discovery';
                    $pdo->prepare($discoveryUpsert)->execute([$deviceId, $epoch]);
                }
            }

            $q = $pdo->prepare('SELECT ifindex,ifname,alias,description,iftype,oper_status,admin_status,status_pending,status_pending_count,last_attempt,last_successful_poll,last_error FROM port_inventory WHERE device_id=? ORDER BY ifindex');
            $q->execute([$deviceId]);
            $inventory = array_values(array_filter(
                $q->fetchAll(PDO::FETCH_ASSOC),
                static fn(array $row): bool => !np_is_olt_type($deviceType)
                    || np_olt_interface_is_allowed($deviceType, (string)$row['ifname'], $allowlist)
                    || np_olt_port_is_graph_candidate($row)
            ));
            if (!$inventory) continue;

            $operResult = $walkResult($d['ip'], $prof, '1.3.6.1.2.1.2.2.1.8');
            $adminResult = $walkResult($d['ip'], $prof, '1.3.6.1.2.1.2.2.1.7');
            $ins = $walkResult($d['ip'], $prof, '1.3.6.1.2.1.31.1.1.1.6');
            $outs = $walkResult($d['ip'], $prof, '1.3.6.1.2.1.31.1.1.1.10');

            foreach ($inventory as $row) {
                $idx = (int)$row['ifindex'];
                $name = trim((string)$row['ifname']);
                if (!np_olt_interface_is_allowed($deviceType, $name, $allowlist) && !np_olt_port_is_graph_candidate($row)) continue;
                $alias = trim((string)$row['alias']);
                $operOutcome = (string)($operResult['status'] ?? 'malformed_response');
                $operValueExists = array_key_exists($idx, $operResult['values'] ?? []);
                $operValue = $operValueExists ? $operResult['values'][$idx] : null;
                $operType = (string)($operResult['types'][$idx] ?? '');
                if ($operOutcome === 'success' && !$operValueExists) {
                    $operOutcome = 'missing_index';
                } elseif ($operOutcome === 'success' &&
                    !in_array($operType, ['integer','enum_text'], true)) {
                    $operOutcome = 'unexpected_type';
                } elseif ($operOutcome === 'success' &&
                    np_port_enum_status($operValue, $operType, 'oper') === 'invalid') {
                    $operOutcome = 'invalid_enum';
                }
                $adminValueExists = array_key_exists($idx, $adminResult['values'] ?? []);
                $sample = [
                    'ts' => $now,
                    'oper_status' => $operValue,
                    'oper_type' => $operType,
                    'admin_status' => $adminValueExists ? $adminResult['values'][$idx] : null,
                    'admin_type' => (string)($adminResult['types'][$idx] ?? ''),
                    'outcome' => $operOutcome,
                ];
                $statusUpdate = np_port_apply_status($pdo, $row, $sample, 3);
                $msg = "Port {$name}" . ($alias !== '' ? " ({$alias})" : '') . " on {$d['name']} [{$d['ip']}] is " . strtoupper((string)$statusUpdate['event_status']);
                np_port_persist_status($pdo, $statusUpdate, [
                    'now'=>$now,
                    'device_id'=>$deviceId,
                    'ifindex'=>$idx,
                    'ifname'=>$name,
                    'alias'=>$alias,
                    'message'=>$msg,
                ]);
                if ($statusUpdate['event_status'] !== null) {
                    $eventCount++;
                }

                $in = np_port_num($ins['values'][$idx] ?? null);
                $out = np_port_num($outs['values'][$idx] ?? null);
                if ($in !== null && $out !== null) {
                    $q = $pdo->prepare('SELECT in_oct,out_oct,ts_unix FROM port_counters WHERE device_id=? AND ifindex=?');
                    $q->execute([$deviceId, $idx]);
                    $prev = $q->fetch(PDO::FETCH_ASSOC);
                    if ($prev && $epoch > (int)$prev['ts_unix']) {
                        $dt = $epoch - (int)$prev['ts_unix'];
                        $di = $in - (float)$prev['in_oct'];
                        $do = $out - (float)$prev['out_oct'];
                        if ($di >= 0 && $do >= 0) {
                            $q = $pdo->prepare('INSERT INTO port_traffic_history(ts,device_id,ifindex,rx_bps,tx_bps) VALUES(?,?,?,?,?)');
                            $q->execute([$now, $deviceId, $idx, $di * 8 / $dt, $do * 8 / $dt]);
                        }
                    }
                    $counterUpsert = $mysql
                        ? 'INSERT INTO port_counters(device_id,ifindex,in_oct,out_oct,ts_unix) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE in_oct=VALUES(in_oct),out_oct=VALUES(out_oct),ts_unix=VALUES(ts_unix)'
                        : 'INSERT OR REPLACE INTO port_counters(device_id,ifindex,in_oct,out_oct,ts_unix) VALUES(?,?,?,?,?)';
                    $q = $pdo->prepare($counterUpsert);
                    $q->execute([$deviceId, $idx, $in, $out, $epoch]);
                }
                $discoverCount++;
            }
        }
        return ['interfaces' => $discoverCount, 'events' => $eventCount, 'locked' => false];
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
