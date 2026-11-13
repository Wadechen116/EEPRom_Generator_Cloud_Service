<?php
/**
 * Geography for monitor/index.php: map points, country and continent statistics of "疑似盜用" devices.
 * Shared by the real data (SQL rows) and the demo data (demo_data.php), so both follow the same rules.
 *
 * A device counts as 疑似盜用 (suspected unlicensed use) when ANY of these holds -- the same rules as the
 * stat tiles at the top of the page:
 *   - its license key runs on more devices than telemetry.license_device_limit allows (授權碼超用);
 *   - its organisation is a suspected VM clone (more motherboards than disks: one disk image copied);
 *   - it reports a license_key listed in telemetry.crack_markers (the app's own "cracked" marker).
 *
 * Locations come from IP geolocation (ip_intel_cache.lat/lon): city level at best, often the ISP's location.
 */

if (!defined('APP_ENTRY')) {
    http_response_code(403);
    exit;
}

/** The five continents as commonly counted in Taiwan (Americas = North + South). Display order. */
const GEO_CONTINENTS = ['AS' => '亞洲', 'EU' => '歐洲', 'AM' => '美洲', 'AF' => '非洲', 'OC' => '大洋洲'];

/** ip-api continentCode -> one of GEO_CONTINENTS (or '' = unknown; Antarctica also lands there). */
function geo_continent_key(?string $code): string {
    $code = strtoupper((string) $code);
    if ($code === 'NA' || $code === 'SA') return 'AM';
    return isset(GEO_CONTINENTS[$code]) ? $code : '';
}

/** Chinese name for common ISO country codes; falls back to the English name from ip-api. */
function geo_country_name(?string $cc, ?string $english): string {
    static $zh = [
        'TW' => '台灣', 'CN' => '中國', 'HK' => '香港', 'MO' => '澳門', 'JP' => '日本', 'KR' => '南韓', 'KP' => '北韓',
        'VN' => '越南', 'TH' => '泰國', 'MY' => '馬來西亞', 'SG' => '新加坡', 'ID' => '印尼', 'PH' => '菲律賓',
        'IN' => '印度', 'PK' => '巴基斯坦', 'BD' => '孟加拉', 'KH' => '柬埔寨', 'MM' => '緬甸', 'LK' => '斯里蘭卡',
        'AE' => '阿聯', 'SA' => '沙烏地阿拉伯', 'IL' => '以色列', 'TR' => '土耳其', 'IR' => '伊朗', 'KZ' => '哈薩克',
        'US' => '美國', 'CA' => '加拿大', 'MX' => '墨西哥', 'BR' => '巴西', 'AR' => '阿根廷', 'CL' => '智利',
        'CO' => '哥倫比亞', 'PE' => '秘魯', 'GB' => '英國', 'DE' => '德國', 'FR' => '法國', 'IT' => '義大利',
        'ES' => '西班牙', 'NL' => '荷蘭', 'BE' => '比利時', 'CH' => '瑞士', 'AT' => '奧地利', 'SE' => '瑞典',
        'NO' => '挪威', 'DK' => '丹麥', 'FI' => '芬蘭', 'PL' => '波蘭', 'CZ' => '捷克', 'HU' => '匈牙利',
        'RO' => '羅馬尼亞', 'UA' => '烏克蘭', 'RU' => '俄羅斯', 'PT' => '葡萄牙', 'IE' => '愛爾蘭', 'GR' => '希臘',
        'AU' => '澳洲', 'NZ' => '紐西蘭', 'ZA' => '南非', 'EG' => '埃及', 'NG' => '奈及利亞', 'KE' => '肯亞',
        'MA' => '摩洛哥', 'ET' => '衣索比亞',
    ];
    $cc = strtoupper((string) $cc);
    if (isset($zh[$cc])) return $zh[$cc];
    return ($english !== null && $english !== '') ? $english : '未知';
}

