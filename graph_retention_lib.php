<?php
declare(strict_types=1);

require_once __DIR__ . '/olt_interface_policy.php';

function np_graph_retention_schema(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sourceType = $mysql ? "VARCHAR(64)" : "TEXT";
    $createdType = $mysql ? "VARCHAR(32)" : "TEXT";
    $pdo->exec("CREATE TABLE IF NOT EXISTS graph_favorites (
        source_id $sourceType PRIMARY KEY,
        created_at $createdType NOT NULL
    )" . ($mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ""));
}

function np_graph_permissions_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_graph_permissions (
            user_id BIGINT NOT NULL,
            source_id VARCHAR(64) NOT NULL,
            PRIMARY KEY(user_id, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        np_graph_interface_selections_schema($pdo);
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_graph_permissions (
        user_id INTEGER NOT NULL,
        source_id TEXT NOT NULL,
        PRIMARY KEY(user_id, source_id)
    )");
    np_graph_interface_selections_schema($pdo);
}

function np_graph_interface_selections_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_graph_interface_selections (
            user_id BIGINT NOT NULL,
            source_id VARCHAR(64) NOT NULL,
            PRIMARY KEY(user_id, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_graph_interface_selections (
        user_id INTEGER NOT NULL,
        source_id TEXT NOT NULL,
        PRIMARY KEY(user_id, source_id)
    )");
}

function np_graph_mx80_selected_ids(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT source_id FROM user_graph_interface_selections WHERE user_id=?');
    $st->execute([$userId]);
    return array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

function np_graph_mx80_save_selection(PDO $pdo, int $userId, array $sourceIds, ?array $allowedIds = null): void {
    $sources = [];
    $editableIds = [];
    if ($allowedIds !== null) {
        foreach (array_unique(array_map('strval', $allowedIds)) as $allowedId) {
            if (preg_match('/^p:(\d+):(\d+)$/', $allowedId)) {
                $editableIds[$allowedId] = $allowedId;
                continue;
            }
            if (!preg_match('/^\d+$/', $allowedId)) continue;
            $st = $pdo->prepare("SELECT l.snmp_device,l.snmp_ifindex FROM map_links l
                JOIN devices d ON d.id=l.snmp_device
                WHERE l.id=? AND l.snmp_type=1 AND d.name='MX80' AND d.ip='10.255.240.9'");
            $st->execute([(int)$allowedId]);
            $link = $st->fetch(PDO::FETCH_ASSOC);
            if ($link) {
                $selectionId = 'p:' . (int)$link['snmp_device'] . ':' . (int)$link['snmp_ifindex'];
                $editableIds[$selectionId] = $selectionId;
            }
        }
    }
    foreach ($sourceIds as $sourceId) {
        if (!is_string($sourceId) || !preg_match('/^p:(\d+):(\d+)$/', $sourceId, $match)) {
            throw new InvalidArgumentException('Invalid MX80 interface selection.');
        }
        $canonicalId = 'p:' . (int)$match[1] . ':' . (int)$match[2];
        $st = $pdo->prepare("SELECT 1 FROM devices d
            WHERE d.id=? AND d.name='MX80' AND d.ip='10.255.240.9'
              AND (EXISTS (SELECT 1 FROM port_inventory p WHERE p.device_id=d.id AND p.ifindex=?)
                OR EXISTS (SELECT 1 FROM map_links l WHERE l.snmp_device=d.id AND l.snmp_ifindex=? AND l.snmp_type=1))");
        $st->execute([(int)$match[1], (int)$match[2], (int)$match[2]]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('MX80 interface was not found.');
        }
        if ($allowedIds !== null && !in_array($canonicalId, $allowedIds, true)) {
            $st = $pdo->prepare("SELECT id FROM map_links
                WHERE snmp_device=? AND snmp_ifindex=? AND snmp_type=1");
            $st->execute([(int)$match[1], (int)$match[2]]);
            $allowedLink = false;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $linkId) {
                if (in_array((string)$linkId, $allowedIds, true)) {
                    $allowedLink = true;
                    break;
                }
            }
            if (!$allowedLink) throw new InvalidArgumentException('You are not allowed to select this graph.');
        }
        $sources[$canonicalId] = $canonicalId;
    }

    $pdo->beginTransaction();
    try {
        if ($allowedIds === null) {
            $pdo->prepare('DELETE FROM user_graph_interface_selections WHERE user_id=?')->execute([$userId]);
        } else {
            $delete = $pdo->prepare('DELETE FROM user_graph_interface_selections WHERE user_id=? AND source_id=?');
            foreach ($editableIds as $editableId) {
                $delete->execute([$userId, $editableId]);
            }
        }
        $insert = $pdo->prepare('INSERT INTO user_graph_interface_selections(user_id,source_id) VALUES(?,?)');
        foreach ($sources as $sourceId) $insert->execute([$userId, $sourceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function np_graph_filter_sources_for_role(array $sources, string $role, array $allowedIds): array {
    if (in_array($role, ['admin', 'administrator'], true)) return $sources;
    $allowed = array_fill_keys(array_map('strval', $allowedIds), true);
    return array_values(array_filter(
        $sources,
        static fn(array $source): bool => isset($allowed[(string)$source['id']])
    ));
}

function np_olt_graph_counter_available(array $interface, ?int $now = null): bool {
    $now ??= time();
    $counterTs = (int)($interface['counter_ts'] ?? 0);
    return isset($interface['in_oct'], $interface['out_oct'])
        && is_numeric($interface['in_oct'])
        && is_numeric($interface['out_oct'])
        && $counterTs > 0
        && $counterTs <= $now
        && $now - $counterTs <= 900;
}

function np_graph_olt_interface_is_available(PDO $pdo, int $deviceId, int $ifIndex): ?bool {
    $device = $pdo->prepare(
        'SELECT dt.name FROM devices d LEFT JOIN device_types dt ON dt.id=d.type_id WHERE d.id=?'
    );
    $device->execute([$deviceId]);
    $typeName = $device->fetchColumn();
    if ($typeName === false) return false;
    if (np_is_olt_type((string)$typeName)) return false;
    return null;
}

function np_graph_sources(PDO $pdo, string $role, array $allowedIds = [], ?int $userId = null): array {
    $mx80Selected = $userId ? np_graph_mx80_selected_ids($pdo, $userId) : [];
    $rows=$pdo->query("SELECT l.id,l.snmp_ifindex ifidx,l.snmp_device AS device_id,
        md.name master,md.name AS device_name,md.ip,dt.name AS device_type,
        pi.ifname,pi.alias,pi.description,pi.iftype,pc.in_oct,pc.out_oct,
        pc.ts_unix AS counter_ts,
        (SELECT dn.name FROM map_nodes nf JOIN devices dn ON dn.id=nf.device_id WHERE nf.id=l.from_node) fromname,
        (SELECT dn.name FROM map_nodes nt JOIN devices dn ON dn.id=nt.device_id WHERE nt.id=l.to_node) toname
        FROM map_links l JOIN devices md ON md.id=l.snmp_device
        LEFT JOIN device_types dt ON dt.id=md.type_id
        LEFT JOIN port_inventory pi ON pi.device_id=l.snmp_device AND pi.ifindex=l.snmp_ifindex
        LEFT JOIN port_counters pc ON pc.device_id=l.snmp_device AND pc.ifindex=l.snmp_ifindex
        WHERE l.snmp_type=1 AND l.snmp_ifindex>0")->fetchAll(PDO::FETCH_ASSOC);
    $sources=[];
    foreach($rows as $row){
        if (np_is_olt_type((string)($row['device_type'] ?? ''))) continue;
        $isMx80=($row['device_name']??'')==='MX80' && ($row['ip']??'')==='10.255.240.9';
        $mx80SelectionId='p:'.$row['device_id'].':'.$row['ifidx'];
        $interface=trim((string)($row['ifname']??''));
        $alias=trim((string)($row['alias']??''));
        $interfaceLabel=$interface!==''?$interface:'ifIndex '.$row['ifidx'];
        if($alias!=='')$interfaceLabel.=' — '.$alias;
        $linkName=($row['fromname']&&$row['toname'])
            ?($row['fromname'].' ↔ '.$row['toname'])
            :$interfaceLabel;
        $source=[
            'id'=>(string)$row['id'],
            'name'=>$linkName,
            'sub'=>$row['master'].' · '.($row['ip']?:'IP unavailable').' · '.$interfaceLabel,
            'device_id'=>(string)$row['device_id'],
            'device_name'=>$row['device_name'],
            'ip'=>(string)($row['ip']??''),
            'ifname'=>$interface,
            'alias'=>$alias,
            'interface_name'=>$interfaceLabel
        ];
        if($isMx80){
            $source['mx80_interface']=true;
            $source['mx80_selection_id']=$mx80SelectionId;
            $source['selected']=isset($mx80Selected[$mx80SelectionId]);
        }
        $sources[]=$source;
    }
    $ports=$pdo->query("SELECT p.device_id,p.ifindex,p.ifname,p.alias,p.description,p.iftype,
        d.name AS device_name,d.ip,dt.name AS device_type,
        pc.in_oct,pc.out_oct,pc.ts_unix AS counter_ts
        FROM port_inventory p JOIN devices d ON d.id=p.device_id
        LEFT JOIN device_types dt ON dt.id=d.type_id
        LEFT JOIN port_counters pc ON pc.device_id=p.device_id AND pc.ifindex=p.ifindex
        ORDER BY d.name,p.ifindex")->fetchAll(PDO::FETCH_ASSOC);
    foreach($ports as $port){
        if (np_is_olt_type((string)($port['device_type'] ?? ''))) continue;
        $label=trim((string)$port['ifname']);
        $alias=trim((string)$port['alias']);
        if($alias!=='')$label.=' — '.$alias;
        $sourceId='p:'.$port['device_id'].':'.$port['ifindex'];
        $isMx80=($port['device_name']??'')==='MX80' && ($port['ip']??'')==='10.255.240.9';
        $source=[
            'id'=>$sourceId,
            'name'=>$port['device_name'].' / '.$label,
            'sub'=>$port['device_name'].' · '.($port['ip']?:'IP unavailable').' · Interface traffic · ifIndex '.$port['ifindex'],
            'device_id'=>(string)$port['device_id'],
            'device_name'=>$port['device_name'],
            'ip'=>(string)($port['ip']??''),
            'ifname'=>trim((string)$port['ifname']),
            'alias'=>$alias,
            'interface_name'=>$label
        ];
        if($isMx80){
            $source['mx80_interface']=true;
            $source['mx80_selection_id']=$sourceId;
            $source['selected']=isset($mx80Selected[$sourceId]);
        }
        $sources[]=$source;
    }
    $favorites=$pdo->query('SELECT source_id FROM graph_favorites')->fetchAll(PDO::FETCH_COLUMN);
    $favoriteIds=array_fill_keys(array_map('strval',$favorites),true);
    foreach($sources as &$source){
        $source['favorite']=isset($favoriteIds[(string)$source['id']]);
    }
    unset($source);
    $sources=np_graph_filter_sources_for_role($sources,$role,$allowedIds);
    usort($sources,static fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return $sources;
}

function np_graph_set_favorite(PDO $pdo, string $source, bool $favorite): bool {
    $valid = false;
    $deviceId = 0;
    $ifIndex = 0;
    if (preg_match('/^p:(\d+):(\d+)$/', $source, $match)) {
        $deviceId = (int)$match[1];
        $ifIndex = (int)$match[2];
        $st = $pdo->prepare('SELECT 1 FROM port_inventory WHERE device_id=? AND ifindex=?');
        $st->execute([(int)$match[1], (int)$match[2]]);
        $valid = (bool)$st->fetchColumn();
    } elseif (preg_match('/^\d+$/', $source)) {
        $st = $pdo->prepare(
            'SELECT snmp_device,snmp_ifindex FROM map_links
             WHERE id=? AND snmp_type=1 AND snmp_ifindex>0'
        );
        $st->execute([(int)$source]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if ($link) {
            $deviceId = (int)$link['snmp_device'];
            $ifIndex = (int)$link['snmp_ifindex'];
            $valid = true;
        }
    }
    if ($valid) {
        $oltAvailability = np_graph_olt_interface_is_available($pdo, $deviceId, $ifIndex);
        if (preg_match('/^\d+$/', $source) && $oltAvailability !== null) $valid = false;
        if (preg_match('/^p:/', $source) && $oltAvailability === false) $valid = false;
    }
    if (!$valid) return false;

    if ($favorite) {
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT IGNORE INTO'
            : 'INSERT OR IGNORE INTO';
        $st = $pdo->prepare($insert . ' graph_favorites(source_id,created_at) VALUES(?,?)');
        $st->execute([$source, gmdate('Y-m-d\TH:i:s\Z')]);
    } else {
        $st = $pdo->prepare('DELETE FROM graph_favorites WHERE source_id=?');
        $st->execute([$source]);
    }
    return true;
}

function np_graph_range_seconds(string $range): int {
    return [
        'hour'=>3600,
        'day'=>86400,
        'week'=>604800,
        'month'=>2592000,
        'year'=>31536000
    ][$range] ?? 86400;
}

function np_graph_traffic_response(array $rows, int $seconds, ?int $now = null): array {
    $now ??= time();
    $bucketCount = 120;
    $start = $now - $seconds;
    $bucketWidth = $seconds / $bucketCount;
    $buckets = [];
    foreach ($rows as $row) {
        $timestamp = strtotime((string)$row['ts']);
        if ($timestamp === false) continue;
        $index = (int)(($timestamp - $start) / $bucketWidth);
        if ($index < 0 || $index >= $bucketCount) continue;
        if (!isset($buckets[$index])) $buckets[$index] = ['rx'=>0.0, 'tx'=>0.0, 'n'=>0];
        $buckets[$index]['rx'] += (float)$row['rx_bps'];
        $buckets[$index]['tx'] += (float)$row['tx_bps'];
        $buckets[$index]['n']++;
    }

    $points = [];
    for ($index=0; $index<$bucketCount; $index++) {
        if (!isset($buckets[$index])) continue;
        $points[] = [
            't'=>(int)($start + $index * $bucketWidth),
            'rx'=>$buckets[$index]['rx'] / $buckets[$index]['n'],
            'tx'=>$buckets[$index]['tx'] / $buckets[$index]['n']
        ];
    }

    $summary = null;
    if ($rows) {
        $sumRx=0.0; $sumTx=0.0; $maxRx=0.0; $maxTx=0.0;
        foreach ($rows as $row) {
            $rx=(float)$row['rx_bps']; $tx=(float)$row['tx_bps'];
            $sumRx+=$rx; $sumTx+=$tx;
            $maxRx=max($maxRx,$rx); $maxTx=max($maxTx,$tx);
        }
        $last=$rows[count($rows)-1]; $count=count($rows);
        $summary=[
            'current'=>['rx'=>(float)$last['rx_bps'],'tx'=>(float)$last['tx_bps']],
            'average'=>['rx'=>$sumRx/$count,'tx'=>$sumTx/$count],
            'maximum'=>['rx'=>$maxRx,'tx'=>$maxTx]
        ];
    }
    return ['points'=>$points,'summary'=>$summary];
}

function np_delete_link_graph_data(PDO $pdo, array $linkIds): void {
    $sources = array_values(array_unique(array_filter(
        array_map(static fn($id) => (string)(int)$id, $linkIds),
        static fn($id) => $id !== '0'
    )));
    if (!$sources) return;

    $placeholders = implode(',', array_fill(0, count($sources), '?'));
    $pdo->prepare("DELETE FROM graph_favorites WHERE source_id IN ($placeholders)")
        ->execute($sources);
    $pdo->prepare("DELETE FROM user_graph_permissions WHERE source_id IN ($placeholders)")
        ->execute($sources);
    $pdo->prepare("DELETE FROM traffic_history WHERE link_id IN ($placeholders)")
        ->execute(array_map('intval', $sources));
    $pdo->prepare("DELETE FROM link_traffic WHERE link_id IN ($placeholders)")
        ->execute(array_map('intval', $sources));
}

function np_graph_retention_counts(PDO $pdo, int $days = 90, ?int $opticalDays = null): array {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $linkSourceT = $mysql ? "CAST(t.link_id AS CHAR)" : "CAST(t.link_id AS TEXT)";
    $linkSourceL = $mysql ? "CAST(l.id AS CHAR)" : "CAST(l.id AS TEXT)";
    $portSourceT = $mysql
        ? "CONCAT('p:',t.device_id,':',t.ifindex)"
        : "('p:'||t.device_id||':'||t.ifindex)";
    $portSourceP = $mysql
        ? "CONCAT('p:',p.device_id,':',p.ifindex)"
        : "('p:'||p.device_id||':'||p.ifindex)";

    np_graph_retention_schema($pdo);
    $retentionSeconds = max(1, $days) * 86400;
    $opticalRetentionSeconds = max(1, $opticalDays ?? $days) * 86400;
    $cut = date('Y-m-d H:i:s', time() - $retentionSeconds);
    $optCut = gmdate('Y-m-d\TH:i:s\Z', time() - $opticalRetentionSeconds);

    $sql = [
        'traffic_history' =>
            "SELECT COUNT(*) FROM traffic_history t
             WHERE t.ts < ? AND NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               WHERE f.source_id=$linkSourceT)",

        'port_traffic_history' =>
            "SELECT COUNT(*) FROM port_traffic_history t
             WHERE t.ts < ? AND NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               WHERE f.source_id=$portSourceT)",

        'optical_power_history' =>
            "SELECT COUNT(*) FROM optical_power_history t
             WHERE t.sampled_at < ? AND
             NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               JOIN port_inventory p
                 ON f.source_id=$portSourceP
               WHERE p.device_id=t.device_id AND p.ifname=t.ifname
             ) AND NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               JOIN map_links l ON f.source_id=$linkSourceL
               JOIN port_inventory p
                 ON p.device_id=l.snmp_device AND p.ifindex=l.snmp_ifindex
               WHERE p.device_id=t.device_id AND p.ifname=t.ifname
             )"
    ];

    $out = [];
    foreach ($sql as $table => $query) {
        try {
            $st = $pdo->prepare($query);
            $st->execute([$table === 'optical_power_history' ? $optCut : $cut]);
            $out[$table] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $out[$table] = 'NOT_CHECKED: ' . $e->getMessage();
        }
    }
    return $out;
}

/* Batched deletion helper. It is called only after explicit enablement. */
function np_graph_retention_cleanup(PDO $pdo, float $budget = 2.0, int $days = 90, ?int $opticalDays = null): array {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $linkSourceT = $mysql ? "CAST(t.link_id AS CHAR)" : "CAST(t.link_id AS TEXT)";
    $linkSourceL = $mysql ? "CAST(l.id AS CHAR)" : "CAST(l.id AS TEXT)";
    $portSourceT = $mysql
        ? "CONCAT('p:',t.device_id,':',t.ifindex)"
        : "('p:'||t.device_id||':'||t.ifindex)";
    $portSourceP = $mysql
        ? "CONCAT('p:',p.device_id,':',p.ifindex)"
        : "('p:'||p.device_id||':'||p.ifindex)";

    $counts = np_graph_retention_counts($pdo, $days, $opticalDays);
    foreach ($counts as $v) {
        if (!is_int($v)) {
            throw new RuntimeException('Retention dry-run check failed; cleanup aborted.');
        }
    }

    $retentionSeconds = max(1, $days) * 86400;
    $opticalRetentionSeconds = max(1, $opticalDays ?? $days) * 86400;
    $cut = date('Y-m-d H:i:s', time() - $retentionSeconds);
    $optCut = gmdate('Y-m-d\TH:i:s\Z', time() - $opticalRetentionSeconds);
    $conditions = [
        'traffic_history' =>
            "t.ts < ? AND NOT EXISTS (
              SELECT 1 FROM graph_favorites f
              WHERE f.source_id=$linkSourceT)",
        'port_traffic_history' =>
            "t.ts < ? AND NOT EXISTS (
              SELECT 1 FROM graph_favorites f
              WHERE f.source_id=$portSourceT)",
        'optical_power_history' =>
            "t.sampled_at < ? AND
             NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               JOIN port_inventory p
                 ON f.source_id=$portSourceP
               WHERE p.device_id=t.device_id AND p.ifname=t.ifname
             ) AND NOT EXISTS (
               SELECT 1 FROM graph_favorites f
               JOIN map_links l ON f.source_id=$linkSourceL
               JOIN port_inventory p
                 ON p.device_id=l.snmp_device AND p.ifindex=l.snmp_ifindex
               WHERE p.device_id=t.device_id AND p.ifname=t.ifname
             )"
    ];

    $done = [];
    $deadline = microtime(true) + max(0.1, $budget);
    foreach ($conditions as $table => $condition) {
        $done[$table] = 0;
        $timestamp = $table === 'optical_power_history' ? $optCut : $cut;
        while (microtime(true) < $deadline) {
            if ($mysql) {
                $idQuery = "SELECT batch.id FROM (
                    SELECT t.id FROM $table t WHERE $condition LIMIT 500
                ) AS batch";
            } else {
                $idQuery = "SELECT t.id FROM $table t WHERE $condition LIMIT 500";
            }
            $sql = "DELETE FROM $table WHERE id IN ($idQuery)";
            $st = $pdo->prepare($sql);
            $st->execute([$timestamp]);
            $n = $st->rowCount();
            $done[$table] += $n;
            if ($n < 500) break;
            usleep(100000);
        }
    }
    return $done;
}
