<?php
/**
 * DEMO DATA for monitor/index.php?demo=1 -- every number it produces is fake. The database is never touched.
 *
 * A fake world: ~150 organisations (~2,000 machines) in ~80 cities on five continents, with planted anomalies
 * (license keys shared inside an organisation, keys shared across organisations, VM-cloned disks).
 *
 * Launches follow ONE world-wide rate (launches per second by local hour, weekends lower), stored in three tiers
 * so that 90 days stay cheap to compute every second:
 *   D  days   90 days ago .. 3 days ago      per (day, machine) launch counts
 *   H  hours  start of the day before yesterday .. start of the previous hour   per (hour, machine) counts
 *   E  events start of the previous hour .. now                                 one row per launch, per second
 * The three tiers use the same rate, so the time series has no seam where one tier meets the next.
 *
 * Determinism: every day / hour / second is generated from a seed derived from its own timestamp, so the past
 * never changes when time moves on -- each new second only adds that second's launches.
 *
 * Today's "story", also seeded per time slot and therefore persistent for the rest of the day:
 *   every DEMO_INSTALL_EVERY seconds a new machine is installed -- sometimes in a brand-new organisation (a new dot
 *   on the map), sometimes with a leaked license key (授權碼超用), a copied disk image (VM clone) or the demo's
 *   "cracked" marker (DEMO_CRACK), and with the newest app version.
 *
 * Aggregates are computed with the same rules as the SQL in index.php, so all tiles / charts / tables agree.
 */

if (!defined('APP_ENTRY')) {
    http_response_code(403);
    exit;
}

const DEMO_SEED          = 20261113;
const DEMO_HISTORY_DAYS  = 90;
const DEMO_INSTALL_EVERY = 180;          // seconds between two new installs today
const DEMO_CRACK         = 'CRACKED-DEMO';
// World-wide launches per second by local hour on weekdays; weekends are DEMO_WEEKEND of that.
const DEMO_PROFILE = [0.12, 0.10, 0.10, 0.10, 0.10, 0.15, 0.30, 0.70, 1.60, 1.90, 1.90, 1.80,
                      1.20, 1.70, 1.90, 1.90, 1.80, 1.40, 0.90, 0.60, 0.50, 0.45, 0.35, 0.20];
const DEMO_WEEKEND = 0.22;

