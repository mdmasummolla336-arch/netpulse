<?php
/** JSON API pre DudeWeb (chránené prihlásením). */
require __DIR__ . '/auth.php';
require __DIR__ . '/telegram.php';
require_once __DIR__ . '/sms.php';
require __DIR__ . '/snmp_lib.php';
require __DIR__ . '/probe_lib.php';
require_once __DIR__ . '/graph_retention_lib.php';
require_once __DIR__ . '/optical_history_lib.php';
require_once __DIR__ . '/port_monitor_lib.php';
require_once __DIR__ . '/ssh_terminal_lib.php';
require_once __DIR__ . '/olt_interface_policy.php';
require_once __DIR__ . '/olt_monitor_lib.php';
require_once __DIR__ . '/mx80_lib.php';
require_login(true);
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
migrate();
np_graph_retention_schema($pdo);
np_graph_permissions_schema($pdo);
op_history_schema($pdo);
$a = (string)($_GET['action'] ?? '');

// Graph-only restriction for ordinary user accounts; admin roles remain unchanged.
if (current_role() === 'user' &&
    !in_array($a, ['graph_sources','graph_data','graph_interface_selection_set','mx80_categories','mx80_graphs','mx80_graph_data','faults','fault_history','whoami','client_config'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'শুধু Graphs দেখার অনুমতি আছে']);
    exit;
}
if (current_role() === 'employee' &&
    !in_array($a, ['maps','map','summary','mx80_categories','mx80_graphs','mx80_graph_data','whoami','client_config'], true)) {
    http_response_code(403);
    echo json_encode(['error'=>'শুধু Map dashboard দেখার অনুমতি আছে']);
    exit;
}
// Akcie, ktoré len čítajú. Všetko ostatné mení dáta => len POST s hlavičkou X-NetPulse
// (cudzia stránka ju bez CORS povolenia poslať nevie – ochrana proti CSRF).
$readActions = ['maps','map','device','probes','ping_now','probe_now','devices','services','faults','fault_history','events','summary',
                'live_status','link_types','snmp_profiles_list','snmp_interfaces','get_settings','timezones',
                'graph_sources','graph_data','optical_history','device_types','whoami','client_config','users_list','login_history','graph_permissions_get','backup',
                'ssh_devices','mx80_categories','mx80_graphs','mx80_graph_data','mx80_devices','mx80_interfaces','mx80_permissions_get'];
