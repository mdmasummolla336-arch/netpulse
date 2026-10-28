<?php
/**
 * Trojstavový živý monitoring (ako Dude):
 *   up=zelená (odpovedá), pending=žltá (práve nereaguje), down=červená (>DOWN_AFTER s).
 *
 * Jeden beh = jeden cyklus (systemd ho spúšťa v slučke). Cyklus má tri fázy:
 *   1) SONDOVANIE celej siete naraz – fping + paralelné TCP (bez zápisu do DB),
 *   2) ZÁPIS výsledkov v JEDNEJ krátkej transakcii (stav, história, udalosti, výpadky,
 *      fronta Telegram správ) – všetko alebo nič, takže sa nič nestratí ani nezdvojí,
 *   3) DORUČENIE Telegram správ z fronty (pri výpadku Telegramu počkajú na ďalší pokus).
 *
 * Zariadenie je "up", ak odpovie ping ALEBO ktorákoľvek jeho TCP/SNMP/DNS služba,
 * alebo (pri CHECK_METHOD=auto) niektorý záložný TCP port.
 */
require __DIR__ . '/cli_guard.php';   // len z príkazového riadka, nie ako root
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
require_once __DIR__ . '/snmp_lib.php';
require_once __DIR__ . '/olt_monitor_lib.php';
np_cli_guard();

// ---- len jedna inštancia naraz (ručné spustenie popri službe by zdvojilo prácu) ----
$lockPath = is_writable(__DIR__ . '/data') ? __DIR__ . '/data/monitor.lock'
                                           : sys_get_temp_dir() . '/netpulse-monitor-' . md5(__DIR__) . '.lock';
$lockFh = @fopen($lockPath, 'c');
if ($lockFh && !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Monitor už beží v inom procese – tento beh preskakujem\n"); exit(0);
}

try { migrate(); } catch (Throwable $e) { error_log('NetPulse migrate: '.$e->getMessage()); }

require __DIR__ . '/probe_lib.php';
require_once __DIR__ . '/graph_retention_lib.php';
require_once __DIR__ . '/optical_history_lib.php';

try { $pdo = db(); } catch (Throwable $e) {
    fwrite(STDERR, "DB nedostupná – cyklus preskočený: ".$e->getMessage()."\n"); exit(0);
}
$t0Cycle = microtime(true);
$pt        = max(1, (int) cfg('PING_TIMEOUT'));
$tcpTo     = (float) (cfg('TCP_TIMEOUT') ?: 1);
$downAfter = (int) (cfg('DOWN_AFTER') ?: 30);
$histDays  = max(1, (int) setting_get('history_days',  (string)(cfg('HISTORY_DAYS')  ?: 14)));
$histEvery = max(0, (int) setting_get('history_every', (string)(cfg('HISTORY_EVERY') ?? 60)));
$fallbackPorts = cfg('TCP_FALLBACK_PORTS') ?: [8291,80,443,22,23];
$method   = cfg('CHECK_METHOD') ?: 'auto';
$useIcmp  = ($method === 'icmp') || ($method === 'auto' && exec_allowed());

