<?php
require __DIR__ . '/db.php';
require __DIR__ . '/snmp_lib.php';
require_once __DIR__ . '/optical_history_lib.php';
require_once __DIR__ . '/olt_interface_policy.php';

const OP_ENTITY_NAME = '1.3.6.1.2.1.47.1.1.1.1.2';
const OP_SENSOR_BASE = '1.3.6.1.4.1.9.9.91.1.1.1.1';

const OP_MTK_IFNAME = '1.3.6.1.4.1.14988.1.1.19.1.1.2';
const OP_MTK_TX     = '1.3.6.1.4.1.14988.1.1.19.1.1.9';
const OP_MTK_RX     = '1.3.6.1.4.1.14988.1.1.19.1.1.10';
const OP_BDCOM_IFNAME = '1.3.6.1.2.1.31.1.1.1.1';
const OP_BDCOM_SFP_BASE = '1.3.6.1.4.1.3320.9.63.1.7.1';
const OP_BDCOM_RX_COLUMNS = [1=>3, 2=>9, 3=>12, 4=>15];
const OP_BDCOM_TX_COLUMNS = [1=>2, 2=>8, 3=>11, 4=>14];
const OP_VSOL_IFNAME = '1.3.6.1.4.1.37950.1.1.5.10.1.1.1.1.2';
const OP_VSOL_IFALIAS = '1.3.6.1.4.1.37950.1.1.5.10.1.1.1.1.20';
const OP_VSOL_TX = '1.3.6.1.4.1.37950.1.1.5.10.1.1.5.1.5';
const OP_VSOL_RX = '1.3.6.1.4.1.37950.1.1.5.10.1.1.5.1.6';
const OP_VSOL_MODEL = '1.3.6.1.4.1.37950.1.1.5.10.12.5.15.0';

function op_detect_template(string $ip, array $prof, array &$identity=[]): string {
    $oid = snmp_walk($ip, $prof, '1.3.6.1.2.1.1.2.0');
    $desc = snmp_walk($ip, $prof, '1.3.6.1.2.1.1.1.0');
    $identity = [
        'sysObjectID' => (string)($oid[0] ?? ''),
        'sysDescr' => (string)($desc[0] ?? '')
    ];
    $sysid = strtolower($identity['sysObjectID']);
    $sysdescr = strtolower($identity['sysDescr']);

    if (str_contains($sysid, '1.3.6.1.4.1.9.') ||
        str_contains($sysdescr, 'cisco nx-os') ||
        str_contains($sysdescr, 'nexus operating system')) {
        return 'cisco_nexus_nxos';
    }
    if (str_contains($sysid, '1.3.6.1.4.1.14988.1') ||
        str_contains($sysdescr, 'routeros')) {
        return 'mikrotik_routeros';
    }
    if (str_contains($sysid, '1.3.6.1.4.1.3320.') ||
        str_contains($sysdescr, 'bdcom')) {
        return 'bdcom';
    }
    if (str_contains($sysid, '1.3.6.1.4.1.37950.') ||
        str_contains($sysdescr, 'vsol') ||
        str_contains($sysdescr, 'v1600d4-dp')) {
        $model = snmp_walk($ip, $prof, OP_VSOL_MODEL);
        if (strtolower(trim((string)($model[0] ?? ''))) === 'v1600d4-dp') {
            return 'vsol';
        }
    }
    return 'unsupported';
}

function op_num($value): ?float {
    if ($value === null) return null;
    $v = trim((string)$value);
    if (preg_match('/^-?\d+(?:\.\d+)?$/', $v)) return (float)$v;
    if (preg_match('/(?:INTEGER|Gauge32|INTEGER32|Counter32):\s*(-?\d+(?:\.\d+)?)/i', $v, $m)) {
        return (float)$m[1];
    }
    return null;
}

function op_profile(array $d): array {
    return [
        'version'=>(int)$d['version'],
        'port'=>(int)($d['port'] ?? 161),
        'community'=>$d['community'],
        'sec_name'=>$d['sec_name'],
        'auth_pass'=>$d['auth_pass'],
        'priv_pass'=>$d['priv_pass'],
        'auth_proto'=>$d['auth_proto'],
        'priv_proto'=>$d['priv_proto']
    ];
}

