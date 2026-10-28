<?php
declare(strict_types=1);

function op_history_schema(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS optical_power_history (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            sampled_at VARCHAR(32) NOT NULL,
            device_id BIGINT NOT NULL,
            device_name VARCHAR(255) NOT NULL,
            ip VARCHAR(64) NOT NULL,
            sensor_index BIGINT NOT NULL,
            ifname VARCHAR(255) NOT NULL,
            direction VARCHAR(2) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            dbm DOUBLE NOT NULL,
            sensor_status INT NULL,
            raw_value DOUBLE NULL,
            scale INT NULL,
            `precision` INT NULL,
            INDEX idx_optical_history_device_time(device_id, sampled_at),
            INDEX idx_optical_history_port_dir_time(device_id, ifname, direction, sampled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!np_column_exists($pdo, 'optical_power_history', 'description')) {
            $pdo->exec("ALTER TABLE optical_power_history
                ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT ''");
        }
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS optical_power_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sampled_at TEXT NOT NULL,
        device_id INTEGER NOT NULL,
        device_name TEXT NOT NULL,
        ip TEXT NOT NULL,
        sensor_index INTEGER NOT NULL,
        ifname TEXT NOT NULL,
        direction TEXT NOT NULL CHECK(direction IN ('rx','tx')),
        description TEXT NOT NULL DEFAULT '',
        dbm REAL NOT NULL,
        sensor_status INTEGER,
        raw_value REAL,
        scale INTEGER,
        `precision` INTEGER
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_optical_history_device_time
        ON optical_power_history(device_id, sampled_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_optical_history_port_dir_time
        ON optical_power_history(device_id, ifname, direction, sampled_at)");
    if (!np_column_exists($pdo, 'optical_power_history', 'description')) {
        $pdo->exec("ALTER TABLE optical_power_history
            ADD COLUMN description TEXT NOT NULL DEFAULT ''");
    }
}
