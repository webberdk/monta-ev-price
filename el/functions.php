<?php
declare(strict_types=1);
require_once __DIR__ . "/config.php";
date_default_timezone_set("Europe/Copenhagen");
/** Validerer og normaliserer prisdata (fra API eller cache) – alt udefra er utroværdigt. */
function rensPriser($records, string $tidFelt, string $prisFelt, float $divisor): array {
    $ud = [];
    if (!is_array($records)) return $ud;
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        $t = $r[$tidFelt] ?? null;
        $v = $r[$prisFelt] ?? null;
        if (!is_string($t) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $t)) continue;
        $format = strlen($t) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s';
        $dato = DateTimeImmutable::createFromFormat($format, $t, new DateTimeZone('UTC'));
        $fejl = DateTimeImmutable::getLastErrors();
        if (!$dato || ($fejl && ($fejl['warning_count'] || $fejl['error_count']))) continue;
        if (!is_int($v) && !is_float($v)) continue;
        $spot = $v / $divisor;
        if (!is_finite($spot) || abs($spot) > 100) continue; // urimelige værdier (kr/kWh) afvises
        $ud[] = ['t' => $t, 'spot' => $spot];
    }
    return $ud;
}

function cacheSti(): string {
    // Egen mappe med adgang nægtet via .htaccess. Delt /tmp på webhoteller undgås,
    // fordi andre kunder på samme server kan skrive til den.
    $mappe = __DIR__ . '/cache';
    if (!is_dir($mappe)) @mkdir($mappe, 0750);
    if (is_dir($mappe) && !is_file($mappe . '/.htaccess')) {
        @file_put_contents($mappe . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    // Ny fil: den tidligere cache indeholdt lokal tid og kan ikke bruges som UTC.
    return $mappe . '/spot-' . PRISOMRAADE . '-utc.json';
}

function hentSpotpriser(): array {
    $cacheFil = cacheSti();
    $gemt = is_file($cacheFil) ? json_decode((string)@file_get_contents($cacheFil), true) : null;
    $cache = rensPriser($gemt['priser'] ?? null, 't', 'spot', 1);
    $hentet = $gemt['hentet'] ?? null;
    if (!is_int($hentet) || $hentet <= 0 || $hentet > time()) {
        $cache = [];
        $hentet = null;
    }
    $foraeldet = $hentet === null || time() - $hentet >= CACHE_SEKUNDER;
    // mtime styrer næste forsøg; hentet bevarer tidspunktet for faktisk API-succes.
    if ($cache && time() - (int)@filemtime($cacheFil) < CACHE_SEKUNDER) {
        return ['priser' => $cache, 'hentet' => $hentet, 'foraeldet' => $foraeldet];
    }

    $url = 'https://api.energidataservice.dk/dataset/DayAheadPrices?' . http_build_query([
        'start'   => date('Y-m-d', strtotime('-1 day')),
        'filter'  => json_encode(['PriceArea' => [PRISOMRAADE]]),
        'columns' => 'TimeUTC,DayAheadPriceDKK',
        'sort'    => 'TimeUTC asc',
        'limit'   => 0,
    ]);

    $svar = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE    => 2000000,
            CURLOPT_USERAGENT      => 'smedegaard-flexenergi/1.0',
        ]);
        $svar = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $svar = false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'follow_location' => 0, 'user_agent' => 'smedegaard-flexenergi/1.0'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $svar = @file_get_contents($url, false, $ctx, 0, 2000000);
    }

    $json = is_string($svar) ? json_decode($svar, true) : null;
    $priser = rensPriser($json['records'] ?? null, 'TimeUTC', 'DayAheadPriceDKK', 1000); // DKK/MWh -> kr/kWh

    if ($priser) {
        $hentet = time();
        $data = ['priser' => $priser, 'hentet' => $hentet];
        @file_put_contents($cacheFil, json_encode($data), LOCK_EX);
        return $data + ['foraeldet' => false];
    }
    // Fejl hos Energinet: brug gammel cache og vent 2 min. før næste forsøg,
    // så siden ikke hænger på timeout ved hvert eneste besøg.
    if ($cache) @touch($cacheFil, time() - CACHE_SEKUNDER + 120);
    return ['priser' => $cache, 'hentet' => $hentet, 'foraeldet' => true];
}

function tillaeg(int $ts): ?float {
    if (date('Y-m-d', $ts) < SATSER_FRA || date('Y-m-d', $ts) >= SATSER_TIL) return null;
    $winter = (int)date('n', $ts) <= 3 || (int)date('n', $ts) >= 10;
    $hour = (int)date('G', $ts);
    $net = $hour < 6 ? RADIUS_LAV : ($hour >= 17 && $hour < 21
        ? ($winter ? RADIUS_VINTER_SPIDS : RADIUS_SOMMER_SPIDS)
        : ($winter ? RADIUS_VINTER_HOEJ : RADIUS_SOMMER_HOEJ));
    return ANDEL_TILLAEG + ($net + ENERGINET_NET + ENERGINET_SYSTEM + ELAFGIFT) * MOMS;
}

/** Kun komplette timer med fire forskellige UTC-kvarterer må få en timepris. */
function samlTimepriser(array $kvarterer, int $nu): array {
    $idagStart = strtotime(date('Y-m-d', $nu) . ' 00:00:00');
    $timer = [];
    $konflikter = [];
    foreach ($kvarterer as $k) {
        $ts = strtotime($k['t'] . 'Z');
        if ($ts === false || $ts < $idagStart || $ts % 900 !== 0) continue;
        $start = intdiv($ts, 3600) * 3600;
        if (isset($timer[$start][$ts]) && $timer[$start][$ts] !== $k['spot']) {
            $konflikter[$start] = true;
        }
        $timer[$start][$ts] = $k['spot'];
    }
    ksort($timer, SORT_NUMERIC);
    $liste = [];
    foreach ($timer as $ts => $kvarterpriser) {
        if (count($kvarterpriser) !== 4 || isset($konflikter[$ts])) continue;
        $spot = array_sum($kvarterpriser) / 4;
        $liste[] = ['ts' => $ts, 'spot' => round($spot, 4),
                    'pris' => tillaeg($ts) === null ? null : round($spot * MOMS + tillaeg($ts), 2),
                    'nu' => $nu >= $ts && $nu < $ts + 3600, 'fortid' => $ts + 3600 <= $nu];
    }
    return $liste;
}