// cc => [English name, ip-api continentCode, weight (share of organisations), ISPs, cities [name, lat, lon]]
const DEMO_COUNTRIES = [
    'TW' => ['Taiwan', 'AS', 34, ['HiNet', 'Chunghwa Telecom', 'Taiwan Fixed Network', 'Taiwan Mobile', 'Asia Pacific Telecom'],
             [['Taipei', 25.0330, 121.5654], ['New Taipei', 25.0120, 121.4650], ['Hsinchu', 24.8138, 120.9675],
              ['Taichung', 24.1477, 120.6736], ['Tainan', 22.9999, 120.2270], ['Kaohsiung', 22.6273, 120.3014],
              ['Taoyuan', 24.9936, 121.3010], ['Miaoli', 24.5602, 120.8214]]],
    'CN' => ['China', 'AS', 18, ['China Telecom', 'China Unicom', 'China Mobile'],
             [['Shanghai', 31.2304, 121.4737], ['Shenzhen', 22.5431, 114.0579], ['Beijing', 39.9042, 116.4074],
              ['Suzhou', 31.2990, 120.5853], ['Hangzhou', 30.2741, 120.1551], ['Guangzhou', 23.1291, 113.2644],
              ['Chengdu', 30.5728, 104.0668], ['Wuhan', 30.5928, 114.3055], ['Xiamen', 24.4798, 118.0894],
              ['Dongguan', 23.0207, 113.7518]]],
    'HK' => ['Hong Kong', 'AS', 2, ['HKT', 'HGC Global'], [['Hong Kong', 22.3193, 114.1694]]],
    'JP' => ['Japan', 'AS', 5, ['KDDI', 'NTT', 'SoftBank'],
             [['Tokyo', 35.6762, 139.6503], ['Osaka', 34.6937, 135.5023], ['Nagoya', 35.1815, 136.9066], ['Fukuoka', 33.5904, 130.4017]]],
    'KR' => ['South Korea', 'AS', 4, ['Korea Telecom', 'SK Broadband', 'LG U+'],
             [['Seoul', 37.5665, 126.9780], ['Busan', 35.1796, 129.0756], ['Suwon', 37.2636, 127.0286]]],
    'VN' => ['Vietnam', 'AS', 6, ['Viettel Group', 'VNPT', 'FPT Telecom'],
             [['Hanoi', 21.0278, 105.8342], ['Ho Chi Minh City', 10.8231, 106.6297], ['Da Nang', 16.0544, 108.2022], ['Hai Phong', 20.8449, 106.6881]]],
    'TH' => ['Thailand', 'AS', 3, ['True Internet', 'AIS', '3BB'], [['Bangkok', 13.7563, 100.5018], ['Chiang Mai', 18.7883, 98.9853]]],
    'MY' => ['Malaysia', 'AS', 2, ['TM Net', 'Maxis'], [['Kuala Lumpur', 3.1390, 101.6869], ['Penang', 5.4141, 100.3288]]],
    'SG' => ['Singapore', 'AS', 2, ['Singtel', 'StarHub'], [['Singapore', 1.3521, 103.8198]]],
    'ID' => ['Indonesia', 'AS', 2, ['Telkom Indonesia', 'Indosat'], [['Jakarta', -6.2088, 106.8456], ['Surabaya', -7.2575, 112.7521]]],
    'PH' => ['Philippines', 'AS', 2, ['PLDT', 'Globe Telecom'], [['Manila', 14.5995, 120.9842], ['Cebu', 10.3157, 123.8854]]],
    'IN' => ['India', 'AS', 3, ['Reliance Jio', 'Bharti Airtel'],
             [['Bengaluru', 12.9716, 77.5946], ['Mumbai', 19.0760, 72.8777], ['New Delhi', 28.6139, 77.2090], ['Chennai', 13.0827, 80.2707]]],
    'AE' => ['United Arab Emirates', 'AS', 1, ['Etisalat'], [['Dubai', 25.2048, 55.2708]]],
    'IL' => ['Israel', 'AS', 1, ['Bezeq'], [['Tel Aviv', 32.0853, 34.7818]]],
    'TR' => ['Turkey', 'AS', 1, ['Turk Telekom'], [['Istanbul', 41.0082, 28.9784]]],
    'DE' => ['Germany', 'EU', 3, ['Deutsche Telekom', 'Vodafone DE'],
             [['Munich', 48.1351, 11.5820], ['Berlin', 52.5200, 13.4050], ['Frankfurt', 50.1109, 8.6821], ['Stuttgart', 48.7758, 9.1829]]],
    'GB' => ['United Kingdom', 'EU', 2, ['BT Group', 'Virgin Media'], [['London', 51.5074, -0.1278], ['Manchester', 53.4808, -2.2426]]],
    'FR' => ['France', 'EU', 2, ['Orange', 'SFR'], [['Paris', 48.8566, 2.3522], ['Lyon', 45.7640, 4.8357]]],
    'NL' => ['Netherlands', 'EU', 1, ['KPN'], [['Amsterdam', 52.3676, 4.9041], ['Eindhoven', 51.4416, 5.4697]]],
    'IT' => ['Italy', 'EU', 1, ['TIM'], [['Milan', 45.4642, 9.1900]]],
    'PL' => ['Poland', 'EU', 1, ['Orange Polska'], [['Warsaw', 52.2297, 21.0122]]],
    'RU' => ['Russia', 'EU', 2, ['Rostelecom', 'MTS'], [['Moscow', 55.7558, 37.6173], ['Saint Petersburg', 59.9311, 30.3609]]],
    'US' => ['United States', 'NA', 7, ['Comcast Cable', 'Verizon', 'AT&T', 'Charter'],
             [['San Jose', 37.3382, -121.8863], ['Boston', 42.3601, -71.0589], ['Austin', 30.2672, -97.7431],
              ['Seattle', 47.6062, -122.3321], ['New York', 40.7128, -74.0060], ['Chicago', 41.8781, -87.6298]]],
    'CA' => ['Canada', 'NA', 1, ['Bell Canada', 'Rogers'], [['Toronto', 43.6532, -79.3832], ['Vancouver', 49.2827, -123.1207]]],
    'MX' => ['Mexico', 'NA', 1, ['Telmex'], [['Guadalajara', 20.6597, -103.3496], ['Mexico City', 19.4326, -99.1332]]],
    'BR' => ['Brazil', 'SA', 2, ['Claro Brasil', 'Vivo'], [['São Paulo', -23.5505, -46.6333], ['Campinas', -22.9099, -47.0626]]],
    'AR' => ['Argentina', 'SA', 1, ['Telecom Argentina'], [['Buenos Aires', -34.6037, -58.3816]]],
    'ZA' => ['South Africa', 'AF', 1, ['Telkom SA', 'Vodacom'], [['Johannesburg', -26.2041, 28.0473], ['Cape Town', -33.9249, 18.4241]]],
    'EG' => ['Egypt', 'AF', 1, ['Telecom Egypt'], [['Cairo', 30.0444, 31.2357]]],
    'NG' => ['Nigeria', 'AF', 1, ['MTN Nigeria'], [['Lagos', 6.5244, 3.3792]]],
    'KE' => ['Kenya', 'AF', 1, ['Safaricom'], [['Nairobi', -1.2921, 36.8219]]],
    'AU' => ['Australia', 'OC', 2, ['Telstra', 'Optus'], [['Sydney', -33.8688, 151.2093], ['Melbourne', -37.8136, 144.9631]]],
    'NZ' => ['New Zealand', 'OC', 1, ['Spark NZ'], [['Auckland', -36.8485, 174.7633]]],
];
const DEMO_NAME_A = ['Acme', 'Foxtron', 'Quanta', 'Mega', 'Opto', 'Lens', 'Pixel', 'Sensor', 'Vision', 'Nova', 'Apex', 'Orbit',
                     'Delta', 'Sigma', 'Zen', 'Aero', 'Titan', 'Lumen', 'Prime', 'Vertex', 'Photon', 'Iris', 'Spectra', 'Kepler'];