function op_collect_cisco(array $d, array $prof): array {
    $names = snmp_walk($d['ip'], $prof, OP_ENTITY_NAME);
    $cols = [];
    foreach ([1,2,3,4,5] as $col) {
        $cols[$col] = snmp_walk($d['ip'], $prof, OP_SENSOR_BASE . '.' . $col);
    }

    $rows = [];
    foreach ($names as $idx => $name) {
        if ((int)($cols[1][$idx] ?? -1) !== 14) continue;
        if (!preg_match('/Ethernet1\/(\d+).*?(Receive|Transmit) Power Sensor/i', $name, $m)) continue;

        $scale = (int)($cols[2][$idx] ?? -1);
        $precision = (int)($cols[3][$idx] ?? 0);
        $raw = op_num($cols[4][$idx] ?? null);
        $status = isset($cols[5][$idx]) ? (int)$cols[5][$idx] : null;
        if ($raw === null) continue;

        $factor = match ($scale) { 8 => 0.001, 9 => 1.0, default => null };
        if ($factor === null) continue;
        $dbm = $raw * $factor * pow(10, -$precision);

        $rows[] = [
            'device_id'=>(int)$d['id'], 'device_name'=>$d['name'], 'ip'=>$d['ip'],
            'sensor_index'=>(int)$idx, 'ifname'=>'Ethernet1/' . (int)$m[1],
            'direction'=>strtolower($m[2]) === 'receive' ? 'rx' : 'tx',
            'dbm'=>$dbm, 'sensor_status'=>$status, 'raw_value'=>$raw,
            'scale'=>$scale, 'precision'=>$precision
        ];
    }
    return $rows;
}

function op_collect_mikrotik(array $d, array $prof): array {
    $names = snmp_walk($d['ip'], $prof, OP_MTK_IFNAME);
    $tx = snmp_walk($d['ip'], $prof, OP_MTK_TX);
    $rx = snmp_walk($d['ip'], $prof, OP_MTK_RX);
    $rows = [];

    foreach ($names as $idx => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;

        foreach (['rx'=>$rx, 'tx'=>$tx] as $direction=>$values) {
            $raw = op_num($values[$idx] ?? null);
            if ($raw === null) continue;
            $rows[] = [
                'device_id'=>(int)$d['id'], 'device_name'=>$d['name'], 'ip'=>$d['ip'],
                'sensor_index'=>(int)$idx, 'ifname'=>$name, 'direction'=>$direction,
                'dbm'=>$raw / 1000, 'sensor_status'=>null, 'raw_value'=>$raw,
                'scale'=>8, 'precision'=>0
            ];
        }
    }
    return $rows;
}

function op_collect_bdcom_data(array $d, array $ifnames, array $rxChannels, array $txChannels): array {
    $rows = [];
    foreach ($ifnames as $idx => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;

        foreach (['rx'=>$rxChannels, 'tx'=>$txChannels] as $direction=>$channels) {
            foreach ($channels as $channel=>$values) {
                $raw = op_num($values[$idx] ?? null);
                if ($raw === null || $raw === -65535.0) continue;
                // BDCOM stores power in hundredths of dBm and uses -65535 when unavailable.
                $rows[] = [
                    'device_id'=>(int)$d['id'], 'device_name'=>$d['name'], 'ip'=>$d['ip'],
                    'sensor_index'=>(int)$idx,
                    'ifname'=>$channel === 1 ? $name : $name . ' (lane ' . $channel . ')',
                    'direction'=>$direction, 'dbm'=>$raw / 100, 'sensor_status'=>null,
                    'raw_value'=>$raw, 'scale'=>8, 'precision'=>2
                ];
            }
        }
    }
    return $rows;
}

function op_collect_bdcom(array $d, array $prof): array {
    $ifnames = snmp_walk($d['ip'], $prof, OP_BDCOM_IFNAME);
    $rx = [];
    $tx = [];
    foreach (OP_BDCOM_RX_COLUMNS as $channel=>$column) {
        $rx[$channel] = snmp_walk($d['ip'], $prof, OP_BDCOM_SFP_BASE . '.' . $column);
    }
    foreach (OP_BDCOM_TX_COLUMNS as $channel=>$column) {
        $tx[$channel] = snmp_walk($d['ip'], $prof, OP_BDCOM_SFP_BASE . '.' . $column);
    }
    return op_collect_bdcom_data($d, $ifnames, $rx, $tx);
}

function op_vsol_dbm($value): ?float {
    if (!is_string($value) ||
        !preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*dBm\s*$/i', $value, $match)) {
        return null;
    }
    return (float)$match[1];
}

function op_collect_vsol_data(array $d, array $ifnames, array $aliases, array $txValues, array $rxValues): array {
    $rows = [];
    foreach ($ifnames as $idx => $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $description = trim((string)($aliases[$idx] ?? ''));

        foreach (['tx'=>$txValues, 'rx'=>$rxValues] as $direction=>$values) {
            $dbm = op_vsol_dbm($values[$idx] ?? null);
            if ($dbm === null) continue;
            $rows[] = [
                'device_id'=>(int)$d['id'], 'device_name'=>$d['name'], 'ip'=>$d['ip'],
                'sensor_index'=>(int)$idx, 'ifname'=>$name, 'direction'=>$direction,
                'description'=>$description,
                'dbm'=>$dbm, 'sensor_status'=>null, 'raw_value'=>$dbm,
                'scale'=>8, 'precision'=>3
            ];
        }
    }
    return $rows;
}

