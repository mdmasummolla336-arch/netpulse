<?php
declare(strict_types=1);

require_once __DIR__ . '/../graph_retention_lib.php';

function graph_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

foreach ([
    'hour'=>3600,
    'day'=>86400,
    'week'=>604800,
    'month'=>2592000,
    'year'=>31536000,
    'unknown'=>86400
] as $range=>$seconds) {
    graph_test_assert(np_graph_range_seconds($range)===$seconds, "Incorrect graph range: $range");
}

$sources=[
    ['id'=>'21','name'=>'Core ↔ Edge','device_name'=>'Core-SW','ip'=>'10.0.0.1','ifname'=>'xe-0/0/0','alias'=>'uplink'],
    ['id'=>'p:8:4','name'=>'Access-SW / ether4','device_name'=>'Access-SW','ip'=>'10.0.0.8','ifname'=>'ether4','alias'=>'office']
];
$visible=np_graph_filter_sources_for_role($sources,'user',['p:8:4']);
graph_test_assert(count($visible)===1 && $visible[0]['id']==='p:8:4', 'User graph permission filter leaked or hid a source.');
graph_test_assert(count(np_graph_filter_sources_for_role($sources,'admin',[]))===2, 'Admin should see every graph source.');
graph_test_assert(count(np_graph_filter_sources_for_role($sources,'administrator',[]))===2, 'Administrator should see every graph source.');

