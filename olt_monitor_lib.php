<?php
declare(strict_types=1);

require_once __DIR__ . '/olt_interface_policy.php';

function np_olt_monitoring_enabled($monitored): bool {
    return $monitored === null || (string)$monitored === '1';
}

function np_olt_icmp_transition(
    string $confirmedStatus,
    int $failedCount,
    ?string $downSince,
    ?int $downTs,
    bool $pingAttempted,
    bool $pingSucceeded,
    string $now,
    int $nowTs,
    int $failureThreshold = 3
): array {
    $threshold = max(1, $failureThreshold);
    if (!$pingAttempted) {
        return [
            'valid' => false,
            'status' => $confirmedStatus,
            'ping_fail_count' => max(0, $failedCount),
            'down_since' => $downSince,
            'down_ts' => $downTs,
        ];
    }

    if ($pingSucceeded) {
        return [
            'valid' => true,
            'status' => 'up',
            'ping_fail_count' => 0,
            'down_since' => null,
            'down_ts' => null,
        ];
    }

    $failedCount = max(0, $failedCount);
    if ($confirmedStatus === 'down') {
        $failedCount = max($failedCount, $threshold);
    }
    if ($failedCount === 0) {
        $downSince = $now;
        $downTs = $nowTs;
    }
    $failedCount = min($threshold, $failedCount + 1);

    return [
        'valid' => true,
        'status' => $failedCount >= $threshold ? 'down' : 'pending',
        'ping_fail_count' => $failedCount,
        'down_since' => $downSince,
        'down_ts' => $downTs,
    ];
}
