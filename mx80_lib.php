<?php
declare(strict_types=1);

const NP_MX80_DEVICE_ID = 1000004;
const NP_MX80_DEVICE_IP = '10.255.240.9';

function np_mx80_schema(PDO $pdo): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $id = $mysql ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $num = $mysql ? 'BIGINT' : 'INTEGER';
    $text = $mysql ? 'VARCHAR(255)' : 'TEXT';
    $time = $mysql ? 'DATETIME' : 'TEXT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS mx80_categories (
        id $id,
        parent_id $num NULL,
        name $text NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at $time NOT NULL,
        updated_at $time NOT NULL" . ($mysql ? ', INDEX idx_mx80_categories_parent(parent_id)' : '') . "
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mx80_interfaces (
        device_id $num NOT NULL,
        ifindex INT NOT NULL,
        description $text NOT NULL,
        ifname $text NOT NULL DEFAULT '',
        selected INT NOT NULL DEFAULT 0,
        category_id $num NULL,
        discovered INT NOT NULL DEFAULT 1,
        last_discovery BIGINT NOT NULL" . ($mysql ? ', INDEX idx_mx80_interfaces_category(category_id,selected)' : '') . ",
        PRIMARY KEY(device_id,ifindex)
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mx80_counters (
        device_id $num NOT NULL,
        ifindex INT NOT NULL,
        in_oct DOUBLE NOT NULL,
        out_oct DOUBLE NOT NULL,
        ctype VARCHAR(4) NOT NULL,
        uptime BIGINT NULL,
        ts_unix BIGINT NOT NULL,
        PRIMARY KEY(device_id,ifindex)
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mx80_traffic_history (
        id $id,
        ts $time NOT NULL,
        device_id $num NOT NULL,
        ifindex INT NOT NULL,
        rx_bps DOUBLE NOT NULL,
        tx_bps DOUBLE NOT NULL" . ($mysql ? ', INDEX idx_mx80_history_device_if_ts(device_id,ifindex,ts)' : '') . "
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mx80_category_permissions (
        user_id $num NOT NULL,
        category_id $num NOT NULL,
        PRIMARY KEY(user_id,category_id)
    )$engine");
    if (!$mysql) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mx80_categories_parent ON mx80_categories(parent_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mx80_history_device_if_ts ON mx80_traffic_history(device_id,ifindex,ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mx80_interfaces_category ON mx80_interfaces(category_id,selected)');
    }
    np_mx80_ensure_category_name_index($pdo, $mysql);
}

function np_mx80_ensure_category_name_index(PDO $pdo, bool $mysql): void {
    $indexName = 'idx_mx80_categories_name_unique';
    if ($mysql) {
        $indexes = $pdo->query('SHOW INDEX FROM mx80_categories')->fetchAll(PDO::FETCH_ASSOC);
        $matching = array_values(array_filter($indexes, static fn(array $index): bool =>
            ($index['Key_name'] ?? null) === $indexName
        ));
        if ($matching) {
            if (count($matching) !== 1 || (int)$matching[0]['Non_unique'] !== 0 ||
                ($matching[0]['Column_name'] ?? null) !== 'name' ||
                (int)($matching[0]['Seq_in_index'] ?? 0) !== 1) {
                throw new RuntimeException('MX80 category name index exists with an invalid definition.');
            }
            return;
        }
        $pdo->exec("CREATE UNIQUE INDEX $indexName ON mx80_categories(name)");
        return;
    }

    $indexes = $pdo->query("PRAGMA index_list('mx80_categories')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $index) {
        if (($index['name'] ?? null) !== $indexName) continue;
        $columns = $pdo->query("PRAGMA index_info('$indexName')")->fetchAll(PDO::FETCH_ASSOC);
        if ((int)$index['unique'] !== 1 || count($columns) !== 1 || ($columns[0]['name'] ?? null) !== 'name') {
            throw new RuntimeException('MX80 category name index exists with an invalid definition.');
        }
        return;
    }
    $pdo->exec("CREATE UNIQUE INDEX $indexName ON mx80_categories(name COLLATE NOCASE)");
}

function np_mx80_is_legacy_source(PDO $pdo, string $sourceId): bool {
    if (preg_match('/^p:(\d+):(\d+)$/', $sourceId, $match)) {
        return (int)$match[1] === NP_MX80_DEVICE_ID;
    }
    if (!preg_match('/^\d+$/', $sourceId)) return false;
    $query = $pdo->prepare('SELECT 1 FROM map_links WHERE id=? AND snmp_type=1 AND snmp_device=?');
    $query->execute([(int)$sourceId,NP_MX80_DEVICE_ID]);
    return (bool)$query->fetchColumn();
}

function np_mx80_device_id(PDO $pdo): ?int {
    $value = setting_get('mx80_device_id');
    if ($value !== null && $value !== '' &&
        (!is_numeric($value) || (int)$value !== NP_MX80_DEVICE_ID)) {
        return null;
    }
    $query = $pdo->prepare(
        'SELECT d.id FROM devices d
         JOIN snmp_profiles sp ON sp.id=d.snmp_profile
         LEFT JOIN device_types dt ON dt.id=d.type_id
         WHERE d.id=? AND d.ip=? AND sp.version IN (0,1,2)
           AND UPPER(TRIM(COALESCE(dt.name,\'\')))<>\'OLT\''
    );
    $query->execute([NP_MX80_DEVICE_ID,NP_MX80_DEVICE_IP]);
    $deviceId = $query->fetchColumn();
    return $deviceId === false ? null : (int)$deviceId;
}

function np_mx80_device_candidates(PDO $pdo): array {
    $query = $pdo->prepare(
        "SELECT d.id,d.name,d.ip,dt.name AS device_type,sp.name AS profile_name,0 AS version
         FROM devices d
         JOIN snmp_profiles sp ON sp.id=d.snmp_profile
         LEFT JOIN device_types dt ON dt.id=d.type_id
         WHERE d.id=? AND d.ip=? AND sp.version IN (0,1,2)
           AND UPPER(TRIM(COALESCE(dt.name,'')))<>'OLT'
         ORDER BY d.name"
    );
    $query->execute([NP_MX80_DEVICE_ID,NP_MX80_DEVICE_IP]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function np_mx80_profile(PDO $pdo, int $deviceId): array {
    $query = $pdo->prepare(
        'SELECT d.id,d.name,d.ip,sp.community,sp.version,sp.port,sp.sec_name,
                sp.auth_pass,sp.priv_pass,sp.auth_proto,sp.priv_proto
         FROM devices d JOIN snmp_profiles sp ON sp.id=d.snmp_profile
        LEFT JOIN device_types dt ON dt.id=d.type_id
        WHERE d.id=? AND d.ip=? AND sp.version IN (0,1,2)
          AND UPPER(TRIM(COALESCE(dt.name,\'\')))<>\'OLT\''
    );
    $query->execute([$deviceId,NP_MX80_DEVICE_IP]);
    $device = $query->fetch(PDO::FETCH_ASSOC);
    if (!$device || $deviceId !== NP_MX80_DEVICE_ID) {
        throw new InvalidArgumentException('The configured Juniper MX80 device is unavailable.');
    }
    // Keep the shared profile unchanged; this module uses SNMPv1 for the MX80 only.
    $device['version'] = 0;
    return $device;
}

function np_mx80_categories(PDO $pdo): array {
    return $pdo->query('SELECT id,parent_id,name,sort_order FROM mx80_categories ORDER BY sort_order,name,id')
        ->fetchAll(PDO::FETCH_ASSOC);
}

function np_mx80_visible_category_ids(PDO $pdo, int $userId, string $role): array {
    $categories = np_mx80_categories($pdo);
    if (in_array($role, ['admin','administrator'], true)) {
        return array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $categories), true);
    }
    $query = $pdo->prepare('SELECT category_id FROM mx80_category_permissions WHERE user_id=?');
    $query->execute([$userId]);
    $visible = array_fill_keys(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)), true);
    $children = [];
    foreach ($categories as $category) {
        $parentId = $category['parent_id'] === null ? 0 : (int)$category['parent_id'];
        $children[$parentId][] = (int)$category['id'];
    }
    $queue = array_keys($visible);
    while ($queue) {
        $parentId = (int)array_shift($queue);
        foreach ($children[$parentId] ?? [] as $childId) {
            if (isset($visible[$childId])) continue;
            $visible[$childId] = true;
            $queue[] = $childId;
        }
    }
    return $visible;
}

function np_mx80_visible_categories(PDO $pdo, int $userId, string $role): array {
    $visible = np_mx80_visible_category_ids($pdo, $userId, $role);
    $categories = [];
    foreach (np_mx80_categories($pdo) as $category) {
        $id = (int)$category['id'];
        if (!isset($visible[$id])) continue;
        $parentId = $category['parent_id'] === null ? null : (int)$category['parent_id'];
        $categories[] = [
            'id'=>$id,
            'parent_id'=>$parentId !== null && isset($visible[$parentId]) ? $parentId : null,
            'name'=>(string)$category['name'],
            'sort_order'=>(int)$category['sort_order']
        ];
    }
    return $categories;
}

function np_mx80_category_exists(PDO $pdo, int $categoryId): bool {
    $query = $pdo->prepare('SELECT 1 FROM mx80_categories WHERE id=?');
    $query->execute([$categoryId]);
    return (bool)$query->fetchColumn();
}

function np_mx80_category_name(string $name): string {
    $name = trim($name);
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($name === '' || $length > 255) {
        throw new InvalidArgumentException('Category name must contain 1 to 255 characters.');
    }
    return $name;
}

function np_mx80_create_category(PDO $pdo, string $name, ?int $parentId): int {
    $name = np_mx80_category_name($name);
    if ($parentId !== null && !np_mx80_category_exists($pdo, $parentId)) {
        throw new InvalidArgumentException('Parent category was not found.');
    }
    $now = date('Y-m-d H:i:s');
    $insert = $pdo->prepare('INSERT INTO mx80_categories(parent_id,name,sort_order,created_at,updated_at) VALUES(?,?,?,?,?)');
    try {
        $insert->execute([$parentId,$name,0,$now,$now]);
    } catch (PDOException $error) {
        if (np_mx80_category_name_conflict($error)) {
            throw new InvalidArgumentException('A category with this name already exists.', 0, $error);
        }
        throw $error;
    }
    return (int)$pdo->lastInsertId();
}

function np_mx80_rename_category(PDO $pdo, int $categoryId, string $name): void {
    $name = np_mx80_category_name($name);
    $update = $pdo->prepare('UPDATE mx80_categories SET name=?,updated_at=? WHERE id=?');
    try {
        $update->execute([$name,date('Y-m-d H:i:s'),$categoryId]);
    } catch (PDOException $error) {
        if (np_mx80_category_name_conflict($error)) {
            throw new InvalidArgumentException('A category with this name already exists.', 0, $error);
        }
        throw $error;
    }
    if ($update->rowCount() === 0 && !np_mx80_category_exists($pdo, $categoryId)) {
        throw new InvalidArgumentException('Category was not found.');
    }
}

function np_mx80_category_name_conflict(PDOException $error): bool {
    $message = strtolower($error->getMessage());
    return str_contains($message, 'mx80_categories.name')
        || str_contains($message, 'idx_mx80_categories_name_unique');
}

function np_mx80_delete_category(PDO $pdo, int $categoryId): void {
    $query = $pdo->prepare('SELECT parent_id FROM mx80_categories WHERE id=?');
    $query->execute([$categoryId]);
    $parentId = $query->fetchColumn();
    if ($parentId === false) throw new InvalidArgumentException('Category was not found.');
    $parentId = $parentId === null ? null : (int)$parentId;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE mx80_categories SET parent_id=?,updated_at=? WHERE parent_id=?')
            ->execute([$parentId,date('Y-m-d H:i:s'),$categoryId]);
        $pdo->prepare('UPDATE mx80_interfaces SET category_id=? WHERE category_id=?')
            ->execute([$parentId,$categoryId]);
        $pdo->prepare('DELETE FROM mx80_category_permissions WHERE category_id=?')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM mx80_categories WHERE id=?')->execute([$categoryId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function np_mx80_save_category_permissions(PDO $pdo, int $userId, array $categoryIds): int {
    $user = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $user->execute([$userId]);
    $role = $user->fetchColumn();
    if ($role === false) throw new InvalidArgumentException('User was not found.');
    if (in_array($role, ['admin','administrator'], true)) {
        throw new InvalidArgumentException('Category permissions are managed only for non-administrator users.');
    }
    if (count($categoryIds) > 500) throw new InvalidArgumentException('Too many category permissions.');
    $valid = [];
    foreach ($categoryIds as $categoryId) {
        if (filter_var($categoryId, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Invalid category permission.');
        }
        $id = (int)$categoryId;
        if (!np_mx80_category_exists($pdo, $id)) throw new InvalidArgumentException('Category was not found.');
        $valid[$id] = $id;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM mx80_category_permissions WHERE user_id=?')->execute([$userId]);
        $insert = $pdo->prepare('INSERT INTO mx80_category_permissions(user_id,category_id) VALUES(?,?)');
        foreach ($valid as $categoryId) $insert->execute([$userId,$categoryId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return count($valid);
}

function np_mx80_discover_interfaces(PDO $pdo, int $deviceId, ?callable $walk = null): array {
    require_once __DIR__ . '/snmp_lib.php';
    $device = np_mx80_profile($pdo, $deviceId);
    $profile = [
        'version'=>(int)$device['version'],'port'=>(int)($device['port'] ?: 161),
        'community'=>$device['community'],'sec_name'=>$device['sec_name'],
        'auth_pass'=>$device['auth_pass'],'priv_pass'=>$device['priv_pass'],
        'auth_proto'=>$device['auth_proto'],'priv_proto'=>$device['priv_proto']
    ];
    $walk ??= 'snmp_walk';
    $descriptions = $walk((string)$device['ip'],$profile,'1.3.6.1.2.1.31.1.1.1.18');
    if (!$descriptions) throw new RuntimeException('SNMP interface-description walk returned no interfaces.');
    $names = $walk((string)$device['ip'],$profile,'1.3.6.1.2.1.31.1.1.1.1');
    $now = time();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $insertSql = $mysql
        ? 'INSERT INTO mx80_interfaces(device_id,ifindex,description,ifname,selected,category_id,discovered,last_discovery)
           VALUES(?,?,?,?,0,NULL,1,?) ON DUPLICATE KEY UPDATE description=VALUES(description),ifname=VALUES(ifname),discovered=1,last_discovery=VALUES(last_discovery)'
        : 'INSERT INTO mx80_interfaces(device_id,ifindex,description,ifname,selected,category_id,discovered,last_discovery)
           VALUES(?,?,?,?,0,NULL,1,?) ON CONFLICT(device_id,ifindex) DO UPDATE SET description=excluded.description,ifname=excluded.ifname,discovered=1,last_discovery=excluded.last_discovery';
    $insert = $pdo->prepare($insertSql);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE mx80_interfaces SET discovered=0 WHERE device_id=?')->execute([$deviceId]);
        $included = 0;
        $excluded = 0;
        foreach ($descriptions as $ifIndex => $description) {
            $ifIndex = filter_var($ifIndex, FILTER_VALIDATE_INT);
            if ($ifIndex === false || $ifIndex < 1) continue;
            $description = trim((string)$description);
            if ($description === '') {
                $excluded++;
                continue;
            }
            $insert->execute([$deviceId,$ifIndex,$description,trim((string)($names[$ifIndex] ?? '')),$now]);
            $included++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['interfaces'=>$included,'without_description'=>$excluded];
}

function np_mx80_save_interfaces(PDO $pdo, int $deviceId, array $items): int {
    if (count($items) > 1024) throw new InvalidArgumentException('Too many interface selections.');
    $exists = $pdo->prepare('SELECT 1 FROM mx80_interfaces WHERE device_id=? AND ifindex=? AND discovered=1 AND TRIM(description)<>\'\'');
    $update = $pdo->prepare('UPDATE mx80_interfaces SET selected=?,category_id=? WHERE device_id=? AND ifindex=? AND discovered=1 AND TRIM(description)<>\'\'');
    $categoryIds = [];
    foreach (np_mx80_categories($pdo) as $category) $categoryIds[(int)$category['id']] = true;
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item) || filter_var($item['ifindex'] ?? null, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Invalid interface selection.');
        }
        $ifIndex = (int)$item['ifindex'];
        $categoryId = ($item['category_id'] ?? null) === null || $item['category_id'] === ''
            ? null : filter_var($item['category_id'], FILTER_VALIDATE_INT);
        if ($ifIndex < 1 || ($categoryId !== null && ($categoryId === false || !isset($categoryIds[(int)$categoryId])))) {
            throw new InvalidArgumentException('Invalid interface category.');
        }
        $exists->execute([$deviceId,$ifIndex]);
        if (!$exists->fetchColumn()) throw new InvalidArgumentException('Interface is not discovered or has no description.');
        $normalized[$ifIndex] = [
            'selected'=>!empty($item['selected']) ? 1 : 0,
            'category_id'=>$categoryId === null ? null : (int)$categoryId
        ];
    }
    $pdo->beginTransaction();
    try {
        foreach ($normalized as $ifIndex => $item) {
            $update->execute([$item['selected'],$item['category_id'],$deviceId,$ifIndex]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return count($normalized);
}

function np_mx80_rates(?array $previous, array $current, int $now): ?array {
    if ($previous === null) return null;
    $elapsed = $now - (int)$previous['ts_unix'];
    if ($elapsed <= 0 || (string)$previous['ctype'] !== (string)$current['ctype']) return null;
    if (isset($previous['uptime'],$current['uptime']) &&
        $previous['uptime'] !== null && $current['uptime'] !== null &&
        (float)$current['uptime'] < (float)$previous['uptime']) {
        return null;
    }
    $inDelta = (float)$current['in_oct'] - (float)$previous['in_oct'];
    $outDelta = (float)$current['out_oct'] - (float)$previous['out_oct'];
    if ($current['ctype'] === '32') {
        if ($inDelta < 0 && (float)$previous['in_oct'] <= 4294967295.0) $inDelta += 4294967296.0;
        if ($outDelta < 0 && (float)$previous['out_oct'] <= 4294967295.0) $outDelta += 4294967296.0;
    }
    if ($inDelta < 0 || $outDelta < 0) return null;
    return ['rx_bps'=>($inDelta * 8) / $elapsed,'tx_bps'=>($outDelta * 8) / $elapsed];
}

function np_mx80_poll(PDO $pdo, ?callable $get = null, ?int $now = null): array {
    require_once __DIR__ . '/snmp_lib.php';
    $now ??= time();
    $get ??= 'snmp_get';
    $deviceId = np_mx80_device_id($pdo);
    if ($deviceId === null) return ['polled'=>0,'samples'=>0,'failures'=>0];
    $device = np_mx80_profile($pdo, $deviceId);
    $interfaces = $pdo->prepare(
        "SELECT ifindex FROM mx80_interfaces
         WHERE device_id=? AND selected=1 AND discovered=1 AND TRIM(description)<>'' ORDER BY ifindex"
    );
    $interfaces->execute([$deviceId]);
    $indices = array_map('intval',$interfaces->fetchAll(PDO::FETCH_COLUMN));
    if (!$indices) return ['polled'=>0,'samples'=>0,'failures'=>0];
    $profile = [
        'version'=>(int)$device['version'],'port'=>(int)($device['port'] ?: 161),
        'community'=>$device['community'],'sec_name'=>$device['sec_name'],
        'auth_pass'=>$device['auth_pass'],'priv_pass'=>$device['priv_pass'],
        'auth_proto'=>$device['auth_proto'],'priv_proto'=>$device['priv_proto']
    ];
    $counterQuery = $pdo->prepare('SELECT in_oct,out_oct,ctype,uptime,ts_unix FROM mx80_counters WHERE device_id=? AND ifindex=?');
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $saveCounter = $pdo->prepare(($mysql ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO') .
        ' mx80_counters(device_id,ifindex,in_oct,out_oct,ctype,uptime,ts_unix) VALUES(?,?,?,?,?,?,?)');
    $saveHistory = $pdo->prepare('INSERT INTO mx80_traffic_history(ts,device_id,ifindex,rx_bps,tx_bps) VALUES(?,?,?,?,?)');
    $polled = 0;
    $samples = 0;
    $failures = 0;
    foreach ($indices as $ifIndex) {
        try {
            $uptimeOid = '1.3.6.1.2.1.1.3.0';
            $type = (int)$profile['version'] === 0 ? '32' : 'hc';
            if ($type === '32') {
                $values = $get((string)$device['ip'],$profile,[
                    '1.3.6.1.2.1.2.2.1.10.' . $ifIndex,
                    '1.3.6.1.2.1.2.2.1.16.' . $ifIndex,
                    $uptimeOid
                ]);
            } else {
                $values = $get((string)$device['ip'],$profile,[
                    '1.3.6.1.2.1.31.1.1.1.6.' . $ifIndex,
                    '1.3.6.1.2.1.31.1.1.1.10.' . $ifIndex,
                    $uptimeOid
                ]);
                if (!is_numeric($values[0] ?? null) || !is_numeric($values[1] ?? null)) {
                    $type = '32';
                    $values = $get((string)$device['ip'],$profile,[
                        '1.3.6.1.2.1.2.2.1.10.' . $ifIndex,
                        '1.3.6.1.2.1.2.2.1.16.' . $ifIndex,
                        $uptimeOid
                    ]);
                }
            }
            $in = $values[0] ?? null;
            $out = $values[1] ?? null;
            if (!is_numeric($in) || !is_numeric($out)) {
                $failures++;
                continue;
            }
            $current = [
                'in_oct'=>(float)$in,'out_oct'=>(float)$out,'ctype'=>$type,
                'uptime'=>isset($values[2]) && is_numeric($values[2]) ? (float)$values[2] : null
            ];
            $counterQuery->execute([$deviceId,$ifIndex]);
            $previous = $counterQuery->fetch(PDO::FETCH_ASSOC) ?: null;
            $rates = np_mx80_rates($previous,$current,$now);
            $pdo->beginTransaction();
            try {
                $saveCounter->execute([$deviceId,$ifIndex,$current['in_oct'],$current['out_oct'],$type,$current['uptime'],$now]);
                if ($rates !== null) {
                    $saveHistory->execute([date('Y-m-d H:i:s',$now),$deviceId,$ifIndex,$rates['rx_bps'],$rates['tx_bps']]);
                    $samples++;
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
            $polled++;
        } catch (Throwable $error) {
            $failures++;
            error_log('NetPulse MX80 SNMP poll failed for ifIndex ' . $ifIndex . ': ' . $error->getMessage());
        }
    }
    $cutoff = date('Y-m-d H:i:s',$now - 8 * 86400);
    $pdo->prepare('DELETE FROM mx80_traffic_history WHERE ts<?')->execute([$cutoff]);
    return ['polled'=>$polled,'samples'=>$samples,'failures'=>$failures];
}
