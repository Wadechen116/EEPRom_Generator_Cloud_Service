-- IP geolocation for the monitor map (monitor/index.php) and the country / continent statistics.
-- Run once against the remote database (chamonixdb, phpMyAdmin -> SQL tab). Safe to re-run (IF NOT EXISTS).
--
-- api/collect.php fills these from ip-api.com (fields lat, lon, countryCode, continent, continentCode) when an
-- IP is first seen, and refreshes a cached IP whose lat is still NULL or whose row is older than
-- telemetry.geo_refresh_days (default 90). Rows cached before this migration therefore fill in by themselves
-- the next time that IP reports. To refill everything at once instead, empty the cache (it is only a cache):
--     DELETE FROM ip_intel_cache;
--
-- Accuracy: IP geolocation is city level at best and often only the ISP's location -- good enough for a map
-- of where the software runs, never evidence of where a specific machine is.

ALTER TABLE ip_intel_cache
    ADD COLUMN IF NOT EXISTS country_code   CHAR(2)      NULL COMMENT 'ISO 3166-1 alpha-2 國碼' AFTER country,
    ADD COLUMN IF NOT EXISTS continent      VARCHAR(32)  NULL COMMENT '洲別（英文）'            AFTER country_code,
    ADD COLUMN IF NOT EXISTS continent_code CHAR(2)      NULL COMMENT '洲別代碼 AF/AN/AS/EU/NA/OC/SA' AFTER continent,
    ADD COLUMN IF NOT EXISTS lat            DECIMAL(9,6) NULL COMMENT '緯度（依 IP 推算）'      AFTER city,
    ADD COLUMN IF NOT EXISTS lon            DECIMAL(9,6) NULL COMMENT '經度（依 IP 推算）'      AFTER lat;
