<?php
require __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require __DIR__ . '/port_monitor_lib.php';
require __DIR__ . '/telegram.php';
require_login(true);
if (in_array(current_role(), ['user', 'employee'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'শুধু Graphs দেখার অনুমতি আছে']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
migrate();
$oltAllowlists = [];
$loadPortRows = static function (array $rows) use (&$oltAllowlists): array {
    $visible = [];
    foreach ($rows as $row) {
        $typeName = (string)($row['type_name'] ?? '');
        $deviceId = (int)$row['device_id'];
        if (np_is_olt_type($typeName)) {
            if (!array_key_exists($deviceId, $oltAllowlists)) {
                $oltAllowlists[$deviceId] = np_olt_interface_allowlist(
                    $deviceId, $typeName, (string)($row['ip'] ?? ''), (string)($row['vendor'] ?? '')
                );
            }
            if (!np_olt_interface_is_allowed(
                $typeName, (string)$row['ifname'], $oltAllowlists[$deviceId]
            )) continue;
            $row = np_normalize_olt_port_labels($row, $typeName, $oltAllowlists[$deviceId]);
        }
        $row['alias_display'] = np_port_alias_display(
            (string)($row['alias'] ?? ''), (string)$row['ifname'],
            (string)($row['description'] ?? ''), $typeName
        );
        unset($row['type_name'], $row['vendor']);
        $visible[] = $row;
    }
    return $visible;
};
$action = (string)($_GET['action'] ?? 'list');

if ($action === 'discover') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
        ($_SERVER['HTTP_X_NETPULSE'] ?? '') !== '1') {
        http_response_code(403);
        echo json_encode(['error'=>'Invalid request']);
        exit;
    }
    require_role('admin');
    try {
        echo json_encode(['ok'=>true] + np_port_poll($pdo));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error'=>'Discovery failed; check the server log.']);
    }
    exit;
}

np_port_schema($pdo);

if ($action === 'events') {
    $rows = $pdo->query(
        "SELECT e.ts,d.id AS device_id,d.name AS device_name,d.ip,d.vendor,
                dt.name AS type_name,e.ifindex,e.ifname,e.alias,
                COALESCE(pi.description,'') AS description,e.status,e.message
         FROM port_events e JOIN devices d ON d.id=e.device_id
         LEFT JOIN device_types dt ON dt.id=d.type_id
         LEFT JOIN port_inventory pi ON pi.device_id=e.device_id AND pi.ifindex=e.ifindex
         WHERE d.id<>1000004 AND UPPER(TRIM(COALESCE(dt.name,'')))<>'OLT'
         ORDER BY e.id DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($loadPortRows($rows));
    exit;
}

if ($action === 'traffic') {
    $device = (int)($_GET['device_id'] ?? 0);
    $idx = (int)($_GET['ifindex'] ?? 0);
    if ($device === 1000004) {
        echo json_encode([]);
        exit;
    }
    $deviceQuery = $pdo->prepare(
        'SELECT d.ip,d.vendor,dt.name AS type_name,p.ifname
         FROM devices d LEFT JOIN device_types dt ON dt.id=d.type_id
         LEFT JOIN port_inventory p ON p.device_id=d.id AND p.ifindex=?
         WHERE d.id=?'
    );
    $deviceQuery->execute([$idx,$device]);
    $deviceInfo = $deviceQuery->fetch(PDO::FETCH_ASSOC);
    if ($deviceInfo && np_is_olt_type((string)$deviceInfo['type_name'])) {
        echo json_encode([]);
        exit;
    }
    if ($deviceInfo && np_is_olt_type((string)$deviceInfo['type_name']) &&
        !np_olt_interface_is_allowed(
            (string)$deviceInfo['type_name'],
            (string)($deviceInfo['ifname'] ?? ''),
            np_olt_interface_allowlist(
                $device, (string)$deviceInfo['type_name'], (string)$deviceInfo['ip'],
                (string)$deviceInfo['vendor']
            )
        )) {
        echo json_encode([]);
        exit;
    }
    $rows = $pdo->prepare(
        "SELECT ts,rx_bps,tx_bps FROM port_traffic_history
         WHERE device_id=? AND ifindex=? AND ts>=?
         ORDER BY ts"
    );
    $rows->execute([$device,$idx,date('Y-m-d H:i:s',time()-86400)]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$rows = $pdo->query(
    "SELECT p.device_id,d.name AS device_name,d.ip,d.vendor,dt.name AS type_name,
            p.ifindex,p.ifname,p.alias,p.description,
            p.oper_status,p.first_seen,p.last_check,p.last_change
     FROM port_inventory p JOIN devices d ON d.id=p.device_id
     LEFT JOIN device_types dt ON dt.id=d.type_id
     WHERE d.id<>1000004 AND UPPER(TRIM(COALESCE(dt.name,'')))<>'OLT'
     ORDER BY d.name,p.ifindex"
)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($loadPortRows($rows));
