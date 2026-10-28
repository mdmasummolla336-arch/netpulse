<?php
declare(strict_types=1);

define('NP_SKIP_INIT_TZ', true);
require_once __DIR__ . '/../optical_poll.php';

function optical_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$device = [
    'version'=>2,
    'port'=>1161,
    'community'=>'optical-community',
    'sec_name'=>'optical-user',
    'auth_pass'=>'auth-secret',
    'priv_pass'=>'priv-secret',
    'auth_proto'=>'SHA',
    'priv_proto'=>'AES'
];
$profile = op_profile($device);

optical_test_assert($profile['port'] === 1161, 'Optical SNMP profile dropped the configured port.');
optical_test_assert(
    snmp_agent('192.0.2.10', $profile) === 'udp:192.0.2.10:1161',
    'Optical SNMP target did not use the configured non-default port.'
);

foreach (['version','community','sec_name','auth_pass','priv_pass','auth_proto','priv_proto'] as $key) {
    optical_test_assert(($profile[$key] ?? null) === $device[$key], "Optical SNMP profile changed $key.");
}

$defaultPortProfile = op_profile([
    'version'=>0,
    'port'=>161,
    'community'=>'public',
    'sec_name'=>'',
    'auth_pass'=>'',
    'priv_pass'=>'',
    'auth_proto'=>'MD5',
    'priv_proto'=>'DES'
]);
optical_test_assert(
    snmp_agent('192.0.2.10', $defaultPortProfile) === 'udp:192.0.2.10:161',
    'Optical SNMP profile did not preserve the default SNMP port.'
);

foreach ([
    0=>'-v1 -c',
    1=>'-v2c -c',
    2=>'-v3 -l authPriv'
] as $version=>$expectedArgs) {
    $versionProfile = op_profile(array_replace($device, ['version'=>$version]));
    optical_test_assert(
        str_contains(snmp_authargs($versionProfile), $expectedArgs),
        "Optical SNMP profile changed authentication behavior for version $version."
    );
    optical_test_assert(
        snmp_agent('192.0.2.10', $versionProfile) === 'udp:192.0.2.10:1161',
        "Optical SNMP version $version did not use the configured non-default port."
    );
}

$bdcomRows = op_collect_bdcom_data(
    ['id'=>9, 'name'=>'BDCOM test switch', 'ip'=>'192.0.2.20'],
    [17=>'GigaEthernet0/1', 18=>'GigaEthernet0/2', 19=>'GigaEthernet0/3'],
    [1=>[17=>'-2350', 18=>'-65535'], 2=>[19=>'-1636']],
    [1=>[17=>'120', 18=>'-65535'], 2=>[19=>'250']]
);
optical_test_assert(
    OP_BDCOM_SFP_BASE === '1.3.6.1.4.1.3320.9.63.1.7.1'
        && OP_BDCOM_RX_COLUMNS === [1=>3, 2=>9, 3=>12, 4=>15]
        && OP_BDCOM_TX_COLUMNS === [1=>2, 2=>8, 3=>11, 4=>14],
    'BDCOM SFP table OIDs do not match the switch MIB.'
);
optical_test_assert(count($bdcomRows) === 4, 'BDCOM optical parser returned unexpected readings.');
optical_test_assert(
    $bdcomRows[0]['ifname'] === 'GigaEthernet0/1'
        && $bdcomRows[0]['direction'] === 'rx'
        && $bdcomRows[0]['dbm'] === -23.5
        && $bdcomRows[0]['precision'] === 2
        && $bdcomRows[0]['sensor_status'] === null,
    'BDCOM receive-power reading was not parsed correctly.'
);
optical_test_assert(
    $bdcomRows[1]['direction'] === 'tx' && $bdcomRows[1]['dbm'] === 1.2,
    'BDCOM transmit-power reading or hundredths-of-dBm scaling is incorrect.'
);
optical_test_assert(
    $bdcomRows[2]['ifname'] === 'GigaEthernet0/3 (lane 2)'
        && $bdcomRows[2]['direction'] === 'rx'
        && $bdcomRows[2]['dbm'] === -16.36,
    'BDCOM multi-lane receive-power reading was not parsed correctly.'
);
optical_test_assert(
    $bdcomRows[3]['ifname'] === 'GigaEthernet0/3 (lane 2)'
        && $bdcomRows[3]['direction'] === 'tx'
        && $bdcomRows[3]['dbm'] === 2.5,
    'BDCOM multi-lane transmit-power reading was not parsed correctly.'
);