const DEMO_NAME_B = ['tech', 'labs', 'optics', 'imaging', 'systems', 'electronics', 'works', 'corp', 'semi', 'devices', 'vision', 'cam'];
const DEMO_OS  = [['Windows 11 23H2', 30], ['Windows 10 22H2', 30], ['Windows 11 24H2', 24], ['Windows 10 21H2', 8],
                  ['Windows Server 2019', 4], ['Windows Server 2022', 4]];
const DEMO_VER = [['2.1.0', 40], ['2.0.3', 30], ['2.0.1', 14], ['1.9.8', 10], ['1.0.0', 6]];
const DEMO_VER_TODAY = [['2.2.0', 70], ['2.1.0', 30]];   // today's installs get the newest version

/* ---------------------------------------------------------------- helpers */

/**
 * Is machine $mi away for the whole local day starting at $dayStart (leave, weekend)? Deterministic per
 * (machine, day), used by all three tiers so a day's device count looks the same whichever tier holds it.
 */
function demo_absent(int $mi, int $dayStart): bool {
    $pct = (int) date('N', $dayStart) >= 6 ? 70 : 12;
    return ((($mi * 2654435761) ^ (intdiv($dayStart, 86400) * 40503)) & 0x7fffffff) % 100 < $pct;
}

/** World-wide launches per second at local time $ts. */
function demo_rate(int $ts): float {
    $v = DEMO_PROFILE[(int) date('G', $ts)];
    return (int) date('N', $ts) >= 6 ? $v * DEMO_WEEKEND : $v;
}

/** A random count with the given mean (cheap, good enough for fake data). */
function demo_count(float $mean): int {
    static $max = null;
    $max ??= mt_getrandmax();
    if ($mean <= 0) return 0;
    if ($mean < 1) return (mt_rand() / $max) < $mean ? 1 : 0;
    return (int) round($mean * (0.55 + 0.9 * mt_rand() / $max));
}

function demo_pick(array $weighted) {
    $r = mt_rand(1, array_sum(array_column($weighted, 1)));
    foreach ($weighted as [$v, $w]) {
        if (($r -= $w) <= 0) return $v;
    }
    return $weighted[0][0];
}

function demo_hex(int $n): string { return strtoupper(substr(md5((string) mt_rand()), 0, $n)); }
function demo_key(): string { return sprintf('SOI-%s-%s-%s', demo_hex(4), demo_hex(4), demo_hex(4)); }
function demo_ip(): string {
    do { $a = mt_rand(1, 223); } while (in_array($a, [10, 100, 127, 169, 172, 192], true));
    return $a . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
}

/* ---------------------------------------------------------------- the world */

