<?php
/** SNMP helpers using net-snmp CLI or the PHP SNMP extension. */
function snmp_cli(): bool {
    static $available = null;
    if ($available !== null) return $available;
    if (!function_exists('exec')) return $available = false;
    @exec('command -v snmpget 2>/dev/null', $output, $status);
    return $available = ($status === 0 && !empty($output));
}

function snmpwalk_cli(): bool {
    static $available = null;
    if ($available !== null) return $available;
    if (!function_exists('exec')) return $available = false;
    @exec('command -v snmpwalk 2>/dev/null', $output, $status);
    return $available = ($status === 0 && !empty($output));
}

function snmp_auth_proto($value): string {
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['MD5','SHA','SHA-224','SHA-256','SHA-384','SHA-512'], true)
        ? $value : 'MD5';
}

function snmp_priv_proto($value): string {
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['DES','AES','AES-192','AES-256'], true)
        ? $value : 'DES';
}

function snmp_profile_port(array $profile): int {
    $port = filter_var($profile['port'] ?? 161, FILTER_VALIDATE_INT);
    return $port !== false && $port >= 1 && $port <= 65535 ? $port : 161;
}

/** Build an explicit UDP agent target, including IPv6 and the profile's port. */
function snmp_agent(string $host, array $profile): string {
    $port = snmp_profile_port($profile);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return "udp6:[$host]:$port";
    }
    return "udp:$host:$port";
}

/** Build net-snmp authentication arguments from a validated profile. */
function snmp_authargs(array $profile): string {
    $version = (int)($profile['version'] ?? 0);
    if ($version === 2) {
        $user = escapeshellarg((string)($profile['sec_name'] ?? ''));
        $authPass = (string)($profile['auth_pass'] ?? '');
        $privPass = (string)($profile['priv_pass'] ?? '');
        $authProto = escapeshellarg(snmp_auth_proto($profile['auth_proto'] ?? 'MD5'));
        $privProto = escapeshellarg(snmp_priv_proto($profile['priv_proto'] ?? 'DES'));
        if ($authPass !== '' && $privPass !== '') {
            return "-v3 -l authPriv -u $user -a $authProto -A " . escapeshellarg($authPass)
                . " -x $privProto -X " . escapeshellarg($privPass);
        }
        if ($authPass !== '') {
            return "-v3 -l authNoPriv -u $user -a $authProto -A " . escapeshellarg($authPass);
        }
        return "-v3 -l noAuthNoPriv -u $user";
    }
    $community = escapeshellarg((string)($profile['community'] ?? 'public'));
    return ($version === 1 ? '-v2c' : '-v1') . " -c $community";
}

/** Call the PHP extension for a single OID, including SNMPv3 security levels. */
function snmp_extension_get(string $agent, array $profile, string $oid) {
    @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
    $version = (int)($profile['version'] ?? 0);
    if ($version === 2) {
        if (!function_exists('snmp3_get')) return false;
        $authPass = (string)($profile['auth_pass'] ?? '');
        $privPass = (string)($profile['priv_pass'] ?? '');
        $level = $authPass === '' ? 'noAuthNoPriv' : ($privPass === '' ? 'authNoPriv' : 'authPriv');
        return @snmp3_get(
            $agent,
            (string)($profile['sec_name'] ?? ''),
            $level,
            snmp_auth_proto($profile['auth_proto'] ?? 'MD5'),
            $authPass,
            snmp_priv_proto($profile['priv_proto'] ?? 'DES'),
            $privPass,
            $oid,
            1000000,
            1
        );
    }
    $community = (string)($profile['community'] ?? 'public');
    return $version === 1
        ? @snmp2_get($agent, $community, $oid, 1000000, 1)
        : @snmpget($agent, $community, $oid, 1000000, 1);
}