if (!in_array($a, $readActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_NETPULSE'] ?? '') !== '1') {
        http_response_code(403); echo json_encode(['error'=>'Neplatná požiadavka']); exit;
    }
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$nextId = fn(string $t) => (int)$pdo->query("SELECT COALESCE(MAX(id),1000000)+1 FROM $t")->fetchColumn();

// editačné akcie: len admin/administrator (user = len čítanie)
$editActions=['move','add_node','new_device','del_node','add_link','del_link','add_map',
              'move_map','reorder_maps','update_device','add_service','del_service',
              'toggle_service','toggle_monitor','update_link','set_node_category','snmp_profile_save','snmp_profile_delete','delete_device'];
array_push($editActions,'mx80_device_set','mx80_discover','mx80_interfaces_save','mx80_category_create',
    'mx80_category_rename','mx80_category_delete','mx80_permissions_save');
if(in_array($a,$editActions,true)) require_role('admin');

/** Počty zariadení podľa mapy: total/up/down/pending/unknown (pre submapy aj panel). */
function mapStats(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT n.map_id AS m,
           COUNT(*) total,
           SUM(CASE WHEN COALESCE(d.monitored,1)=1 AND d.status='up' THEN 1 ELSE 0 END) up,
           SUM(CASE WHEN COALESCE(d.monitored,1)=1 AND d.status='down' THEN 1 ELSE 0 END) down,
           SUM(CASE WHEN COALESCE(d.monitored,1)=1 AND d.status='pending' THEN 1 ELSE 0 END) pending
         FROM map_nodes n JOIN devices d ON d.id=n.device_id
         WHERE n.kind='device' GROUP BY n.map_id")->fetchAll();
    $s = [];
    foreach ($rows as $r) $s[$r['m']] = [
        'total'=>(int)$r['total'],'up'=>(int)$r['up'],'down'=>(int)$r['down'],
        'pending'=>(int)$r['pending'],
        'unknown'=>max(0,(int)$r['total']-(int)$r['up']-(int)$r['down']-(int)$r['pending'])
    ];
    return $s;
}

try {

/** Súhrn stavov pre horný panel – vypnutý monitoring sa ráta ako „neznáme". */
function np_summary(PDO $pdo): array {
    return $pdo->query(
        "SELECT COUNT(*) total,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='up' THEN 1 ELSE 0 END) up,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='pending' THEN 1 ELSE 0 END) pending,
           SUM(CASE WHEN COALESCE(monitored,1)=1 AND status='down' THEN 1 ELSE 0 END) down,
           SUM(CASE WHEN COALESCE(monitored,1)=0 OR status='unknown' OR status IS NULL THEN 1 ELSE 0 END) unknown
         FROM devices")->fetch();
}

/** Obnova SQLite zálohy BEZ prepísania súboru pod bežiacimi procesmi (monitor, SNMP poller
 *  majú DB otvorenú vo WAL – prepis súboru by ju mohol poškodiť). Tabuľky sa skopírujú
 *  z pripojenej zálohy v jednej transakcii, takže ostatné procesy len chvíľu počkajú. */
function np_restore_sqlite(PDO $pdo, string $file): array {
    try {
        $chk = new PDO('sqlite:' . $file); $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $integ = (string)$chk->query('PRAGMA quick_check')->fetchColumn();
        $hasDev = (int)$chk->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='devices'")->fetchColumn();
        $chk = null;
    } catch (Throwable $e) { return ['error'=>'Súbor zálohy sa nedá otvoriť']; }
    if ($integ !== 'ok') return ['error'=>'Záloha je poškodená (integrity check zlyhal)'];
    if (!$hasDev)        return ['error'=>'Súbor nie je záloha NetPulse (chýba tabuľka devices)'];

    $dst = cfg('SQLITE_PATH'); $pre = $dst . '.before-restore';
    @unlink($pre);
    try { $pdo->exec('VACUUM INTO ' . $pdo->quote($pre)); } catch (Throwable $e) { error_log('NetPulse restore – záloha pred obnovou: '.$e->getMessage()); }

    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS src');
    try {
        $ok = db_tx(function (PDO $pdo) {
            $tables = $pdo->query("SELECT name FROM src.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                          ->fetchAll(PDO::FETCH_COLUMN);
            // používateľov prevezmi len ak záloha obsahuje administrátora s heslom (inak by sa nikto neprihlásil
            // alebo by vznikol predvolený admin/admin)
            $usersOk = false;
            if (in_array('users', $tables, true)) {
                try { $usersOk = (int)$pdo->query("SELECT COUNT(*) FROM src.users WHERE role='administrator'
                                                    AND pass_hash IS NOT NULL AND pass_hash<>''")->fetchColumn() > 0; }
                catch (Throwable $e) { $usersOk = false; }
            }
            foreach ($tables as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$t)) continue;
                if ($t === 'login_fail' || ($t === 'users' && !$usersOk)) continue;
                $exists = (int)$pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
                if (!$exists) {   // tabuľka zo zálohy, ktorú aktuálna DB nemá
                    $sql = (string)$pdo->query("SELECT sql FROM src.sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
                    if ($sql) $pdo->exec($sql);
                }
                $cm = array_column($pdo->query("PRAGMA main.table_info($t)")->fetchAll(), 'name');
                $cs = array_column($pdo->query("PRAGMA src.table_info($t)")->fetchAll(), 'name');
                $cols = array_values(array_intersect($cm, $cs));
                if (!$cols) continue;
                $list = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols));
                $pdo->exec("DELETE FROM main.$t");
                $pdo->exec("INSERT INTO main.$t($list) SELECT $list FROM src.$t");
            }
            $pdo->exec('DELETE FROM main.notify_queue');   // staré neodoslané správy zo zálohy neposielaj
        }, 8);
    } catch (Throwable $e) {
        error_log('NetPulse restore: '.$e->getMessage());
        $ok = false;
    } finally {
        try { $pdo->exec('DETACH DATABASE src'); } catch (Throwable $e) {}
    }
    if (!$ok) return ['error'=>'Obnova zlyhala, databáza ostala nezmenená'];
    try { setting_set('schema_ver', '0'); } catch (Throwable $e) {}   // doplň prípadné nové stĺpce
    return ['ok'=>true];
}

/** Vypnutý monitoring: zariadenie zmizne z porúch, otvorený výpadok sa uzavrie
 *  a po opätovnom zapnutí sa prípadný výpadok nahlási odznova. */
function np_monitoring_off(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE devices SET status='unknown',rtt=NULL,down_since=NULL,down_ts=NULL,notified='up',ping_fail_count=0 WHERE id=?")->execute([$id]);
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT id,started,started_ts FROM outages WHERE device_id=? AND ended IS NULL');
    $st->execute([$id]);
    $cl = $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');
    foreach ($st->fetchAll() as $o) { $s = np_epoch($o['started_ts'], $o['started']); $cl->execute([$now, $s ? max(0, time() - $s) : null, $o['id']]); }
}

    switch ($a) {

    case 'mx80_categories':
        $userId = current_uid();
        $categories = $userId === null ? [] : np_mx80_visible_categories($pdo,$userId,current_role());
        $deviceId = np_mx80_device_id($pdo);
        $device = null;
        if ($deviceId !== null) {
            $query = $pdo->prepare('SELECT id,name,ip FROM devices WHERE id=?');
            $query->execute([$deviceId]);
            $device = $query->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        echo json_encode(['categories'=>$categories,'device'=>$device]); break;

    case 'mx80_devices':
        require_role('admin');
        echo json_encode(np_mx80_device_candidates($pdo)); break;

    case 'mx80_device_set':
        require_role('admin');
        $deviceId = filter_var($in['device_id'] ?? null,FILTER_VALIDATE_INT);
        if ($deviceId === false || $deviceId < 1) {
            http_response_code(400);
            echo json_encode(['error'=>'Select a valid MX80 device.']); break;
        }
        try {
            np_mx80_profile($pdo,(int)$deviceId);
            setting_set('mx80_device_id',(string)(int)$deviceId);
            echo json_encode(['ok'=>true,'device_id'=>(int)$deviceId]);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'mx80_discover':
        require_role('admin');
        $deviceId = np_mx80_device_id($pdo);
        if ($deviceId === null) {
            http_response_code(400);
            echo json_encode(['error'=>'Choose the MX80 device before discovering interfaces.']); break;
        }
        try {
            echo json_encode(['ok'=>true] + np_mx80_discover_interfaces($pdo,$deviceId));
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        } catch (Throwable $error) {
            error_log('NetPulse MX80 interface discovery failed: '.$error->getMessage());
            http_response_code(502);
            echo json_encode(['error'=>'SNMP interface discovery failed. Check the device profile and SNMP reachability.']);
        }
        break;

    case 'mx80_interfaces':
        require_role('admin');
        $deviceId = np_mx80_device_id($pdo);
        if ($deviceId === null) { echo json_encode([]); break; }
        $query = $pdo->prepare(
            'SELECT i.ifindex,i.description,i.ifname,i.selected,i.category_id,c.name AS category_name,
                    h.rx_bps,h.tx_bps,x.ts_unix
             FROM mx80_interfaces i
             LEFT JOIN mx80_categories c ON c.id=i.category_id
             LEFT JOIN mx80_counters x ON x.device_id=i.device_id AND x.ifindex=i.ifindex
             LEFT JOIN mx80_traffic_history h ON h.id=(
                 SELECT MAX(h2.id) FROM mx80_traffic_history h2
                 WHERE h2.device_id=i.device_id AND h2.ifindex=i.ifindex
             )
             WHERE i.device_id=? AND i.discovered=1 AND TRIM(i.description)<>\'\' ORDER BY i.ifindex'
        );
        $query->execute([$deviceId]);
        echo json_encode($query->fetchAll(PDO::FETCH_ASSOC)); break;

    case 'mx80_interfaces_save':
        require_role('admin');
        $deviceId = np_mx80_device_id($pdo);
        $items = $in['interfaces'] ?? null;
        if ($deviceId === null || !is_array($items)) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid MX80 interface selection.']); break;
        }
        try {
            echo json_encode(['ok'=>true,'count'=>np_mx80_save_interfaces($pdo,$deviceId,$items)]);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'mx80_category_create':
        require_role('admin');
        $parentId = ($in['parent_id'] ?? null) === null || $in['parent_id'] === ''
            ? null : filter_var($in['parent_id'],FILTER_VALIDATE_INT);
        if ($parentId === false) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid parent category.']); break;
        }
        try {
            echo json_encode(['ok'=>true,'id'=>np_mx80_create_category(
                $pdo,(string)($in['name'] ?? ''),$parentId === null ? null : (int)$parentId
            )]);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'mx80_category_rename':
        require_role('admin');
        $categoryId = filter_var($in['category_id'] ?? null,FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid category.']); break;
        }
        try {
            np_mx80_rename_category($pdo,(int)$categoryId,(string)($in['name'] ?? ''));
            echo json_encode(['ok'=>true]);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'mx80_category_delete':
        require_role('admin');
        $categoryId = filter_var($in['category_id'] ?? null,FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid category.']); break;
        }
        try {
            np_mx80_delete_category($pdo,(int)$categoryId);
            echo json_encode(['ok'=>true]);
        } catch (InvalidArgumentException $error) {
            http_response_code(404);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'mx80_graphs':
        $categoryId = filter_var($_GET['category_id'] ?? null,FILTER_VALIDATE_INT);
        $userId = current_uid();
        $visible = $userId === null ? [] : np_mx80_visible_category_ids($pdo,$userId,current_role());
        if ($categoryId === false || $categoryId < 1 || !isset($visible[(int)$categoryId])) {
            http_response_code(403);
            echo json_encode(['error'=>'You are not allowed to view this MX80 category.']); break;
        }
        $deviceId = np_mx80_device_id($pdo);
        if ($deviceId === null) { echo json_encode([]); break; }
        $query = $pdo->prepare(
            'SELECT i.device_id,i.ifindex,i.description,i.ifname,i.category_id,h.rx_bps,h.tx_bps,x.ts_unix
             FROM mx80_interfaces i
             LEFT JOIN mx80_counters x ON x.device_id=i.device_id AND x.ifindex=i.ifindex
             LEFT JOIN mx80_traffic_history h ON h.id=(
                 SELECT MAX(h2.id) FROM mx80_traffic_history h2
                 WHERE h2.device_id=i.device_id AND h2.ifindex=i.ifindex
             )
             WHERE i.device_id=? AND i.category_id=? AND i.selected=1 AND i.discovered=1
               AND TRIM(i.description)<>\'\' ORDER BY i.description,i.ifindex'
        );
        $query->execute([$deviceId,(int)$categoryId]);
        echo json_encode($query->fetchAll(PDO::FETCH_ASSOC)); break;

    case 'mx80_graph_data':
        $categoryId = filter_var($_GET['category_id'] ?? null,FILTER_VALIDATE_INT);
        $ifIndex = filter_var($_GET['ifindex'] ?? null,FILTER_VALIDATE_INT);
        $userId = current_uid();
        $visible = $userId === null ? [] : np_mx80_visible_category_ids($pdo,$userId,current_role());
        if ($categoryId === false || $categoryId < 1 || !isset($visible[(int)$categoryId]) ||
            $ifIndex === false || $ifIndex < 1) {
            http_response_code(403);
            echo json_encode(['error'=>'You are not allowed to view this MX80 interface.']); break;
        }
        $deviceId = np_mx80_device_id($pdo);
        $access = $pdo->prepare(
            'SELECT 1 FROM mx80_interfaces
             WHERE device_id=? AND ifindex=? AND category_id=? AND selected=1 AND discovered=1 AND TRIM(description)<>\'\''
        );
        $access->execute([$deviceId ?? 0,(int)$ifIndex,(int)$categoryId]);
        if (!$access->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error'=>'You are not allowed to view this MX80 interface.']); break;
        }
        $range = (string)($_GET['range'] ?? 'day');
        if (!in_array($range,['hour','day','week'],true)) {
            http_response_code(400);
            echo json_encode(['error'=>'Range must be hour, day, or week.']); break;
        }
        $seconds = np_graph_range_seconds($range);
        $from = date('Y-m-d H:i:s',time()-$seconds);
        $history = $pdo->prepare(
            'SELECT ts,rx_bps,tx_bps FROM mx80_traffic_history
             WHERE device_id=? AND ifindex=? AND ts>=? ORDER BY ts'
        );
        $history->execute([$deviceId,(int)$ifIndex,$from]);
        echo json_encode(np_graph_traffic_response($history->fetchAll(PDO::FETCH_ASSOC),$seconds)); break;

    case 'mx80_permissions_get':
        require_role('admin');
        $userId = filter_var($_GET['user_id'] ?? null,FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid user.']); break;
        }
        $user = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $user->execute([(int)$userId]);
        $targetRole = $user->fetchColumn();
        if ($targetRole === false || in_array($targetRole,['admin','administrator'],true)) {
            http_response_code(400);
            echo json_encode(['error'=>'Choose a non-administrator user.']); break;
        }
        $query = $pdo->prepare('SELECT category_id FROM mx80_category_permissions WHERE user_id=? ORDER BY category_id');
        $query->execute([(int)$userId]);
        echo json_encode(['category_ids'=>$query->fetchAll(PDO::FETCH_COLUMN)]); break;

    case 'mx80_permissions_save':
        require_role('admin');
        $userId = filter_var($in['user_id'] ?? null,FILTER_VALIDATE_INT);
        $categoryIds = $in['category_ids'] ?? null;
        if ($userId === false || $userId < 1 || !is_array($categoryIds)) {
            http_response_code(400);
            echo json_encode(['error'=>'Invalid category permissions.']); break;
        }
        try {
            echo json_encode(['ok'=>true,'count'=>np_mx80_save_category_permissions($pdo,(int)$userId,$categoryIds)]);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error'=>$error->getMessage()]);
        }
        break;

    case 'maps':
        $stats = mapStats($pdo);
        $maps = $pdo->query('SELECT id,name FROM maps ORDER BY COALESCE(sort_order,999999), name')->fetchAll();
        foreach ($maps as &$m) {
            $st = $stats[$m['id']] ?? ['total'=>0,'up'=>0,'down'=>0];
            $m['nodes'] = $st['total']; $m['down'] = $st['down'];
        }
        echo json_encode($maps); break;

    case 'set_node_category':
        $nodeId = (int)($in['node'] ?? 0);
        $category = (string)($in['category'] ?? 'normal');
        $allowedCategories = ['normal', 'reseller', 'bandwidth_reseller'];

        if ($nodeId < 1 || !in_array($category, $allowedCategories, true)) {
            echo json_encode(['ok'=>false, 'error'=>'Invalid node or category']);
            break;
        }

        $check = $pdo->prepare("SELECT id FROM map_nodes WHERE id=? AND kind='device'");
        $check->execute([$nodeId]);
        if (!$check->fetchColumn()) {
            echo json_encode(['ok'=>false, 'error'=>'Device map node not found']);
            break;
        }

        $save = $pdo->prepare("UPDATE map_nodes SET category=? WHERE id=? AND kind='device'");
        $save->execute([$category, $nodeId]);
        echo json_encode(['ok'=>true, 'node'=>$nodeId, 'category'=>$category]);
        break;

    case 'map':
        $id = (int)($_GET['id'] ?? 0);
        $stats = mapStats($pdo);
        $nq = $pdo->prepare(
            "SELECT n.id,n.x,n.y,n.kind,n.device_id,n.submap_id,n.label,n.category,
                    d.name dev_name,d.ip,
                    CASE WHEN COALESCE(d.monitored,1)=0 THEN 'unknown' ELSE COALESCE(d.status,'unknown') END status,
                    d.rtt,dt.icon,dt.name type_name
             FROM map_nodes n
             LEFT JOIN devices d ON d.id=n.device_id
             LEFT JOIN device_types dt ON dt.id=d.type_id
             WHERE n.map_id=?");
        $nq->execute([$id]);
        $nodes = $nq->fetchAll();
        foreach ($nodes as &$n) {
            $iconFile = (string)$n['icon'];
            if (!str_contains($iconFile, '.')) $iconFile .= '.svg';
            if (strtolower((string)($n['type_name'] ?? '')) === 'olt' &&
                !is_file(__DIR__ . '/assets/icons/' . basename($iconFile))) {
                $n['icon'] = 'switch';
            }
            if ($n['kind'] === 'submap') {
                $sm = $pdo->query('SELECT name FROM maps WHERE id=' . (int)$n['submap_id'])->fetchColumn();
                $st = $stats[$n['submap_id']] ?? ['total'=>0,'up'=>0,'down'=>0,'pending'=>0,'unknown'=>0];
                $n['sub_name'] = $sm ?: $n['label'];
                $n['sub_total'] = $st['total']; $n['sub_down'] = $st['down'];
                $n['status'] = $st['down'] > 0 ? 'down'
                    : ($st['pending'] > 0 ? 'pending' : ($st['up'] > 0 ? 'up' : 'unknown'));
            }
        }
        $lq = $pdo->prepare('SELECT l.id,l.from_node,l.to_node,l.width,l.style,l.thickness,l.ltype,l.label,l.snmp_device,l.snmp_ifindex,
                                    t.rx_bps,t.tx_bps,t.speed_bps,t.ts,t.ts_unix,link_type.name AS snmp_device_type
                             FROM map_links l
                             LEFT JOIN link_traffic t ON t.link_id=l.id
                             LEFT JOIN devices link_device ON link_device.id=l.snmp_device
                             LEFT JOIN device_types link_type ON link_type.id=link_device.type_id
                             WHERE l.map_id=?');
        $lq->execute([$id]);
        $links = $lq->fetchAll();
        // zastaraný tok (zariadenie neodpovedá na SNMP) nezobrazuj ako aktuálny
        $stale = max(120, 3 * (int) setting_get('snmp_interval', 30)); $tnow = time();
        foreach ($links as &$lk) {
            if (np_is_olt_type((string)($lk['snmp_device_type'] ?? ''))) {
                $lk['rx_bps'] = $lk['tx_bps'] = $lk['speed_bps'] = null;
            }
            $t = np_epoch($lk['ts_unix'] ?? null, $lk['ts'] ?? null);
            if ($t !== null && $tnow - $t > $stale) { $lk['rx_bps'] = null; $lk['tx_bps'] = null; }
            unset($lk['ts'], $lk['ts_unix'], $lk['snmp_device_type']);
            if (current_role() === 'employee') {
                unset($lk['rx_bps'], $lk['tx_bps'], $lk['speed_bps']);
            }
        }
        unset($lk);
        echo json_encode([
            'map'   => $pdo->query('SELECT id,name FROM maps WHERE id=' . $id)->fetch(),
            'nodes' => $nodes, 'links' => $links,
        ]); break;

    case 'device':
        $id = (int)($_GET['id'] ?? 0);
        $dev = $pdo->query(
            "SELECT d.*,dt.name type_name,dt.icon FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id WHERE d.id=$id")->fetch();
        $sv = $pdo->prepare(
            "SELECT s.id,s.name,s.ptype,s.port,
                    CASE WHEN COALESCE(d.monitored,1)=0 OR COALESCE(s.enabled,1)=0
                         THEN 'unknown' ELSE COALESCE(s.status,'unknown') END status,
                    s.down,s.enabled
             FROM services s JOIN devices d ON d.id=s.device_id
             WHERE s.device_id=? ORDER BY s.name"
        );
        $sv->execute([$id]);
        $hi = $pdo->prepare('SELECT ts,rtt,status FROM status_history WHERE device_id=? ORDER BY ts DESC LIMIT 300');
        $hi->execute([$id]);
        $ou = $pdo->prepare('SELECT service,started,ended,duration FROM outages WHERE device_id=? ORDER BY id DESC LIMIT 100');
        $ou->execute([$id]);
        if ($dev) {
            if ((string)($dev['monitored'] ?? '1') === '0') $dev['status'] = 'unknown';
            $dev['optical_template'] = (string)setting_get('optical_template_device_' . $id, 'auto');
            if (!in_array($dev['optical_template'], ['auto','cisco_nexus_nxos','mikrotik_routeros','bdcom','vsol','none'], true)) {
                $dev['optical_template'] = 'auto';
            }
            $dev['olt_interface_allowlist'] = np_olt_interface_allowlist(
                $id, (string)($dev['type_name'] ?? ''), (string)($dev['ip'] ?? ''),
                (string)($dev['vendor'] ?? '')
            );
        }
        if ($dev && !has_role('admin')) { unset($dev['password'], $dev['username']); }
        if ($dev) {
            if (np_is_olt_type((string)($dev['type_name'] ?? ''))) {
                $dev['check_effective'] = 'olt';
            } else {
                $svAll = $pdo->prepare('SELECT ptype FROM services WHERE device_id=? AND (enabled=1 OR enabled IS NULL)');
                $svAll->execute([$id]);
                $dev['check_effective'] = np_effective_mode($dev['check_mode'] ?? null, $svAll->fetchAll());
            }
        }
        echo json_encode([
            'device'=>$dev,'services'=>$sv->fetchAll(),
            'history'=>array_reverse($hi->fetchAll()),'outages'=>$ou->fetchAll()]); break;

    case 'probes':
        echo json_encode($pdo->query('SELECT id,name,type,port FROM probes ORDER BY name')->fetchAll()); break;

    case 'update_device':
        $ipIn = trim((string)($in['ip'] ?? '')); $dnsIn = trim((string)($in['dns'] ?? ''));
        $opticalTemplate = (string)($in['optical_template'] ?? 'auto');
        if (!in_array($opticalTemplate, ['auto','cisco_nexus_nxos','mikrotik_routeros','bdcom','vsol','none'], true)) {
            echo json_encode(['error'=>'Invalid optical template']); break;
        }
        if ($ipIn !== '' && !np_valid_host($ipIn))  { echo json_encode(['error'=>'Neplatná IP adresa']); break; }
        if ($dnsIn !== '' && !np_valid_host($dnsIn)) { echo json_encode(['error'=>'Neplatný DNS názov']); break; }
        if (trim((string)($in['name'] ?? '')) === '') { echo json_encode(['error'=>'Názov je povinný']); break; }
        $vendor = (string)($in['vendor'] ?? 'unknown');
        if (!in_array($vendor, ['unknown','mikrotik','cisco','bdcom','juniper','vsol','other'], true)) {
            echo json_encode(['error'=>'Invalid vendor']); break;
        }
        $sshPort = filter_var($in['ssh_port'] ?? 22, FILTER_VALIDATE_INT);
        if ($sshPort === false || $sshPort < 1 || $sshPort > 65535) {
            echo json_encode(['error'=>'SSH port must be between 1 and 65535']); break;
        }
        $mon = empty($in['monitored']) ? 0 : 1;
        $deviceId = (int)($in['id'] ?? 0);
        $typeId = ($in['type_id'] ?? '') !== '' ? (int)$in['type_id'] : null;
        if ($typeId !== null && isset($in['olt_interface_allowlist'])) {
            $typeQuery = $pdo->prepare('SELECT name FROM device_types WHERE id=?');
            $typeQuery->execute([$typeId]);
            if (np_is_olt_type((string)$typeQuery->fetchColumn())) {
                $list = np_parse_olt_interfaces((string)$in['olt_interface_allowlist']);
                setting_set('olt_interface_allowlist_device_' . $deviceId, json_encode($list));
                np_port_schema($pdo);
                $pdo->prepare('UPDATE port_discovery_state SET last_discovery=0 WHERE device_id=?')
                    ->execute([$deviceId]);
            }
        }
        if (isset($in['check_mode'])) {
            $checkMode = in_array($in['check_mode'], ['auto','ping','services'], true)
                ? $in['check_mode'] : 'auto';
            $pdo->prepare('UPDATE devices SET check_mode=? WHERE id=?')
                ->execute([$checkMode === 'auto' ? null : $checkMode, $deviceId]);
        }
        $pdo->prepare('UPDATE devices SET name=?,ip=?,dns=?,type_id=?,username=?,password=?,monitored=?,snmp_profile=?,vendor=?,ssh_port=? WHERE id=?')
            ->execute([trim((string)$in['name']),trim((string)($in['ip'] ?? '')),trim((string)($in['dns'] ?? '')),
                       $typeId,trim((string)($in['username'] ?? '')),(string)($in['password'] ?? ''),$mon,
                       (isset($in['snmp_profile'])&&$in['snmp_profile']!=='')?(int)$in['snmp_profile']:null,
                       $vendor,(int)$sshPort,$deviceId]);
        if (!$mon) np_monitoring_off($pdo, (int)$in['id']);
        setting_set('optical_template_device_' . (int)$in['id'], $opticalTemplate);
        echo json_encode(['ok'=>true]); break;

    case 'delete_device':
        // zmaže zariadenie zo všetkých máp aj z monitoringu (aj keď na žiadnej mape nie je)
        $did = (int)($in['id'] ?? 0);
        if ($did <= 0) { echo json_encode(['error'=>'Chýba zariadenie']); break; }
        $ok = db_tx(function (PDO $pdo) use ($did) {
            $nodes = $pdo->prepare('SELECT id FROM map_nodes WHERE device_id=?'); $nodes->execute([$did]);
            $nodeIds = array_map('intval', $nodes->fetchAll(PDO::FETCH_COLUMN));
            $linkIds = [];
            $findLinks = $pdo->prepare('SELECT id FROM map_links WHERE from_node=? OR to_node=?');
            foreach ($nodeIds as $nid) {
                $findLinks->execute([$nid, $nid]);
                array_push($linkIds, ...$findLinks->fetchAll(PDO::FETCH_COLUMN));
            }
            np_delete_link_graph_data($pdo, $linkIds);
            $dl = $pdo->prepare('DELETE FROM map_links WHERE from_node=? OR to_node=?');
            foreach ($nodeIds as $nid) $dl->execute([$nid, $nid]);
            $pdo->prepare('DELETE FROM map_nodes WHERE device_id=?')->execute([$did]);
            np_delete_device($pdo, $did);
        });
        echo json_encode($ok ? ['ok'=>true] : ['error'=>'Databáza je zaneprázdnená – skús znova']); break;

    case 'toggle_monitor':
        $mon = empty($in['monitored']) ? 0 : 1;
        $pdo->prepare('UPDATE devices SET monitored=? WHERE id=?')->execute([$mon,(int)$in['id']]);
        if (!$mon) np_monitoring_off($pdo, (int)$in['id']);
        echo json_encode(['ok'=>true]); break;

    case 'add_service':
        $pr = $pdo->query('SELECT name,type,port FROM probes WHERE id=' . (int)$in['probe_id'])->fetch();
        if (!$pr) { echo json_encode(['error'=>'sonda nenájdená']); break; }
        $sid = $nextId('services');
        $pdo->prepare("INSERT INTO services(id,device_id,probe_id,ptype,port,name,enabled,down,acked,status)
                       VALUES(?,?,?,?,?,?,1,0,0,'unknown')")
            ->execute([$sid,(int)$in['device_id'],(int)$in['probe_id'],$pr['type'],$pr['port'],$pr['name']]);
        echo json_encode(['ok'=>true,'service'=>$sid]); break;

    case 'del_service':
        $pdo->prepare('DELETE FROM services WHERE id=?')->execute([(int)$in['service']]);
        echo json_encode(['ok'=>true]); break;

    case 'toggle_service':
        $pdo->prepare('UPDATE services SET enabled=? WHERE id=?')
            ->execute([(int)$in['enabled'],(int)$in['service']]);
        echo json_encode(['ok'=>true]); break;

    case 'ping_now':
        $ip = trim($_GET['ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['error'=>'neplatná IP']); break; }
        if (!exec_allowed()) {
            http_response_code(503);
            echo json_encode(['error'=>'Ping is unavailable because PHP exec() is disabled.']);
            break;
        }
        $win = stripos(PHP_OS,'WIN')===0;
        $cmd = $win ? 'ping -n 3 '.escapeshellarg($ip) : 'ping -c 3 -W 1 '.escapeshellarg($ip);
        $out=[]; exec($cmd,$out,$rc);
        echo json_encode(['ok'=>$rc===0,'output'=>implode("\n",$out)]); break;

    case 'probe_now':
        // Okamžitý test zariadenia presne tak, ako ho robí monitor: ping + každá služba.
        // Nič nezapisuje – stav v mape aktualizuje monitor v najbližšom cykle.
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare(
            'SELECT d.id,d.name,d.ip,d.monitored,dt.name AS type_name,sp.community,sp.version,sp.port,sp.sec_name,
                    sp.auth_pass,sp.priv_pass,sp.auth_proto,sp.priv_proto
             FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id
             LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile
             WHERE d.id=?'
        );
        $st->execute([$id]); $dv = $st->fetch();
        if (!$dv) { echo json_encode(['error'=>'Zariadenie nenájdené']); break; }
        $ip = trim((string)$dv['ip']);
        if (!np_valid_host($ip)) { echo json_encode(['error'=>'Zariadenie nemá platnú IP adresu']); break; }
        $isOlt = np_is_olt_type((string)($dv['type_name'] ?? ''));
        if ($isOlt && !np_olt_monitoring_enabled($dv['monitored'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error'=>'OLT monitoring is disabled.']);
            break;
        }
        $out = [];
        $pingOk = false; $pingMs = null;
        if (exec_allowed()) {
            if (fping_available()) { $al = fping_batch([$ip], 1000); if (array_key_exists($ip, $al)) { $pingOk = true; $pingMs = $al[$ip]; } }
            else { $o = []; @exec('ping -c 1 -W 1 '.escapeshellarg($ip).' 2>/dev/null', $o, $rc); $pingOk = ($rc === 0);
                   if ($pingOk && preg_match('/time[=<]([\d.]+)/', implode("\n",$o), $m)) $pingMs = (float)$m[1]; }
            $out[] = ['check'=>'Ping (ICMP)', 'ok'=>$pingOk, 'ms'=>$pingMs];
        }
        if ($isOlt) {
            echo json_encode(['ok'=>true, 'results'=>$out]);
            break;
        }
        $sv = $pdo->prepare('SELECT id,name,ptype,port,enabled FROM services WHERE device_id=? ORDER BY name'); $sv->execute([$id]);
        $svcs = $sv->fetchAll(); $tcp = [];
        foreach ($svcs as $s) if ($s['ptype'] === 'tcp') $tcp['s'.$s['id']] = [$ip, (int)$s['port']];
        $tcpOk = $tcp ? tcp_probe_batch($tcp, 2.0) : [];
        foreach ($svcs as $s) {
            $label = $s['name'] . ' (' . strtoupper((string)$s['ptype']) . ($s['port'] ? ' '.$s['port'] : '') . ')';
            switch ($s['ptype']) {
                case 'tcp':  $ok = isset($tcpOk['s'.$s['id']]); $ms = $tcpOk['s'.$s['id']] ?? null; break;
                case 'dns':  $ok = dns_check($ip); $ms = null; break;
                case 'snmp':
                    $prof = [
                        'community'=>$dv['community'],'version'=>$dv['version'],'port'=>$dv['port'],
                        'sec_name'=>$dv['sec_name'],'auth_pass'=>$dv['auth_pass'],'priv_pass'=>$dv['priv_pass'],
                        'auth_proto'=>$dv['auth_proto'],'priv_proto'=>$dv['priv_proto']
                    ];
                    $ok = snmp_check($ip, (int)$s['port'] ?: (int)($dv['port'] ?? 161), $prof);
                    $ms = null;
                    break;
                default:     $ok = $pingOk; $ms = $pingMs;
            }
            $out[] = ['check'=>$label, 'ok'=>$ok, 'ms'=>$ms, 'service'=>true, 'enabled'=>(string)$s['enabled'] !== '0'];
        }
        $cmSt = $pdo->prepare('SELECT check_mode FROM devices WHERE id=?'); $cmSt->execute([$id]);
        $en = array_values(array_filter($svcs, fn($s) => (string)$s['enabled'] !== '0'));
        $mode = np_effective_mode($cmSt->fetchColumn() ?: null, $en);
        $svcRes = array_values(array_filter($out, fn($x) => !empty($x['service']) && $x['enabled']
                       && preg_match('/\((ICMP|TCP|DNS|SNMP)\b/', $x['check'])));
        if ($mode === 'services') { $up = (bool)$svcRes; foreach ($svcRes as $x) if (!$x['ok']) $up = false; }
        else { $up = $pingOk; foreach ($svcRes as $x) if ($x['ok']) $up = true; }
        echo json_encode(['ok'=>true, 'results'=>$out, 'mode'=>$mode, 'up'=>$up]); break;

    case 'devices':
        $q = '%' . ($_GET['q'] ?? '') . '%';
        $s = $pdo->prepare(
            "SELECT d.id,d.name,d.ip,
                    CASE WHEN COALESCE(d.monitored,1)=0 THEN 'unknown' ELSE COALESCE(d.status,'unknown') END status,
                    dt.name type_name FROM devices d
             LEFT JOIN device_types dt ON dt.id=d.type_id
             WHERE d.name LIKE ? OR d.ip LIKE ? ORDER BY d.name LIMIT 200");
        $s->execute([$q, $q]);
        echo json_encode($s->fetchAll()); break;

    case 'ssh_devices':
        require_role('admin');
        echo json_encode(np_ssh_devices($pdo)); break;

    case 'ssh_credentials':
        require_role('admin');
        if (!np_ssh_bridge_token_valid((string)($_SERVER['HTTP_X_NETPULSE_SSH_BRIDGE'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error'=>'SSH bridge authorization failed']); break;
        }
        $device = np_ssh_device_credentials($pdo, (int)($in['device_id'] ?? 0));
        if (!$device) {
            http_response_code(404);
            echo json_encode(['error'=>'SSH device is unavailable']); break;
        }
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        echo json_encode($device);
        break;

    case 'services':
        echo json_encode($pdo->query(
            "SELECT s.id,s.name,
                    CASE WHEN COALESCE(d.monitored,1)=0 OR COALESCE(s.enabled,1)=0
                         THEN 'unknown' ELSE COALESCE(s.status,'unknown') END status,
                    s.down,s.device_id,s.ptype,s.port,s.enabled,d.name dev_name,d.ip
             FROM services s JOIN devices d ON d.id=s.device_id
             ORDER BY (s.status='down') DESC, d.name LIMIT 500")->fetchAll()); break;

    case 'fault_history':
        $rows = $pdo->query(
            "SELECT o.id,o.device_id,o.service,o.started,o.ended,o.duration,
                    d.name,d.ip
             FROM outages o
             JOIN devices d ON d.id=o.device_id
             ORDER BY o.id DESC
             LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as &$r) {
            $r['fault_status'] = !empty($r['ended']) ? 'recovered' : 'active';
            if (empty($r['ended']) && !empty($r['started'])) {
                $r['duration'] = max(0, $now - strtotime($r['started']));
            }
        }
        unset($r);
        echo json_encode($rows); break;

    case 'faults':
        $rows = $pdo->query(
            "SELECT id,name,ip,status,last_check,rtt,down_since
             FROM devices
             WHERE status='down'
               AND (monitored=1 OR monitored IS NULL)
             ORDER BY down_since"
        )->fetchAll(PDO::FETCH_ASSOC);
        $t = time();
        foreach ($rows as &$r) {
            $r['duration'] = !empty($r['down_since'])
                ? max(0, $t - strtotime($r['down_since'])) : null;
        }
        unset($r);
        echo json_encode($rows); break;

    case 'events':
        echo json_encode($pdo->query(
            "SELECT * FROM (
                SELECT ts,device_name,ip,
                       '' AS ifname,status,message FROM events
                UNION ALL
                SELECT e.ts, d.name AS device_name, d.ip,
                       e.ifname,e.status,e.message
                FROM port_events e JOIN devices d ON d.id=e.device_id
                LEFT JOIN device_types dt ON dt.id=d.type_id
                WHERE d.id<>1000004 AND UPPER(TRIM(COALESCE(dt.name,'')))<>'OLT'
             ) ORDER BY ts DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC)); break;

    case 'summary':
        $r = np_summary($pdo);
        $r['maps'] = (int)$pdo->query('SELECT COUNT(*) FROM maps')->fetchColumn();
        $r['services'] = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
        echo json_encode($r); break;

    case 'live_status':
        // rýchle prefarbenie: stavy zariadení danej mapy + súhrn (žltá/zelená/červená)
        $id = (int)($_GET['map'] ?? 0);
        $rows = $pdo->query("SELECT DISTINCT d.id,
                                     CASE WHEN COALESCE(d.monitored,1)=0 THEN 'unknown' ELSE COALESCE(d.status,'unknown') END status
                             FROM devices d
                             JOIN map_nodes n ON n.device_id=d.id WHERE n.map_id=$id")->fetchAll();
        $devs = []; foreach ($rows as $r) $devs[$r['id']] = $r['status'];
        $sum = np_summary($pdo);
        $mp = mapStats($pdo);
        echo json_encode(['devices'=>$devs,'summary'=>$sum,'mapstats'=>$mp]); break;

    // ---- editovanie ----
    case 'move':
        $pdo->prepare('UPDATE map_nodes SET x=?,y=? WHERE id=?')
            ->execute([(int)$in['x'],(int)$in['y'],(int)$in['node']]);
        echo json_encode(['ok'=>true]); break;

    case 'add_node':
        $nid = $nextId('map_nodes');
        $pdo->prepare("INSERT INTO map_nodes(id,map_id,kind,device_id,x,y,label) VALUES(?,?,'device',?,?,?,'')")
            ->execute([$nid,(int)$in['map'],(int)$in['device_id'],(int)$in['x'],(int)$in['y']]);
        echo json_encode(['ok'=>true,'node'=>$nid]); break;

    case 'new_device':
        if (trim((string)($in['ip'] ?? '')) !== '' && !np_valid_host((string)$in['ip'])) { echo json_encode(['error'=>'Neplatná IP adresa']); break; }
        $did = $nextId('devices');
        $pdo->prepare("INSERT INTO devices(id,name,ip,type_id,status) VALUES(?,?,?,?,'unknown')")
            ->execute([$did,trim($in['name']),trim($in['ip'] ?? ''),$in['type_id'] ?? null]);
        $nid = $nextId('map_nodes');
        $pdo->prepare("INSERT INTO map_nodes(id,map_id,kind,device_id,x,y,label) VALUES(?,?,'device',?,?,?,'')")
            ->execute([$nid,(int)$in['map'],$did,(int)$in['x'],(int)$in['y']]);
        echo json_encode(['ok'=>true,'node'=>$nid,'device_id'=>$did]); break;

    case 'del_node':
        // Zmazanie ikony z mapy. Ak to bol POSLEDNÝ výskyt zariadenia, odstráni sa aj zariadenie –
        // inak by ostalo neviditeľne v databáze a monitorovalo by sa (a hlásilo výpadky) ďalej.
        $n = (int)$in['node'];
        $st = $pdo->prepare('SELECT device_id FROM map_nodes WHERE id=?'); $st->execute([$n]);
        $devId = (int)($st->fetchColumn() ?: 0);
        $devDeleted = false;
        $ok = db_tx(function (PDO $pdo) use ($n, $devId, &$devDeleted) {
            $devDeleted = false;
            $findLinks = $pdo->prepare('SELECT id FROM map_links WHERE from_node=? OR to_node=?');
            $findLinks->execute([$n, $n]);
            np_delete_link_graph_data($pdo, $findLinks->fetchAll(PDO::FETCH_COLUMN));
            $pdo->prepare('DELETE FROM map_links WHERE from_node=? OR to_node=?')->execute([$n,$n]);
            $pdo->prepare('DELETE FROM map_nodes WHERE id=?')->execute([$n]);
            if ($devId) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM map_nodes WHERE device_id=?'); $c->execute([$devId]);
                if ((int)$c->fetchColumn() === 0) { np_delete_device($pdo, $devId); $devDeleted = true; }
            }
        });
        echo json_encode($ok ? ['ok'=>true,'device_deleted'=>$devDeleted] : ['error'=>'Databáza je zaneprázdnená – skús znova']); break;

    case 'add_link':
        $lid = $nextId('map_links');
        $pdo->prepare('INSERT INTO map_links(id,map_id,from_node,to_node,width) VALUES(?,?,?,?,1)')
            ->execute([$lid,(int)$in['map'],(int)$in['from'],(int)$in['to']]);
        echo json_encode(['ok'=>true,'link'=>$lid]); break;

    case 'del_link':
        $lid = (int)$in['link'];
        $ok = db_tx(function (PDO $pdo) use ($lid) {
            np_delete_link_graph_data($pdo, [$lid]);
            $pdo->prepare('DELETE FROM map_links WHERE id=?')->execute([$lid]);
        });
        if (!$ok) { echo json_encode(['error'=>'Databáza je zaneprázdnená – skús znova']); break; }
        echo json_encode(['ok'=>true]); break;

    case 'add_map':
        $mid = $nextId('maps');
        $pdo->prepare('INSERT INTO maps(id,name) VALUES(?,?)')->execute([$mid,trim($in['name'])]);
        echo json_encode(['ok'=>true,'map'=>$mid]); break;

    case 'move_map':
        // presun mapy hore/dole; prepočíta sort_order všetkých máp
        $dir = ($in['dir'] ?? 'up') === 'down' ? 1 : -1;
        $mid = (int)$in['map'];
        $list = $pdo->query('SELECT id FROM maps ORDER BY COALESCE(sort_order,999999), name')->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($mid, $list);
        if ($i !== false) {
            $j = $i + $dir;
            if ($j >= 0 && $j < count($list)) { $t=$list[$i]; $list[$i]=$list[$j]; $list[$j]=$t; }
        }
        $u = $pdo->prepare('UPDATE maps SET sort_order=? WHERE id=?');
        foreach ($list as $k=>$id) $u->execute([$k*10, $id]);
        echo json_encode(['ok'=>true]); break;

    case 'reorder_maps':
        // uloží nové poradie máp podľa poľa ID
        $order = $in['order'] ?? [];
        if (is_array($order)) {
            $u = $pdo->prepare('UPDATE maps SET sort_order=? WHERE id=?');
            foreach ($order as $k=>$id) $u->execute([$k*10, (int)$id]);
        }
        echo json_encode(['ok'=>true]); break;

    case 'link_types':
        echo json_encode($pdo->query('SELECT id,name,style,thickness FROM link_types ORDER BY thickness DESC, name')->fetchAll()); break;

    case 'update_link':
        $lid=(int)($in['link']??0);
        if(isset($in['type_id']) && $in['type_id']!==''){
            $t=$pdo->query('SELECT name,style,thickness FROM link_types WHERE id='.(int)$in['type_id'])->fetch();
            if($t) $pdo->prepare('UPDATE map_links SET ltype=?,style=?,thickness=? WHERE id=?')
                       ->execute([$t['name'],$t['style'],$t['thickness'],$lid]);
        }
        if(array_key_exists('snmp_device',$in)){
            $sd=$in['snmp_device']?(int)$in['snmp_device']:null;
            $si=(isset($in['snmp_ifindex'])&&$in['snmp_ifindex']!=='')?(int)$in['snmp_ifindex']:null;
            $pdo->prepare('UPDATE map_links SET snmp_device=?,snmp_ifindex=?,snmp_type=? WHERE id=?')
                ->execute([$sd,$si,($sd&&$si)?1:0,$lid]);
        }
        echo json_encode(['ok'=>true]); break;

    case 'snmp_profiles_list':
        // community a v3 heslá sú prístupové údaje k sieti – vidí ich len administrator
        $cols = has_role('administrator') ? 'id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto'
                                          : 'id,name,version,port';
        echo json_encode($pdo->query("SELECT $cols FROM snmp_profiles ORDER BY name")->fetchAll()); break;

    case 'snmp_profile_save':
        require_role('administrator');
        $id=(isset($in['id'])&&$in['id'])?(int)$in['id']:$nextId('snmp_profiles');
        $ver=(int)($in['version']??0);
        if (!in_array($ver, [0,1,2], true)) { echo json_encode(['error'=>'Neplatná verzia SNMP']); break; }
        $in['auth_proto'] = snmp_auth_proto($in['auth_proto'] ?? 'MD5');   // len povolené hodnoty (idú do príkazu snmpget)
        $in['priv_proto'] = snmp_priv_proto($in['priv_proto'] ?? 'DES');
        $port = (int)($in['port'] ?? 161); if ($port < 1 || $port > 65535) $port = 161; $in['port'] = $port;
        $repl2 = cfg('DB_DRIVER')==='mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
        $pdo->prepare("$repl2 snmp_profiles(id,name,community,version,port,sec_name,auth_pass,priv_pass,auth_proto,priv_proto) VALUES(?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,trim($in['name']??''),trim($in['community']??'public'),$ver,(int)($in['port']??161)?:161,
                       trim($in['sec_name']??''),trim($in['auth_pass']??''),trim($in['priv_pass']??''),
                       trim($in['auth_proto']??'MD5'),trim($in['priv_proto']??'DES')]);
        echo json_encode(['ok'=>true,'id'=>$id]); break;

    case 'snmp_profile_delete':
        require_role('administrator');
        $pdo->prepare('DELETE FROM snmp_profiles WHERE id=?')->execute([(int)$in['id']]);
        echo json_encode(['ok'=>true]); break;

    case 'snmp_interfaces':
        $id=(int)($_GET['device_id']??0);
        if ($id === NP_MX80_DEVICE_ID) { echo json_encode([]); break; }
        $dev=$pdo->query("SELECT d.ip,dt.name AS type_name,sp.community,sp.version,sp.port,sp.sec_name,sp.auth_pass,sp.priv_pass,sp.auth_proto,sp.priv_proto
                          FROM devices d
                          LEFT JOIN device_types dt ON dt.id=d.type_id
                          LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile
                          WHERE d.id=$id")->fetch();
        if(!$dev||!$dev['ip']){ echo json_encode(['error'=>'Zariadenie nemá IP']); break; }
        if (np_is_olt_type((string)($dev['type_name'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error'=>'SNMP interface discovery is disabled for OLT devices.']);
            break;
        }
        if($dev['version']===null || (int)$dev['version']<0){ echo json_encode(['error'=>'Zariadenie nemá SNMP profil – nastav ho v okne zariadenia → SNMP']); break; }
        $prof=['version'=>(int)$dev['version'],'port'=>(int)($dev['port']??161),'community'=>$dev['community'],'sec_name'=>$dev['sec_name'],
               'auth_pass'=>$dev['auth_pass'],'priv_pass'=>$dev['priv_pass'],'auth_proto'=>$dev['auth_proto'],'priv_proto'=>$dev['priv_proto']];
        $discovery = snmp_interface_discovery_result(
            static fn(string $oid): array => snmp_walk_result($dev['ip'], $prof, $oid)
        );
        $names = $discovery['values'];
        if (!$names) {
            http_response_code(502);
            echo json_encode([
                'error'=>snmp_interface_diagnostic_message($discovery['attempts']),
                'diagnostics'=>$discovery['attempts'],
            ]);
            break;
        }
        $aliases=snmp_walk($dev['ip'],$prof,'1.3.6.1.2.1.31.1.1.1.18'); // ifAlias
        $out=[];
        foreach($names as $idx=>$nm) {
            $out[]=[
                'idx'=>(int)$idx,
                'name'=>$nm,
                'description'=>trim($aliases[$idx] ?? '')
            ];
        }
        usort($out,fn($a,$b)=>$a['idx']-$b['idx']);
        echo json_encode($out); break;

    case 'get_settings':
        require_role('administrator');
        echo json_encode(['tg_enabled'=>setting_get('tg_enabled','0'),
            'tg_token'=>setting_get('tg_token',''), 'tg_chat'=>setting_get('tg_chat',''),
            'sms_enabled'=>setting_get('sms_enabled','0'),
            'sms_gateway_url'=>setting_get('sms_gateway_url',''),
            'sms_callerid'=>setting_get('sms_callerid',''),
            'sms_recipients'=>setting_get('sms_recipients',''),
            'sms_key_saved'=>setting_get('sms_apikey','')!=='' ? '1' : '0',
            'sms_secret_saved'=>setting_get('sms_secretkey','')!=='' ? '1' : '0',
            'snmp_interval'=>setting_get('snmp_interval','30'), 'map_refresh'=>setting_get('map_refresh','10'),
            'history_days'=>setting_get('history_days',(string)(cfg('HISTORY_DAYS') ?: 14)),
            'history_every'=>setting_get('history_every',(string)(cfg('HISTORY_EVERY') ?: 60)),
            'traffic_days'=>setting_get('traffic_days',(string)(cfg('TRAFFIC_DAYS') ?: 90)),
            'timezone'=>setting_get('timezone',''), 'timezone_eff'=>date_default_timezone_get(),
            'timezone_sys'=>np_system_tz(), 'now'=>date('Y-m-d H:i:s T')]); break;

    case 'timezones':
        require_role('administrator');
        echo json_encode(np_tz_list()); break;

    case 'save_settings':
        require_role('administrator');
        if (array_key_exists('sms_gateway_url',$in) &&
            trim((string)$in['sms_gateway_url']) !== '' &&
            !np_sms_gateway_endpoint_valid(trim((string)$in['sms_gateway_url']))) {
            echo json_encode(['error'=>'SMS gateway endpoint must be a valid HTTP or HTTPS URL']); break;
        }
        if(array_key_exists('tg_enabled',$in)) setting_set('tg_enabled', !empty($in['tg_enabled'])?'1':'0');
        if(array_key_exists('tg_token',$in)) setting_set('tg_token', trim($in['tg_token'] ?? ''));
        if(array_key_exists('tg_chat',$in)) setting_set('tg_chat', trim($in['tg_chat'] ?? ''));
        if(array_key_exists('sms_enabled',$in)) setting_set('sms_enabled', !empty($in['sms_enabled'])?'1':'0');
        foreach (['sms_apikey','sms_secretkey','sms_gateway_url','sms_callerid','sms_recipients'] as $sk) {
            if (array_key_exists($sk,$in)) {
                $sv = trim((string)$in[$sk]);
                if ($sk === 'sms_apikey' || $sk === 'sms_secretkey') { if ($sv !== '') setting_set($sk,$sv); }
                else setting_set($sk,$sv);
            }
        }
        if(array_key_exists('tg_token',$in) || array_key_exists('tg_chat',$in) || array_key_exists('tg_enabled',$in)) {
            setting_set('tg_block_until', '0'); setting_set('tg_fail_streak', '0');
        }
        if(array_key_exists('snmp_interval',$in)) setting_set('snmp_interval', max(3,(int)$in['snmp_interval']));
        if(array_key_exists('map_refresh',$in)) setting_set('map_refresh', max(2,(int)$in['map_refresh']));
        if(isset($in['history_days'])  && is_numeric($in['history_days'])  && (int)$in['history_days'] >= 1)  setting_set('history_days',  (int)$in['history_days']);
        if(isset($in['history_every']) && is_numeric($in['history_every']) && (int)$in['history_every'] >= 0) setting_set('history_every', (int)$in['history_every']);
        if(isset($in['traffic_days'])  && is_numeric($in['traffic_days'])  && (int)$in['traffic_days'] >= 1)  setting_set('traffic_days',  (int)$in['traffic_days']);
        if(array_key_exists('timezone',$in)) {
            $tz = trim((string)$in['timezone']);
            if ($tz !== '' && !np_tz_valid($tz)) {
                echo json_encode(['error'=>'Neznáme časové pásmo']); break;
            }
            setting_set('timezone', $tz);          // prázdne = automaticky zo servera
            $eff = $tz !== '' ? $tz : np_system_tz();
            if (!np_tz_valid($eff)) $eff = 'UTC';
            @date_default_timezone_set($eff);
        }
        echo json_encode(['ok'=>true]); break;

    case 'test_sms':
        require_role('administrator');
        $cfg = np_sms_config($in);
        $to = np_sms_recipients((string)($in['sms_recipients'] ?? $cfg['recipients']));
        if (!$to) { echo json_encode(['error'=>'Enter at least one valid recipient number']); break; }
        $results = [];
        $allOk = true;
        foreach ($to as $recipient) {
            $r = np_sms_send_one($cfg, $recipient, 'NetPulse test SMS: gateway connection check.');
            $ok = !empty($r['ok']);
            if (!$ok) $allOk = false;
            $results[] = [
                'recipient'=>$recipient,
                'ok'=>$ok,
                'http_code'=>$r['http_code'] ?? 0,
                'gateway_response'=>$r['gateway_response'] ?? '',
                'error'=>$r['error'] ?? ''
            ];
        }
        echo json_encode(['ok'=>$allOk, 'results'=>$results]); break;

    case 'test_telegram':
        require_role('administrator');
        $token=trim($in['tg_token'] ?? setting_get('tg_token',''));
        $chat=trim($in['tg_chat'] ?? setting_get('tg_chat',''));
        if($token===''||$chat===''){ echo json_encode(['error'=>'Zadaj token aj chat ID']); break; }
        $r=tg_send($token,$chat,'✅ NetPulse test — Telegram funguje.');
        if(!empty($r['ok'])){ setting_set('tg_block_until','0'); setting_set('tg_fail_streak','0'); }
        echo json_encode($r['ok']?['ok'=>true]:['error'=>'Nepodarilo sa: '.substr((string)($r['resp']??$r['err']??'chyba'),0,200)]); break;

    case 'graph_sources':
        np_port_schema($pdo);
        $allowedIds=[];
        if(!has_role('admin')){
            $st=$pdo->prepare('SELECT source_id FROM user_graph_permissions WHERE user_id=?');
            $st->execute([current_uid()]);
            $allowedIds=$st->fetchAll(PDO::FETCH_COLUMN);
        }
        $sources = np_graph_sources($pdo,current_role(),$allowedIds,current_uid());
        $sources = array_values(array_filter(
            $sources,
            static fn(array $source): bool => !np_mx80_is_legacy_source($pdo,(string)($source['id'] ?? ''))
        ));
        echo json_encode($sources); break;

    case 'graph_interface_selection_set':
        $sourceIds=$in['source_ids']??null;
        if(!is_array($sourceIds)||count($sourceIds)>512){
            http_response_code(400);
            echo json_encode(['error'=>'Invalid MX80 interface selection.']);
            break;
        }
        $allowedIds=null;
        if(!has_role('admin')){
            $st=$pdo->prepare('SELECT source_id FROM user_graph_permissions WHERE user_id=?');
            $st->execute([current_uid()]);
            $allowedIds=array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN));
        }
        try{
            $userId=current_uid();
            if($userId===null)throw new RuntimeException('Authenticated user is unavailable.');
            np_graph_mx80_save_selection($pdo,$userId,$sourceIds,$allowedIds);
            echo json_encode(['ok'=>true]);
        }catch(InvalidArgumentException $e){
            http_response_code(400);
            echo json_encode(['error'=>$e->getMessage()]);
        }
        break;

    case 'graph_favorites_set':
        require_role('admin');
        $source=trim((string)($in['source_id']??''));
        $favorite=!empty($in['favorite']);
        if(!np_graph_set_favorite($pdo,$source,$favorite)){http_response_code(400);echo json_encode(['error'=>'Invalid graph source']);break;}
        echo json_encode(['ok'=>true,'source_id'=>$source,'favorite'=>$favorite]); break;

    case 'graph_data':
        np_port_schema($pdo);
        $source=(string)($_GET['id']??'');
        if(!has_role('admin')){
            $st=$pdo->prepare('SELECT 1 FROM user_graph_permissions WHERE user_id=? AND source_id=?');
            $st->execute([current_uid(),$source]);
            if(!$st->fetchColumn()){
                http_response_code(403);
                echo json_encode(['error'=>'এই graph দেখার অনুমতি নেই']); exit;
            }
        }
        if (np_mx80_is_legacy_source($pdo,$source)) {
            http_response_code(403);
            echo json_encode(['error'=>'Use the Core Router MX80 category graphs for this interface.']);
            break;
        }
        $range=(string)($_GET['range']??'day');
        $secs=np_graph_range_seconds($range);
        $from=date('Y-m-d H:i:s', time()-$secs);
        if(preg_match('/^p:(\d+):(\d+)$/',$source,$m)){
            if (np_graph_olt_interface_is_available($pdo, (int)$m[1], (int)$m[2]) === false) {
                echo json_encode(['points'=>[],'summary'=>null]);
                break;
            }
            $q=$pdo->prepare('SELECT ts,rx_bps,tx_bps FROM port_traffic_history
                WHERE device_id=? AND ifindex=? AND ts>=? ORDER BY ts');
            $q->execute([(int)$m[1],(int)$m[2],$from]);
        }else{
            $link=$pdo->prepare('SELECT snmp_device,snmp_ifindex FROM map_links WHERE id=? AND snmp_type=1');
            $link->execute([(int)$source]);
            $sourceLink=$link->fetch(PDO::FETCH_ASSOC);
            if ($sourceLink) {
                $oltAvailability = np_graph_olt_interface_is_available(
                    $pdo, (int)$sourceLink['snmp_device'], (int)$sourceLink['snmp_ifindex']
                );
                if ($oltAvailability !== null) {
                    echo json_encode(['points'=>[],'summary'=>null]);
                    break;
                }
            }
            $q=$pdo->prepare('SELECT ts,rx_bps,tx_bps FROM traffic_history
                WHERE link_id=? AND ts>=? ORDER BY ts');
            $q->execute([(int)$source,$from]);
        }
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(np_graph_traffic_response($rows,$secs)); break;

    case 'optical_history':
        $deviceId = filter_var($_GET['device_id'] ?? '1000001', FILTER_VALIDATE_INT);
        $ifname = trim((string)($_GET['ifname'] ?? ''));
        $direction = strtolower(trim((string)($_GET['direction'] ?? '')));
        $range = (string)($_GET['range'] ?? 'day');

        if ($deviceId === false || $deviceId < 1 ||
            !in_array($direction, ['', 'rx', 'tx'], true) ||
            !in_array($range, ['hour', 'day', 'week', 'month'], true) ||
            strlen($ifname) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid optical history parameters']);
            break;
        }
        if ((int)$deviceId === NP_MX80_DEVICE_ID) {
            http_response_code(403);
            echo json_encode(['error'=>'Use Core Router MX80 for router traffic; Optical / SFP is disabled for this device.']);
            break;
        }

        $seconds = [
            'hour' => 3600,
            'day' => 86400,
            'week' => 604800,
            'month' => 2592000
        ][$range];

        op_history_schema($pdo);
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
        $deviceQuery = $pdo->prepare(
            'SELECT dt.name,d.ip,d.vendor FROM devices d LEFT JOIN device_types dt ON dt.id=d.type_id WHERE d.id=?'
        );
        $deviceQuery->execute([$deviceId]);
        $deviceInfo = $deviceQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        $deviceType = (string)($deviceInfo['name'] ?? '');
        if (np_is_olt_type($deviceType)) {
            echo json_encode(['device_id'=>(int)$deviceId,'range'=>$range,'points'=>[]]);
            break;
        }
        np_port_schema($pdo);
        $allowlist = np_olt_interface_allowlist(
            $deviceId, $deviceType, (string)($deviceInfo['ip'] ?? ''),
            (string)($deviceInfo['vendor'] ?? '')
        );
        $sql = 'SELECT oph.sampled_at, oph.device_id, oph.device_name, oph.ip,
                       oph.sensor_index, oph.ifname, oph.direction, oph.dbm,
                       oph.sensor_status,
                       COALESCE(NULLIF(TRIM(pi.oper_status), \'\'), \'unknown\') AS port_status,
                       COALESCE(NULLIF(TRIM(oph.description), \'\'),
                                NULLIF(TRIM(pi.description), \'\'), \'\') AS description
                FROM optical_power_history oph
                LEFT JOIN devices optical_device ON optical_device.id = oph.device_id
                LEFT JOIN port_inventory pi
                  ON pi.device_id = oph.device_id AND pi.ifname = oph.ifname
                WHERE oph.device_id = ? AND oph.sampled_at >= ?';
        $params = [$deviceId, $from];
        if (np_is_olt_type($deviceType)) {
            $visibleInterfaces = array_values(array_filter(
                $allowlist,
                static fn(string $interface): bool => !np_olt_interface_is_excluded($interface)
            ));
            if (!$visibleInterfaces) {
                $sql .= ' AND 1=0';
            } else {
                $sql .= ' AND oph.ifname IN (' . implode(',', array_fill(0, count($visibleInterfaces), '?')) . ')';
                array_push($params, ...$visibleInterfaces);
            }
        }

        if ($ifname !== '') {
            $sql .= ' AND oph.ifname = ?';
            $params[] = $ifname;
        }
        if ($direction !== '') {
            $sql .= ' AND oph.direction = ?';
            $params[] = $direction;
        }

        $sql .= ' ORDER BY oph.sampled_at ASC, oph.ifname ASC, oph.direction ASC LIMIT 1000';
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $points = $q->fetchAll(PDO::FETCH_ASSOC);
        $points = np_filter_olt_interfaces($deviceType, $points, $allowlist);

        echo json_encode([
            'device_id' => $deviceId,
            'range' => $range,
            'points' => $points
        ]);
        break;

    case 'device_types':
        echo json_encode($pdo->query('SELECT id,name,icon FROM device_types ORDER BY name')->fetchAll()); break;

    case 'change_password':
        $u = current_user();
        $st = $pdo->prepare('SELECT pass_hash FROM users WHERE username=?'); $st->execute([$u]);
        $h = $st->fetchColumn();
        if (!password_verify((string)($in['old'] ?? ''), (string)$h)) { echo json_encode(['error'=>'Staré heslo nesedí']); break; }
        if (!np_password_ok((string)($in['new'] ?? ''))) { echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE username=?')
            ->execute([password_hash((string)$in['new'], PASSWORD_DEFAULT), $u]);
        session_regenerate_id(true);
        echo json_encode(['ok'=>true]); break;

    case 'whoami':
        echo json_encode(['user'=>current_user(),'role'=>current_role(),'default_pw'=>np_default_password_active()]); break;

    case 'client_config':
        echo json_encode(['map_refresh'=>(int)setting_get('map_refresh',10)]); break;

    case 'users_list':
        require_role('admin');
        echo json_encode($pdo->query('SELECT id,username,role,created FROM users ORDER BY username')->fetchAll()); break;

    case 'login_history':
        require_role('admin');
        $rows=$pdo->query('SELECT username,logged_at FROM login_history ORDER BY id DESC LIMIT 200')->fetchAll();
        foreach($rows as &$row) $row['logged_at']=(int)$row['logged_at'];
        unset($row);
        echo json_encode($rows); break;

    case 'graph_permissions_get':
        require_role('admin');
        $uid=(int)($_GET['user_id']??0);
        $st=$pdo->prepare('SELECT source_id FROM user_graph_permissions WHERE user_id=? ORDER BY source_id');
        $st->execute([$uid]);
        echo json_encode(['source_ids'=>$st->fetchAll(PDO::FETCH_COLUMN)]); break;

    case 'graph_permissions_save':
        require_role('admin');
        $uid=(int)($in['user_id']??0);
        $st=$pdo->prepare('SELECT 1 FROM users WHERE id=?'); $st->execute([$uid]);
        if(!$st->fetchColumn()){ echo json_encode(['error'=>'Používateľ nenájdený']); break; }
        $valid=[];
        $linkIdCast = np_is_mysql() ? 'CAST(id AS CHAR)' : 'CAST(id AS TEXT)';
        $portSourceExpr = np_is_mysql()
            ? "CONCAT('p:',device_id,':',ifindex)"
            : "'p:'||device_id||':'||ifindex";
        foreach($pdo->query("SELECT $linkIdCast AS sid FROM map_links WHERE snmp_type=1 AND snmp_ifindex>0") as $r) $valid[$r['sid']]=true;
        foreach($pdo->query("SELECT $portSourceExpr AS sid FROM port_inventory") as $r) $valid[$r['sid']]=true;
        $ids=$in['source_ids']??[]; if(!is_array($ids)) $ids=[];
        $ids=array_values(array_unique(array_filter(array_map('strval',$ids),fn($x)=>isset($valid[$x]))));
        try{
            $pdo->beginTransaction();
            $st=$pdo->prepare('DELETE FROM user_graph_permissions WHERE user_id=?'); $st->execute([$uid]);
            $st=$pdo->prepare('INSERT INTO user_graph_permissions(user_id,source_id) VALUES(?,?)');
            foreach($ids as $sid) $st->execute([$uid,$sid]);
            $pdo->commit(); echo json_encode(['ok'=>true,'count'=>count($ids)]);
        }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); echo json_encode(['error'=>'Permission save failed']); }
        break;

    case 'user_add':
        require_role('admin');
        $u=trim($in['username']??''); $pw=(string)($in['password']??''); $role=$in['role']??'user';
        if($u===''||$pw===''){ echo json_encode(['error'=>'Meno aj heslo sú povinné']); break; }
        if(!np_password_ok($pw)){ echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        if(!in_array($role,['user','employee','admin','administrator'],true)) $role='user';
        if(!has_role('administrator') && !in_array($role,['user','employee'],true)){ echo json_encode(['error'=>'Vyššiu rolu môže prideliť len administrator']); break; }
        try{ $pdo->prepare('INSERT INTO users(username,pass_hash,role,created) VALUES(?,?,?,?)')
                ->execute([$u,password_hash($pw,PASSWORD_DEFAULT),$role,date('Y-m-d H:i:s')]);
             echo json_encode(['ok'=>true]); }
        catch(Throwable $e){ echo json_encode(['error'=>'Používateľ už existuje']); }
        break;

    case 'user_delete':
        require_role('admin');
        $id=(int)($in['id']??0);
        if($id===current_uid()){ echo json_encode(['error'=>'Nemôžeš zmazať sám seba']); break; }
        $tg=$pdo->query('SELECT role FROM users WHERE id='.$id)->fetch();
        if(!$tg){ echo json_encode(['error'=>'Používateľ nenájdený']); break; }
        if($tg['role']==='administrator' && !has_role('administrator')){ echo json_encode(['error'=>'Administrátora môže mazať len administrator']); break; }
        if($tg['role']==='administrator' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='administrator'")->fetchColumn()<=1){ echo json_encode(['error'=>'Musí zostať aspoň 1 administrator']); break; }
        $pdo->prepare('DELETE FROM user_graph_permissions WHERE user_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM user_graph_interface_selections WHERE user_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM mx80_category_permissions WHERE user_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        echo json_encode(['ok'=>true]); break;

    case 'user_set_role':
        require_role('administrator');
        $id=(int)($in['id']??0); $role=$in['role']??'user';
        if(!in_array($role,['user','employee','admin','administrator'],true)){ echo json_encode(['error'=>'Neplatná rola']); break; }
        $cur=$pdo->query('SELECT role FROM users WHERE id='.$id)->fetch();
        if($cur && $cur['role']==='administrator' && $role!=='administrator' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='administrator'")->fetchColumn()<=1){ echo json_encode(['error'=>'Musí zostať aspoň 1 administrator']); break; }
        $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$id]);
        echo json_encode(['ok'=>true]); break;

    case 'user_reset_password':
        require_role('administrator');
        $id=(int)($in['id']??0); $pw=(string)($in['new']??'');
        if($pw===''){ echo json_encode(['error'=>'Zadaj nové heslo']); break; }
        if(!np_password_ok($pw)){ echo json_encode(['error'=>'Heslo musí mať aspoň '.NP_MIN_PASSWORD.' znakov']); break; }
        $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
        echo json_encode(['ok'=>true]); break;

    case 'backup':
        // obsahuje hashe hesiel, heslá zariadení, SNMP údaje a Telegram token => len administrator
        require_role('administrator');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Záloha cez web je pre SQLite; pri MySQL použi mysqldump']); break; }
        @set_time_limit(600);
        // Konzistentná kópia aj s dátami, ktoré sú ešte vo WAL (obyčajné kopírovanie súboru by ich vynechalo)
        $f = tempnam(sys_get_temp_dir(), 'npbak'); @unlink($f); $tmpUsed = true;
        try { $pdo->exec('VACUUM INTO '.$pdo->quote($f)); }
        catch (Throwable $e) {
            error_log('NetPulse backup VACUUM INTO: '.$e->getMessage());
            try { $pdo->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Throwable $e2) {}
            $f = cfg('SQLITE_PATH'); $tmpUsed = false;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="netpulse-backup-'.date('Y-m-d_His').'.db"');
        header('Content-Length: '.filesize($f));
        readfile($f); if ($tmpUsed) @unlink($f); exit;

    case 'restore':
        require_role('administrator');
        if(cfg('DB_DRIVER')!=='sqlite'){ echo json_encode(['error'=>'Obnova cez web je len pre SQLite']); break; }
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor zálohy']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný súbor (nie je SQLite záloha)']); break; }
        @set_time_limit(600);
        $r = np_restore_sqlite($pdo, $tmp);
        if (!empty($r['ok'])) logout();   // používatelia sa mohli zmeniť – prihlás sa znova
        echo json_encode($r); break;

    case 'import_dude':
        require_role('administrator');
        if(empty($_FILES['file']['tmp_name'])){ echo json_encode(['error'=>'Chýba súbor dude.db']); break; }
        $tmp=$_FILES['file']['tmp_name'];
        if(strpos((string)file_get_contents($tmp,false,null,0,16),'SQLite format 3')!==0){ echo json_encode(['error'=>'Neplatný dude.db (nie je SQLite)']); break; }
        require_once __DIR__.'/importer.php';
        try{ echo json_encode(['ok'=>true,'summary'=>dude_import($tmp)]); }
        catch(Throwable $e){ error_log('NetPulse import: '.$e->getMessage()); echo json_encode(['error'=>'Import zlyhal: '.$e->getMessage()]); }
        break;

    default:
        http_response_code(400); echo json_encode(['error'=>'neznáma akcia']);
    }
} catch (Throwable $e) {
    error_log('NetPulse API ['.$a.']: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo json_encode(['error'=> db_is_locked($e) ? 'Databáza je práve zaneprázdnená – skús to znova'
                                                  : 'Interná chyba servera (podrobnosti v logu)']);
}