function demo_new_org(array &$W): int {
    static $ccs = null;
    if ($ccs === null) {
        $ccs = [];
        foreach (DEMO_COUNTRIES as $cc => $c) $ccs[] = [$cc, $c[2]];
    }
    $cc = demo_pick($ccs);
    [$en, $cont, , $isps, $cities] = DEMO_COUNTRIES[$cc];
    $city = $cities[mt_rand(0, count($cities) - 1)];
    $isp = $isps[mt_rand(0, count($isps) - 1)];
    $domain = '';
    if (mt_rand(1, 100) <= 65) {
        $tld = ['TW' => '.com.tw', 'CN' => '.cn', 'JP' => '.co.jp', 'KR' => '.co.kr', 'DE' => '.de', 'GB' => '.co.uk'][$cc] ?? '.com';
        $base = strtolower(DEMO_NAME_A[mt_rand(0, count(DEMO_NAME_A) - 1)] . DEMO_NAME_B[mt_rand(0, count(DEMO_NAME_B) - 1)]);
        $domain = (mt_rand(1, 100) <= 50 ? $base . '.local' : $base . $tld);
        while (isset($W['domains'][$domain])) $domain = mt_rand(2, 9) . $domain;
        $W['domains'][$domain] = true;
    }
    // Up to ~30 km from the city centre (another ISP gateway in the same metro area): every organisation gets its
    // own dot on the map, so a new customer is visible even in a city that already has one.
    $W['orgs'][] = ['name' => $domain !== '' ? $domain : $isp, 'domain' => $domain, 'isp' => $isp, 'cc' => $cc,
                    'country' => $en, 'cont' => $cont, 'city' => $city[0],
                    'lat' => round($city[1] + mt_rand(-27, 27) / 100, 4), 'lon' => round($city[2] + mt_rand(-27, 27) / 100, 4),
                    'ip' => demo_ip()];
    $W['byOrg'][] = [];
    return count($W['orgs']) - 1;
}

/** $install: seconds relative to today 00:00 (negative = in the past), or null = installed before history. */
function demo_new_machine(array &$W, int $oi, ?int $install, array $ver, ?string $key = null, ?string $disk = null): int {
    $n = count($W['byOrg'][$oi]) + 1;
    $o = $W['orgs'][$oi];
    $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $o['name']), 0, 3)) ?: 'PC';
    $r = mt_rand(1, 100);
    $mi = count($W['m']);
    $W['m'][] = [
        'org' => $oi, 'mid' => md5('demo-' . $mi . '-' . mt_rand()),
        'host' => sprintf('%s-%s-%03d', $prefix, ['ENG', 'RD', 'QA', 'LAB', 'PC', 'CAD', 'OPS'][$n % 7], $n),
        'lip' => sprintf('10.%d.%d.%d', $oi % 250, intdiv($n, 240), 10 + $n % 240),
        'cpu' => 'BFEBFBFF000' . demo_hex(5), 'mb' => sprintf('4C4C4544-%s-%s', demo_hex(4), demo_hex(8)),
        'disk' => $disk ?? ('S' . demo_hex(11)), 'os' => demo_pick(DEMO_OS),
        'arch' => $r <= 9 ? 'x86' : ($r <= 12 ? 'ARM64' : 'x64'), 'ver' => demo_pick($ver),
        'key' => $key ?? demo_key(), 'w' => mt_rand(30, 300) / 100, 'install' => $install,
    ];
    $W['byOrg'][$oi][] = $mi;
    return $mi;
}

/** Organisations and machines that existed before today (fixed seed: identical on every request). */
function demo_base_world(): array {
    mt_srand(DEMO_SEED);
    $W = ['orgs' => [], 'byOrg' => [], 'm' => [], 'domains' => []];
    for ($i = 0; $i < 150; $i++) {
        $oi = demo_new_org($W);
        $size = max(1, (int) round(235 / (($i + 1) ** 0.85)));
        $share = ($i % 13 === 3);                 // license keys shared inside the organisation
        $clone = ($i % 19 === 5);                 // one disk image copied to most machines
        $sharedKeys = [demo_key(), demo_key()];
        $cloneDisk = 'WD-' . demo_hex(10);
        for ($m = 0; $m < $size; $m++) {
            $install = mt_rand(1, 100) <= 75 ? null : -mt_rand(3 * 86400, DEMO_HISTORY_DAYS * 86400);
            demo_new_machine($W, $oi, $install, DEMO_VER,
                $share && $m % 2 === 0 ? $sharedKeys[$m % 4 === 0 ? 0 : 1] : null,
                $clone && $m % 3 !== 0 ? $cloneDisk : null);
        }
    }
    // Three keys that turn up in two different organisations each (跨組織).
    for ($c = 0; $c < 3; $c++) {
        $key = demo_key();
        foreach ([mt_rand(0, 40), mt_rand(41, 149)] as $oi) {
            $W['m'][$W['byOrg'][$oi][0]]['key'] = $key;
        }
    }
    $W['leaked'] = [demo_key(), demo_key(), demo_key(), demo_key(), demo_key()];
    $W['baseCount'] = count($W['m']);
    return $W;
}