foreach ([
    ['ifindex'=>1,'ifname'=>'GE0/1','description'=>'biplop','iftype'=>6],
    ['ifindex'=>2,'ifname'=>'GigabitEthernet0/2','description'=>'uplink','iftype'=>6],
    ['ifindex'=>3,'ifname'=>'GE0/3','description'=>'EPON ONU','iftype'=>6],
    ['ifindex'=>4,'ifname'=>'GE0/4','description'=>'access','iftype'=>161],
    ['ifindex'=>5,'ifname'=>'GE0/9','description'=>'uplink','iftype'=>6],
    ['ifindex'=>0,'ifname'=>'GE0/1','description'=>'uplink','iftype'=>6],
    ['ifindex'=>6,'ifname'=>'Ethernet6','description'=>'GE0/6','iftype'=>6],
    ['ifindex'=>7,'ifname'=>'GE0/7','description'=>'GE0/8','iftype'=>6]
] as $candidate) {
    graph_test_assert(
        np_olt_graph_port_number($candidate) === (in_array($candidate['ifindex'], [1,2], true) ? $candidate['ifindex'] : null),
        'OLT Graph accepted a non-GE, non-Ethernet, out-of-range, conflicting, or invalid-ifIndex interface.'
    );
}
graph_test_assert(
    np_olt_graph_counter_available(['in_oct'=>0,'out_oct'=>0,'counter_ts'=>990],1000)
        && !np_olt_graph_counter_available(['in_oct'=>null,'out_oct'=>10,'counter_ts'=>990],1000)
        && !np_olt_graph_counter_available(['in_oct'=>10,'out_oct'=>10,'counter_ts'=>0],1000)
        && !np_olt_graph_counter_available(['in_oct'=>10,'out_oct'=>10,'counter_ts'=>1],1000)
        && !np_olt_graph_counter_available(['in_oct'=>10,'out_oct'=>10,'counter_ts'=>1001],1000),
    'OLT Graph counter availability validation accepted missing counters.'
);
graph_test_assert(
    np_olt_port_is_graph_candidate([
        'ifindex'=>8,'ifname'=>'GE0/8','description'=>'LAN-8','alias'=>'','iftype'=>6
    ]) && !np_olt_port_is_graph_candidate([
        'ifindex'=>9,'ifname'=>'EPON ONU 1','description'=>'','alias'=>'','iftype'=>6
    ]),
    'OLT GE1-8 discovery eligibility included an ONU or missed GE8 outside the old allowlist.'
);

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE graph_favorites(source_id TEXT PRIMARY KEY,created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE user_graph_permissions(user_id INTEGER NOT NULL,source_id TEXT NOT NULL,PRIMARY KEY(user_id,source_id))');
$pdo->exec('CREATE TABLE device_types(id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec("INSERT INTO device_types VALUES(1,'Router'),(2,'OLT')");
$pdo->exec('CREATE TABLE devices(id INTEGER PRIMARY KEY,name TEXT,ip TEXT,type_id INTEGER)');
$pdo->exec('CREATE TABLE map_nodes(id INTEGER PRIMARY KEY,device_id INTEGER)');
$pdo->exec('CREATE TABLE map_links(id INTEGER PRIMARY KEY,snmp_type INTEGER,snmp_ifindex INTEGER,snmp_device INTEGER,from_node INTEGER,to_node INTEGER)');
$pdo->exec('CREATE TABLE port_inventory(device_id INTEGER,ifindex INTEGER,ifname TEXT,alias TEXT,description TEXT,iftype INTEGER)');
$pdo->exec('CREATE TABLE port_counters(device_id INTEGER,ifindex INTEGER,in_oct REAL,out_oct REAL,ts_unix INTEGER,PRIMARY KEY(device_id,ifindex))');
$pdo->exec("INSERT INTO devices VALUES(1,'Core-SW','10.20.0.1',1),(8,'Access-SW','10.20.0.8',1),(9,'MX80','10.255.240.9',1),(100,'OLT-test','10.20.0.100',2)");
$pdo->exec('INSERT INTO map_nodes VALUES(1,1),(2,8),(3,9)');
$pdo->exec('INSERT INTO map_links VALUES(21,1,3,1,1,2),(22,1,2,9,3,2),(23,1,1,100,1,2),(24,1,9,100,1,2)');
$pdo->exec("INSERT INTO port_inventory VALUES
    (1,3,'xe-0/0/0','WAN uplink','xe-0/0/0',6),
    (8,4,'ether4','Office floor','ether4',6),
    (9,2,'ge-0/0/0','Uplink','ge-0/0/0',6),
    (9,3,'ge-0/0/1','LAN','ge-0/0/1',6)");
$freshCounterTimestamp=time()-60;
for ($index=1; $index<=8; $index++) {
    $pdo->prepare('INSERT INTO port_inventory VALUES(100,?,?,?,?,6)')
        ->execute([$index, "GE0/$index", "GE0/$index", "GE0/$index"]);
    $pdo->prepare('INSERT INTO port_counters VALUES(100,?,?,?,?)')
        ->execute([$index, 1000+$index, 2000+$index, $freshCounterTimestamp]);
}
$pdo->exec("INSERT INTO port_inventory VALUES
    (100,9,'EPON ONU 1','ONU RX','EPON ONU 1',6),
    (100,10,'GE0/9','extra port','GE0/9',6),
    (100,11,'GE0/10','not ethernet','GE0/10',161),
    (100,12,'Ethernet12','GE0/8','Ethernet12',6),
    (100,13,'GE0/7','GE0/8','GE0/7',6),
    (100,14,'GE0/6','no counters','GE0/6',6),
    (100,15,'VLAN100','management vlan','VLAN100',6),
    (100,16,'GPON ONU RX','optical signal','GPON ONU RX',6),
    (100,17,'PON1','PON optical module','PON1',6)");
$staleCounterStatement=$pdo->prepare('INSERT INTO port_counters VALUES(100,?,?,?,?)');
foreach ([9,10,11,12] as $index) {
    $staleCounterStatement->execute([$index,1,2,$freshCounterTimestamp]);
}
np_graph_interface_selections_schema($pdo);
graph_test_assert(np_graph_set_favorite($pdo,'21',true), 'Map-link favorite was rejected.');
graph_test_assert(np_graph_set_favorite($pdo,'p:8:4',true), 'Discovered-interface favorite was rejected.');
graph_test_assert((int)$pdo->query('SELECT COUNT(*) FROM graph_favorites')->fetchColumn()===2, 'Favorites were not persisted.');
graph_test_assert(!np_graph_set_favorite($pdo,'99',true), 'Invalid graph source was favorited.');
graph_test_assert(!np_graph_set_favorite($pdo,'p:100:9',true), 'OLT ONU graph was favorited.');
graph_test_assert(!np_graph_set_favorite($pdo,'23',true), 'OLT map-link duplicate graph was favorited.');
graph_test_assert(np_graph_set_favorite($pdo,'21',false), 'Map-link favorite could not be removed.');
graph_test_assert((int)$pdo->query("SELECT COUNT(*) FROM graph_favorites WHERE source_id='21'")->fetchColumn()===0, 'Favorite removal did not persist.');
$generated=np_graph_sources($pdo,'admin',[],7);
graph_test_assert(count($generated)===6, 'Non-OLT graph sources changed or an OLT interface graph was exposed.');
 $oltSources=array_values(array_filter(
    $generated,
    static fn(array $source): bool=>(int)$source['device_id']===100
));
graph_test_assert(
    count($oltSources)===0,
    'OLT interface sources were exposed in the generic graph list.'
);
graph_test_assert(
    !array_filter(
        $generated,
        static fn(array $source): bool=>in_array(
            $source['id'],
            ['23','24','p:100:9','p:100:15','p:100:16','p:100:17'],
            true
        ) || str_contains(strtolower($source['name']),'onu')
    ),
    'OLT legacy map-link, VLAN, ONU, GPON, or PON source leaked into the graph-list response.'
);
$sourceResponse=json_decode(json_encode($generated,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
graph_test_assert(
    count($sourceResponse)===6 && isset($sourceResponse[0]['id'],$sourceResponse[0]['name'],$sourceResponse[0]['sub']),
    'Graph source API response lost the fields consumed by the frontend.'
);
graph_test_assert(
    np_graph_olt_interface_is_available($pdo,100,1) === false
        && np_graph_olt_interface_is_available($pdo,100,9) === false
    && np_graph_olt_interface_is_available($pdo,100,14) === false
    && np_graph_olt_interface_is_available($pdo,100,13) === false
        && np_graph_olt_interface_is_available($pdo,8,4) === null,
    'OLT graph data was not blocked or non-OLT graph compatibility changed.'
);
$linkSource=array_values(array_filter($generated,static fn(array $source): bool=>$source['id']==='21'))[0]??null;
graph_test_assert($linkSource!==null, 'SNMP map-link source was not generated.');
graph_test_assert(str_contains($linkSource['sub'],'10.20.0.1') && str_contains($linkSource['interface_name'],'WAN uplink'), 'Map-link source is missing its IP or interface alias.');
graph_test_assert($linkSource['name']==='Core-SW ↔ Access-SW', 'Map-link label lost its endpoint identity.');
$userSources=np_graph_sources($pdo,'user',['p:8:4']);
graph_test_assert(count($userSources)===1 && $userSources[0]['id']==='p:8:4', 'Graph source API helper did not enforce user permissions.');
graph_test_assert(str_contains($userSources[0]['sub'],'10.20.0.8') && str_contains($userSources[0]['interface_name'],'Office floor'), 'Discovered source is missing IP or alias metadata.');
$favoriteSources=np_graph_sources($pdo,'admin');
$favoritePort=array_values(array_filter($favoriteSources,static fn(array $source): bool=>$source['id']==='p:8:4'))[0]??null;
graph_test_assert(($favoritePort['favorite']??false)===true, 'Favorite status was not included in generated graph sources.');
$mx80Sources=array_values(array_filter($generated,static fn(array $source): bool=>!empty($source['mx80_interface'])));
graph_test_assert(count($mx80Sources)===3, 'MX80 map-link and discovered-interface sources were not marked for persistent selection.');
graph_test_assert(count(array_unique(array_map(static fn(array $source): string=>$source['mx80_selection_id'],$mx80Sources)))===2, 'MX80 interface sources were not grouped by interface.');
graph_test_assert(!array_filter($mx80Sources,static fn(array $source): bool=>$source['selected']), 'MX80 interfaces must not be selected by default.');
np_graph_mx80_save_selection($pdo,7,['p:9:2','p:9:3']);
np_graph_mx80_save_selection($pdo,7,['p:9:2'],['p:9:2']);
$savedSources=np_graph_sources($pdo,'user',['p:9:2'],7);
$savedMx80=array_values(array_filter($savedSources,static fn(array $source): bool=>!empty($source['mx80_interface'])));
graph_test_assert(array_values(array_filter($savedMx80,static fn(array $source): bool=>$source['selected']))[0]['id']==='p:9:2', 'MX80 checkbox selection did not persist for the user.');
graph_test_assert(count(array_filter($savedMx80,static fn(array $source): bool=>$source['selected']))===1, 'Unselected MX80 interfaces were also enabled.');
graph_test_assert(isset(np_graph_mx80_selected_ids($pdo,7)['p:9:3']), 'Updating visible selections removed a graph hidden by user permissions.');
$allSavedSources=np_graph_sources($pdo,'admin',[],7);
$selectedSourceIds=array_column(array_filter($allSavedSources,static fn(array $source): bool=>!empty($source['selected'])),'id');
graph_test_assert(in_array('22',$selectedSourceIds,true)&&in_array('p:9:2',$selectedSourceIds,true), 'One saved interface selection did not apply consistently to its graph sources.');
graph_test_assert(np_graph_mx80_selected_ids($pdo,8)===[], 'MX80 selection leaked between users.');
try {
    np_graph_mx80_save_selection($pdo,7,['p:8:4'],['p:8:4']);
    graph_test_assert(false, 'A non-MX80 graph source was accepted as an MX80 selection.');
} catch (InvalidArgumentException $e) {
}
try {
    np_graph_mx80_save_selection($pdo,7,['p:9:3'],['p:9:2']);
    graph_test_assert(false, 'A graph outside the user permission list was accepted.');
} catch (InvalidArgumentException $e) {
}
$persistedSelection=np_graph_mx80_selected_ids($pdo,7);
graph_test_assert(count($persistedSelection)===2&&isset($persistedSelection['p:9:2'],$persistedSelection['p:9:3']), 'Invalid selection changed the saved interface selection.');
np_graph_mx80_save_selection($pdo,7,['p:9:2'],['22']);
$linkPermittedSources=np_graph_sources($pdo,'user',['22'],7);
$linkPermittedMx80=array_values(array_filter($linkPermittedSources,static fn(array $source): bool=>!empty($source['mx80_interface'])));
graph_test_assert(count($linkPermittedMx80)===1&&$linkPermittedMx80[0]['selected'], 'A permitted map-link graph did not restore its interface selection.');
np_graph_mx80_save_selection($pdo,7,[],['22']);
graph_test_assert(!isset(np_graph_mx80_selected_ids($pdo,7)['p:9:2'])&&isset(np_graph_mx80_selected_ids($pdo,7)['p:9:3']), 'Clearing a visible interface did not preserve only the permission-hidden selection.');

$now=2000000000;
$rows=[];
for($i=1;$i<=240;$i++){
    $rows[]=[
        'ts'=>gmdate('Y-m-d H:i:s',$now-3599+(($i-1)*14)),
        'rx_bps'=>(string)$i,
        'tx_bps'=>(string)($i*2)
    ];
}
$response=np_graph_traffic_response($rows,np_graph_range_seconds('hour'),$now);
graph_test_assert(count($response['points'])<=120, 'Graph response exceeded the 120-bucket limit.');
graph_test_assert(abs($response['points'][0]['rx']-2.0)<0.001, 'Traffic samples in a bucket were not averaged.');
graph_test_assert(abs($response['summary']['current']['rx']-240)<0.001, 'Latest Rx summary is incorrect.');
graph_test_assert(abs($response['summary']['average']['rx']-120.5)<0.001, 'Average Rx summary is incorrect.');
graph_test_assert(abs($response['summary']['maximum']['tx']-480)<0.001, 'Maximum Tx summary is incorrect.');

$empty=np_graph_traffic_response([],np_graph_range_seconds('year'),$now);
graph_test_assert($empty['points']===[] && $empty['summary']===null, 'Empty history should return no fake data and no summary.');

echo "Graph sources, permissions, favorites, MX80 selections, ranges, summaries, bucket limit, and empty-history tests passed.\n";
