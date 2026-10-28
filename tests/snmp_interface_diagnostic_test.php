<?php
declare(strict_types=1);

require_once __DIR__ . '/../snmp_lib.php';

function snmp_diag_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$categories = [
    [['Timeout: No Response from host'], 1, false, 'timeout'],
    [['Authentication failure (incorrect password?)'], 1, false, 'authentication_failure'],
    [['No Such Object available on this agent at this OID'], 1, false, 'unsupported_oid'],
    [['No more variables left in this MIB View'], 0, false, 'empty_table'],
    [[], 0, false, 'empty_table'],
    [[], 1, false, 'empty_response'],
    [['not an OID response'], 1, false, 'malformed_response'],
    [['/bin/sh: snmpwalk: Permission denied'], 126, false, 'backend_unavailable'],
    [['Error in packet. Reason: authorizationError'], 1, false, 'authentication_failure'],
    [['Error in packet. Reason: genError'], 1, false, 'other_error'],
];
foreach ($categories as [$output, $exitCode, $hasValues, $expected]) {
    snmp_diag_assert(
        snmp_result_status($output, $exitCode, $hasValues) === $expected,
        "Expected SNMP result category $expected."
    );
}

$ifNameOid = '1.3.6.1.2.1.31.1.1.1.1';
$ifDescrOid = '1.3.6.1.2.1.2.2.1.2';
$called = [];
$fallback = snmp_interface_discovery_result(
    static function (string $oid) use (&$called, $ifNameOid): array {
        $called[] = $oid;
        if ($oid === $ifNameOid) {
            return ['status'=>'timeout','values'=>[],'types'=>[]];
        }
        return [
            'status'=>'success',
            'values'=>[7=>'ether1'],
            'types'=>[7=>'string'],
        ];
    }
);
snmp_diag_assert($called === [$ifNameOid,$ifDescrOid], 'ifDescr fallback was not attempted after ifName failed.');
snmp_diag_assert(
    $fallback['status'] === 'success' && $fallback['source'] === 'ifDescr'
        && $fallback['values'] === [7=>'ether1'],
    'Successful ifDescr fallback did not preserve the interface list.'
);

$failed = snmp_interface_discovery_result(
    static fn(string $oid): array => $oid === $ifNameOid
        ? ['status'=>'unsupported_oid','values'=>[]]
        : ['status'=>'timeout','values'=>[]]
);
snmp_diag_assert(
    $failed['attempts'] === ['ifName'=>'unsupported_oid','ifDescr'=>'timeout'],
    'Per-OID sanitized failure categories were not retained.'
);
$message = snmp_interface_diagnostic_message($failed['attempts']);
snmp_diag_assert(
    str_contains($message, 'interface OID unsupported')
        && str_contains($message, 'timeout or no response'),
    'Diagnostic message did not explain both safe failure categories.'
);
snmp_diag_assert(
    !preg_match('/community|password|secret|\/home\/|\/var\//i', $message),
    'Diagnostic message contains a secret or internal path.'
);

echo "SNMP interface discovery diagnostic tests passed.\n";