/** Today's installs, one per DEMO_INSTALL_EVERY seconds up to now (seeded per slot: they stay all day). */
function demo_add_today(array &$W, int $todayStart, int $now): void {
    $daySeed = (int) date('Ymd', $todayStart);
    $base = $W['baseCount'];
    for ($s = 0, $t = $todayStart; $t <= $now; $s++, $t = $todayStart + $s * DEMO_INSTALL_EVERY) {
        mt_srand($daySeed * 1000 + $s);
        $at = $t + mt_rand(0, DEMO_INSTALL_EVERY - 1);
        if ($at > $now) break;
        if (mt_rand(1, 100) <= 30) {
            $oi = demo_new_org($W);                                  // a new customer: new dot on the map
        } else {
            $oi = $W['m'][mt_rand(0, $base - 1)]['org'];            // an existing one, weighted by its size
        }
        $r = mt_rand(1, 100);
        // A new key "leaks" every 10 slots (30 min); installs that use it push 授權碼超用 up during the day.
        $leak = sprintf('SOI-LEAK-%s', strtoupper(substr(md5($daySeed . '-' . intdiv($s, 10)), 0, 8)));
        $key = $r <= 20 ? ($r <= 5 ? $W['leaked'][mt_rand(0, 4)] : $leak) : ($r <= 28 ? DEMO_CRACK : null);
        $disk = null;
        if (count($W['byOrg'][$oi]) > 0 && mt_rand(1, 100) <= 12) {  // copied disk image -> VM clone
            $disk = $W['m'][$W['byOrg'][$oi][mt_rand(0, count($W['byOrg'][$oi]) - 1)]]['disk'];
        }
        demo_new_machine($W, $oi, $at - $todayStart, DEMO_VER_TODAY, $key, $disk);
    }
}

/**
 * Generates the three tiers relative to $now.
 * Returns D [dayStart => [mi => count]], H [hourStart => [mi => count]], E [[ts, mi], ...] (oldest first).
 */
function demo_tiers(array $W, int $now, int $todayStart): array {
    $M = $W['m'];
    $n = count($M);
    $installAt = [];
    $wsum = 0.0;
    foreach ($M as $mi => $m) {
        $installAt[$mi] = $m['install'] === null ? PHP_INT_MIN : $todayStart + $m['install'];
        if ($mi < $W['baseCount']) $wsum += $m['w'];
    }
    $share = [];
    foreach ($M as $mi => $m) $share[$mi] = $m['w'] / $wsum;   // each machine's part of the world-wide volume

    $hStart = $todayStart - 2 * 86400;
    $eStart = $now - $now % 3600 - 3600;   // start of the previous hour (Asia/Taipei: whole-hour offset)

    // D: whole days. Machines are absent on some days (leave, weekends), which makes daily device counts vary.
    $D = [];
    $weekdayTotal = array_sum(DEMO_PROFILE) * 3600;
    for ($day = $todayStart - (DEMO_HISTORY_DAYS - 1) * 86400; $day < $hStart; $day += 86400) {
        mt_srand(DEMO_SEED + intdiv($day, 86400));
        $weekend = (int) date('N', $day) >= 6;
        $total = $weekdayTotal * ($weekend ? DEMO_WEEKEND : 1.0);
        $boost = 100 / (100 - ($weekend ? 70 : 12));   // present machines make up for the absent ones
        $row = [];
        for ($mi = 0; $mi < $W['baseCount']; $mi++) {
            if ($installAt[$mi] >= $day + 86400 || demo_absent($mi, $day)) continue;
            $c = demo_count($share[$mi] * $total * $boost);
            if ($c > 0) $row[$mi] = $c;
        }
        $D[$day] = $row;
    }

    // H: whole hours up to the start of the previous hour.
    $H = [];
    for ($h = $hStart; $h < $eStart; $h += 3600) {
        mt_srand(DEMO_SEED * 3 + intdiv($h, 3600));
        $vol = demo_rate($h + 1800) * 3600;
        $day = strtotime(date('Y-m-d 00:00:00', $h));
        $boost = 100 / (100 - ((int) date('N', $day) >= 6 ? 70 : 12));
        $row = [];
        for ($mi = 0; $mi < $n; $mi++) {
            // today's installs are never "away" on the day they were installed
            if ($installAt[$mi] >= $h + 3600 || ($mi < $W['baseCount'] && demo_absent($mi, $day))) continue;
            $c = demo_count($share[$mi] * $vol * $boost);
            if ($c > 0) $row[$mi] = $c;
        }
        $H[$h] = $row;
    }

    // E: every second since $eStart. Base machines are picked in proportion to their share (16384-slot table built
    // from base machines only, so it never changes). Today's installs are picked separately, among those already
    // installed at that second -- so a past second always produces exactly the same launches.
    $base = $W['baseCount'];
    $table = [];
    $acc = 0.0;
    $slots = 16384;
    for ($mi = 0; $mi < $base; $mi++) {
        $acc += $share[$mi] * $slots;
        while (count($table) < min($slots, (int) round($acc))) $table[] = $mi;
    }
    while (count($table) < $slots) $table[] = $base - 1;
    $todayIdx = range($base, $n - 1);   // in install order (appended slot by slot)
    $installed = 0;                     // how many of today's installs exist at second $s
    $E = [];
    $max = mt_getrandmax();
    for ($s = $eStart; $s <= $now; $s++) {
        while ($installed < count($todayIdx) && $installAt[$todayIdx[$installed]] <= $s) $installed++;
        $q = min(0.3, $installed / $base);   // each new install about as busy as an average machine
        mt_srand($s);
        $day = $todayStart + intdiv($s - $todayStart, 86400) * 86400;
        $absentPct = (int) date('N', $day) >= 6 ? 70 : 12;
        $p = min(1.0, demo_rate($s) / 4 * 100 / (100 - $absentPct));
        for ($j = 0; $j < 4; $j++) {
            $hit = mt_rand() / $max < $p;
            $toNew = mt_rand() / $max < $q;
            $pick = mt_rand(0, $slots - 1);
            if (!$hit) continue;
            $mi = ($toNew && $installed > 0) ? $todayIdx[$pick % $installed] : $table[$pick];
            if ($installAt[$mi] > $s || ($mi < $base && demo_absent($mi, $day))) continue;
            $E[] = [$s, $mi];
        }
    }
    return [$D, $H, $E];
}