$vsolRows = op_collect_vsol_data(
    ['id'=>10, 'name'=>'VSOL test OLT', 'ip'=>'192.0.2.30'],
    [1=>'GE0/1', 2=>'GE0/2', 3=>'GE0/3', 4=>'GE0/4'],
    [1=>'biplop', 2=>'DAK-KABOL', 3=>'UPLINK-1', 4=>'naieem-tora'],
    [1=>'-5.086 dbm', 2=>'-2.534 dbm', 3=>'-1.953 dbm', 4=>'2.853 dbm'],
    [1=>'-12.503 dbm', 2=>'-4.891 dbm', 3=>'-10.339 dbm', 4=>'-40.000 dbm']
);
optical_test_assert(
    OP_VSOL_IFNAME === '1.3.6.1.4.1.37950.1.1.5.10.1.1.1.1.2'
        && OP_VSOL_IFALIAS === '1.3.6.1.4.1.37950.1.1.5.10.1.1.1.1.20'
        && OP_VSOL_TX === '1.3.6.1.4.1.37950.1.1.5.10.1.1.5.1.5'
        && OP_VSOL_RX === '1.3.6.1.4.1.37950.1.1.5.10.1.1.5.1.6'
        && OP_VSOL_MODEL === '1.3.6.1.4.1.37950.1.1.5.10.12.5.15.0',
    'VSOL optical OIDs do not match the supplied V1600D4-DP SNMP walk.'
);
optical_test_assert(count($vsolRows) === 8, 'VSOL optical parser returned unexpected readings.');
optical_test_assert(
    $vsolRows[0]['ifname'] === 'GE0/1'
        && $vsolRows[0]['direction'] === 'tx'
        && $vsolRows[0]['description'] === 'biplop'
        && $vsolRows[0]['dbm'] === -5.086
        && $vsolRows[0]['precision'] === 3,
    'VSOL transmit-power reading was not parsed correctly.'
);
optical_test_assert(
    $vsolRows[1]['ifname'] === 'GE0/1'
        && $vsolRows[1]['direction'] === 'rx'
        && $vsolRows[1]['dbm'] === -12.503,
    'VSOL receive-power reading or dBm parsing is incorrect.'
);
optical_test_assert(
    array_map(
        static fn(array $row): array => [$row['ifname'], $row['description']],
        array_values(array_filter($vsolRows, static fn(array $row): bool => $row['direction'] === 'tx'))
    ) === [
        ['GE0/1', 'biplop'],
        ['GE0/2', 'DAK-KABOL'],
        ['GE0/3', 'UPLINK-1'],
        ['GE0/4', 'naieem-tora']
    ],
    'VSOL GE interface descriptions do not match the supplied SNMP walk.'
);
optical_test_assert(
    !array_filter($vsolRows, static fn(array $row): bool => str_starts_with($row['ifname'], 'PON')),
    'VSOL optical parser included a PON interface.'
);
optical_test_assert(op_vsol_dbm(' 2.853 dBm ') === 2.853, 'VSOL parser rejected a valid dBm reading.');
optical_test_assert(op_vsol_dbm('-40.000 dBm') === -40.0, 'VSOL parser rejected a low optical reading.');
optical_test_assert(op_vsol_dbm('unknown') === null, 'VSOL parser accepted a non-numeric optical reading.');

$oltAllowlist = np_olt_default_interfaces('OLT', '172.29.1.102', 'vsol');
optical_test_assert(
    $oltAllowlist === ['GE0/1', 'GE0/2', 'GE0/3', 'GE0/4'],
    'Known VSOL OLT did not receive the initial GE interface allowlist.'
);
optical_test_assert(
    np_olt_default_interfaces('Router', '172.29.1.102', 'vsol') === []
        && np_olt_default_interfaces('OLT', '192.0.2.80', 'other') === [],
    'OLT default allowlist was applied without the OLT type and known device identity.'
);

