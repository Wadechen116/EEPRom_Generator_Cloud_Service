-- Telemetry from the desktop app (api/collect.php) and the monitor dashboard (monitor/index.php).
-- RECORD of tables that were created by hand on the remote database (chamonixdb) on 2026-11-12;
-- both definitions below are copied from SHOW CREATE TABLE on the server (AUTO_INCREMENT value dropped).
-- Safe to re-run (IF NOT EXISTS): use it to rebuild the tables on a new server.
--
-- telemetry_logs: one row per app launch. machine_id is the main device identity (hash of hardware IDs);
--   cpu_id / mb_uuid / disk_serial are kept separately so the dashboard can spot cloned VMs
--   (more motherboards than disks inside one organisation = one disk image copied to many machines).
--   Column lengths match the truncation in api/collect.php (characters, utf8mb4).
--   idx_created_at serves every time-range filter on the dashboard; idx_public_ip serves the
--   per-IP rate limit in api/collect.php.
--
-- ip_intel_cache: ISP / organisation / country per public IP (ip-api.com lookup, cached once per IP).
--   PRIMARY KEY (public_ip) is what makes INSERT IGNORE in api/collect.php skip an IP that is already
--   cached, and keeps the LEFT JOIN in monitor/index.php to at most one row per log entry (no double counting).
--   updated_at is filled by the database; the code never writes it.

CREATE TABLE IF NOT EXISTS `telemetry_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '紀錄建立時間',
  `public_ip` varchar(45) NOT NULL COMMENT '對外公開 IP（支援 IPv4 與 IPv6）',
  `local_ips` varchar(255) DEFAULT NULL COMMENT '本機內網 IP 列表（例如 192.168.1.50, 10.0.0.12）',
  `hostname` varchar(128) DEFAULT NULL COMMENT '本機電腦名稱（Dns.GetHostName）',
  `ad_domain` varchar(128) DEFAULT NULL COMMENT 'Windows AD 網域名稱',
  `machine_id` varchar(64) NOT NULL COMMENT '綜合硬體指紋雜湊值（主要裝置識別碼）',
  `cpu_id` varchar(64) DEFAULT NULL COMMENT 'CPU 序號（Win32_Processor -> ProcessorId）',
  `mb_uuid` varchar(64) DEFAULT NULL COMMENT '主機板 UUID（Win32_ComputerSystemProduct -> UUID）',
  `disk_serial` varchar(128) DEFAULT NULL COMMENT '系統硬碟序號（Win32_DiskDrive -> SerialNumber）',
  `os_version` varchar(64) DEFAULT NULL COMMENT '作業系統版本',
  `arch` varchar(16) DEFAULT NULL COMMENT '系統架構（x86 或 x64）',
  `app_version` varchar(32) DEFAULT NULL COMMENT '軟體版本號',
  `license_key` varchar(128) DEFAULT NULL COMMENT '目前使用的授權序號（或破解狀態標記）',
  PRIMARY KEY (`id`),
  KEY `idx_public_ip` (`public_ip`),
  KEY `idx_ad_domain` (`ad_domain`),
  KEY `idx_machine_id` (`machine_id`),
  KEY `idx_mb_uuid` (`mb_uuid`),
  KEY `idx_disk_serial` (`disk_serial`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='軟體遙測與盜版偵測主表';

CREATE TABLE IF NOT EXISTS `ip_intel_cache` (
  `public_ip` varchar(45) NOT NULL COMMENT '對外公開 IP',
  `isp` varchar(128) DEFAULT NULL COMMENT '電信商名稱',
  `org` varchar(128) DEFAULT NULL COMMENT '所屬企業或 ASN 組織名稱',
  `country` varchar(64) DEFAULT NULL COMMENT '國家',
  `city` varchar(64) DEFAULT NULL COMMENT '城市',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`public_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP 地理位置與企業組織反查快取表';
