-- Report type for telemetry rows (api/collect.php, monitor/index.php). Run once against chamonixdb
-- (phpMyAdmin -> SQL tab). Safe to re-run (IF NOT EXISTS).
--
-- event: why the desktop application sent the report
--   start      the application was launched (also what every row before this migration was, hence the default)
--   heartbeat  periodic "still running" report (MonitorSoftDevice library, interval set by the application)
--   manual     an extra report the application asked for
-- The monitor counts only "start" rows as launches; every row still counts for "active devices".
--
-- idx_machine_created serves the per-device rate limit in api/collect.php
-- (... WHERE machine_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE).
--
-- Order of deployment: run this first, then upload the new collect.php / monitor/index.php. (Both also work
-- without it: collect.php then stores rows without the event and logs a reminder; the monitor counts every row
-- as a launch, as before.)

ALTER TABLE telemetry_logs
    ADD COLUMN IF NOT EXISTS event VARCHAR(16) NOT NULL DEFAULT 'start'
        COMMENT '回報類型：start=啟動、heartbeat=心跳、manual=手動' AFTER created_at,
    ADD INDEX IF NOT EXISTS idx_machine_created (machine_id, created_at);
