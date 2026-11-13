<?php
/**
 * Telemetry / license monitor dashboard (reads telemetry_logs + ip_intel_cache, written by api/collect.php).
 *
 * Login: this page has its OWN sign-in form and login state (session key 'monitor_account'), separate from
 * the EEPROM Config page (index.html, session key 'account'). Same accounts (users table) for both.
 * Who may sign in here: config.php 'monitor_admins' (list of users.name). Empty/missing = any account.
 *
 * Filters (GET, one row at the top, scope every tile/chart/table): range (24h|7d|30d|90d|all),
 * ver (app_version), target (organisation drill-down). Charts: ECharts 6 (echarts.min.js next to this file).
 * Every value drawn by a chart is also in a table on the page (tooltips never gate a value).
 */

// Never let a PHP error / stack trace (which could include DB details) reach the browser.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('APP_ENTRY', true);
const MONITOR_VERSION = '2026-11-13f';   // shown in the page footer: tells which upload is live on the server

require __DIR__ . '/../api/lib/Response.php';
require __DIR__ . '/../api/lib/SessionAuth.php';
require __DIR__ . '/../api/lib/Database.php';
require __DIR__ . '/../api/lib/SecurityHeaders.php';
require __DIR__ . '/../api/lib/LoginThrottle.php';
require __DIR__ . '/geo.php';

function monitor_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><title>監控中心</title></head>'
       . '<body style="background:#0d0d0d;color:#f0efec;font-family:system-ui,sans-serif;padding:48px;text-align:center;">'
       . '<p>' . htmlspecialchars($message) . '</p>'
       . '<p><a href="index.php" style="color:#3987e5;">重新載入</a></p></body></html>';
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[monitor] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    monitor_fail(500, '伺服器錯誤，請稍後再試。');
});

$configFile = __DIR__ . '/../api/config/config.php';
if (!file_exists($configFile)) {
    error_log('[monitor] Missing api/config/config.php');
    monitor_fail(500, '伺服器尚未設定。');
}
$config = require $configFile;

// Security headers on every response of this page (login page included). The inline <script> blocks carry a
// per-response nonce; any other inline script -- e.g. one smuggled in through collected data -- will not run.
$cspNonce = base64_encode(random_bytes(16));
SecurityHeaders::send($config, implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'nonce-{$cspNonce}' https://unpkg.com",   // echarts (self), Leaflet (unpkg, SRI-checked)
    "style-src 'self' 'unsafe-inline' https://unpkg.com",        // inline styles + style attributes, Leaflet CSS
    "img-src 'self' data: https://maps.geoapify.com",            // map tiles
    "connect-src 'self'",                                        // live refresh fetches this same page
    "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
]));
SecurityHeaders::requireHttps($config, false);   // only with security.force_https on

/* ============================== Login (own page, own login state) ==============================
 * Same accounts as the EEPROM Config page (users table, bcrypt password_hash), but a SEPARATE login:
 * this page keeps its own session key 'monitor_account' (the main page uses 'account'), so logging in
 * or out on one page never logs you in or out of the other. Only config 'monitor_admins' may log in here
 * (empty list = any account).
 */

SessionAuth::start();