/** SNMP GET multiple numeric OIDs. Returns one numeric value or null per OID. */
function snmp_get(string $ip, array $profile, array $oids): array {
    $agent = snmp_agent($ip, $profile);
    if (snmp_cli()) {
        $command = 'snmpget ' . snmp_authargs($profile) . ' -Ovq -Ot -t 1 -r 1 '
            . escapeshellarg($agent) . ' ' . implode(' ', array_map('escapeshellarg', $oids))
            . ' 2>/dev/null';
        $output = [];
        @exec($command, $output, $status);
        $result = [];
        foreach ($oids as $index => $_oid) {
            $line = trim($output[$index] ?? '');
            $result[$index] = preg_match('/^"?(\d+)"?$/', $line, $match) ? (float)$match[1] : null;
        }
        return $result;
    }
    if (function_exists('snmpget')) {
        $result = [];
        foreach ($oids as $index => $oid) {
            $value = snmp_extension_get($agent, $profile, (string)$oid);
            $result[$index] = $value !== false && preg_match('/^"?(\d+)"?$/', trim((string)$value), $match)
                ? (float)$match[1] : null;
        }
        return $result;
    }
    return array_fill(0, count($oids), null);
}

/** SNMP WALK one subtree. Returns [last OID index => plain value]. */
function snmp_result_status(array $output, int $exitCode, bool $hasValues): string {
    $text = strtolower(implode("\n", $output));
    if (preg_match('/authentication failure|authorization error|authorizationerror|unknown user|wrong digest|decryption error|not in time window|unknown engine id/', $text)) {
        return 'authentication_failure';
    }
    if (preg_match('/exec\(\) has been disabled|permission denied.*snmpwalk|snmpwalk.*permission denied|unable to execute|failed to execute/', $text)) {
        return 'backend_unavailable';
    }
    if (preg_match('/no response|timeout|timed out|request timed out/', $text)) {
        return 'timeout';
    }
    if (preg_match('/no more variables left in this mib view|end of mib view/', $text)) {
        return 'empty_table';
    }
    if (preg_match('/no such object|no such instance|no such name|unknown object identifier|cannot find module|end of mib/', $text)) {
        return 'unsupported_oid';
    }
    if (preg_match('/error in packet|no route to host|network is unreachable|unknown host|cannot resolve/', $text)) {
        return 'other_error';
    }
    if ($hasValues) return 'success';
    if (!$output) return $exitCode === 0 ? 'empty_table' : 'empty_response';
    return 'malformed_response';
}

function snmp_parse_value(string $value, string $baseOid): ?array {
    $value = trim($value);
    if (preg_match('/^-?\d+$/', $value)) {
        return ['type'=>'integer', 'value'=>$value];
    }

    if (preg_match('/^(INTEGER|Integer32):\s*(-?\d+)$/i', $value, $match)) {
        return ['type'=>'integer', 'value'=>$match[2]];
    }
    if (preg_match('/^(Counter32|Counter64|Gauge32|Unsigned32|Timeticks):\s*(\d+)$/i', $value, $match)) {
        return ['type'=>strtolower($match[1]), 'value'=>$match[2]];
    }
    if (preg_match('/^INTEGER:\s*(up|down|testing|unknown|dormant|notPresent|lowerLayerDown)$/i', $value, $match)) {
        return ['type'=>'enum_text', 'value'=>strtolower($match[1])];
    }
    if (preg_match('/^(?:STRING:\s*)?(.+)$/i', $value, $match)) {
        $text = trim($match[1]);
        $isOperStatus = rtrim($baseOid, '. ') === '1.3.6.1.2.1.2.2.1.8';
        $isAdminStatus = rtrim($baseOid, '. ') === '1.3.6.1.2.1.2.2.1.7';
        if (($isOperStatus || $isAdminStatus) &&
            preg_match('/^(up|down|testing|unknown|dormant|notpresent|lowerlayerdown)$/i', $text, $enum)) {
            return ['type'=>'enum_text', 'value'=>strtolower($enum[1])];
        }
        if (preg_match('/^STRING:\s*(.*)$/is', $value, $stringMatch)) {
            $text = trim($stringMatch[1]);
            if (strlen($text) >= 2 && $text[0] === '"' && substr($text, -1) === '"') {
                $text = substr($text, 1, -1);
            }
            return ['type'=>'string', 'value'=>$text];
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.\/:-]*(?:\s+.*)?$/s', $text)) {
            return null;
        }
        return ['type'=>'string', 'value'=>$text];
    }

    return null;
}

