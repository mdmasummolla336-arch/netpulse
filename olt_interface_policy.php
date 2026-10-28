<?php
declare(strict_types=1);

function np_is_olt_type(string $typeName): bool {
    return strcasecmp(trim($typeName), 'OLT') === 0;
}

function np_olt_default_interfaces(string $typeName, string $ip, string $vendor): array {
    if (np_is_olt_type($typeName) && $ip === '172.29.1.102' && strtolower($vendor) === 'vsol') {
        return ['GE0/1', 'GE0/2', 'GE0/3', 'GE0/4'];
    }
    return [];
}

function np_parse_olt_interfaces($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $value);
    }
    if (!is_array($value)) return [];

    $interfaces = [];
    foreach ($value as $entry) {
        if (!is_string($entry)) continue;
        $entry = trim($entry);
        if ($entry === '' || strlen($entry) > 128 || preg_match('/[\x00-\x1F\x7F]/', $entry)) continue;
        $key = strtolower($entry);
        $interfaces[$key] = $entry;
    }
    return array_values($interfaces);
}

function np_olt_interface_is_excluded(string $ifname): bool {
    return preg_match(
        '/(?:^|[^a-z0-9])(?:vlan(?:if|interface)?|management|mgmt|onu|ont|(?:g|e)?pon)(?=[^a-z]|$)/i',
        $ifname
    ) === 1 || preg_match('/(?:management|mgmt)[-_ ]?vlan|vlan[-_ ]?(?:management|mgmt)/i', $ifname) === 1;
}

function np_olt_graph_port_number(array $interface): ?int {
    $ifIndex = (int)($interface['ifindex'] ?? 0);
    $ifType = (int)($interface['iftype'] ?? 0);
    if ($ifIndex < 1 || $ifType !== 6) return null;

    $ifName = trim((string)($interface['ifname'] ?? ''));
    $description = trim((string)($interface['description'] ?? ''));
    $alias = trim((string)($interface['alias'] ?? ''));
    if (np_olt_interface_is_excluded($ifName . ' ' . $description . ' ' . $alias)) return null;
    if (preg_match(
        '/^(?:ge|gigabitethernet)-?\s*(?:\d+\/){0,2}([1-8])(?:\s+.+)?$/i',
        $ifName,
        $match
    ) !== 1) return null;

    $port = (int)$match[1];
    if ($description !== '' &&
        preg_match('/^(?:ge|gigabitethernet)-?\s*(?:\d+\/){0,2}([1-8])(?:\s+.+)?$/i', $description, $descMatch) === 1 &&
        (int)$descMatch[1] !== $port) {
        return null;
    }
    return $port;
}

function np_olt_port_is_graph_candidate(array $interface): bool {
    return np_olt_graph_port_number($interface) !== null;
}

function np_olt_interface_is_allowed(string $typeName, string $ifname, array $allowlist): bool {
    if (!np_is_olt_type($typeName)) return true;
    if ($ifname === '' || np_olt_interface_is_excluded($ifname)) return false;

    $allowed = array_fill_keys(array_map('strtolower', np_parse_olt_interfaces($allowlist)), true);
    $candidate = strtolower(trim($ifname));
    if (isset($allowed[$candidate])) return true;
    foreach (array_keys($allowed) as $interface) {
        if (str_starts_with($candidate, $interface . ' ')) return true;
    }
    return false;
}

function np_filter_olt_interfaces(string $typeName, array $rows, array $allowlist): array {
    if (!np_is_olt_type($typeName)) return $rows;
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => np_olt_interface_is_allowed(
            $typeName,
            trim((string)($row['ifname'] ?? '')),
            $allowlist
        )
    ));
}

function np_normalize_olt_port_labels(array $row, string $typeName, array $allowlist): array {
    if (!np_is_olt_type($typeName)) return $row;
    $ifname = trim((string)($row['ifname'] ?? ''));
    $alias = trim((string)($row['alias'] ?? ''));
    $description = trim((string)($row['description'] ?? ''));
    if ($description === '' && $alias !== '' && strcasecmp($alias, $ifname) === 0 &&
        preg_match('/^(\S+)\s+(.+)$/', $ifname, $parts) === 1 &&
        np_olt_interface_is_allowed($typeName, $parts[1], $allowlist)) {
        $row['ifname'] = $parts[1];
        $row['description'] = trim($parts[2]);
    }
    return $row;
}

function np_olt_interface_allowlist(int $deviceId, string $typeName, string $ip, string $vendor): array {
    $saved = setting_get('olt_interface_allowlist_device_' . $deviceId);
    if ($saved !== null) return np_parse_olt_interfaces((string)$saved);
    return np_olt_default_interfaces($typeName, $ip, $vendor);
}

function np_port_alias_display(string $alias, string $ifname, string $description, string $typeName): string {
    $alias = trim($alias);
    if (!np_is_olt_type($typeName) || $alias === '') return $alias;

    $normalizedAlias = strtolower(preg_replace('/\s+/', ' ', $alias));
    $normalizedDescription = strtolower(preg_replace('/\s+/', ' ', trim($description)));
    $normalizedCombined = strtolower(preg_replace('/\s+/', ' ', trim($ifname . ' ' . $description)));
    if ($normalizedAlias === $normalizedDescription || $normalizedAlias === $normalizedCombined) return '';
    return $alias;
}
