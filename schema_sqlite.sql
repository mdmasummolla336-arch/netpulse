-- NetPulse schéma (SQLite). Kompletná – čistá inštalácia nesmie závisieť od migrate().
CREATE TABLE IF NOT EXISTS maps(
  id INTEGER PRIMARY KEY, name TEXT, image_id INTEGER, canvas_id INTEGER, sort_order INTEGER);
CREATE TABLE IF NOT EXISTS device_types(
  id INTEGER PRIMARY KEY, name TEXT, icon TEXT);
CREATE TABLE IF NOT EXISTS devices(
  id INTEGER PRIMARY KEY, name TEXT, ip TEXT, dns TEXT,
  snmp_profile INTEGER, username TEXT, password TEXT, type_id INTEGER,
  status TEXT DEFAULT 'unknown', last_check TEXT, rtt REAL, down_since TEXT, down_ts INTEGER,
  monitored INTEGER DEFAULT 1, notified TEXT, ping_fail_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS map_nodes(
  id INTEGER PRIMARY KEY, map_id INTEGER, kind TEXT DEFAULT 'device',
  device_id INTEGER, submap_id INTEGER, x INTEGER, y INTEGER, image INTEGER, label TEXT);
CREATE TABLE IF NOT EXISTS map_links(
  id INTEGER PRIMARY KEY, map_id INTEGER, from_node INTEGER,
  to_node INTEGER, width INTEGER, style INTEGER, thickness INTEGER, ltype TEXT, snmp_device INTEGER, snmp_ifindex INTEGER, snmp_type INTEGER, label TEXT);
CREATE TABLE IF NOT EXISTS services(
  id INTEGER PRIMARY KEY, device_id INTEGER, name TEXT,
  probe_id INTEGER, ptype TEXT, port INTEGER, enabled INTEGER, down INTEGER, acked INTEGER,
  status TEXT DEFAULT 'unknown', last_check TEXT);
CREATE TABLE IF NOT EXISTS status_history(
  id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER,
  ts TEXT, status TEXT, rtt REAL);
CREATE TABLE IF NOT EXISTS events(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, device_id INTEGER,
  device_name TEXT, ip TEXT, status TEXT, message TEXT);
CREATE TABLE IF NOT EXISTS probes(
  id INTEGER PRIMARY KEY, name TEXT, type TEXT, port INT, dns_name TEXT);
CREATE TABLE IF NOT EXISTS outages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, service TEXT,
  started TEXT, started_ts INTEGER, ended TEXT, duration INT);
CREATE TABLE IF NOT EXISTS link_types(id INTEGER PRIMARY KEY, name TEXT, style INTEGER, thickness INTEGER);
CREATE TABLE IF NOT EXISTS snmp_profiles(id INTEGER PRIMARY KEY, name TEXT, community TEXT, version INTEGER, port INTEGER,
  sec_name TEXT, auth_pass TEXT, priv_pass TEXT, auth_proto TEXT, priv_proto TEXT);
CREATE TABLE IF NOT EXISTS link_traffic(link_id INTEGER PRIMARY KEY, in_oct INTEGER, out_oct INTEGER, ts TEXT, ts_unix INTEGER,
  ctype TEXT, uptime INTEGER, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE);
CREATE TABLE IF NOT EXISTS traffic_history(
  id INTEGER PRIMARY KEY AUTOINCREMENT, link_id INTEGER, ts TEXT, rx_bps DOUBLE, tx_bps DOUBLE);
CREATE TABLE IF NOT EXISTS app_settings(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS notify_queue(
  id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, status TEXT, text TEXT,
  created INTEGER, attempts INTEGER DEFAULT 0, next_try INTEGER);
CREATE TABLE IF NOT EXISTS login_fail(id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, ts INTEGER);
CREATE TABLE IF NOT EXISTS mx80_categories(
  id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, name TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS mx80_interfaces(
  device_id INTEGER NOT NULL, ifindex INTEGER NOT NULL, description TEXT NOT NULL,
  ifname TEXT NOT NULL DEFAULT '', selected INTEGER NOT NULL DEFAULT 0, category_id INTEGER,
  discovered INTEGER NOT NULL DEFAULT 1, last_discovery INTEGER NOT NULL,
  PRIMARY KEY(device_id,ifindex));
CREATE TABLE IF NOT EXISTS mx80_counters(
  device_id INTEGER NOT NULL, ifindex INTEGER NOT NULL, in_oct DOUBLE NOT NULL, out_oct DOUBLE NOT NULL,
  ctype VARCHAR(4) NOT NULL, uptime INTEGER, ts_unix INTEGER NOT NULL, PRIMARY KEY(device_id,ifindex));
CREATE TABLE IF NOT EXISTS mx80_traffic_history(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, device_id INTEGER NOT NULL,
  ifindex INTEGER NOT NULL, rx_bps DOUBLE NOT NULL, tx_bps DOUBLE NOT NULL);
CREATE TABLE IF NOT EXISTS mx80_category_permissions(
  user_id INTEGER NOT NULL, category_id INTEGER NOT NULL, PRIMARY KEY(user_id,category_id));
CREATE UNIQUE INDEX IF NOT EXISTS idx_mx80_categories_name_unique ON mx80_categories(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_mx80_categories_parent ON mx80_categories(parent_id);
CREATE INDEX IF NOT EXISTS idx_mx80_interfaces_category ON mx80_interfaces(category_id,selected);
CREATE INDEX IF NOT EXISTS idx_mx80_history_device_if_ts ON mx80_traffic_history(device_id,ifindex,ts);
CREATE INDEX IF NOT EXISTS idx_nodes_map ON map_nodes(map_id);
CREATE INDEX IF NOT EXISTS idx_links_map ON map_links(map_id);
CREATE INDEX IF NOT EXISTS idx_svc_dev ON services(device_id);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
CREATE INDEX IF NOT EXISTS idx_sh_dev_ts ON status_history(device_id, ts);
CREATE INDEX IF NOT EXISTS idx_sh_ts ON status_history(ts);
CREATE INDEX IF NOT EXISTS idx_th ON traffic_history(link_id, ts);
CREATE INDEX IF NOT EXISTS idx_tr_ts ON traffic_history(ts);
CREATE INDEX IF NOT EXISTS idx_out_dev ON outages(device_id, ended);
CREATE INDEX IF NOT EXISTS idx_nq_next ON notify_queue(next_try);
CREATE INDEX IF NOT EXISTS idx_lf_ip_ts ON login_fail(ip, ts);