function snmp_parse_walk_output_details(array $output, string $baseOid): array {
    $values = [];
    $types = [];
    $invalid = false;
    $prefix = trim($baseOid, '.');
    foreach ($output as $line) {
        if (!preg_match('/^\.?(\d+(?:\.\d+)+)\s*(?:=\s*)?(.+)$/', trim($line), $match)) {
            $invalid = true;
            continue;
        }
        $oid = $match[1];
        if (!str_starts_with($oid, $prefix . '.')) {
            $invalid = true;
            continue;
        }
        $suffix = substr($oid, strlen($prefix) + 1);
        if (!preg_match('/^\d+$/', $suffix)) {
            $invalid = true;
            continue;
        }
        $parsed = snmp_parse_value($match[2], $prefix);
        if ($parsed === null) {
            $invalid = true;
            continue;
        }
        $index = (int)$suffix;
        $values[$index] = $parsed['value'];
        $types[$index] = $parsed['type'];
    }
    return ['values'=>$values, 'types'=>$types, 'invalid'=>$invalid];
}

function snmp_parse_walk_output(array $output, string $baseOid = ''): array {
    if ($baseOid === '') {
        $values = [];
        foreach ($output as $line) {
            if (preg_match('/^\.?[\d.]*\.(\d+)\s+(.*)$/', trim($line), $match)) {
                $parsed = snmp_parse_value($match[2], '');
                if ($parsed !== null) {
                    $values[(int)$match[1]] = $parsed['value'];
                }
            }
        }
        return $values;
    }
    return snmp_parse_walk_output_details($output, $baseOid)['values'];
}

/** SNMP WALK with a safe diagnostic category and no raw agent error output. */
function snmp_walk_result(string $ip, array $profile, string $baseOid): array {
    $agent = snmp_agent($ip, $profile);
    if (snmpwalk_cli()) {
        $command = 'snmpwalk ' . snmp_authargs($profile) . ' -Oqn -t 1 -r 1 '
            . escapeshellarg($agent) . ' ' . escapeshellarg($baseOid) . ' 2>&1';
        $output = [];
        @exec($command, $output, $status);
        $parsed = snmp_parse_walk_output_details($output, $baseOid);
        $resultStatus = snmp_result_status($output, (int)$status, (bool)$parsed['values']);
        if (!$parsed['values'] && $parsed['invalid'] && $resultStatus === 'empty_response') {
            $resultStatus = 'malformed_response';
        }
        return ['status'=>$resultStatus, 'values'=>$parsed['values'], 'types'=>$parsed['types']];
    }
    if (function_exists('snmpwalk')) {
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        @snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $version = (int)($profile['version'] ?? 0);
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
        if ($version === 2) {
            if (!function_exists('snmp3_real_walk')) {
                restore_error_handler();
                return ['status'=>'backend_unavailable','values'=>[]];
            }
            $authPass = (string)($profile['auth_pass'] ?? '');
            $privPass = (string)($profile['priv_pass'] ?? '');
            $level = $authPass === '' ? 'noAuthNoPriv' : ($privPass === '' ? 'authNoPriv' : 'authPriv');
            $rows = @snmp3_real_walk(
                $agent,
                (string)($profile['sec_name'] ?? ''),
                $level,
                snmp_auth_proto($profile['auth_proto'] ?? 'MD5'),
                $authPass,
                snmp_priv_proto($profile['priv_proto'] ?? 'DES'),
                $privPass,
                $baseOid,
                1000000,
                1
            );
        } else {
            $community = (string)($profile['community'] ?? 'public');
            $rows = $version === 1
                ? @snmp2_real_walk($agent, $community, $baseOid, 1000000, 1)
                : @snmprealwalk($agent, $community, $baseOid, 1000000, 1);
        }
        } catch (Throwable $error) {
            $rows = false;
            $warning = $error->getMessage();
        } finally {
            restore_error_handler();
        }
        $values = [];
        $types = [];
        $prefix = trim($baseOid, '.');
        if (is_array($rows)) {
            foreach ($rows as $oid => $value) {
                $oid = ltrim((string)$oid, '.');
                if (!str_starts_with($oid, $prefix . '.')) continue;
                $suffix = substr($oid, strlen($prefix) + 1);
                if (!preg_match('/^\d+$/', $suffix)) continue;
                $parsed = snmp_parse_value((string)$value, $prefix);
                if ($parsed !== null) {
                    $index = (int)$suffix;
                    $values[$index] = $parsed['value'];
                    $types[$index] = $parsed['type'];
                }
            }
        }
        $output = $warning === '' ? [] : [$warning];
        $emptyWalk = is_array($rows) && $rows === [] && $warning === '';
        $status = snmp_result_status($output, ($values || $emptyWalk) ? 0 : 1, (bool)$values);
        return ['status'=>$status, 'values'=>$values, 'types'=>$types];
    }
    return ['status'=>'backend_unavailable','values'=>[],'types'=>[]];
}