function billigsteVindue(array $kommende): ?array {
    $bedste = null;
    for ($i = 0; $i + 2 < count($kommende); $i++) {
        if ($kommende[$i+1]['ts'] !== $kommende[$i]['ts'] + 3600 ||
            $kommende[$i+2]['ts'] !== $kommende[$i]['ts'] + 7200) continue;
        $snit = ($kommende[$i]['pris'] + $kommende[$i+1]['pris'] + $kommende[$i+2]['pris']) / 3;
        if (!$bedste || $snit < $bedste['snit']) $bedste = ['start' => $kommende[$i]['ts'], 'snit' => $snit];
    }
    return $bedste;
}


/** Forecast timestamps are UTC epochs, including both repeated autumn hours. */
function rensVejr($data): array {
    if (!is_array($data) || ($data['hourly_units']['time'] ?? '') !== 'unixtime'
        || ($data['hourly_units']['temperature_2m'] ?? '') !== '°C'
        || ($data['hourly_units']['wind_speed_10m'] ?? '') !== 'm/s'
        || ($data['hourly_units']['precipitation'] ?? '') !== 'mm') return [];
    $hours = $data['hourly'] ?? [];
    if (!is_array($hours['time'] ?? null)) return [];
    $rows = [];
    foreach ($hours['time'] as $i => $ts) {
        if (!is_int($ts) || $ts % 3600 !== 0) continue;
        $number = static function ($v, float $min, float $max): ?float {
            return (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $min && $v <= $max ? (float)$v : null;
        };
        $code = $hours['weather_code'][$i] ?? null;
        // Open-Meteo precipitation is the preceding hour's sum; take the next
        // timestamp for the interval starting at $ts. No next hour means unknown.
        $next = ($hours['time'][$i + 1] ?? null) === $ts + 3600;
        $rows[$ts] = [
            'temperatur' => $number($hours['temperature_2m'][$i] ?? null, -90, 65),
            'vind' => $number($hours['wind_speed_10m'][$i] ?? null, 0, 120),
            'nedboer' => $next ? $number($hours['precipitation'][$i + 1] ?? null, 0, 500) : null,
            'kode' => is_int($code) && in_array($code, [0,1,2,3,45,48,51,53,55,56,57,61,63,65,66,67,71,73,75,77,80,81,82,85,86,95,96,99], true) ? $code : null,
            'dag' => ($hours['is_day'][$i] ?? null) === 1,
        ];
    }
    return $rows;
}

function hentVejr(): array {
    cacheSti();
    $file = __DIR__ . '/cache/weather-' . VEJR_LAT . '-' . VEJR_LON . '.json';
    $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    $fetched = $cached['hentet'] ?? null;
    $rows = rensVejr($cached['data'] ?? null);
    if (!is_int($fetched) || $fetched <= 0 || $fetched > time()) { $rows = []; $fetched = null; }
    if ($rows && time() - (int)@filemtime($file) < VEJR_CACHE_SEKUNDER)
        return ['timer' => $rows, 'hentet' => $fetched, 'foraeldet' => time() - $fetched >= VEJR_CACHE_SEKUNDER];
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => VEJR_LAT, 'longitude' => VEJR_LON,
        'hourly' => 'temperature_2m,precipitation,weather_code,wind_speed_10m,is_day',
        'wind_speed_unit' => 'ms', 'timeformat' => 'unixtime', 'timezone' => 'UTC',
        'past_days' => 1, 'forecast_days' => 3,
    ]);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 2000000]);
        $body = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $body = false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $context, 0, 2000000);
    }
    $json = is_string($body) ? json_decode($body, true) : null;
    $new = rensVejr($json);
    if ($new) {
        $fetched = time();
        @file_put_contents($file, json_encode(['hentet' => $fetched, 'data' => $json]), LOCK_EX);
        return ['timer' => $new, 'hentet' => $fetched, 'foraeldet' => false];
    }
    if ($rows) @touch($file, time() - VEJR_CACHE_SEKUNDER + 120);
    return ['timer' => $rows, 'hentet' => $fetched, 'foraeldet' => true];
}

function vejrSymbol(?array $weather): array {
    $code = $weather['kode'] ?? null;
    if ($code === null) return [null, 'Vejr mangler'];
    if ($code === 0) return [$weather['dag'] ? 'sun' : 'moon', $weather['dag'] ? 'Klart' : 'Klar nat'];
    if ($code <= 2) return [$weather['dag'] ? 'cloud-sun' : 'cloud-moon', 'Let skyet'];
    if ($code === 3) return ['cloud', 'Overskyet'];
    if ($code <= 48) return ['cloud-fog', 'Tåge'];
    if (in_array($code, [56,57,66,67], true)) return ['cloud-rain', 'Underafkølet nedbør'];
    if ($code <= 67) return ['cloud-rain', 'Regn'];
    if ($code <= 77 || in_array($code, [85,86], true)) return ['cloud-snow', 'Sne'];
    if ($code <= 82) return ['cloud-drizzle', 'Regnbyger'];
    return ['cloud-lightning', 'Torden'];
}