// One token per session for this page's forms (login + logout), so another site cannot post them.
if (empty($_SESSION['monitor_csrf'])) {
    $_SESSION['monitor_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['monitor_csrf'];

// ?demo=1: every number comes from demo_data.php (fake), the database is not queried. Still needs a login.
$demo = (($_GET['demo'] ?? '') === '1');
$selfUrl = 'index.php' . ($demo ? '?demo=1' : '');

$monitorAdmins = $config['monitor_admins'] ?? [];
$isMonitorAdmin = fn(string $name) => empty($monitorAdmins) || in_array($name, $monitorAdmins, true);

/** The monitor's own sign-in page. */
function monitor_login_page(string $error, string $csrf, string $selfUrl, string $accountValue, bool $demo): void {
    http_response_code($error === '' ? 200 : 401);
    header('Content-Type: text/html; charset=utf-8');
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>授權監控中心 登入</title>
<style>
:root { color-scheme: dark; --ink: #f0efec; --ink-2: #c3c2b7; --muted: #898781; --surface: #1a1a19; --border: rgba(255,255,255,.12);
        --accent: #3987e5; --critical: #d03b3b; --warning: #fab219; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; color: var(--ink);
       font: 14px/1.5 system-ui, -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif;
       background: radial-gradient(900px 500px at 10% -10%, rgba(57,135,229,.28), transparent 65%),
                   radial-gradient(800px 480px at 100% 110%, rgba(25,158,112,.18), transparent 60%),
                   linear-gradient(160deg, #0b1e36 0%, #0a0e14 60%); }
.box { width: 100%; max-width: 380px; background: color-mix(in srgb, var(--surface) 88%, transparent); border: 1px solid var(--border);
       border-radius: 12px; padding: 28px 28px 24px; box-shadow: 0 12px 40px rgba(0,0,0,.45), inset 0 3px 0 var(--accent); }
h1 { margin: 0 0 4px; font-size: 20px; }
.sub { margin: 0 0 20px; color: var(--muted); font-size: 13px; }
label { display: block; font-size: 13px; color: var(--ink-2); margin: 12px 0 6px; }
input { width: 100%; height: 40px; border-radius: 8px; border: 1px solid var(--border); background: #0f1115; color: var(--ink);
        padding: 0 12px; font: inherit; }
input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: transparent; }
button { width: 100%; height: 42px; margin-top: 20px; border: 0; border-radius: 8px; background: var(--accent); color: #fff;
         font: inherit; font-weight: 600; cursor: pointer; }
button:hover { filter: brightness(1.1); }
.err { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; padding: 8px 10px; border-radius: 8px; font-size: 13px;
       background: color-mix(in srgb, var(--critical) 18%, transparent); border: 1px solid color-mix(in srgb, var(--critical) 50%, transparent); }
.err i, .demo i { font-style: normal; display: inline-grid; place-items: center; width: 18px; height: 18px; border-radius: 50%;
                  font-weight: 700; font-size: 12px; flex: none; }
.err i { background: var(--critical); color: #fff; }
.demo { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; font-size: 12px; color: var(--ink-2); }
.demo i { background: var(--warning); color: #0b0b0b; }
.foot { margin-top: 16px; text-align: center; color: var(--muted); font-size: 12px; }
</style>
</head>
<body>
<form class="box" method="post" action="<?= $h($selfUrl) ?>" autocomplete="on">
    <h1>授權監控中心</h1>
    <p class="sub">軟體遙測 · 異常組織偵測</p>
    <?php if ($demo): ?><div class="demo"><i>!</i>登入後顯示示範資料（假資料）</div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err" role="alert"><i>!</i><?= $h($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <label for="account">帳號</label>
    <input id="account" name="account" value="<?= $h($accountValue) ?>" autocomplete="username" required autofocus>
    <label for="password">密碼</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit" name="login" value="1">登入</button>
    <div class="foot">monitor 頁面版本 <?= MONITOR_VERSION ?></div>
</form>
</body>
</html>
<?php
    exit;
}

// ---- Login (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $name = trim((string) ($_POST['account'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        monitor_login_page('頁面已過期，請再登入一次。', $csrf, $selfUrl, $name, $demo);
    }
    if ($name === '' || $pass === '') {
        monitor_login_page('請輸入帳號和密碼。', $csrf, $selfUrl, $name, $demo);
    }

    $pdo = Database::connection($config['db']);
    $wait = LoginThrottle::retryAfter($pdo, $config, $name);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        monitor_login_page('登入失敗次數過多，請 ' . LoginThrottle::minutes($wait) . ' 分鐘後再試。', $csrf, $selfUrl, $name, $demo);
    }
    $st = $pdo->prepare('SELECT name, password_hash FROM users WHERE name = :name');
    $st->execute([':name' => $name]);
    $user = $st->fetch();

    // Always run one bcrypt check (against a dummy hash when the account does not exist), so the response
    // time does not tell whether an account exists. Same generic message for every failure.
    $hash = $user['password_hash'] ?? '$2y$10$1zc6yZB34/seIylmF2uVyeqUKyU7SrAjiL0/NfxBqRVHYNQ3s9wN.';
    if (!password_verify($pass, $hash) || !$user || !$isMonitorAdmin($user['name'])) {
        error_log('[monitor] login failed for "' . substr($name, 0, 100) . '" from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        LoginThrottle::fail($pdo, $name, 'monitor');
        usleep(random_int(300000, 600000));   // slows down password guessing a little
        monitor_login_page('帳號或密碼錯誤，或此帳號沒有監控中心的權限。', $csrf, $selfUrl, $name, $demo);
    }

    LoginThrottle::success($pdo, $user['name']);
    session_regenerate_id(true);   // new session id once trusted (prevents session fixation)
    $_SESSION['monitor_account'] = $user['name'];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE name = :name')->execute([':name' => $user['name']]);
    header('Location: ' . $selfUrl);   // POST -> redirect -> GET, so a refresh does not re-send the password
    exit;
}

// ---- Logout (POST): ends only the monitor login; the EEPROM Config page stays logged in ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        monitor_fail(400, '請重新整理頁面後再登出。');
    }
    $was = $_SESSION['monitor_account'] ?? null;
    unset($_SESSION['monitor_account']);
    session_regenerate_id(true);
    if ($was !== null) {
        Database::connection($config['db'])
            ->prepare('UPDATE users SET last_logout_at = NOW() WHERE name = :name')->execute([':name' => $was]);
    }
    header('Location: ' . $selfUrl);
    exit;
}

$account = $_SESSION['monitor_account'] ?? null;
if ($account === null) {
    monitor_login_page('', $csrf, $selfUrl, '', $demo);
}
// Re-checked on every request: removing someone from monitor_admins takes effect immediately.
if (!$isMonitorAdmin($account)) {
    unset($_SESSION['monitor_account']);
    monitor_login_page('此帳號沒有監控中心的權限。', $csrf, $selfUrl, '', $demo);
}

/* ============================== Filters ============================== */

$tcfg         = $config['telemetry'] ?? [];
$licenseLimit = max(1, (int) ($tcfg['license_device_limit'] ?? 1));   // devices one license key may run on
$orgAlert     = max(1, (int) ($tcfg['org_device_alert'] ?? 5));       // devices per org that count as "many"

// key => [label, length in seconds (0 = everything)]. Short ranges are what make a per-second refresh
// meaningful: devices / organisations / places move in and out of the window every second.
$RANGES = [
    '5m'  => ['最近 5 分鐘', 300],
    '15m' => ['最近 15 分鐘', 900],
    '1h'  => ['最近 1 小時', 3600],
    '24h' => ['最近 24 小時', 86400],
    '7d'  => ['最近 7 天', 7 * 86400],
    '30d' => ['最近 30 天', 30 * 86400],
    '90d' => ['最近 90 天', 90 * 86400],
    'all' => ['全部時間', 0],
];
$range = (string) ($_GET['range'] ?? '');
if (!isset($RANGES[$range])) {
    $range = $demo ? '15m' : '7d';
}
$span           = $RANGES[$range][1];
// Time-series bucket for the range: [seconds, PHP key format, label]. The SQL below builds the same keys.
$BUCKETS = [
    '10s'  => [10, 'Y-m-d H:i:s', '每 10 秒'],
    'min'  => [60, 'Y-m-d H:i', '每分鐘'],
    'hour' => [3600, 'Y-m-d H:00', '每小時'],
    'day'  => [86400, 'Y-m-d', '每日'],
];
$bucket = ($span === 0 || $span > 86400) ? 'day' : ($span === 86400 ? 'hour' : ($span === 3600 ? 'min' : '10s'));
$ver            = trim((string) ($_GET['ver'] ?? ''));
$selectedTarget = trim((string) ($_GET['target'] ?? ''));

// Use the database clock for every time window, so PHP vs MySQL time-zone differences cannot shift them.
// (Demo mode has no database: PHP's clock is used, the fake data is generated relative to it.)
if ($demo) {
    $pdo = null;
    date_default_timezone_set('Asia/Taipei');   // demo only: fake working hours line up with Taiwan time
    $dbNow = date('Y-m-d H:i:s');
} else {
    $pdo = Database::connection($config['db']);
    $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
}
$nowDt  = new DateTimeImmutable($dbNow);
$since  = $span > 0 ? $nowDt->modify("-{$span} second")->format('Y-m-d H:i:s') : null;
$psince = $span > 0 ? $nowDt->modify('-' . (2 * $span) . ' second')->format('Y-m-d H:i:s') : null;

const ORG_EXPR = "COALESCE(NULLIF(t.ad_domain, ''), c.org, t.public_ip)";
const FROM_SQL = 'FROM telemetry_logs t LEFT JOIN ip_intel_cache c ON t.public_ip = c.public_ip';

/** WHERE clause + params for the current filters. $prev = the period of equal length right before it. */
function filter_where(bool $prev = false): array {
    global $since, $psince, $ver;
    $w = ['1=1'];
    $p = [];
    if ($since !== null) {
        if ($prev) {
            $w[] = 't.created_at >= :f_from AND t.created_at < :f_to';
            $p[':f_from'] = $psince;
            $p[':f_to']   = $since;
        } else {
            $w[] = 't.created_at >= :f_from';
            $p[':f_from'] = $since;
        }
    }
    if ($ver !== '') {
        $w[] = 't.app_version = :f_ver';
        $p[':f_ver'] = $ver;
    }
    return [' WHERE ' . implode(' AND ', $w), $p];
}

function q(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function h($s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Link to this page with some filters changed (empty value = filter removed). */
function url_with(array $over): string {
    global $range, $ver, $selectedTarget, $demo;
    $qs = array_merge(['range' => $range, 'ver' => $ver, 'target' => $selectedTarget, 'demo' => $demo ? '1' : ''], $over);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($qs);
}

/** Full digits up to 9,999,999 (a live counter must visibly move by 1); 12.3M beyond that. */
function compact_num($n): string {
    $n = (float) $n;
    if ($n >= 1e7) return rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.') . 'M';
    return number_format($n);
}


$geoRows = $overKeyList = $cloneOrgList = [];
$geoError = '';
$livePerSec = [];   // 'Y-m-d H:i:s' => launches in that second, for the last 5 minutes
$liveDev5 = 0;      // distinct devices in the last 5 minutes
if ($demo) {
    require __DIR__ . '/demo_data.php';
    extract(monitor_demo_data([
        'dbNow' => $dbNow, 'since' => $since, 'psince' => $psince, 'ver' => $ver, 'target' => $selectedTarget,
        'bucket' => $bucket, 'licenseLimit' => $licenseLimit,
    ]));
} else {

[$W, $P] = filter_where();
$ORG = ORG_EXPR;
$FROM = FROM_SQL;

// Launches = rows with event 'start' (sql/migrate_2026-11-13_event.sql). Heartbeats / manual reports still count for
// devices, organisations, licenses, map... but not as launches. Before that migration every row is a launch.
$hasEvent = (bool) $pdo->query("SHOW COLUMNS FROM telemetry_logs LIKE 'event'")->fetch();
$IS_START = $hasEvent ? "(t.event = 'start')" : '1';
$EVENT_COL = $hasEvent ? 't.event' : "'start'";

/* ============================== Queries ============================== */

$kpiSql = "SELECT COALESCE(SUM($IS_START), 0) AS launches,
                  COUNT(DISTINCT t.machine_id) AS devices,
                  COUNT(DISTINCT NULLIF(t.license_key, '')) AS licenses,
                  COUNT(DISTINCT $ORG) AS orgs
           $FROM";
$kpi = q($pdo, $kpiSql . $W, $P)[0];
$kpiPrev = null;
if ($span > 0) {
    [$Wp, $Pp] = filter_where(true);
    $kpiPrev = q($pdo, $kpiSql . $Wp, $Pp)[0];
}

// The lists (not just counts) also feed the map's "疑似盜用" rule in geo.php.
$cloneOrgList = array_column(q($pdo, "SELECT $ORG AS org
        $FROM $W
        GROUP BY org
        HAVING COUNT(DISTINCT NULLIF(t.mb_uuid, '')) > COUNT(DISTINCT NULLIF(t.disk_serial, ''))
           AND COUNT(DISTINCT NULLIF(t.disk_serial, '')) > 0", $P), 'org');
$vmCloneOrgs = count($cloneOrgList);

$overKeyList = array_column(q($pdo, "SELECT t.license_key
        $FROM $W AND t.license_key <> ''
        GROUP BY t.license_key
        HAVING COUNT(DISTINCT t.machine_id) > $licenseLimit", $P), 'license_key');
$overLicensed = count($overKeyList);

// Live panel: launches per second over the last 5 minutes. Always "now", whatever the range filter says
// (only the version filter applies). Served by idx_created_at, cheap even at a 1-second refresh.
$liveWhere = 't.created_at >= :lf' . ($ver !== '' ? ' AND t.app_version = :lv' : '');
$liveParams = [':lf' => $nowDt->modify('-299 second')->format('Y-m-d H:i:s')] + ($ver !== '' ? [':lv' => $ver] : []);
$livePerSec = array_column(q($pdo, "SELECT DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:%s') AS s, SUM($IS_START) AS n
                                    FROM telemetry_logs t WHERE $liveWhere GROUP BY s", $liveParams), 'n', 's');
$liveDev5 = (int) q($pdo, "SELECT COUNT(DISTINCT t.machine_id) AS n FROM telemetry_logs t WHERE $liveWhere",
                    $liveParams)[0]['n'];

// One row per (device, public IP) with the IP's location. Needs sql/migrate_2026-11-13_ip_geo.sql; until that
// has run, the columns do not exist -- the rest of the page still works and the map card says what to do.
$geoRows = [];
$geoError = '';
try {
    $geoRows = q($pdo, "SELECT t.machine_id, MAX(t.license_key) AS license_key, MAX($ORG) AS org,
                               MAX(c.country) AS country, MAX(c.country_code) AS country_code,
                               MAX(c.continent_code) AS continent_code, MAX(c.city) AS city,
                               MAX(c.lat) AS lat, MAX(c.lon) AS lon
                        $FROM $W
                        GROUP BY t.machine_id, t.public_ip
                        LIMIT 50000", $P);
} catch (PDOException $e) {
    error_log('[monitor] geo query: ' . $e->getMessage());
    $geoError = '地理資料欄位尚未建立：請在資料庫執行 sql/migrate_2026-11-13_ip_geo.sql。';
}

// Time series buckets. Gaps are filled with 0 below (after the demo/real branch).
$bucketExpr = [
    '10s'  => "CONCAT(DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:'), LPAD(FLOOR(SECOND(t.created_at) / 10) * 10, 2, '0'))",
    'min'  => "DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i')",
    'hour' => "DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00')",
    'day'  => "DATE_FORMAT(t.created_at, '%Y-%m-%d')",
][$bucket];
$tsRows = q($pdo, "SELECT $bucketExpr AS b, SUM($IS_START) AS launches, COUNT(DISTINCT t.machine_id) AS devices
                   $FROM $W GROUP BY b ORDER BY b", $P);
// Weekday (0 = Monday) x hour heatmap of launches.
$heat = array_map(fn($r) => [(int) $r['h'], (int) $r['wd'], (int) $r['n']],
    q($pdo, "SELECT WEEKDAY(t.created_at) AS wd, HOUR(t.created_at) AS h, SUM($IS_START) AS n
             $FROM $W GROUP BY wd, h HAVING n > 0", $P));

$orgList = q($pdo, "SELECT $ORG AS target_name,
                           MAX(c.org) AS isp_org,
                           MAX(c.country) AS country,
                           COUNT(DISTINCT t.machine_id) AS device_count,
                           COUNT(DISTINCT NULLIF(t.disk_serial, '')) AS unique_disks,
                           COUNT(DISTINCT NULLIF(t.mb_uuid, '')) AS unique_mbs,
                           COUNT(DISTINCT NULLIF(t.license_key, '')) AS licenses,
                           SUM($IS_START) AS launch_count,
                           MAX(t.created_at) AS last_seen
                    $FROM $W
                    GROUP BY target_name
                    ORDER BY device_count DESC, launch_count DESC
                    LIMIT 30", $P);

$countries = q($pdo, "SELECT COALESCE(NULLIF(c.country, ''), '未知') AS name, COUNT(DISTINCT t.machine_id) AS n
                      $FROM $W GROUP BY name ORDER BY n DESC LIMIT 10", $P);
$versions  = q($pdo, "SELECT COALESCE(NULLIF(t.app_version, ''), '未知') AS name, COUNT(DISTINCT t.machine_id) AS n
                      $FROM $W GROUP BY name ORDER BY n DESC LIMIT 10", $P);
$osStats   = q($pdo, "SELECT COALESCE(NULLIF(t.os_version, ''), '未知') AS name, COUNT(DISTINCT t.machine_id) AS n
                      $FROM $W GROUP BY name ORDER BY n DESC LIMIT 8", $P);
$archStats = q($pdo, "SELECT COALESCE(NULLIF(t.arch, ''), '未知') AS name, COUNT(DISTINCT t.machine_id) AS n
                      $FROM $W GROUP BY name ORDER BY n DESC", $P);

$licenses = q($pdo, "SELECT t.license_key,
                            COUNT(DISTINCT t.machine_id) AS devices,
                            COUNT(DISTINCT $ORG) AS orgs,
                            SUM($IS_START) AS launches,
                            MAX(t.created_at) AS last_seen
                     $FROM $W AND t.license_key <> ''
                     GROUP BY t.license_key
                     ORDER BY devices DESC, launches DESC
                     LIMIT 15", $P);

$recent = q($pdo, "SELECT t.created_at, $EVENT_COL AS event, t.hostname, t.public_ip, $ORG AS org, c.country,
                          t.app_version, t.os_version, t.arch, t.machine_id, t.license_key
                   $FROM $W ORDER BY t.created_at DESC LIMIT 50", $P);

$deviceDetails = [];
if ($selectedTarget !== '') {
    $deviceDetails = q($pdo, "SELECT t.machine_id,
                                     MAX(t.hostname) AS hostname,
                                     MAX(t.local_ips) AS local_ips,
                                     MAX(t.public_ip) AS public_ip,
                                     MAX(t.os_version) AS os_version,
                                     MAX(t.arch) AS arch,
                                     MAX(t.cpu_id) AS cpu_id,
                                     MAX(t.mb_uuid) AS mb_uuid,
                                     MAX(t.disk_serial) AS disk_serial,
                                     MAX(t.license_key) AS license_key,
                                     MAX(t.app_version) AS app_version,
                                     SUM($IS_START) AS run_count,
                                     MAX(t.created_at) AS last_active
                              $FROM $W AND $ORG = :target
                              GROUP BY t.machine_id
                              ORDER BY last_active DESC", $P + [':target' => $selectedTarget]);
}

// Version list for the filter (not narrowed by the current filters, so every version stays selectable).
$allVersions = array_column(q($pdo, "SELECT DISTINCT app_version FROM telemetry_logs
                                     WHERE app_version <> '' ORDER BY app_version DESC LIMIT 50"), 'app_version');

}   // end of real (database) branch

$live = ['labels' => [], 'counts' => [], 'm1' => 0, 'm5' => 0, 'dev5' => $liveDev5];
for ($i = 299; $i >= 0; $i--) {
    $k = $nowDt->modify("-{$i} second")->format('Y-m-d H:i:s');
    $n = (int) ($livePerSec[$k] ?? 0);
    $live['m5'] += $n;
    if ($i < 60) {
        $live['m1'] += $n;
        $live['labels'][] = substr($k, 11);
        $live['counts'][] = $n;
    }
}

$crackMarkers = (array) ($tcfg['crack_markers'] ?? []);
if ($demo) {
    $crackMarkers[] = DEMO_CRACK;   // the demo's fake "cracked copy" marker
}
$geo = monitor_geo_aggregate($geoRows, $overKeyList, $cloneOrgList, $crackMarkers);
// Map tiles: Geoapify (https://www.geoapify.com), drawn with Leaflet. Only the API key is needed for tiles.
$geoapify = $config['geoapify'] ?? [];
$mapsKey = trim((string) ($geoapify['api_key'] ?? ''));
// Style names go into the tile URL: allow only Geoapify's style-name characters.
$mapStyle = fn($v, $def) => preg_match('/^[a-z0-9-]{1,40}$/', (string) $v) ? (string) $v : $def;
$styleLight = $mapStyle($geoapify['style_light'] ?? '', 'osm-bright-grey');
$styleDark  = $mapStyle($geoapify['style_dark'] ?? '', 'dark-matter-brown');

$tsMap = [];
foreach ($tsRows as $r) {
    $tsMap[$r['b']] = $r;
}
$series = ['labels' => [], 'launches' => [], 'devices' => []];
$start = $since !== null ? new DateTime($since) : (!empty($tsRows) ? new DateTime($tsRows[0]['b']) : null);
if ($start !== null) {
    // Align the first bucket down to its boundary (10 s / minute / hour / day), then step one bucket at a time.
    [$bSec, $fmt] = $BUCKETS[$bucket];
    $H = (int) $start->format('H');
    $M = (int) $start->format('i');
    $S = (int) $start->format('s');
    switch ($bucket) {   // (switch, not match: keeps the page working on PHP 7.4)
        case '10s':  $start->setTime($H, $M, $S - $S % 10); break;
        case 'min':  $start->setTime($H, $M, 0); break;
        case 'hour': $start->setTime($H, 0, 0); break;
        default:     $start->setTime(0, 0, 0);
    }
    $end  = new DateTime($dbNow);
    if ($bucket === '10s') {
        $end->modify('-10 second');   // leave out the 10 s still in progress: a half-full last point looks like a drop
    }
    $step = new DateInterval('PT' . $bSec . 'S');
    for ($i = 0; $start <= $end && $i < 5000; $i++, $start->add($step)) {
        $k = $start->format($fmt);
        $series['labels'][]   = $k;
        $series['launches'][] = (int) ($tsMap[$k]['launches'] ?? 0);
        $series['devices'][]  = (int) ($tsMap[$k]['devices'] ?? 0);
    }
}


/** "+12% 較前期" text for a stat tile; '' when there is no previous period. */
function delta_html(string $key): string {
    global $kpi, $kpiPrev;
    if ($kpiPrev === null) {
        return '<span class="tile-delta">全部時間，無前期比較</span>';
    }
    $cur = (int) $kpi[$key];
    $prev = (int) $kpiPrev[$key];
    if ($prev === 0) {
        return '<span class="tile-delta">' . ($cur > 0 ? '前期無資料' : '與前期相同') . '</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $arrow = $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '–');
    $pctText = round($pct) == 0 ? '0%' : sprintf('%+.0f%%', $pct);
    return sprintf('<span class="tile-delta"><b>%s %s</b> 較前期 (%s)</span>', $arrow, $pctText, number_format($prev));
}

$archTotal = array_sum(array_column($archStats, 'n'));

// Everything the charts need, as JSON in a <script type="application/json"> block. The JSON_HEX_* flags turn
// < > & ' " into \u escapes: hostnames/versions come from api/collect.php (anyone can post), so a value like
// "</script><script>..." must not be able to break out of the block.
$viz = [
    'bucket'    => $bucket,
    'demo'      => $demo,
    'series'    => $series,
    'heat'      => $heat,
    'orgs'      => array_map(fn($r) => ['name' => (string) $r['target_name'], 'n' => (int) $r['device_count']],
                       array_slice($orgList, 0, 10)),
    'countries' => array_map(fn($r) => ['name' => (string) $r['name'], 'n' => (int) $r['n']], $countries),
    'versions'  => array_map(fn($r) => ['name' => (string) $r['name'], 'n' => (int) $r['n']], $versions),
    'os'        => array_map(fn($r) => ['name' => (string) $r['name'], 'n' => (int) $r['n']], $osStats),
    'live'      => ['labels' => $live['labels'], 'counts' => $live['counts']],
    'geo'       => ['points' => $geo['points'], 'continents' => $geo['continents'],
                    'countries' => array_slice($geo['countries'], 0, 10)],
    // The tile API key always reaches the browser (every tile request carries it): restrict it to this
    // site's URL in the Geoapify project settings -- see config.sample.php.
    'maps'      => ['key' => $mapsKey, 'light' => $styleLight, 'dark' => $styleDark],
];
$vizJson = json_encode($viz, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>授權監控中心</title>
<script nonce="<?= h($cspNonce) ?>">
// Apply the saved theme before first paint (no light/dark flash). Storage can be blocked: ignore errors.
try { const t = localStorage.getItem('monitor-theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<script src="echarts.min.js"></script>
<?php if ($mapsKey !== '' && $geoError === ''): ?>
<!-- Leaflet 1.9.4 for the Geoapify map. integrity = the published hashes: a changed file on the CDN is refused. -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php endif; ?>
<style>
:root {
    color-scheme: light;
    --page: #eef2f7;
    --page-glow-1: rgba(42, 120, 214, 0.14);
    --page-glow-2: rgba(27, 175, 122, 0.10);
    --surface: #fcfcfb;
    --surface-2: #f3f2ee;
    --ink: #0b0b0b;
    --ink-2: #52514e;
    --muted: #898781;
    --grid: #e1e0d9;
    --axis: #c3c2b7;
    --border: rgba(11, 11, 11, 0.10);
    --series-1: #2a78d6;
    --series-2: #eb6834;
    --series-3: #1baf7a;
    --accent: #2a78d6;
    --good: #0ca30c;
    --good-text: #006300;
    --serious: #ec835a;
    --critical: #d03b3b;
    --seq: #cde2fb,#86b6ef,#3987e5,#1c5cab,#0d366b;
    --violet: #6250d6;
    --warning: #fab219;
    --tint: 14%;          /* how strongly a tile/card is tinted with its tone */
    --bar-top: #0f2a4a;   /* top bar gradient (same in both themes, white text on it) */
    --bar-bottom: #1c5cab;
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        color-scheme: dark;
        --page: #0a0e14;
        --page-glow-1: rgba(57, 135, 229, 0.20);
        --page-glow-2: rgba(25, 158, 112, 0.12);
        --surface: #1a1a19;
        --surface-2: #232321;
        --ink: #f0efec;
        --ink-2: #c3c2b7;
        --muted: #898781;
        --grid: #2c2c2a;
        --axis: #383835;
        --border: rgba(255, 255, 255, 0.10);
        --series-1: #3987e5;
        --series-2: #d95926;
        --series-3: #199e70;
        --accent: #3987e5;
        --good-text: #0ca30c;
        --seq: #184f95,#2a78d6,#5598e7,#9ec5f4,#cde2fb;
        --violet: #9085e9;
        --tint: 20%;
        --bar-top: #0b1e36;
        --bar-bottom: #184f95;
    }
}
:root[data-theme="dark"] {
    color-scheme: dark;
    --page: #0a0e14;
    --page-glow-1: rgba(57, 135, 229, 0.20);
    --page-glow-2: rgba(25, 158, 112, 0.12);
    --surface: #1a1a19;
    --surface-2: #232321;
    --ink: #f0efec;
    --ink-2: #c3c2b7;
    --muted: #898781;
    --grid: #2c2c2a;
    --axis: #383835;
    --border: rgba(255, 255, 255, 0.10);
    --series-1: #3987e5;
    --series-2: #d95926;
    --series-3: #199e70;
    --accent: #3987e5;
    --good-text: #0ca30c;
    --seq: #184f95,#2a78d6,#5598e7,#9ec5f4,#cde2fb;
    --violet: #9085e9;
    --tint: 20%;
    --bar-top: #0b1e36;
    --bar-bottom: #184f95;
}

* { box-sizing: border-box; }
body { margin: 0; color: var(--ink); min-height: 100vh;
       background:
           radial-gradient(1100px 520px at 0% -10%, var(--page-glow-1), transparent 65%),
           radial-gradient(900px 480px at 100% 0%, var(--page-glow-2), transparent 60%),
           var(--page);
       background-attachment: fixed; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif; }
a { color: var(--accent); }
.wrap { max-width: 1440px; margin: 0 auto; padding: 0 24px 48px; }

/* Top bar */
.topbar { position: sticky; top: 0; z-index: 10; color: #fff; border-bottom: 1px solid rgba(255,255,255,.12);
          background: linear-gradient(100deg, var(--bar-top) 0%, var(--bar-bottom) 55%, var(--bar-top) 100%);
          box-shadow: 0 2px 12px rgba(0,0,0,.18); }
.topbar .brand small, .topbar .who { color: rgba(255,255,255,.75); }
.topbar .btn { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.22); color: #fff; }
.topbar .btn:hover { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.4); }
.topbar .btn:focus-visible { outline-color: #fff; }
.topbar .wrap { display: flex; align-items: center; gap: 12px; padding-top: 10px; padding-bottom: 10px; flex-wrap: wrap; }
.brand { font-size: 16px; font-weight: 600; margin: 0; margin-right: auto; }
.brand small { font-weight: 400; color: var(--muted); margin-left: 8px; font-size: 12px; }
.who { color: var(--ink-2); font-size: 13px; }
.btn { display: inline-flex; align-items: center; height: 32px; padding: 0 12px; border-radius: 6px; border: 1px solid var(--border);
       background: var(--surface-2); color: var(--ink); text-decoration: none; font: inherit; font-size: 13px; cursor: pointer; }
.btn:hover { border-color: var(--axis); }
.btn:focus-visible, select:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

/* Filter row: one row, scopes everything below */
.filters { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; padding: 16px 0; }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; background: var(--surface); }
.seg a { padding: 6px 12px; color: var(--ink-2); text-decoration: none; font-size: 13px; border-right: 1px solid var(--border); white-space: nowrap; }
@media (max-width: 640px) { .seg { flex-wrap: wrap; } .seg a { padding: 6px 10px; } }
.seg a:last-child { border-right: 0; }
.seg a:hover { background: var(--surface-2); }
.seg a[aria-current="true"] { color: var(--ink); font-weight: 600; background: var(--surface-2); box-shadow: inset 0 -2px 0 var(--accent); }
.field { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }
select { height: 32px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); color: var(--ink); font: inherit; font-size: 13px; padding: 0 8px; }
.chip { display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 4px 0 10px; border-radius: 14px; background: var(--surface-2);
        border: 1px solid var(--border); font-size: 13px; }
.chip a { text-decoration: none; color: var(--muted); padding: 0 6px; font-size: 15px; }
.updated { margin-left: auto; color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }

/* Stat tiles */
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.tile { --tone: var(--series-1); position: relative; overflow: hidden; border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px;
        background: linear-gradient(140deg, color-mix(in srgb, var(--tone) var(--tint), var(--surface)) 0%, var(--surface) 78%);
        box-shadow: inset 0 3px 0 var(--tone), 0 1px 2px rgba(0,0,0,.06); }
.tile::after { content: ""; position: absolute; right: -28px; bottom: -28px; width: 96px; height: 96px; border-radius: 50%;
               background: var(--tone); opacity: .08; }
.tone-blue   { --tone: var(--series-1); }
.tone-aqua   { --tone: var(--series-3); }
.tone-violet { --tone: var(--violet); }
.tone-orange { --tone: var(--series-2); }
.tone-good   { --tone: var(--good); }
.tone-warn   { --tone: var(--serious); }
.tone-crit   { --tone: var(--critical); }
.tile-label { color: var(--ink-2); font-size: 13px; }
.tile-value { font-size: 28px; font-weight: 600; line-height: 1.25; margin: 4px 0 2px; }
.tile-delta { color: var(--muted); font-size: 12px; }
.tile-delta b { color: var(--ink-2); font-weight: 600; }
.status { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--ink-2); }
.status i { font-style: normal; display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%; color: #fff; font-size: 11px; font-weight: 700; }
.status.ok i { background: var(--good); }
.status.bad i { background: var(--critical); }
.status.warn i { background: var(--serious); }

/* Cards & grid */
.grid { display: grid; gap: 16px; margin-bottom: 16px; }
.g-2 { grid-template-columns: repeat(auto-fit, minmax(min(100%, 480px), 1fr)); }
.g-3 { grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); }
.card { --tone: var(--accent); border: 1px solid var(--border); border-radius: 8px; padding: 16px; min-width: 0;
        background: linear-gradient(180deg, color-mix(in srgb, var(--tone) calc(var(--tint) / 2), var(--surface)) 0, var(--surface) 64px);
        box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.card-h h2::before { content: ""; display: inline-block; width: 3px; height: 14px; border-radius: 2px; background: var(--tone);
                     margin-right: 8px; vertical-align: -2px; }
.filters { background: color-mix(in srgb, var(--surface) 70%, transparent); border: 1px solid var(--border); border-radius: 8px;
           padding: 10px 12px !important; margin: 16px 0; backdrop-filter: blur(6px); }

/* Demo mode banner: warning tone, always with icon + words (never color alone) */
.demo-banner { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; padding: 10px 14px; border-radius: 8px;
               border: 1px solid color-mix(in srgb, var(--warning) 55%, transparent);
               background: repeating-linear-gradient(135deg, color-mix(in srgb, var(--warning) 16%, var(--surface)) 0 14px,
                                                             color-mix(in srgb, var(--warning) 9%, var(--surface)) 14px 28px); }
.demo-banner i { font-style: normal; display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 50%;
                 background: var(--warning); color: #0b0b0b; font-weight: 700; font-size: 13px; }
.demo-banner .btn { margin-left: auto; }
.demo-link { font-size: 13px; text-decoration: none; padding: 5px 10px; border-radius: 6px; border: 1px dashed var(--axis); color: var(--ink-2); }
.demo-link:hover { border-style: solid; color: var(--ink); }
.card-h { display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; }
.card-h h2 { margin: 0; font-size: 14px; font-weight: 600; }
.card-h .sub { color: var(--muted); font-size: 12px; }
.card-h .right { margin-left: auto; }
.chart { width: 100%; height: 260px; }
.chart.tall { height: 300px; }
.empty { display: grid; place-items: center; height: 100%; color: var(--muted); font-size: 13px; text-align: center; }

/* Geography: map + continents side by side, country table below */
.g-map { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
@media (max-width: 1000px) { .g-map { grid-template-columns: 1fr; } }
.map { width: 100%; height: 440px; border-radius: 6px; overflow: hidden; background: var(--surface-2); }
.map .empty { padding: 24px; line-height: 1.7; }
.map .empty code { background: var(--surface); padding: 1px 6px; border-radius: 4px; font-size: 12px; }
.map-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 10px; font-size: 12px; color: var(--ink-2); align-items: center; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: -1px;
       box-shadow: 0 0 0 2px var(--surface); }
.map.leaflet-container { font: inherit; }

/* Live panel + live indicator */
.live-grid { display: grid; grid-template-columns: minmax(0, 260px) minmax(0, 1fr); gap: 16px; align-items: center; }
@media (max-width: 760px) { .live-grid { grid-template-columns: 1fr; } }
.live-stats { display: grid; gap: 8px; }
.live-stats div { display: flex; align-items: baseline; gap: 8px; }
.live-stats span { color: var(--ink-2); font-size: 13px; width: 96px; flex: none; }
.live-stats b { font-size: 22px; font-weight: 600; }
.live-stats small { color: var(--muted); font-size: 12px; }
.live-pill { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border-radius: 13px; font-size: 12px;
             color: var(--ink-2); border: 1px solid var(--border); background: var(--surface); }
.live-pill[hidden] { display: none; }   /* display:inline-flex above would otherwise beat the hidden attribute */
.live-pill i { width: 8px; height: 8px; border-radius: 50%; background: var(--good); }
.live-pill.on i { animation: livepulse 1.6s ease-in-out infinite; }
.live-pill.err i { background: var(--critical); animation: none; }
@keyframes livepulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
@media (prefers-reduced-motion: reduce) { .live-pill.on i { animation: none; } }
.map-note { background: var(--surface); color: var(--ink); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px;
            font-size: 12px; max-width: 320px; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.leaflet-interactive:hover { fill-opacity: 1; }
.iw { font: 13px/1.5 system-ui, "Microsoft JhengHei", sans-serif; color: #0b0b0b; min-width: 180px; }
.iw b { font-size: 15px; }
.iw .s { color: #b02a2a; font-weight: 600; }
.iw .o { color: #52514e; font-size: 12px; margin-top: 4px; }
.ev { display: inline-block; padding: 0 8px; border-radius: 10px; font-size: 12px; border: 1px solid var(--border); }
.ev-start { background: color-mix(in srgb, var(--series-1) 18%, transparent); }
.ev-heartbeat { background: color-mix(in srgb, var(--series-3) 18%, transparent); }
.ev-manual { background: color-mix(in srgb, var(--violet) 18%, transparent); }
.ratio { display: inline-block; width: 80px; height: 8px; border-radius: 4px; background: var(--grid); vertical-align: middle;
         margin-right: 8px; overflow: hidden; }
.ratio span { display: block; height: 100%; background: var(--critical); border-radius: 4px; }
.card.focus { border-color: var(--accent); }
.card.focus td.mono { white-space: nowrap; }

/* Arch proportion bar */
.pbar { display: flex; gap: 2px; height: 28px; border-radius: 4px; overflow: hidden; margin: 12px 0; }
.pbar span { display: block; min-width: 2px; }
.legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; color: var(--ink-2); }
.legend .key { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
.legend b { color: var(--ink); font-weight: 600; margin-left: 4px; }

/* Tables */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-weight: 500; color: var(--muted); padding: 8px 10px; border-bottom: 1px solid var(--axis); white-space: nowrap; position: sticky; top: 0; background: var(--surface); }
td { padding: 8px 10px; border-bottom: 1px solid var(--grid); vertical-align: top; }
tbody tr:hover { background: var(--surface-2); }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
.mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 12px; }
.muted { color: var(--muted); }
.nowrap { white-space: nowrap; }
.tbl-scroll { max-height: 440px; overflow: auto; }
.flag { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; white-space: nowrap; color: var(--ink-2); }
.flag::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--serious); }
.flag.crit::before { background: var(--critical); }
details.raw summary { cursor: pointer; color: var(--muted); font-size: 12px; margin-top: 8px; }

@media (max-width: 640px) {
    .wrap { padding: 0 16px 32px; }
    .updated { margin-left: 0; width: 100%; }
    .tile-value { font-size: 24px; }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="wrap">
        <h1 class="brand">授權監控中心<small>軟體遙測 · 異常組織偵測</small></h1>
        <span class="who"><?= h($account) ?></span>
        <button type="button" class="btn" id="themeBtn" title="切換亮色 / 暗色">主題：自動</button>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" name="logout" value="1" class="btn">登出</button>
        </form>
    </div>
</header>

<main class="wrap">

    <?php if ($demo): ?>
    <div class="demo-banner" role="status">
        <i>!</i>
        <span><b>示範模式</b>：以下所有數字、組織、電腦與授權碼都是程式產生的假資料，沒有查詢資料庫。篩選條件可以照常操作。</span>
        <a class="btn" href="<?= h(url_with(['demo' => '', 'target' => ''])) ?>">離開示範模式</a>
    </div>
    <?php endif; ?>

    <!-- Filters: one row, above everything they scope -->
    <form class="filters" method="get" id="filterForm">
        <nav class="seg" aria-label="時間範圍">
            <?php foreach ($RANGES as $k => [$label]): ?>
                <a href="<?= h(url_with(['range' => $k])) ?>" aria-current="<?= $k === $range ? 'true' : 'false' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <input type="hidden" name="range" value="<?= h($range) ?>">
        <?php if ($selectedTarget !== ''): ?><input type="hidden" name="target" value="<?= h($selectedTarget) ?>"><?php endif; ?>
        <?php if ($demo): ?><input type="hidden" name="demo" value="1"><?php endif; ?>
        <label class="field">軟體版本
            <select name="ver" onchange="this.form.submit()">
                <option value="">全部版本</option>
                <?php foreach ($allVersions as $v): ?>
                    <option value="<?= h($v) ?>" <?= $v === $ver ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($selectedTarget !== ''): ?>
            <span class="chip">組織：<?= h($selectedTarget) ?><a href="<?= h(url_with(['target' => ''])) ?>" aria-label="移除組織篩選">×</a></span>
        <?php endif; ?>
        <label class="field">即時更新
            <select id="refreshSel">
                <option value="0">關閉</option>
                <option value="1">每秒</option>
                <option value="5">5 秒</option>
                <option value="10">10 秒</option>
                <option value="30">30 秒</option>
                <option value="60">1 分鐘</option>
            </select>
        </label>
        <span class="live-pill" id="livePill" hidden><i></i><span>即時</span></span>
        <span class="updated" data-live="updated">資料時間 <?= h($dbNow) ?><?= $demo ? '（示範）' : '' ?></span>
        <?php if (!$demo): ?><a class="demo-link" href="<?= h(url_with(['demo' => '1', 'target' => '', 'ver' => '', 'range' => '15m'])) ?>">看示範資料</a><?php endif; ?>
    </form>

    <!-- Stat tiles -->
    <section class="tiles" aria-label="摘要" data-live="tiles">
        <div class="tile tone-blue">
            <div class="tile-label">啟動次數</div>
            <div class="tile-value"><?= compact_num($kpi['launches']) ?></div>
            <?= delta_html('launches') ?>
        </div>
        <div class="tile tone-aqua">
            <div class="tile-label">活躍裝置</div>
            <div class="tile-value"><?= compact_num($kpi['devices']) ?></div>
            <?= delta_html('devices') ?>
        </div>
        <div class="tile tone-violet">
            <div class="tile-label">組織 / 網域</div>
            <div class="tile-value"><?= compact_num($kpi['orgs']) ?></div>
            <?= delta_html('orgs') ?>
        </div>
        <div class="tile tone-orange">
            <div class="tile-label">使用中的授權碼</div>
            <div class="tile-value"><?= compact_num($kpi['licenses']) ?></div>
            <?= delta_html('licenses') ?>
        </div>
        <div class="tile <?= $overLicensed > 0 ? 'tone-warn' : 'tone-good' ?>">
            <div class="tile-label">授權碼超用</div>
            <div class="tile-value"><?= number_format($overLicensed) ?></div>
            <?php if ($overLicensed > 0): ?>
                <span class="status warn"><i>!</i>超過 <?= $licenseLimit ?> 台裝置的授權碼</span>
            <?php else: ?>
                <span class="status ok"><i>✓</i>沒有超用</span>
            <?php endif; ?>
        </div>
        <div class="tile <?= $vmCloneOrgs > 0 ? 'tone-crit' : 'tone-good' ?>">
            <div class="tile-label">疑似 VM 克隆組織</div>
            <div class="tile-value"><?= number_format($vmCloneOrgs) ?></div>
            <?php if ($vmCloneOrgs > 0): ?>
                <span class="status bad"><i>!</i>主機板數 &gt; 硬碟數</span>
            <?php else: ?>
                <span class="status ok"><i>✓</i>未偵測到</span>
            <?php endif; ?>
        </div>
    </section>

    <!-- Live: the last 60 seconds, independent of the range filter -->
    <section class="card tone-aqua" style="margin-bottom:16px">
        <div class="card-h"><h2>即時動態</h2><span class="sub">最近 60 秒每秒啟動次數（不受時間範圍影響）</span></div>
        <div class="live-grid">
            <div class="live-stats" data-live="livestats">
                <div><span>最近 1 分鐘</span><b><?= number_format($live['m1']) ?></b><small>次啟動</small></div>
                <div><span>最近 5 分鐘</span><b><?= number_format($live['m5']) ?></b><small>次啟動</small></div>
                <div><span>5 分鐘內活躍</span><b><?= number_format($live['dev5']) ?></b><small>台裝置</small></div>
            </div>
            <div class="chart" id="chLive" style="height:150px"></div>
        </div>
    </section>

    <?php if ($selectedTarget !== ''): ?>
    <!-- Drill-down -->
    <section class="card focus" style="margin-bottom:16px">
        <div class="card-h">
            <h2>組織內電腦明細：<?= h($selectedTarget) ?></h2>
            <span class="sub" data-live="detailcount">共 <?= count($deviceDetails) ?> 台</span>
            <a class="btn right" href="<?= h(url_with(['target' => ''])) ?>">關閉明細</a>
        </div>
        <div class="tbl-wrap tbl-scroll">
            <table>
                <thead><tr>
                    <th>電腦名稱</th><th>內網 IP</th><th>對外 IP</th><th>OS / 架構</th><th>版本</th>
                    <th>授權碼</th><th>硬碟序號</th><th>主機板 UUID</th><th>CPU ID</th>
                    <th class="num">啟動次數</th><th>最後活動</th>
                </tr></thead>
                <tbody data-live="detail">
                <?php foreach ($deviceDetails as $d): ?>
                    <tr>
                        <td><b><?= h($d['hostname'] ?: '-') ?></b></td>
                        <td class="mono"><?= h($d['local_ips'] ?: '-') ?></td>
                        <td class="mono"><?= h($d['public_ip']) ?></td>
                        <td class="nowrap"><?= h($d['os_version']) ?> (<?= h($d['arch']) ?>)</td>
                        <td class="mono"><?= h($d['app_version'] ?: '-') ?></td>
                        <td class="mono"><?= h($d['license_key'] ?: '-') ?></td>
                        <td class="mono"><?= h($d['disk_serial'] ?: '-') ?></td>
                        <td class="mono muted"><?= h($d['mb_uuid'] ?: '-') ?></td>
                        <td class="mono muted"><?= h($d['cpu_id'] ?: '-') ?></td>
                        <td class="num"><?= number_format($d['run_count']) ?></td>
                        <td class="mono muted nowrap"><?= h($d['last_active']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($deviceDetails)): ?>
                    <tr><td colspan="11" class="muted" style="text-align:center;padding:24px">此時間範圍內沒有這個組織的紀錄</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- Activity over time: two charts, never two y-axes on one -->
    <section class="grid g-2">
        <div class="card">
            <div class="card-h"><h2>啟動次數</h2><span class="sub"><?= $BUCKETS[$bucket][2] ?> · 拖曳或滾輪可縮放</span></div>
            <div class="chart" id="chLaunches"></div>
        </div>
        <div class="card tone-aqua">
            <div class="card-h"><h2>活躍裝置</h2><span class="sub"><?= $BUCKETS[$bucket][2] ?>不重複裝置數</span></div>
            <div class="chart" id="chDevices"></div>
        </div>
    </section>

    <!-- Geography: map of IP locations + suspected unlicensed use by continent / country -->
    <section class="grid g-map">
        <div class="card tone-blue">
            <div class="card-h"><h2>地理分佈</h2>
                <span class="sub">依 IP 推算位置（城市等級，常為電信商所在地）· 點圓點看明細</span>
                <?php if ($mapsKey !== ''): ?>
                <label class="field right">底圖
                    <select id="mapStyle">
                        <option value="auto">跟隨主題</option>
                        <option value="osm-bright-grey">標準</option>
                        <option value="klokantech-basic">彩色</option>
                        <option value="positron">淺灰</option>
                        <option value="dark-matter-brown">深灰</option>
                        <option value="dark-matter">黑色</option>
                    </select>
                </label>
                <?php endif; ?></div>
            <div class="map" id="gmap">
                <?php if ($geoError !== ''): ?>
                    <div class="empty"><?= h($geoError) ?></div>
                <?php elseif ($mapsKey === ''): ?>
                    <div class="empty"><div>
                        <b>尚未設定 Geoapify 金鑰</b><br>
                        在 <code>api/config/config.php</code> 加上 <code>'geoapify' =&gt; ['api_key' =&gt; '…']</code>
                        （設定方式見 <code>config.sample.php</code>）。<br>下方的洲別與國家統計不需要金鑰。
                    </div></div>
                <?php elseif (empty($geo['points'])): ?>
                    <div class="empty">此時間範圍內沒有可定位的裝置</div>
                <?php endif; ?>
            </div>
            <div class="map-legend">
                <span><span class="dot" style="background:var(--series-1)"></span>只有正常裝置</span>
                <span><span class="dot" style="background:var(--critical)"></span>含疑似盜用裝置</span>
                <span>圓點大小 = 裝置數</span>
                <span class="muted" style="margin-left:auto" data-live="located">已定位 <?= number_format($geo['located']) ?> / <?= number_format($geo['devices']) ?> 台</span>
            </div>
        </div>
        <div class="card tone-crit">
            <div class="card-h"><h2>五大洲疑似盜用</h2><span class="sub">不重複裝置</span></div>
            <div class="chart" id="chContinents" style="height:300px"></div>
            <div class="tbl-wrap">
                <table>
                    <thead><tr><th>洲別</th><th class="num">裝置</th><th class="num">疑似盜用</th><th class="num">比例</th></tr></thead>
                    <tbody data-live="continents">
                    <?php foreach ($geo['continents'] as $c): ?>
                        <tr><td><?= h($c['name']) ?></td><td class="num"><?= number_format($c['n']) ?></td>
                            <td class="num"><?= number_format($c['s']) ?></td>
                            <td class="num"><?= $c['n'] ? round($c['s'] / $c['n'] * 100) . '%' : '-' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card tone-crit" style="margin-bottom:16px">
        <div class="card-h"><h2>各國疑似盜用統計</h2>
            <span class="sub" data-live="suspectcount">疑似盜用 = 授權碼超用、所屬組織疑似 VM 克隆，或回報破解標記的裝置 · 共 <?= number_format($geo['suspect']) ?> 台</span></div>
        <div class="grid g-2" style="margin-bottom:0">
            <div class="chart tall" id="chCountrySuspect"></div>
            <div class="tbl-wrap tbl-scroll" style="max-height:300px">
                <table>
                    <thead><tr><th>國家 / 地區</th><th>洲別</th><th class="num">裝置</th><th class="num">疑似盜用</th><th>疑似比例</th></tr></thead>
                    <tbody data-live="countries">
                    <?php foreach ($geo['countries'] as $c): $pct = $c['n'] ? $c['s'] / $c['n'] * 100 : 0; ?>
                        <tr><td><?= h($c['name']) ?></td><td class="muted"><?= h($c['continent']) ?></td>
                            <td class="num"><?= number_format($c['n']) ?></td><td class="num"><?= number_format($c['s']) ?></td>
                            <td class="nowrap"><span class="ratio"><span style="width:<?= round($pct) ?>%"></span></span><?= round($pct) ?>%</td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($geo['countries'])): ?>
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">此時間範圍內沒有資料</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="grid g-2">
        <div class="card">
            <div class="card-h"><h2>使用時段熱力圖</h2><span class="sub">星期 × 小時的啟動次數（伺服器時間）</span></div>
            <div class="chart tall" id="chHeat"></div>
        </div>
        <div class="card">
            <div class="card-h"><h2>裝置數前 10 名組織</h2><span class="sub">點長條查看該組織的電腦明細</span></div>
            <div class="chart tall" id="chOrgs"></div>
        </div>
    </section>

    <section class="grid g-3">
        <div class="card">
            <div class="card-h"><h2>國家 / 地區</h2><span class="sub">不重複裝置</span></div>
            <div class="chart" id="chCountries"></div>
        </div>
        <div class="card">
            <div class="card-h"><h2>軟體版本</h2><span class="sub">不重複裝置</span></div>
            <div class="chart" id="chVersions"></div>
        </div>
        <div class="card">
            <div class="card-h"><h2>作業系統</h2><span class="sub">不重複裝置</span></div>
            <div class="chart" id="chOs"></div>
        </div>
    </section>

    <section class="grid g-2">
        <div class="card">
            <div class="card-h"><h2>系統架構</h2><span class="sub">不重複裝置</span></div>
            <div data-live="arch">
            <?php if ($archTotal > 0): ?>
                <?php // Color follows the architecture, not its rank: x64 / x86 / ARM64 keep their hue under any filter.
                      $archColorOf = fn($name) => [
                          'x64' => 'var(--series-1)', 'amd64' => 'var(--series-1)', 'x86_64' => 'var(--series-1)',
                          'x86' => 'var(--series-2)', 'i386' => 'var(--series-2)', 'i686' => 'var(--series-2)',
                          'arm64' => 'var(--series-3)', 'aarch64' => 'var(--series-3)',
                      ][strtolower((string) $name)] ?? 'var(--muted)';
                      $archShown = array_slice($archStats, 0, 3);
                      $archOther = array_sum(array_column(array_slice($archStats, 3), 'n'));
                      if ($archOther > 0) { $archShown[] = ['name' => '其他', 'n' => $archOther]; } ?>
                <div class="pbar" role="img" aria-label="系統架構比例">
                    <?php foreach ($archShown as $a): ?>
                        <span style="flex:<?= (int) $a['n'] ?>;background:<?= $archColorOf($a['name']) ?>"
                              title="<?= h($a['name']) ?>：<?= (int) $a['n'] ?> 台"></span>
                    <?php endforeach; ?>
                </div>
                <div class="legend">
                    <?php foreach ($archShown as $a): ?>
                        <span><span class="key" style="background:<?= $archColorOf($a['name']) ?>"></span><?= h($a['name']) ?>
                            <b><?= round($a['n'] / $archTotal * 100) ?>%</b> <span class="muted">(<?= number_format($a['n']) ?> 台)</span></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty" style="height:80px">此時間範圍內沒有資料</div>
            <?php endif; ?>
            </div>
        </div>
        <div class="card tone-orange">
            <div class="card-h"><h2>授權碼使用排行</h2><span class="sub">每組授權碼允許 <?= $licenseLimit ?> 台</span></div>
            <div class="tbl-wrap tbl-scroll" style="max-height:240px">
                <table>
                    <thead><tr><th>授權碼</th><th class="num">裝置</th><th class="num">組織</th><th class="num">啟動</th><th>最後使用</th><th></th></tr></thead>
                    <tbody data-live="licenses">
                    <?php foreach ($licenses as $l): ?>
                        <tr>
                            <td class="mono"><?= h($l['license_key']) ?></td>
                            <td class="num"><?= number_format($l['devices']) ?></td>
                            <td class="num"><?= number_format($l['orgs']) ?></td>
                            <td class="num"><?= number_format($l['launches']) ?></td>
                            <td class="mono muted nowrap"><?= h($l['last_seen']) ?></td>
                            <td><?php if ((int) $l['devices'] > $licenseLimit): ?><span class="flag">超用</span><?php endif; ?>
                                <?php if ((int) $l['orgs'] > 1): ?><span class="flag crit">跨組織</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($licenses)): ?>
                        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">此時間範圍內沒有帶授權碼的紀錄</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Organisation leaderboard -->
    <section class="card tone-crit" style="margin-bottom:16px">
        <div class="card-h"><h2>潛在盜用 / 異常組織排行</h2><span class="sub">前 30 名，點名稱查看電腦明細</span></div>
        <div class="tbl-wrap">
            <table>
                <thead><tr>
                    <th>組織 / AD 網域 / IP</th><th>電信商 / ASN 組織</th><th>國家</th>
                    <th class="num">裝置</th><th class="num">授權碼</th><th class="num">硬碟</th><th class="num">主機板</th>
                    <th class="num">啟動</th><th>異常標記</th><th>最後活動</th>
                </tr></thead>
                <tbody data-live="orgs">
                <?php foreach ($orgList as $row):
                    $isVmCloned = ((int) $row['unique_mbs'] > (int) $row['unique_disks'] && (int) $row['unique_disks'] > 0);
                    $isMany     = ((int) $row['device_count'] >= $orgAlert); ?>
                    <tr>
                        <td><a href="<?= h(url_with(['target' => $row['target_name']])) ?>"><b><?= h($row['target_name']) ?></b></a></td>
                        <td class="muted"><?= h($row['isp_org'] ?: '-') ?></td>
                        <td class="muted nowrap"><?= h($row['country'] ?: '-') ?></td>
                        <td class="num"><?= number_format($row['device_count']) ?></td>
                        <td class="num"><?= number_format($row['licenses']) ?></td>
                        <td class="num"><?= number_format($row['unique_disks']) ?></td>
                        <td class="num"><?= number_format($row['unique_mbs']) ?></td>
                        <td class="num"><?= number_format($row['launch_count']) ?></td>
                        <td>
                            <?php if ($isVmCloned): ?><span class="flag crit">疑似 VM 克隆</span><?php endif; ?>
                            <?php if ($isMany): ?><span class="flag">多機集中（≥<?= $orgAlert ?> 台）</span><?php endif; ?>
                            <?php if (!$isVmCloned && !$isMany): ?><span class="muted">-</span><?php endif; ?>
                        </td>
                        <td class="mono muted nowrap"><?= h($row['last_seen']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($orgList)): ?>
                    <tr><td colspan="10" class="muted" style="text-align:center;padding:24px">此時間範圍內沒有遙測紀錄</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Event log (like Kibana Discover) -->
    <section class="card tone-violet">
        <div class="card-h"><h2>最新事件</h2><span class="sub">最近 50 筆回報（啟動、心跳、手動）</span></div>
        <div class="tbl-wrap tbl-scroll">
            <table>
                <thead><tr><th>時間</th><th>事件</th><th>電腦名稱</th><th>組織</th><th>國家</th><th>對外 IP</th><th>版本</th><th>OS / 架構</th><th>Machine ID</th><th>授權碼</th></tr></thead>
                <tbody data-live="recent">
                <?php foreach ($recent as $e): ?>
                    <tr>
                        <td class="mono nowrap"><?= h($e['created_at']) ?></td>
                        <td class="nowrap"><?php $ev = $e['event'] ?? 'start'; ?><span class="ev ev-<?= h($ev) ?>"><?= h(['start' => '啟動', 'heartbeat' => '心跳', 'manual' => '手動'][$ev] ?? $ev) ?></span></td>
                        <td><?= h($e['hostname'] ?: '-') ?></td>
                        <td><a href="<?= h(url_with(['target' => $e['org']])) ?>"><?= h($e['org']) ?></a></td>
                        <td class="muted nowrap"><?= h($e['country'] ?: '-') ?></td>
                        <td class="mono"><?= h($e['public_ip']) ?></td>
                        <td class="mono"><?= h($e['app_version'] ?: '-') ?></td>
                        <td class="nowrap"><?= h($e['os_version'] ?: '-') ?> (<?= h($e['arch'] ?: '-') ?>)</td>
                        <td class="mono muted"><?= h($e['machine_id']) ?></td>
                        <td class="mono muted"><?= h($e['license_key'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="10" class="muted" style="text-align:center;padding:24px">此時間範圍內沒有遙測紀錄</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <details class="raw">
            <summary>圖表資料表（國家 / 版本 / 作業系統）</summary>
            <div class="grid g-3" style="margin-top:8px" data-live="raw">
                <?php foreach (['國家 / 地區' => $countries, '軟體版本' => $versions, '作業系統' => $osStats] as $title => $rows): ?>
                    <table>
                        <thead><tr><th><?= h($title) ?></th><th class="num">裝置</th></tr></thead>
                        <tbody><?php foreach ($rows as $r): ?><tr><td><?= h($r['name']) ?></td><td class="num"><?= number_format($r['n']) ?></td></tr><?php endforeach; ?></tbody>
                    </table>
                <?php endforeach; ?>
            </div>
        </details>
    </section>
    <footer class="muted" style="text-align:right;font-size:11px;margin-top:16px">
        <?php $httpsSeen = SecurityHeaders::isHttps($config); ?>
        伺服器偵測 HTTPS：<?= $httpsSeen ? '是' : '<b style="color:var(--critical)">否</b>' ?>
        · 強制 HTTPS：<?= !empty($config['security']['force_https']) ? '開' : '關' ?>
        · monitor 頁面版本 <?= MONITOR_VERSION ?>
    </footer>
</main>

<script type="application/json" id="viz-data" nonce="<?= h($cspNonce) ?>"><?= $vizJson ?></script>
<script nonce="<?= h($cspNonce) ?>">
(function () {
    // D is replaced on every live update: every chart builder below reads it at build time.
    let D = JSON.parse(document.getElementById('viz-data').textContent);
    const css = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const WEEKDAYS = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];
    const charts = [];

    function T() {   // current theme tokens (re-read on every render, so a theme switch repaints correctly)
        return {
            ink: css('--ink'), ink2: css('--ink-2'), muted: css('--muted'), grid: css('--grid'), axis: css('--axis'),
            surface: css('--surface'), border: css('--border'), s1: css('--series-1'), seq: css('--seq').split(',').map(s => s.trim()),
        };
    }

    function base(t) {
        return {
            animationDuration: 300, animationDurationUpdate: 300,
            textStyle: { fontFamily: 'system-ui, -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif', color: t.ink2 },
            tooltip: {
                backgroundColor: t.surface, borderColor: t.border, borderWidth: 1, padding: [6, 10],
                textStyle: { color: t.ink, fontSize: 12 }, extraCssText: 'box-shadow:0 4px 12px rgba(0,0,0,.18);border-radius:6px;',
            },
            grid: { left: 8, right: 24, top: 12, bottom: 8, containLabel: true },
        };
    }

    // Tooltip text is built from data that clients posted -> escape before ECharts puts it in the DOM.
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = (n) => Number(n).toLocaleString();
    const row = (color, label, value) =>
        `<div style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:2px;background:${color}"></span>` +
        `<b style="font-size:13px">${fmt(value)}</b><span style="opacity:.7">${esc(label)}</span></div>`;

    /**
     * A chart slot: hasData() and build(t) both read the current D. render(full) creates / updates / empties it.
     * full = true replaces the whole option (theme change); false merges (live update keeps zoom and tooltips).
     */
    function mount(id, hasData, build, onClick) {
        const el = document.getElementById(id);
        if (!el) return;
        const entry = { el, hasData, build, c: null };
        entry.render = (full) => {
            if (!hasData()) {
                if (entry.c) { entry.c.dispose(); entry.c = null; }
                el.innerHTML = '<div class="empty">此時間範圍內沒有資料</div>';
                return;
            }
            if (!entry.c) {
                el.innerHTML = '';
                entry.c = echarts.init(el);
                if (onClick) entry.c.on('click', onClick);
                full = true;
            }
            entry.c.setOption(build(T()), full ? true : { notMerge: false, lazyUpdate: true });
        };
        charts.push(entry);
        entry.render(true);
    }

    // '#2a78d6' + alpha -> 'rgba(...)' (for gradient fills)
    function hexA(hex, a) {
        const m = /^#?([0-9a-f]{6})$/i.exec(hex);
        if (!m) return hex;
        const n = parseInt(m[1], 16);
        return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
    }

    function timeSeries(id, key, label, tokenName) {
        mount(id, () => D.series[key].some(v => v > 0), (t) => { const color = css(tokenName) || t.s1; return Object.assign(base(t), {
            tooltip: Object.assign(base(t).tooltip, {
                trigger: 'axis',
                axisPointer: { type: 'line', lineStyle: { color: t.axis, width: 1 } },
                formatter: (p) => `<div style="opacity:.7;margin-bottom:2px">${esc(p[0].axisValue)}</div>` + row(color, label, p[0].value),
            }),
            dataZoom: [{ type: 'inside', filterMode: 'none' }],
            xAxis: {
                type: 'category', data: D.series.labels, boundaryGap: false,
                axisLine: { lineStyle: { color: t.axis } }, axisTick: { show: false },
                axisLabel: { color: t.muted, hideOverlap: true,
                             formatter: (v) => ({ '10s': v.slice(11), min: v.slice(11), hour: v.slice(11) })[D.bucket] || v.slice(5) },
            },
            yAxis: {
                type: 'value', minInterval: 1,
                splitLine: { lineStyle: { color: t.grid, type: 'solid' } }, axisLabel: { color: t.muted },
            },
            series: [{
                type: 'line', name: label, data: D.series[key], smooth: false, showSymbol: false, symbolSize: 8,
                lineStyle: { width: 2, color }, itemStyle: { color, borderColor: t.surface, borderWidth: 2 },
                areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: hexA(color, 0.38) }, { offset: 1, color: hexA(color, 0.02) }]) },
            }],
        }); });
    }

    function rankBars(id, getRows, onClick) {
        mount(id, () => getRows().length > 0, (t) => Object.assign(base(t), {
            // left padding: containLabel measures before truncation and can be a few px short -> first glyph clipped.
            grid: { left: 20, right: 48, top: 4, bottom: 4, containLabel: true },
            tooltip: Object.assign(base(t).tooltip, {
                trigger: 'item',
                formatter: (p) => row(t.s1, p.name, p.value) + (onClick ? '<div style="opacity:.6;margin-top:2px">點擊查看明細</div>' : ''),
            }),
            xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: t.grid } }, axisLabel: { color: t.muted } },
            yAxis: {
                type: 'category', inverse: true, data: getRows().map(r => r.name),
                axisLine: { lineStyle: { color: t.axis } }, axisTick: { show: false },
                axisLabel: { color: t.ink2, width: 140, overflow: 'truncate' },
            },
            series: [{
                type: 'bar', data: getRows().map(r => r.n), barMaxWidth: 14, barCategoryGap: '30%',
                itemStyle: { color: t.s1, borderRadius: [0, 4, 4, 0] },
                emphasis: { itemStyle: { opacity: 0.85 } },
                label: { show: true, position: 'right', color: t.ink2, fontSize: 12, formatter: (p) => fmt(p.value) },
                cursor: onClick ? 'pointer' : 'default',
            }],
        }), onClick ? (p) => onClick(getRows()[p.dataIndex]) : null);
    }

    // Live: launches per second, last 60 s (bars: discrete seconds).
    mount('chLive', () => true, (t) => { const color = css('--series-3') || t.s1; return Object.assign(base(t), {
        animationDurationUpdate: 200,
        grid: { left: 8, right: 8, top: 8, bottom: 4, containLabel: true },
        tooltip: Object.assign(base(t).tooltip, { trigger: 'axis', axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(128,128,128,.08)' } },
            formatter: (p) => `<div style="opacity:.7">${esc(p[0].axisValue)}</div>` + row(color, '次啟動', p[0].value) }),
        xAxis: { type: 'category', data: D.live.labels, axisTick: { show: false }, axisLine: { lineStyle: { color: t.axis } },
                 axisLabel: { color: t.muted, interval: 14 } },
        yAxis: { type: 'value', minInterval: 1, splitNumber: 3, splitLine: { lineStyle: { color: t.grid } }, axisLabel: { color: t.muted } },
        series: [{ type: 'bar', data: D.live.counts, barCategoryGap: '20%', itemStyle: { color, borderRadius: [2, 2, 0, 0] } }],
    }); });

    timeSeries('chLaunches', 'launches', '啟動次數', '--series-1');
    timeSeries('chDevices', 'devices', '活躍裝置', '--series-3');

    mount('chHeat', () => D.heat.length > 0, (t) => Object.assign(base(t), {
        grid: { left: 8, right: 16, top: 8, bottom: 48, containLabel: true },
        tooltip: Object.assign(base(t).tooltip, {
            trigger: 'item',
            formatter: (p) => `<div style="opacity:.7">${WEEKDAYS[p.value[1]]} ${String(p.value[0]).padStart(2, '0')}:00</div>` + row(t.s1, '次啟動', p.value[2]),
        }),
        xAxis: {
            type: 'category', data: Array.from({ length: 24 }, (_, i) => String(i)), splitArea: { show: false },
            axisLine: { lineStyle: { color: t.axis } }, axisTick: { show: false }, axisLabel: { color: t.muted },
        },
        yAxis: {
            type: 'category', data: WEEKDAYS, inverse: true,
            axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: t.ink2 },
        },
        visualMap: {
            min: 0, max: Math.max(1, D.heat.reduce((m, r) => Math.max(m, r[2]), 0)), calculable: false, orient: 'horizontal',
            left: 'center', bottom: 0, itemWidth: 10, itemHeight: 160, text: ['多', '少'], textStyle: { color: t.muted },
            inRange: { color: t.seq },
        },
        series: [{
            type: 'heatmap', data: D.heat,
            itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 2 },
            emphasis: { itemStyle: { borderColor: t.ink, borderWidth: 1 } },
        }],
    }));

    rankBars('chOrgs', () => D.orgs, (r) => {
        const u = new URL(window.location.href);
        u.searchParams.set('target', r.name);
        window.location.href = u.toString();
    });
    rankBars('chCountries', () => D.countries);
    rankBars('chVersions', () => D.versions);
    rankBars('chOs', () => D.os);

    // Continents: normal + suspected devices as one stacked bar per continent (part of the same whole).
    mount('chContinents', () => D.geo.continents.some(c => c.n > 0), (t) => {
        const crit = css('--critical'), cont = D.geo.continents;
        return Object.assign(base(t), {
            grid: { left: 8, right: 24, top: 36, bottom: 4, containLabel: true },
            legend: { top: 0, left: 0, itemWidth: 10, itemHeight: 10, textStyle: { color: t.ink2 }, data: ['正常', '疑似盜用'] },
            tooltip: Object.assign(base(t).tooltip, {
                trigger: 'axis', axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(128,128,128,.08)' } },
                formatter: (p) => `<div style="opacity:.7">${esc(p[0].name)}</div>` + p.map(x => row(x.color, x.seriesName, x.value)).join(''),
            }),
            xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: t.grid } }, axisLabel: { color: t.muted } },
            yAxis: { type: 'category', inverse: true, data: cont.map(c => c.name), axisTick: { show: false },
                     axisLine: { lineStyle: { color: t.axis } }, axisLabel: { color: t.ink2 } },
            series: [
                { name: '正常', type: 'bar', stack: 'd', data: cont.map(c => c.n - c.s), barMaxWidth: 18,
                  itemStyle: { color: t.s1, borderColor: t.surface, borderWidth: 1 } },
                { name: '疑似盜用', type: 'bar', stack: 'd', data: cont.map(c => c.s), barMaxWidth: 18,
                  itemStyle: { color: crit, borderColor: t.surface, borderWidth: 1, borderRadius: [0, 4, 4, 0] },
                  label: { show: true, position: 'right', color: t.ink2, fontSize: 12,
                           formatter: (p) => p.value > 0 ? fmt(p.value) : '' } },
            ],
        });
    });

    // Countries ranked by suspected devices (one measure -> one color).
    const suspectCountries = () => D.geo.countries.filter(c => c.s > 0);
    mount('chCountrySuspect', () => suspectCountries().length > 0, (t) => { const cs = suspectCountries(); return Object.assign(base(t), {
        grid: { left: 20, right: 48, top: 4, bottom: 4, containLabel: true },
        tooltip: Object.assign(base(t).tooltip, { trigger: 'item',
            formatter: (p) => row(css('--critical'), p.name + ' 疑似盜用', p.value) + `<div style="opacity:.6">共 ${fmt((cs[p.dataIndex] || {}).n || 0)} 台裝置</div>` }),
        xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: t.grid } }, axisLabel: { color: t.muted } },
        yAxis: { type: 'category', inverse: true, data: cs.map(c => c.name), axisTick: { show: false },
                 axisLine: { lineStyle: { color: t.axis } }, axisLabel: { color: t.ink2, width: 120, overflow: 'truncate' } },
        series: [{ type: 'bar', data: cs.map(c => c.s), barMaxWidth: 14,
                   itemStyle: { color: css('--critical'), borderRadius: [0, 4, 4, 0] },
                   label: { show: true, position: 'right', color: t.ink2, fontSize: 12, formatter: (p) => fmt(p.value) } }],
    }); });

    /* ---------- Map: Leaflet + Geoapify tiles ---------- */
    const MAP_STYLES = ['osm-bright-grey', 'klokantech-basic', 'positron', 'dark-matter-brown', 'dark-matter'];
    const styleSel = document.getElementById('mapStyle');
    let lmap = null, tiles = null, markers = null, tileErrors = 0, noteShown = false;
    const isDark = () => getComputedStyle(document.documentElement).colorScheme.includes('dark');
    function currentStyle() {   // the viewer's pick from the 底圖 menu, or the configured light / dark default
        const v = styleSel ? styleSel.value : 'auto';
        return MAP_STYLES.includes(v) ? v : (isDark() ? D.maps.dark : D.maps.light);
    }

    function setTiles() {
        if (tiles) lmap.removeLayer(tiles);
        tileErrors = 0;
        tiles = L.tileLayer('https://maps.geoapify.com/v1/tile/' + currentStyle() + '/{z}/{x}/{y}{r}.png?apiKey='
                            + encodeURIComponent(D.maps.key), {
            maxZoom: 18, minZoom: 1,
            attribution: 'Powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geoapify</a> | '
                       + '© <a href="https://openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> '
                       + '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
        }).addTo(lmap);
        // Wrong key / key not allowed for this site: tiles answer 401/403. Say so once instead of a blank map.
        tiles.on('tileerror', () => {
            if (++tileErrors !== 3 || noteShown) return;
            noteShown = true;
            const note = L.control({ position: 'topright' });
            note.onAdd = () => {
                const d = L.DomUtil.create('div', 'map-note');
                d.textContent = '地圖圖磚載入失敗：Geoapify 金鑰無效、未授權此網址，或已超過每日額度。圓點位置仍然正確。';
                return d;
            };
            note.addTo(lmap);
        });
    }

    /** (Re)draws the dots. fit = zoom to them (first draw only; live updates keep the viewer's position). */
    function drawMarkers(fit) {
        markers.clearLayers();
        const pts = D.geo.points;
        if (!pts.length) return;
        const maxN = Math.max(...pts.map(p => p.n));
        const s1 = css('--series-1'), crit = css('--critical');
        // Normal locations first, suspected ones on top so a red dot is never hidden under a blue one.
        [...pts].sort((a, b) => (a.s > 0) - (b.s > 0)).forEach((p) => {
            const r = Math.round(6 + 15 * Math.sqrt(p.n / maxN));   // area ~ device count
            const m = L.circleMarker([p.lat, p.lon], {
                radius: r, color: '#fff', weight: 2, fillColor: p.s > 0 ? crit : s1, fillOpacity: 0.85,
            }).addTo(markers);
            // Built with textContent: place / organisation names come from collected data.
            const box = document.createElement('div');
            box.className = 'iw';
            const t1 = document.createElement('b'); t1.textContent = p.place; box.appendChild(t1);
            const t2 = document.createElement('div'); t2.textContent = `裝置 ${fmt(p.n)} 台`; box.appendChild(t2);
            if (p.s > 0) { const t3 = document.createElement('div'); t3.className = 's'; t3.textContent = `⚠ 疑似盜用 ${fmt(p.s)} 台`; box.appendChild(t3); }
            if (p.orgs.length) { const t4 = document.createElement('div'); t4.className = 'o'; t4.textContent = '組織：' + p.orgs.join('、'); box.appendChild(t4); }
            m.bindPopup(box);
            m.bindTooltip(`${p.place}：${fmt(p.n)} 台` + (p.s ? `（疑似盜用 ${fmt(p.s)}）` : ''), { direction: 'top', offset: [0, -r] });
        });
        if (fit) {
            const b = pts.map(p => [p.lat, p.lon]);
            if (b.length > 1) lmap.fitBounds(b, { padding: [30, 30], maxZoom: 8 }); else lmap.setView(b[0], 6);
        }
    }

    function updateMap() {
        if (!window.L || !D.maps.key) return;
        if (!lmap) {
            if (!D.geo.points.length) return;   // keep the server's "no located devices" text until there is something
            const el = document.getElementById('gmap');
            el.innerHTML = '';
            lmap = L.map(el, { worldCopyJump: true, minZoom: 1, zoomSnap: 0.5 });
            markers = L.layerGroup().addTo(lmap);
            setTiles();
            drawMarkers(true);
            return;
        }
        if (lmap._popup && lmap.hasLayer(lmap._popup)) return;   // do not yank an open popup away; next tick will redraw
        drawMarkers(false);
    }
    updateMap();

    if (styleSel) {
        try { const v = localStorage.getItem('monitor-map-style'); if (v && (v === 'auto' || MAP_STYLES.includes(v))) styleSel.value = v; } catch (e) {}
        styleSel.addEventListener('change', () => {
            try { localStorage.setItem('monitor-map-style', styleSel.value); } catch (e) {}
            if (lmap) setTiles();
        });
        if (lmap && styleSel.value !== 'auto') setTiles();
    }

    function rerender() {
        charts.forEach((e) => e.render(true));
        if (lmap) { setTiles(); drawMarkers(false); }   // theme change: base map + dot colours
    }
    let rt;
    window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { charts.forEach((e) => e.c && e.c.resize()); if (lmap) lmap.invalidateSize(); }, 120); });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', rerender);

    // Theme toggle: auto -> light -> dark (per-viewer convenience; works without storage too).
    const themeBtn = document.getElementById('themeBtn');
    const NAMES = { auto: '自動', light: '亮色', dark: '暗色' };
    const current = () => document.documentElement.dataset.theme || 'auto';
    const showTheme = () => { themeBtn.textContent = '主題：' + NAMES[current()]; };
    themeBtn.addEventListener('click', () => {
        const next = { auto: 'light', light: 'dark', dark: 'auto' }[current()];
        if (next === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = next;
        try { localStorage.setItem('monitor-theme', next); } catch (e) {}
        showTheme();
        rerender();
    });
    showTheme();

    /* ---------- Live update ----------
     * Fetches this same page (same URL = same filters) in the background and swaps in what changed:
     * every [data-live] region's content, the chart data, the map dots. No reload: scroll positions inside
     * tables, chart zoom, the map's position and an open <details> all stay. One request at a time; the next
     * one is scheduled only after the previous finished; nothing is fetched while the tab is hidden.
     */
    const sel = document.getElementById('refreshSel');
    const pill = document.getElementById('livePill');
    const VALID = ['0', '1', '5', '10', '30', '60'];
    // Demo mode starts with the per-second refresh on unless this viewer already chose something.
    let saved = D.demo ? '1' : '0';
    try { saved = localStorage.getItem('monitor-live') || saved; } catch (e) {}
    sel.value = VALID.includes(saved) ? saved : '0';
    let timer = null, busy = false;

    function setPill(state, text) {
        pill.hidden = state === 'off';
        pill.className = 'live-pill' + (state === 'on' ? ' on' : state === 'err' ? ' err' : '');
        pill.lastElementChild.textContent = text;
    }

    function schedule() {
        clearTimeout(timer);
        const s = parseInt(sel.value, 10);
        if (s > 0) {
            setPill('on', '即時 · ' + (s === 1 ? '每秒' : `每 ${s} 秒`));
            timer = setTimeout(tick, s * 1000);
        } else {
            setPill('off', '');
        }
    }

    async function tick() {
        if (busy) return;
        if (document.hidden) { schedule(); return; }
        busy = true;
        try {
            const res = await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' });
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const data = doc.getElementById('viz-data');
            if (!res.ok || !data) {
                // Logged out (session ended) or a server error page: stop instead of hammering the server.
                sel.value = '0';
                setPill('err', res.ok ? '登入已過期，請重新整理頁面' : '伺服器錯誤，已停止即時更新');
                busy = false;
                return;
            }
            D = JSON.parse(data.textContent);
            doc.querySelectorAll('[data-live]').forEach((src) => {
                const dst = document.querySelector(`[data-live="${src.dataset.live}"]`);
                if (dst && dst.innerHTML !== src.innerHTML) dst.innerHTML = src.innerHTML;
            });
            charts.forEach((e) => e.render(false));
            updateMap();
        } catch (err) {
            setPill('err', '連線失敗，稍後重試');
            busy = false;
            clearTimeout(timer);
            timer = setTimeout(tick, 5000);
            return;
        }
        busy = false;
        schedule();
    }

    sel.addEventListener('change', () => { try { localStorage.setItem('monitor-live', sel.value); } catch (e) {} schedule(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && sel.value !== '0') { clearTimeout(timer); tick(); } });
    schedule();
})();
</script>
</body>
</html>
