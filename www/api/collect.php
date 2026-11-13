<?php
/**
 * POST api/collect.php  -- telemetry from the desktop app (no login, no X-API-Key: the app calls it on startup).
 * Body (JSON): machine_id (required), local_ips, hostname, ad_domain, cpu_id, mb_uuid, disk_serial,
 *              os_version, arch, app_version, license_key
 * Reply: {"status":"ok","latest_version":"..."}  or  {"status":"error"} with 400/405/413/429/500.
 * Viewed in monitor/index.php. Tables telemetry_logs / ip_intel_cache are created on the server by hand.
 *
 * Deliberately does NOT include bootstrap.php: that one requires X-API-Key and uses a different JSON shape.
 * Optional settings in config.php under 'telemetry' (see config.sample.php); all have defaults.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function collect_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** String field from the JSON body, cut to $max characters; '' when missing or not a scalar. */
function field(array $data, string $key, int $max): string {
    $v = $data[$key] ?? '';
    if (!is_scalar($v)) {
        return '';
    }
    $v = trim((string) $v);
    // mb_substr keeps multi-byte (e.g. Chinese hostname) characters whole; plain substr if mbstring is missing.
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/**
 * ISP / organisation / country / continent / lat-lon for a public IP, cached in ip_intel_cache.
 * Looked up when the IP is new, when its row has no coordinates yet (cached before the 2026-11-13 migration),
 * or when the row is older than telemetry.geo_refresh_days (default 90; ISPs re-assign addresses).
 * ip-api.com free tier: HTTP only, non-commercial use, 45 requests/minute. telemetry.geo_lookup = false turns it off.
 */
function geo_lookup(PDO $pdo, string $ip, array $tcfg): void {
    if (!($tcfg['geo_lookup'] ?? true)
        || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return;
    }
    $refreshDays = max(1, (int) ($tcfg['geo_refresh_days'] ?? 90));
    $st = $pdo->prepare('SELECT lat, updated_at < NOW() - INTERVAL ' . $refreshDays . ' DAY AS stale
                         FROM ip_intel_cache WHERE public_ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch();
    if ($row && $row['lat'] !== null && !$row['stale']) {
        return;   // cached and fresh
    }

    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $json = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip)
        . '?fields=status,country,countryCode,continent,continentCode,city,lat,lon,isp,org', false, $ctx);
    $geo = $json ? json_decode($json, true) : null;
    if (!is_array($geo) || ($geo['status'] ?? '') !== 'success') {
        return;
    }
    $coord = fn($v, $lim) => (is_numeric($v) && abs((float) $v) <= $lim) ? round((float) $v, 6) : null;

    // Insert, or refresh the existing row (updated_at is bumped by the table's ON UPDATE).
    $pdo->prepare('INSERT INTO ip_intel_cache
                       (public_ip, isp, org, country, country_code, continent, continent_code, city, lat, lon)
                   VALUES (:ip, :isp, :org, :country, :cc, :continent, :ccode, :city, :lat, :lon)
                   ON DUPLICATE KEY UPDATE
                       isp = VALUES(isp), org = VALUES(org), country = VALUES(country),
                       country_code = VALUES(country_code), continent = VALUES(continent),
                       continent_code = VALUES(continent_code), city = VALUES(city),
                       lat = VALUES(lat), lon = VALUES(lon)')
        ->execute([
            ':ip'        => $ip,
            ':isp'       => field($geo, 'isp', 128),
            ':org'       => field($geo, 'org', 128),
            ':country'   => field($geo, 'country', 64),
            ':cc'        => strtoupper(field($geo, 'countryCode', 2)) ?: null,
            ':continent' => field($geo, 'continent', 32) ?: null,
            ':ccode'     => strtoupper(field($geo, 'continentCode', 2)) ?: null,
            ':city'      => field($geo, 'city', 64),
            ':lat'       => $coord($geo['lat'] ?? null, 90),
            ':lon'       => $coord($geo['lon'] ?? null, 180),
        ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    collect_reply(405, ['status' => 'error']);
}

define('APP_ENTRY', true);
require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/SecurityHeaders.php';

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    error_log('[collect] Missing api/config/config.php');
    collect_reply(500, ['status' => 'error']);
}
$config = require $configFile;
$tcfg = $config['telemetry'] ?? [];
// Headers only: no HTTPS rule here -- the desktop app may still be calling over http.
SecurityHeaders::send($config, SecurityHeaders::API_CSP);

// Client IP: REMOTE_ADDR is the only value the client cannot forge. CF-Connecting-IP / X-Forwarded-For are
// trusted only when the request really comes from a proxy listed in telemetry.trusted_proxies.
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (in_array($client_ip, $tcfg['trusted_proxies'] ?? [], true)) {
    $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $first = trim(explode(',', $fwd)[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) {
        $client_ip = $first;
    }
}

$raw_input = file_get_contents('php://input', false, null, 0, 65537);
if (strlen((string) $raw_input) > 65536) {
    collect_reply(413, ['status' => 'error']);
}
$data = json_decode((string) $raw_input, true);
if (!is_array($data) || field($data, 'machine_id', 64) === '') {
    collect_reply(400, ['status' => 'error']);
}

try {
    $pdo = Database::connection($config['db']);

    // Simple flood guard: at most N records per IP per minute (default 30).
    $perMinute = (int) ($tcfg['max_per_minute'] ?? 30);
    if ($perMinute > 0) {
        $rl = $pdo->prepare('SELECT COUNT(*) FROM telemetry_logs
                             WHERE public_ip = :ip AND created_at > NOW() - INTERVAL 1 MINUTE');
        $rl->execute([':ip' => $client_ip]);
        if ((int) $rl->fetchColumn() >= $perMinute) {
            collect_reply(429, ['status' => 'error']);
        }
    }

    $sql = "INSERT INTO telemetry_logs (
                public_ip, local_ips, hostname, ad_domain,
                machine_id, cpu_id, mb_uuid, disk_serial,
                os_version, arch, app_version, license_key
            ) VALUES (
                :public_ip, :local_ips, :hostname, :ad_domain,
                :machine_id, :cpu_id, :mb_uuid, :disk_serial,
                :os_version, :arch, :app_version, :license_key
            )";

    $pdo->prepare($sql)->execute([
        ':public_ip'   => substr($client_ip, 0, 45),
        ':local_ips'   => field($data, 'local_ips', 255),
        ':hostname'    => field($data, 'hostname', 128),
        ':ad_domain'   => field($data, 'ad_domain', 128),
        ':machine_id'  => field($data, 'machine_id', 64),
        ':cpu_id'      => field($data, 'cpu_id', 64),
        ':mb_uuid'     => field($data, 'mb_uuid', 64),
        ':disk_serial' => field($data, 'disk_serial', 128),
        ':os_version'  => field($data, 'os_version', 64),
        ':arch'        => field($data, 'arch', 16),
        ':app_version' => field($data, 'app_version', 32),
        ':license_key' => field($data, 'license_key', 128),
    ]);

    // The launch is recorded. The IP lookup below is best effort: whatever goes wrong there (ip-api.com down,
    // sql/migrate_2026-11-13_ip_geo.sql not run yet, ...) is logged and the app still gets "ok".
    try {
        geo_lookup($pdo, $client_ip, $tcfg);
    } catch (Throwable $e) {
        error_log('[collect] geo lookup: ' . $e->getMessage());
    }

    collect_reply(200, ['status' => 'ok', 'latest_version' => (string) ($tcfg['latest_version'] ?? '1.0.0')]);

} catch (Throwable $e) {
    error_log('[collect] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    collect_reply(500, ['status' => 'error']);
}