/**
 * $rows: one row per (device, public IP) with keys machine_id, license_key, org, country, country_code,
 *        continent_code, city, lat, lon.
 * $overKeys / $cloneOrgs: lists of over-used license keys / VM-clone organisation names for the same filters.
 * Returns ['points' => [...], 'countries' => [...], 'continents' => [...], 'devices' => n, 'located' => n,
 *          'suspect' => n].
 */
function monitor_geo_aggregate(array $rows, array $overKeys, array $cloneOrgs, array $crackMarkers): array {
    $over  = array_flip(array_map('strval', $overKeys));
    $clone = array_flip(array_map('strval', $cloneOrgs));
    $crack = array_flip(array_map('strval', $crackMarkers));

    $devices = $suspectAll = $located = [];
    $countries = $continents = $points = [];
    foreach ($rows as $r) {
        $mid = (string) $r['machine_id'];
        $key = (string) ($r['license_key'] ?? '');
        $org = (string) ($r['org'] ?? '');
        $sus = ($key !== '' && isset($over[$key])) || isset($clone[$org]) || ($key !== '' && isset($crack[$key]));

        $devices[$mid] = true;
        if ($sus) $suspectAll[$mid] = true;

        $cname = geo_country_name($r['country_code'] ?? null, $r['country'] ?? null);
        $ckey  = strtoupper((string) ($r['country_code'] ?? '')) ?: $cname;
        $cont  = geo_continent_key($r['continent_code'] ?? null);
        $c = &$countries[$ckey];
        $c['name'] = $cname;
        $c['continent'] = $cont;
        $c['dev'][$mid] = true;
        if ($sus) $c['sus'][$mid] = true;
        unset($c);

        $k = &$continents[$cont];
        $k['dev'][$mid] = true;
        if ($sus) $k['sus'][$mid] = true;
        unset($k);

        $lat = $r['lat'] ?? null;
        $lon = $r['lon'] ?? null;
        if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) {
            continue;
        }
        $located[$mid] = true;
        $pk = sprintf('%.2f,%.2f', $lat, $lon);   // ~1 km grid: devices behind one ISP gateway share a dot
        $p = &$points[$pk];
        $p['lat'] = round((float) $lat, 4);
        $p['lon'] = round((float) $lon, 4);
        $city = trim((string) ($r['city'] ?? ''));
        $p['place'] = $city !== '' ? "{$city}，{$cname}" : $cname;
        $p['dev'][$mid] = true;
        if ($sus) $p['sus'][$mid] = true;
        $p['orgs'][$org] = ($p['orgs'][$org] ?? 0) + 1;
        unset($p);
    }

    $outPoints = [];
    foreach ($points as $p) {
        arsort($p['orgs']);
        $outPoints[] = ['lat' => $p['lat'], 'lon' => $p['lon'], 'place' => $p['place'],
                        'n' => count($p['dev']), 's' => count($p['sus'] ?? []),
                        'orgs' => array_slice(array_keys($p['orgs']), 0, 3)];
    }
    usort($outPoints, fn($a, $b) => $b['n'] <=> $a['n']);

    $outCountries = [];
    foreach ($countries as $c) {
        $n = count($c['dev']);
        $s = count($c['sus'] ?? []);
        $outCountries[] = ['name' => $c['name'], 'continent' => GEO_CONTINENTS[$c['continent']] ?? '未知',
                           'n' => $n, 's' => $s];
    }
    usort($outCountries, fn($a, $b) => [$b['s'], $b['n']] <=> [$a['s'], $a['n']]);

    $outContinents = [];
    foreach (GEO_CONTINENTS + ['' => '未知'] as $code => $name) {
        $n = count($continents[$code]['dev'] ?? []);
        if ($code === '' && $n === 0) continue;   // show "未知" only when there is something in it
        $outContinents[] = ['name' => $name, 'n' => $n, 's' => count($continents[$code]['sus'] ?? [])];
    }

    return [
        'points'     => array_slice($outPoints, 0, 600),   // the busiest 600 locations are plenty for a map
        'countries'  => $outCountries,
        'continents' => $outContinents,
        'devices'    => count($devices),
        'located'    => count($located),
        'suspect'    => count($suspectAll),
    ];
}