/**
 * Try the standard interface-name table, then the legacy description table.
 * The callback returns snmp_walk_result()'s structured result; only sanitized
 * status codes are retained for failures.
 */
function snmp_interface_discovery_result(callable $walk): array {
    $oids = [
        'ifName' => '1.3.6.1.2.1.31.1.1.1.1',
        'ifDescr' => '1.3.6.1.2.1.2.2.1.2',
    ];
    $attempts = [];
    foreach ($oids as $table => $oid) {
        $result = $walk($oid);
        $values = is_array($result) && is_array($result['values'] ?? null) ? $result['values'] : [];
        $status = is_array($result) ? (string)($result['status'] ?? 'other_error') : 'other_error';
        if (!in_array($status, [
            'success','timeout','authentication_failure','unsupported_oid','empty_table',
            'empty_response','malformed_response','backend_unavailable','other_error',
        ], true)) {
            $status = 'other_error';
        }
        $attempts[$table] = $status;
        if ($values) {
            return ['status'=>'success','values'=>$values,'source'=>$table,'attempts'=>$attempts];
        }
    }
    return ['status'=>'failed','values'=>[],'source'=>null,'attempts'=>$attempts];
}

function snmp_interface_diagnostic_message(array $attempts): string {
    $labels = [
        'timeout' => 'timeout or no response',
        'authentication_failure' => 'authentication or security failure',
        'unsupported_oid' => 'interface OID unsupported',
        'empty_table' => 'SNMP responded, but the interface table is empty',
        'empty_response' => 'empty SNMP response',
        'malformed_response' => 'malformed or unparseable SNMP response',
        'backend_unavailable' => 'SNMP backend unavailable or inaccessible',
        'other_error' => 'other SNMP error',
        'success' => 'success',
    ];
    $details = [];
    foreach (['ifName','ifDescr'] as $table) {
        if (!isset($attempts[$table])) continue;
        $category = (string)$attempts[$table];
        $details[] = $table . ': ' . ($labels[$category] ?? $labels['other_error']);
    }
    return 'SNMP interface discovery failed' . ($details ? ' (' . implode('; ', $details) . ')' : '') . '.';
}

/** Legacy callers receive the original index => value shape. */
function snmp_walk(string $ip, array $profile, string $baseOid): array {
    return snmp_walk_result($ip, $profile, $baseOid)['values'];
}