// ======================================================================
// 0) ÚDRŽBA – vypnutý monitoring / chýbajúca IP => sivé, uzavri ich výpadky;
//    uzavri aj výpadky, ktoré ostali visieť zo staršej verzie.
// ======================================================================
$now = date('Y-m-d H:i:s'); $nowTs = time();
try {
    db_tx(function (PDO $pdo) use ($now, $nowTs) {
        $cl = $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');
        foreach ($pdo->query("SELECT o.id,o.started,o.started_ts FROM outages o JOIN devices d ON d.id=o.device_id
                              WHERE o.ended IS NULL AND (d.monitored=0 OR d.ip IS NULL OR d.ip='')")->fetchAll() as $o) {
            $st = np_epoch($o['started_ts'], $o['started']);
            $cl->execute([$now, $st ? max(0, $nowTs - $st) : null, $o['id']]);
        }
        // funkčné zariadenie, ktorého návrat už bol zaznamenaný, nemá mať otvorený výpadok
        $orph = $pdo->query("SELECT o.id,o.device_id,o.started,o.started_ts FROM outages o JOIN devices d ON d.id=o.device_id
                             WHERE o.ended IS NULL AND d.status='up' AND (d.notified='up' OR d.notified IS NULL)")->fetchAll();
        if ($orph) {
            $fu = $pdo->prepare("SELECT MIN(ts) FROM status_history WHERE device_id=? AND ts > ? AND status='up'");
            foreach ($orph as $o) {
                $fu->execute([$o['device_id'], $o['started']]); $end = $fu->fetchColumn() ?: $now; $fu->closeCursor();
                $st = np_epoch($o['started_ts'], $o['started']); $en = strtotime((string)$end);
                $cl->execute([$end, ($st && $en) ? max(0, $en - $st) : null, $o['id']]);
            }
            error_log('NetPulse: uzavretých '.count($orph).' visiacich výpadkov');
        }
        $pdo->exec("UPDATE devices SET status='unknown', rtt=NULL, down_since=NULL, down_ts=NULL, notified='up', ping_fail_count=0
                    WHERE (monitored=0 OR ip IS NULL OR ip='')
                      AND (status<>'unknown' OR status IS NULL OR notified<>'up' OR notified IS NULL
                           OR down_since IS NOT NULL OR ping_fail_count<>0)");
    });
} catch (Throwable $e) { error_log('NetPulse údržba: '.$e->getMessage()); }

$devs = db_retry(fn() => $pdo->query("SELECT d.id,d.name,d.ip,d.status,d.down_since,d.down_ts,d.notified,d.last_check,
                     d.ping_fail_count,d.check_mode,d.type_id,dt.name AS type_name,
                     sp.community,sp.version,sp.port,sp.sec_name,sp.auth_pass,sp.priv_pass,sp.auth_proto,sp.priv_proto
                     FROM devices d
                     LEFT JOIN device_types dt ON dt.id=d.type_id
                     LEFT JOIN snmp_profiles sp ON sp.id=d.snmp_profile
                     WHERE d.ip IS NOT NULL AND d.ip<>'' AND COALESCE(d.monitored,1)=1")->fetchAll());
if ($devs === null) { fwrite(STDERR, "Zoznam zariadení sa nepodarilo načítať (DB zamknutá) – cyklus preskočený\n"); exit(0); }
// prázdny zoznam: sondovanie a zápis sa preskočia, fronta správ a upratovanie bežia ďalej
$devById = []; foreach ($devs as $d) $devById[$d['id']] = $d;
$hasMonitoredOlt = (bool)array_filter(
    $devs,
    static fn(array $device): bool => np_is_olt_type((string)($device['type_name'] ?? ''))
);
$forceOltIcmp = $hasMonitoredOlt && exec_allowed();
$scanIcmp = $useIcmp || $forceOltIcmp;
$useFping = $scanIcmp && cfg('USE_FPING') && fping_available();
$pingBinaryAvailable = false;
if ($scanIcmp && !$useFping && exec_allowed()) {
    $pingCommand = [];
    @exec('command -v ping 2>/dev/null', $pingCommand, $pingCommandStatus);
    $pingBinaryAvailable = $pingCommandStatus === 0 && !empty($pingCommand);
}
$icmpAvailable = $useFping || $pingBinaryAvailable;
if ($method === 'icmp' && !$icmpAvailable) {
    error_log('NetPulse monitor: ICMP mode is configured, but neither fping nor ping is available; device status will be preserved.');
}
$svcRows = db_retry(fn() => $pdo->query("SELECT id,device_id,ptype,port FROM services WHERE enabled=1 OR enabled IS NULL")->fetchAll()) ?: [];
$svcByDev = []; foreach ($svcRows as $sv) $svcByDev[$sv['device_id']][] = $sv;

// ======================================================================
// 1) SONDOVANIE – bez zápisu do databázy
// ======================================================================
$now = date('Y-m-d H:i:s'); $nowTs = time();          // čas merania
$res = [];                                             // id => mode, ping, reach, rtt, valid
$bad = 0; $svcOnly = 0;
$hosts = [];
foreach ($devs as $d) {
    $ok = np_valid_host($d['ip']);
    if (!$ok) $bad++;
    $isOlt = np_is_olt_type((string)($d['type_name'] ?? ''));
    $mode = $isOlt ? 'ping' : np_effective_mode($d['check_mode'] ?? null, $svcByDev[$d['id']] ?? []);
    if ($mode === 'services') $svcOnly++;
    $needsPing = $mode === 'ping';
    foreach ($svcByDev[$d['id']] ?? [] as $sv) if ($sv['ptype'] === 'icmp') $needsPing = true;
    if ($isOlt) $needsPing = true;
    $res[$d['id']] = [
        'mode' => $mode, 'ping' => false, 'ping_checked' => false,
        'ping_required' => $needsPing, 'reach' => false, 'rtt' => null, 'valid' => $ok
    ];
    if ($ok && $needsPing && $scanIcmp) $hosts[$d['ip']] = true;
}
if ($useFping) {
    $alive = $hosts ? fping_batch(array_keys($hosts), max(300, $pt * 1000)) : [];
    foreach ($devs as $d) {
        if (!$res[$d['id']]['valid'] || !isset($hosts[$d['ip']])) continue;
        $res[$d['id']]['ping_checked'] = true;
        if (array_key_exists($d['ip'], $alive)) {
            $res[$d['id']]['ping'] = true;
            $res[$d['id']]['rtt'] = $alive[$d['ip']];
        }
    }
} elseif ($pingBinaryAvailable) {
    $pinged = [];
    foreach ($devs as $d) {
        if (!$res[$d['id']]['valid'] || !isset($hosts[$d['ip']])) continue;
        if (!array_key_exists($d['ip'], $pinged)) {
            $o = [];
            @exec('ping -c 1 -W ' . $pt . ' ' . escapeshellarg($d['ip']) . ' 2>/dev/null', $o, $rc);
            $pinged[$d['ip']] = [
                'up' => $rc === 0,
                'rtt' => preg_match('/time[=<]([\d.]+)/', implode("\n", $o), $m) ? (float)$m[1] : null
            ];
        }
        $res[$d['id']]['ping_checked'] = true;
        $res[$d['id']]['ping'] = $pinged[$d['ip']]['up'];
        if ($pinged[$d['ip']]['up']) $res[$d['id']]['rtt'] = $pinged[$d['ip']]['rtt'];
    }
}
// služby zariadení
$svcUp = []; $tcpT = [];
foreach ($svcRows as $sv) {
    $d = $devById[$sv['device_id']] ?? null;
    if (!$d || !$res[$d['id']]['valid'] || np_is_olt_type((string)($d['type_name'] ?? ''))) continue;
    switch ($sv['ptype']) {
        case 'tcp':  $tcpT['s' . $sv['id']] = [$d['ip'], (int)$sv['port']]; break;
        case 'dns':  $svcUp[$sv['id']] = dns_check($d['ip']); break;
        case 'snmp':
            $prof = [
                'community'=>$d['community'],'version'=>$d['version'],'port'=>$d['port'],
                'sec_name'=>$d['sec_name'],'auth_pass'=>$d['auth_pass'],'priv_pass'=>$d['priv_pass'],
                'auth_proto'=>$d['auth_proto'],'priv_proto'=>$d['priv_proto']
            ];
            $svcUp[$sv['id']] = snmp_check($d['ip'], (int)$sv['port'] ?: (int)($d['port'] ?? 161), $prof);
            break;
        case 'icmp':
            if ($res[$d['id']]['ping_checked']) $svcUp[$sv['id']] = $res[$d['id']]['ping'];
            break;
        default:
            if ($res[$d['id']]['ping_checked']) $svcUp[$sv['id']] = $res[$d['id']]['ping'];
            break;
    }
}
$tcpOk = $tcpT ? tcp_probe_batch($tcpT, $tcpTo) : [];
foreach ($svcRows as $sv) {
    if (!isset($devById[$sv['device_id']]) || !$res[$sv['device_id']]['valid']) continue;
    if ($sv['ptype'] === 'tcp') $svcUp[$sv['id']] = isset($tcpOk['s' . $sv['id']]);
}
// vyhodnotenie podľa spôsobu kontroly
foreach ($devs as $d) {
    $id = $d['id']; $r = &$res[$id];
    if (!$r['valid']) { unset($r); continue; }
    $eval = array_filter($svcByDev[$id] ?? [], fn($s) => in_array($s['ptype'], ['icmp','tcp','dns','snmp'], true));
    if ($r['mode'] === 'services') {
        // len služby: musia odpovedať všetky – výpadok jediného portu = výpadok zariadenia
        $all = (bool)$eval;
        foreach ($eval as $s) if (empty($svcUp[$s['id']])) { $all = false; break; }
        $r['reach'] = $all;
        if ($r['rtt'] === null) foreach ($eval as $s) if (isset($tcpOk['s' . $s['id']])) { $r['rtt'] = $tcpOk['s' . $s['id']]; break; }
        if (!$all) $r['rtt'] = null;
    } else {
        // ping + služby: stačí ping alebo ktorákoľvek služba
        $r['reach'] = $r['ping'];
        foreach ($svcByDev[$id] ?? [] as $s) if (!empty($svcUp[$s['id']])) {
            $r['reach'] = true;
            if ($r['rtt'] === null && isset($tcpOk['s' . $s['id']])) $r['rtt'] = $tcpOk['s' . $s['id']];
        }
    }
    unset($r);
}
// posledná záchrana – záložné porty (winbox/web…), len pri kontrole pingom, všetky naraz
if ($method === 'auto') {
    $fb = [];
    foreach ($devs as $d) if (!np_is_olt_type((string)($d['type_name'] ?? '')) &&
                              $res[$d['id']]['mode'] === 'ping' && !$res[$d['id']]['reach'] && $res[$d['id']]['valid'])
        foreach ($fallbackPorts as $p) $fb[$d['id'] . ':' . (int)$p] = [$d['ip'], (int)$p];
    foreach (($fb ? tcp_probe_batch($fb, $tcpTo) : []) as $k => $ms) {
        $id = (int) strtok($k, ':');
        if (!$res[$id]['reach']) { $res[$id]['reach'] = true; $res[$id]['rtt'] = $res[$id]['rtt'] ?? $ms; }
    }
}
$probeSec = microtime(true) - $t0Cycle;

// ======================================================================
// 2) ZÁPIS – jedna transakcia: buď sa zapíše celý cyklus, alebo nič
//    (pri zámku sa zopakuje; ďalší cyklus by aj tak zistil ten istý stav)
// ======================================================================
$tr = ['down' => 0, 'up' => 0];
$written = !$devs;
if ($devs) try {
    $written = db_tx(function (PDO $pdo) use ($devs, $res, $svcRows, $svcUp, $devById, $now, $nowTs,
                                             $downAfter, $histEvery, &$tr) {
        $tr = ['down' => 0, 'up' => 0];                // pri opakovaní transakcie začni odznova
        // Podmienka zabráni zápisu výsledku, ak admin počas sondovania zariadeniu vypol monitoring,
        // zmenil IP alebo ho zmazal – inak by vznikla falošná udalosť a správa do Telegramu.
        $updDev  = $pdo->prepare('UPDATE devices SET status=?,rtt=?,last_check=?,down_since=?,down_ts=?,notified=?,ping_fail_count=?
                                  WHERE id=? AND ip=? AND COALESCE(monitored,1)=1
                                    AND ((type_id=?) OR (type_id IS NULL AND ? IS NULL))');
        $updSvc  = $pdo->prepare('UPDATE services SET status=?,last_check=? WHERE id=?');
        $hist    = $pdo->prepare('INSERT INTO status_history(device_id,ts,status,rtt) VALUES(?,?,?,?)');
        $evt     = $pdo->prepare('INSERT INTO events(ts,device_id,device_name,ip,status,message) VALUES(?,?,?,?,?,?)');
        $openOut = $pdo->prepare('SELECT id,started,started_ts FROM outages WHERE device_id=? AND ended IS NULL ORDER BY id');
        $newOut  = $pdo->prepare('INSERT INTO outages(device_id,service,started,started_ts) VALUES(?,?,?,?)');
        $closeOut= $pdo->prepare('UPDATE outages SET ended=?,duration=? WHERE id=?');

        foreach ($devs as $d) {
            $r = $res[$d['id']]; $ip = $d['ip']; $prev = $d['status'];
            if ($r['ping_required'] && !$r['ping_checked'] && !$r['reach']) continue;
            // posledný zaznamenaný prechod (pre staré DB bez stĺpca: podľa stavu)
            $notified  = !empty($d['notified']) ? $d['notified'] : ($prev === 'down' ? 'down' : 'up');
            $downSince = $d['down_since'];
            $downTs    = np_epoch($d['down_ts'], $d['down_since']);   // epoch – odolné voči zmene času
            $pingFailCount = (int)($d['ping_fail_count'] ?? 0);
            $isOlt = np_is_olt_type((string)($d['type_name'] ?? ''));
            if ($isOlt) {
                $oltStatus = np_olt_icmp_transition(
                    (string)$prev,
                    $pingFailCount,
                    $downSince === null ? null : (string)$downSince,
                    $downTs,
                    (bool)$r['ping_checked'],
                    (bool)$r['ping'],
                    $now,
                    $nowTs
                );
                if (!$oltStatus['valid']) continue;
                $status = $oltStatus['status'];
                $pingFailCount = $oltStatus['ping_fail_count'];
                $downSince = $oltStatus['down_since'];
                $downTs = $oltStatus['down_ts'];
            } elseif ($r['reach']) {
                $status = 'up'; $downSince = null; $downTs = null;
            } else {
                if (!$downTs) { $downTs = $nowTs; $downSince = $now; }
                $status = (($nowTs - $downTs) >= $downAfter) ? 'down' : 'pending';
            }
            $doDown = ($status === 'down' && $notified !== 'down');
            $doUp   = ($status === 'up'   && $notified === 'down');
            $newN   = $doDown ? 'down' : ($doUp ? 'up' : $notified);
            $updDev->execute([
                $status, $r['rtt'], $now, $downSince, $downTs, $newN, $pingFailCount,
                $d['id'], $ip, $d['type_id'], $d['type_id']
            ]);
            if ($updDev->rowCount() === 0) continue;          // medzitým zmenené – výsledok neplatí

            // história: zmena stavu vždy, odozva len vzorkovaná (inak DB rastie o 500k riadkov denne)
            $sample = ($status !== $prev) || $histEvery === 0 || empty($d['last_check'])
                   || intdiv($nowTs, max(1, $histEvery)) !== intdiv((int)strtotime((string)$d['last_check']), max(1, $histEvery));
            if ($sample) $hist->execute([$d['id'], $now, $status, $r['rtt']]);

            if ($doDown) {
                $tr['down']++;
                $evt->execute([$now, $d['id'], $d['name'], $ip, 'down', "Zariadenie: {$d['name']} IP:$ip; je nefunkčné"]);
                $openOut->execute([$d['id']]); $has = (bool)$openOut->fetch(); $openOut->closeCursor();
                if (!$has) $newOut->execute([$d['id'], 'ping', $downSince ?: $now, $downTs ?: $nowTs]);
                tg_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'down', $now, $nowTs);
                try { np_sms_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'down', $now, $nowTs); }
                catch (Throwable $e) { error_log('NetPulse SMS enqueue: '.$e->getMessage()); }
            }
            if ($doUp) {
                $tr['up']++;
                $evt->execute([$now, $d['id'], $d['name'], $ip, 'up', "Zariadenie: {$d['name']} IP:$ip; je funkčné"]);
                $openOut->execute([$d['id']]); $open = $openOut->fetchAll(); $openOut->closeCursor();
                foreach ($open as $o) {
                    $st = np_epoch($o['started_ts'], $o['started']);
                    $closeOut->execute([$now, $st ? max(0, $nowTs - $st) : null, $o['id']]);
                }
                tg_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'up', $now, $nowTs);
                try { np_sms_enqueue($pdo, (int)$d['id'], (string)$d['name'], $ip, 'up', $now, $nowTs); }
                catch (Throwable $e) { error_log('NetPulse SMS enqueue: '.$e->getMessage()); }
            }
        }
        foreach ($svcRows as $sv) {
            if (!isset($devById[$sv['device_id']]) || !array_key_exists($sv['id'], $svcUp)) continue;
            if (np_is_olt_type((string)($devById[$sv['device_id']]['type_name'] ?? ''))) continue;
            $updSvc->execute([$svcUp[$sv['id']] ? 'up' : 'down', $now, $sv['id']]);
        }
    });
} catch (Throwable $e) {
    error_log('NetPulse monitor – zápis zlyhal: '.$e->getMessage()
              . (np_schema_error($e) ? ' (neúplná schéma – migrácia sa zopakuje)' : ''));
}

// ======================================================================
// Automatic SNMP port discovery, status events and traffic history.
try {
    require_once __DIR__ . '/port_monitor_lib.php';
    $portResult = np_port_poll($pdo);
} catch (Throwable $e) {
    error_log('NetPulse port monitor: ' . $e->getMessage());
}
try { op_history_schema($pdo); }
catch (Throwable $e) { error_log('NetPulse optical schema: ' . $e->getMessage()); }

// 3) DORUČENIE Telegram správ z fronty
// ======================================================================
$tg = [0, 0, 0];
try { $tg = tg_flush($pdo); } catch (Throwable $e) { error_log('NetPulse Telegram: '.$e->getMessage()); }
try { $sms = np_sms_flush($pdo, 3); } catch (Throwable $e) { error_log('NetPulse SMS flush: '.$e->getMessage()); }

// ======================================================================
// 4) UPRATOVANIE – každých 10 min, s časovým stropom (nesmie brzdiť monitoring)
// ======================================================================
try {
    if ($nowTs - (int) setting_get('last_purge_hist', '0') >= 600) {
        setting_set('last_purge_hist', (string)$nowTs);
        np_purge($pdo, 'status_history', date('Y-m-d H:i:s', $nowTs - $histDays * 86400), 3.0);
        np_purge($pdo, 'events',         date('Y-m-d H:i:s', $nowTs - 365 * 86400), 1.0);
        try {
            $trafficDays = max(1, (int)setting_get('traffic_days', (string)(cfg('TRAFFIC_DAYS') ?: 90)));
            $opticalDays = max(1, (int)(cfg('OPTICAL_HISTORY_DAYS') ?: 30));
            np_graph_retention_cleanup($pdo, 2.0, $trafficDays, $opticalDays);
        } catch (Throwable $e) {
            error_log('NetPulse graph retention: ' . $e->getMessage());
        }
        $sdir = __DIR__ . '/data/sessions';            // PHP vo vlastnom priečinku sessions sám nemaže
        if (is_dir($sdir)) {
            $cutS = $nowTs - 31 * 86400; $stop = microtime(true) + 1.0;
            foreach ((glob($sdir . '/sess_*') ?: []) as $sf) {
                if (@filemtime($sf) < $cutS) @unlink($sf);
                if (microtime(true) > $stop) break;
            }
        }
    }
} catch (Throwable $e) { error_log('NetPulse upratovanie: '.$e->getMessage()); }

// ---- súhrn do logu ----
$m  = $useFping ? 'fping+tcp' : ($useIcmp ? 'ping+tcp' : 'tcp');
$jm = db_journal_mode();
fwrite(STDERR, sprintf('%d zariadení skontrolovaných (%s) @ %s  [%.1f s]', count($devs), $m, $now, microtime(true) - $t0Cycle)
    . ($written ? '' : '  !! výsledky NEZAPÍSANÉ (DB zamknutá) – zopakuje sa v ďalšom cykle')
    . ($tr['down'] || $tr['up'] ? "  výpadky: +{$tr['down']} / návraty: +{$tr['up']}" : '')
    . ($tg[0] || $tg[1] ? "  Telegram: odoslané {$tg[0]}" . ($tg[1] ? ", zlyhalo – čaká {$tg[2]}" : '') : '')
    . ($bad ? "  [$bad zariadení s neplatnou IP/DNS]" : '')
    . ($svcOnly ? "  [$svcOnly len cez služby/porty]" : '')
    . (($jm !== 'wal' && !np_is_mysql()) ? "  !! journal_mode=$jm (nie WAL – hrozia zámky)" : '')
    . "\n");