/* ---------------------------------------------------------------- aggregation */

/**
 * Launch totals per machine in [$from, $to) (tiers D/H count when their midpoint is in the window).
 * With $full also: time-series buckets, weekday x hour heat, last-seen per machine.
 */
function demo_window(array $T, array $f, ?int $from, int $to, bool $full): array {
    [$D, $H, $E, $mver] = $T;
    $ver = $f['ver'];
    $from ??= PHP_INT_MIN;
    $mL = $mLast = $bL = $bD = $heat = [];
    $launches = 0;
    $bucketKey = function (int $ts) use ($f): string {
        switch ($f['bucket']) {
            case '10s':  return date('Y-m-d H:i:s', $ts - $ts % 10);
            case 'min':  return date('Y-m-d H:i', $ts);
            case 'hour': return date('Y-m-d H:00', $ts);
            default:     return date('Y-m-d', $ts);
        }
    };
    $profileSum = array_sum(DEMO_PROFILE);
    $add = function (array $row, int $last, ?string $key) use (&$mL, &$mLast, &$bL, &$bD, &$launches, $mver, $ver, $full): int {
        $sum = 0;
        foreach ($row as $mi => $c) {
            if ($ver !== '' && $mver[$mi] !== $ver) continue;
            $sum += $c;
            $mL[$mi] = ($mL[$mi] ?? 0) + $c;
            if ($full) {
                if ($last > ($mLast[$mi] ?? 0)) $mLast[$mi] = $last;
                $bD[$key][$mi] = true;
            }
        }
        $launches += $sum;
        if ($full && $sum) $bL[$key] = ($bL[$key] ?? 0) + $sum;
        return $sum;
    };

    foreach ($D as $day => $row) {
        $mid = $day + 43200;
        if ($mid < $from || $mid >= $to) continue;
        $sum = $add($row, $day + 17 * 3600, $full ? $bucketKey($day) : null);
        if ($full && $sum) {   // spread the day over its hours with the rate profile
            $wd = (int) date('N', $day) - 1;
            foreach (DEMO_PROFILE as $hr => $v) $heat[$wd][$hr] = ($heat[$wd][$hr] ?? 0) + $sum * $v / $profileSum;
        }
    }
    foreach ($H as $hour => $row) {
        $mid = $hour + 1800;
        if ($mid < $from || $mid >= $to) continue;
        $sum = $add($row, min($hour + 1800, $to - 1), $full ? $bucketKey($hour) : null);
        if ($full && $sum) {
            $wd = (int) date('N', $hour) - 1;
            $hr = (int) date('G', $hour);
            $heat[$wd][$hr] = ($heat[$wd][$hr] ?? 0) + $sum;
        }
    }
    $lastKeyTs = null;
    $key = null;
    $wd = $hr = 0;
    foreach ($E as [$ts, $mi]) {
        if ($ts < $from || $ts >= $to) continue;
        if ($ver !== '' && $mver[$mi] !== $ver) continue;
        $launches++;
        $mL[$mi] = ($mL[$mi] ?? 0) + 1;
        if (!$full) continue;
        if ($ts > ($mLast[$mi] ?? 0)) $mLast[$mi] = $ts;
        $slot = $ts - $ts % 10;   // keys / heat change at most every 10 s: compute them once per slot
        if ($slot !== $lastKeyTs) {
            $lastKeyTs = $slot;
            $key = $bucketKey($ts);
            $wd = (int) date('N', $ts) - 1;
            $hr = (int) date('G', $ts);
        }
        $bL[$key] = ($bL[$key] ?? 0) + 1;
        $bD[$key][$mi] = true;
        $heat[$wd][$hr] = ($heat[$wd][$hr] ?? 0) + 1;
    }
    return ['launches' => $launches, 'mL' => $mL, 'mLast' => $mLast, 'bL' => $bL, 'bD' => $bD, 'heat' => $heat];
}