function op_collect_vsol(array $d, array $prof): array {
    $ifnames = snmp_walk($d['ip'], $prof, OP_VSOL_IFNAME);
    $aliases = snmp_walk($d['ip'], $prof, OP_VSOL_IFALIAS);
    $tx = snmp_walk($d['ip'], $prof, OP_VSOL_TX);
    $rx = snmp_walk($d['ip'], $prof, OP_VSOL_RX);
    return op_collect_vsol_data($d, $ifnames, $aliases, $tx, $rx);
}

function op_collect(?PDO $pdo = null): array {
    $pdo ??= db();
    $devices = $pdo->query(
        'SELECT d.id,d.name,d.ip,d.vendor,dt.name AS type_name,
                p.community,p.version,p.port,
                p.sec_name,p.auth_pass,p.priv_pass,p.auth_proto,p.priv_proto
         FROM devices d JOIN snmp_profiles p ON p.id=d.snmp_profile
         LEFT JOIN device_types dt ON dt.id=d.type_id
         WHERE d.id<>1000004 AND UPPER(TRIM(COALESCE(dt.name,\'\')))<>\'OLT\'
           AND d.ip IS NOT NULL AND d.ip <> "" ORDER BY d.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $all = [];
    foreach ($devices as $d) {
        $prof = op_profile($d);
        $override = (string)setting_get('optical_template_device_' . (int)$d['id'], 'auto');
        if (!in_array($override, ['auto','cisco_nexus_nxos','mikrotik_routeros','bdcom','vsol','none'], true)) {
            $override = 'auto';
        }
        $template = $override === 'auto'
            ? op_detect_template($d['ip'], $prof)
            : $override;
        if ($template === 'none' || $template === 'unsupported') continue;

        $deviceRows = [];
        if ($template === 'cisco_nexus_nxos') {
            $deviceRows = op_collect_cisco($d, $prof);
        } elseif ($template === 'mikrotik_routeros') {
            $deviceRows = op_collect_mikrotik($d, $prof);
        } elseif ($template === 'bdcom') {
            $deviceRows = op_collect_bdcom($d, $prof);
        } elseif ($template === 'vsol') {
            $deviceRows = op_collect_vsol($d, $prof);
        }
        $allowlist = np_olt_interface_allowlist(
            (int)$d['id'], (string)($d['type_name'] ?? ''), (string)$d['ip'], (string)$d['vendor']
        );
        $all = array_merge($all, np_filter_olt_interfaces(
            (string)($d['type_name'] ?? ''), $deviceRows, $allowlist
        ));
    }

    usort($all, fn($a,$b) =>
        [$a['device_id'],$a['ifname'],$a['direction']] <=>
        [$b['device_id'],$b['ifname'],$b['direction']]
    );
    return $all;
}

function op_print(array $rows): void {
    printf("%-16s %-3s %10s %8s %10s\n",
        'INTERFACE','DIR','POWER dBm','STATUS','RAW');
    foreach ($rows as $r) {
        printf("%-16s %-3s %10.3f %8s %10s\n",
            $r['ifname'], strtoupper($r['direction']), $r['dbm'],
            $r['sensor_status'] === 1 ? 'OK' :
                ($r['sensor_status'] === null ? 'UNKNOWN' : (string)$r['sensor_status']),
            $r['raw_value']);
    }
    echo 'TOTAL_RX_TX_SENSORS=' . count($rows) . PHP_EOL;
}


function op_save_history(PDO $pdo, array $rows): int {
    if (!$rows) {
        throw new RuntimeException('No readings; refusing to write');
    }

    op_history_schema($pdo);

    $time = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $pdo->prepare("INSERT INTO optical_power_history
        (sampled_at,device_id,device_name,ip,sensor_index,ifname,direction,description,
         dbm,sensor_status,raw_value,scale,`precision`)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $stmt->execute([
                $time, $r['device_id'], $r['device_name'], $r['ip'],
                $r['sensor_index'], $r['ifname'], $r['direction'], $r['description'] ?? '',
                $r['dbm'], $r['sensor_status'], $r['raw_value'],
                $r['scale'], $r['precision']
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return count($rows);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/cli_guard.php';
    np_cli_guard();

    try {
        $mode = $argv[1] ?? 'test';
        if (!in_array($mode, ['test', 'once'], true)) {
            fwrite(STDERR, "Usage: php optical_poll.php [test|once]\n");
            exit(2);
        }

        $rows = op_collect();
        op_print($rows);

        if ($mode === 'once') {
            $n = op_save_history(db(), $rows);
            echo "HISTORY_ROWS_SAVED=$n\n";
        } else {
            echo "READ_ONLY_TEST=1 (no history writes)\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Optical poll failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