$oltRows = array_merge($vsolRows, [
    ['ifname'=>'VLAN100', 'direction'=>'rx'],
    ['ifname'=>'Management VLAN', 'direction'=>'rx'],
    ['ifname'=>'ONU:1', 'direction'=>'rx'],
    ['ifname'=>'EPON0/1', 'direction'=>'rx'],
    ['ifname'=>'PON1', 'direction'=>'rx'],
    ['ifname'=>'GE0/5', 'direction'=>'rx']
]);
$visibleOltRows = np_filter_olt_interfaces('OLT', $oltRows, $oltAllowlist);
optical_test_assert(
    count($visibleOltRows) === 8 &&
        !array_filter($visibleOltRows, static fn(array $row): bool => !in_array($row['ifname'], $oltAllowlist, true)),
    'OLT optical filtering did not limit readings to the configured GE interface list.'
);
optical_test_assert(
    np_filter_olt_interfaces('OLT', [
        ['ifname'=>'XG1/1'],
        ['ifname'=>'VLAN100']
    ], ['XG1/1']) === [['ifname'=>'XG1/1']],
    'A different OLT model did not honor its own configured interface allowlist.'
);
optical_test_assert(
    count(np_filter_olt_interfaces('Router', $oltRows, [])) === count($oltRows),
    'OLT interface filtering changed non-OLT behavior.'
);
optical_test_assert(
    np_olt_interface_is_excluded('VLAN100')
        && np_olt_interface_is_excluded('Management VLAN')
        && np_olt_interface_is_excluded('ONU:1')
        && np_olt_interface_is_excluded('EPON0/1')
        && np_olt_interface_is_excluded('PON1')
        && np_olt_interface_is_excluded('Vlanif100')
        && np_olt_interface_is_excluded('GPON0/1')
        && np_olt_interface_is_excluded('MgmtVlan1'),
    'An OLT VLAN, management VLAN, ONU, EPON, or PON interface escaped exclusion.'
);
optical_test_assert(
    count(np_filter_olt_interfaces('OLT', $visibleOltRows, $oltAllowlist)) === count($visibleOltRows),
    'Repeated OLT discovery filtering changed the allowed interface set.'
);
optical_test_assert(
    np_parse_olt_interfaces("XG1/1\nXG1/2, XG1/3") === ['XG1/1','XG1/2','XG1/3'],
    'Per-device OLT allowlists did not parse different interface names.'
);
optical_test_assert(
    np_port_alias_display('GE0/4 naieem-tora', 'GE0/4', 'naieem-tora', 'OLT') === ''
        && np_port_alias_display('biplop', 'GE0/1', 'biplop', 'OLT') === ''
        && np_port_alias_display('real alias', 'GE0/1', 'biplop', 'OLT') === 'real alias'
        && np_port_alias_display('GE0/1', 'GE0/1', 'biplop', 'OLT') === 'GE0/1'
        && np_port_alias_display('same', 'GE0/1', 'same', 'Router') === 'same',
    'Interface alias display duplicated descriptions or altered non-OLT aliases.'
);
$legacyPortLabels = np_normalize_olt_port_labels(
    ['ifname'=>'GE0/1 biplop', 'alias'=>'GE0/1 biplop', 'description'=>''],
    'OLT',
    ['GE0/1']
);
optical_test_assert(
    $legacyPortLabels['ifname'] === 'GE0/1'
        && $legacyPortLabels['description'] === 'biplop'
        && $legacyPortLabels['alias'] === 'GE0/1 biplop'
        && np_port_alias_display(
            $legacyPortLabels['alias'], $legacyPortLabels['ifname'],
            $legacyPortLabels['description'], 'OLT'
        ) === '',
    'Existing compound OLT labels were not separated for presentation without overwriting alias data.'
);

echo "Optical SNMP profile, BDCOM, VSOL, and OLT policy tests passed.\n";