/** Everything index.php needs, computed from the fake world with the current filters. */
function monitor_demo_data(array $f): array {
    $now = strtotime($f['dbNow']);
    $todayStart = strtotime(date('Y-m-d 00:00:00', $now));

    $W = demo_base_world();
    demo_add_today($W, $todayStart, $now);
    [$D, $H, $E] = demo_tiers($W, $now, $todayStart);
    $M = $W['m'];
    $orgs = $W['orgs'];
    $mver = array_column($M, 'ver');
    $T = [$D, $H, $E, $mver];

    $orgName = fn(int $mi) => $orgs[$M[$mi]['org']]['name'];   // = COALESCE(NULLIF(ad_domain,''), c.org, ...)
    $sinceTs = $f['since'] !== null ? strtotime($f['since']) : null;
    $psinceTs = $f['psince'] !== null ? strtotime($f['psince']) : null;
    $fmt = fn($ts) => date('Y-m-d H:i:s', (int) $ts);

    $cur = demo_window($T, $f, $sinceTs, $now + 1, true);
    $kpiOf = function (array $mL, int $launches) use ($M, $orgName): array {
        $lic = $org = [];
        foreach ($mL as $mi => $c) {
            $lic[$M[$mi]['key']] = true;
            $org[$orgName($mi)] = true;
        }
        return ['launches' => $launches, 'devices' => count($mL), 'licenses' => count($lic), 'orgs' => count($org)];
    };
    $kpi = $kpiOf($cur['mL'], $cur['launches']);
    $kpiPrev = null;
    if ($sinceTs !== null) {
        $prev = demo_window($T, $f, $psinceTs, $sinceTs, false);
        $kpiPrev = $kpiOf($prev['mL'], $prev['launches']);
    }

    // Per organisation / license / dimension, from the machines active in the window.
    $byOrg = $byLic = $dims = [];
    foreach ($cur['mL'] as $mi => $c) {
        $m = $M[$mi];
        $o = $orgs[$m['org']];
        $on = $o['name'];
        $last = $cur['mLast'][$mi] ?? $now;
        $g = &$byOrg[$on];
        $g['isp'] = $o['isp'];
        $g['country'] = $o['country'];
        $g['dev'][$mi] = true;
        $g['disk'][$m['disk']] = true;
        $g['mb'][$m['mb']] = true;
        $g['lic'][$m['key']] = true;
        $g['n'] = ($g['n'] ?? 0) + $c;
        $g['last'] = max($g['last'] ?? 0, $last);
        unset($g);
        $l = &$byLic[$m['key']];
        $l['dev'][$mi] = true;
        $l['org'][$on] = true;
        $l['n'] = ($l['n'] ?? 0) + $c;
        $l['last'] = max($l['last'] ?? 0, $last);
        unset($l);
        $dims['country'][$o['country']][$mi] = true;
        $dims['version'][$m['ver']][$mi] = true;
        $dims['os'][$m['os']][$mi] = true;
        $dims['arch'][$m['arch']][$mi] = true;
    }

    $cloneOrgList = [];
    $orgList = [];
    foreach ($byOrg as $name => $g) {
        $disks = count($g['disk']);
        $mbs = count($g['mb']);
        if ($mbs > $disks && $disks > 0) $cloneOrgList[] = (string) $name;
        $orgList[] = ['target_name' => (string) $name, 'isp_org' => $g['isp'], 'country' => $g['country'],
                      'device_count' => count($g['dev']), 'unique_disks' => $disks, 'unique_mbs' => $mbs,
                      'licenses' => count($g['lic']), 'launch_count' => $g['n'], 'last_seen' => $fmt($g['last'])];
    }
    usort($orgList, fn($a, $b) => [$b['device_count'], $b['launch_count']] <=> [$a['device_count'], $a['launch_count']]);

    $overKeyList = [];
    $licenses = [];
    foreach ($byLic as $k => $l) {
        if (count($l['dev']) > $f['licenseLimit']) $overKeyList[] = (string) $k;
        $licenses[] = ['license_key' => (string) $k, 'devices' => count($l['dev']), 'orgs' => count($l['org']),
                       'launches' => $l['n'], 'last_seen' => $fmt($l['last'])];
    }
    usort($licenses, fn($a, $b) => [$b['devices'], $b['launches']] <=> [$a['devices'], $a['launches']]);

    $rank = function (string $dim, int $limit) use ($dims): array {
        $rows = [];
        foreach ($dims[$dim] ?? [] as $name => $set) $rows[] = ['name' => (string) $name, 'n' => count($set)];
        usort($rows, fn($a, $b) => $b['n'] <=> $a['n']);
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    };

    $tsRows = [];
    ksort($cur['bL']);
    foreach ($cur['bL'] as $b => $n) $tsRows[] = ['b' => $b, 'launches' => $n, 'devices' => count($cur['bD'][$b] ?? [])];
    $heat = [];
    foreach ($cur['heat'] as $wd => $hours) {
        foreach ($hours as $hr => $n) {
            if (($n = (int) round($n)) > 0) $heat[] = [$hr, $wd, $n];
        }
    }

    // Newest launches in the window (the event tier holds the newest ones).
    $recent = [];
    for ($i = count($E) - 1; $i >= 0 && count($recent) < 50; $i--) {
        [$ts, $mi] = $E[$i];
        if (($sinceTs !== null && $ts < $sinceTs) || ($f['ver'] !== '' && $mver[$mi] !== $f['ver'])) continue;
        $m = $M[$mi];
        $o = $orgs[$m['org']];
        $recent[] = ['created_at' => $fmt($ts), 'hostname' => $m['host'], 'public_ip' => $o['ip'], 'org' => $o['name'],
                     'country' => $o['country'], 'app_version' => $m['ver'], 'os_version' => $m['os'],
                     'arch' => $m['arch'], 'machine_id' => $m['mid'], 'license_key' => $m['key']];
    }

    // Live panel: last 5 minutes, version filter only (same as the real query).
    $livePerSec = [];
    $liveDevs = [];
    for ($i = count($E) - 1; $i >= 0; $i--) {
        [$ts, $mi] = $E[$i];
        if ($ts < $now - 299) break;
        if ($f['ver'] !== '' && $mver[$mi] !== $f['ver']) continue;
        $k = $fmt($ts);
        $livePerSec[$k] = ($livePerSec[$k] ?? 0) + 1;
        $liveDevs[$mi] = true;
    }

    $deviceDetails = [];
    $geoRows = [];
    foreach ($cur['mL'] as $mi => $c) {
        $m = $M[$mi];
        $o = $orgs[$m['org']];
        $geoRows[] = ['machine_id' => $m['mid'], 'license_key' => $m['key'], 'org' => $o['name'], 'country' => $o['country'],
                      'country_code' => $o['cc'], 'continent_code' => $o['cont'], 'city' => $o['city'],
                      'lat' => $o['lat'], 'lon' => $o['lon']];
        if ($f['target'] !== '' && $o['name'] === $f['target']) {
            $deviceDetails[] = ['machine_id' => $m['mid'], 'hostname' => $m['host'], 'local_ips' => $m['lip'],
                                'public_ip' => $o['ip'], 'os_version' => $m['os'], 'arch' => $m['arch'], 'cpu_id' => $m['cpu'],
                                'mb_uuid' => $m['mb'], 'disk_serial' => $m['disk'], 'license_key' => $m['key'],
                                'app_version' => $m['ver'], 'run_count' => $c, 'last_active' => $fmt($cur['mLast'][$mi] ?? $now)];
        }
    }
    usort($deviceDetails, fn($a, $b) => strcmp($b['last_active'], $a['last_active']));

    $allVersions = array_values(array_unique($mver));
    rsort($allVersions, SORT_NATURAL);

    return [
        'kpi' => $kpi, 'kpiPrev' => $kpiPrev,
        'vmCloneOrgs' => count($cloneOrgList), 'overLicensed' => count($overKeyList),
        'cloneOrgList' => $cloneOrgList, 'overKeyList' => $overKeyList, 'geoRows' => $geoRows,
        'tsRows' => $tsRows, 'heat' => $heat, 'orgList' => array_slice($orgList, 0, 30),
        'countries' => $rank('country', 10), 'versions' => $rank('version', 10), 'osStats' => $rank('os', 8),
        'archStats' => $rank('arch', 0), 'licenses' => array_slice($licenses, 0, 15), 'recent' => $recent,
        'deviceDetails' => $deviceDetails, 'allVersions' => $allVersions,
        'livePerSec' => $livePerSec, 'liveDev5' => count($liveDevs),
    ];
}
