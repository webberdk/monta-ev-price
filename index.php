<?php
/* =====================================================================
 *  Ladepris – Monta-standere, Søagerparken 201, 2670 Greve
 *  Beregner aktuel og kommende ladepris ud fra spotprisen (DK2)
 *  fra Energinets Energi Data Service + det tillæg, standeren har i Monta.
 *
 *  INDSTILLINGER – ret disse, så de passer med det, Monta-appen viser:
 * ===================================================================== */

// Prismodel fra ECdrive (operatør på standerne), aflæst i Monta-appen 1. okt. 2026:
//   timepris = gennemsnitlig spotpris for timen × SPOT_FAKTOR + tillæg for tidsrummet
// SPOT_FAKTOR 1,2875 svarer til spotpris + 25 % moms + ca. 3 %.
// Tillæggene (kr/kWh inkl. moms) dækker nettarif, elafgift og ECdrives avance,
// og nettariffen skifter med tidsrum og sæson.
const SPOT_FAKTOR = 1.2875;

// Tillæg pr. tidsrum. Nøglen er den time, tidsrummet starter.
// Vinter = oktober–marts. Spidslast 17–21 er dyrest, lavlast 00–06 er billigst.
const TILLAEG_VINTER = [0 => 2.27, 6 => 2.57, 17 => 3.39, 21 => 2.57];
// Sommer = april–september. Netselskabets sommertariffer er lavere, så ret
// tallene i april ud fra appen (se vejledningen nederst i filen).
const TILLAEG_SOMMER = [0 => 2.27, 6 => 2.57, 17 => 3.39, 21 => 2.57];

// Prisområde: DK2 = Sjælland/øerne (Greve), DK1 = Jylland/Fyn
const PRISOMRAADE = 'DK2';

// Hvor længe spotpriserne caches på serveren (sekunder)
const CACHE_SEKUNDER = 900;

const STED = 'Søagerparken 201, 2670 Greve';

/* ===================================================================== */

ini_set('display_errors', '0');   // vis aldrig PHP-fejl (stier m.m.) til besøgende
date_default_timezone_set('Europe/Copenhagen');

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
            CURLOPT_USERAGENT      => 'ladepris-side/1.1',
        ]);
        $svar = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $svar = false;
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'follow_location' => 0, 'user_agent' => 'ladepris-side/1.1'],
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

function tillaeg(int $ts): float {
    $maaned = (int)date('n', $ts);
    $tabel = ($maaned >= 4 && $maaned <= 9) ? TILLAEG_SOMMER : TILLAEG_VINTER;
    ksort($tabel);
    $time = (int)date('G', $ts);
    $v = end($tabel);
    foreach ($tabel as $fra => $belob) if ($time >= $fra) $v = $belob;
    return $v;
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
                    'pris' => round($spot * SPOT_FAKTOR + tillaeg($ts), 2),
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

/* --- Saml kvarterpriser til timer --------------------------------------- */
$spotdata = hentSpotpriser();
$nu = time();
$liste = samlTimepriser($spotdata['priser'], $nu);
$aktuel = null;
foreach ($liste as $t) if ($t['nu']) $aktuel = $t['pris'];

$kommende = array_values(array_filter($liste, fn($h) => !$h['fortid']));
$billigste = null;
foreach ($kommende as $h) if (!$billigste || $h['pris'] < $billigste['pris']) $billigste = $h;

// Billigste 3 sammenhængende timer fremover
$bedsteVindue = billigsteVindue($kommende);

$sidsteTs = $liste ? end($liste)['ts'] : null;

function iVindue(int $ts): bool {
    global $bedsteVindue;
    return $bedsteVindue && $ts >= $bedsteVindue['start'] && $ts < $bedsteVindue['start'] + 3 * 3600;
}

/* --- Sikkerheds-headers ------------------------------------------------- */
$nonce = base64_encode(random_bytes(16));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Cache-Control: no-cache');

/* --- JSON-udgang: index.php?format=json --------------------------------- */
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sted' => STED, 'enhed' => 'DKK/kWh', 'aktuel' => $aktuel,
        'opdateret' => $spotdata['hentet'] !== null ? date('c', $spotdata['hentet']) : null,
        'foraeldet' => $spotdata['foraeldet'],
        'timer' => array_map(fn($h) => ['tid' => date('c', $h['ts']), 'spot' => $h['spot'], 'pris' => $h['pris']], $liste),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Hjælpere til visning ------------------------------------------------ */
function kr(?float $v): string { return $v === null ? '–' : number_format($v, 2, ',', '.'); }
$dage = ['søndag','mandag','tirsdag','onsdag','torsdag','fredag','lørdag'];
function dagNavn(int $ts): string {
    global $dage;
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'i dag';
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('tomorrow'))) return 'i morgen';
    return $dage[(int)date('w', $ts)];
}
function tid(int $ts): string { return dagNavn($ts) . ' kl. ' . date('H:i', $ts); }

$maks = $liste ? max(array_column($liste, 'pris')) : 1;
$skala = $maks > 0 ? $maks : 1;
$min  = $liste ? min(array_column($liste, 'pris')) : 0;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$prisdage = [];
foreach ($liste as $t) $prisdage[date('Y-m-d', $t['ts'])][] = $t;
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="300">
<title>Monta Ladepriser Søagerparken</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
:root{
  --bg:#f7f7f5; --card:#fff; --ink:#1b1b1a; --ink2:#5d5d58; --muted:#8a8a84; --line:#e6e6e1;
  --bar:#9cc0e6; --bar-past:#dcdcd7; --bar-now:#1d5fae; --bar-cheap:#2e8540; --accent:#1d5fae;
}
@media (prefers-color-scheme: dark){
  :root{ --bg:#141413; --card:#1e1e1c; --ink:#f0f0ec; --ink2:#b4b4ad; --muted:#85857e; --line:#2f2f2c;
         --bar:#3f6c9c; --bar-past:#3a3a37; --bar-now:#8ab8ef; --bar-cheap:#6cc27c; --accent:#8ab8ef; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:860px;margin:0 auto;padding:24px 16px 48px}
h1{font-size:1.25rem;margin:0 0 2px}
.section-title{font-size:1.15rem;margin:0 0 8px}
.sub{color:var(--ink2);margin:0 0 20px;font-size:.92rem}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px}
.label{color:var(--ink2);font-size:.85rem}
.big{font-size:2.6rem;font-weight:700;letter-spacing:-.02em;line-height:1.1;font-variant-numeric:tabular-nums}
.mid{font-size:2.6rem;font-weight:700;letter-spacing:-.02em;line-height:1.1;font-variant-numeric:tabular-nums}
.unit{font-size:.9rem;color:var(--ink2);font-weight:400}
.note{color:var(--ink2);font-size:.85rem}
.day-title{font-size:1rem;margin:20px 0 8px}
.chart-scroll{overflow-x:auto;padding:4px 3px;scrollbar-width:thin}
.chart{display:flex;gap:3px;min-width:720px;height:220px;border-bottom:1px solid var(--line)}
.hour{flex:1;min-width:28px;padding:0;border:0;background:transparent;color:var(--ink2);font:inherit;cursor:pointer;display:flex;flex-direction:column;align-items:stretch;position:relative}
.plot{height:190px;display:flex;align-items:flex-end;position:relative}
.b{width:100%;background:var(--bar);border-radius:4px 4px 0 0;display:block}
.hour.past .b{background:var(--bar-past)} .hour.now .b{background:var(--bar-now)} .hour.cheap .b{background:var(--bar-cheap)}
.hour:hover .b{filter:brightness(1.1)}
.hour:focus-visible,.hour[aria-pressed="true"]{outline:2px solid var(--accent);outline-offset:1px;border-radius:4px}
.hour-label{font-size:.7rem;padding-top:6px;white-space:nowrap}
.marker{position:absolute;top:0;left:0;right:0;font-size:.65rem;font-weight:700;text-align:center}
.selection{min-height:3.5em;padding:12px;background:var(--bg);border-radius:8px;margin-top:12px;font-size:.95rem}
.legend{display:flex;flex-wrap:wrap;gap:14px;font-size:.82rem;color:var(--ink2);margin:10px 0 0}
.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.price-note{margin:4px 0 0;font-size:.9rem;color:var(--ink2)}
details{margin-top:16px}
summary{cursor:pointer;color:var(--accent)}
table{width:100%;border-collapse:collapse;margin-top:8px;font-variant-numeric:tabular-nums}
td,th{padding:5px 8px;border-bottom:1px solid var(--line);text-align:left}
td:last-child,th:last-child{text-align:right}
tr.now td{font-weight:700}
tr.past td{color:var(--muted)}
tr.win td{background:color-mix(in srgb,var(--bar-cheap) 16%,transparent)}
.hero{margin-bottom:12px;border-left:4px solid var(--bar-cheap)}
.when{font-size:1.9rem;font-weight:700;letter-spacing:-.01em;line-height:1.2;margin:2px 0 4px}
.warn{background:#fff4d6;color:#5c4400;border-radius:8px;padding:10px 12px;margin-bottom:16px}
@media (prefers-color-scheme: dark){.warn{background:#3a3016;color:#f3dc9a}}
@media(max-width:480px){.big,.mid{font-size:2rem}.grid .card{padding:12px}.unit{display:block;font-size:.8rem}.when{font-size:1.6rem}.chart{min-width:1056px}.hour{min-width:41px}.marker{font-size:.7rem}}
</style>
</head>
<body>
<main>
  <h1>Monta Ladepriser Søagerparken</h1>
  <p class="sub">OBS: Denne side henter priser fra Andre kilder. Den viste pris, kan varier med nogle få øre.</p>

<?php if (!$liste): ?>
  <div class="warn">Kunne ikke hente spotpriser lige nu. Prøv igen om lidt.</div>
<?php else: ?>
  <?php if ($spotdata['foraeldet']): ?>
  <div class="warn">Spotpriserne kunne ikke opdateres. Der vises forældede data fra <?= $h(date('d-m-Y H:i', $spotdata['hentet'])) ?>.</div>
  <?php endif; ?>
  <?php if ($bedsteVindue): $slut = $bedsteVindue['start'] + 3 * 3600; ?>
  <div class="card hero">
    <div class="label">Lad billigst · Billigste 3 timer i træk</div>
    <div class="when"><?= $h(ucfirst(dagNavn($bedsteVindue['start']))) ?> kl. <?= date('H:i', $bedsteVindue['start']) ?>–<?= date('Y-m-d', $slut) !== date('Y-m-d', $bedsteVindue['start']) ? $h(dagNavn($slut)) . ' kl. ' : '' ?><?= date('H:i', $slut) ?></div>
    <div class="note">
      Gennemsnit <strong><?= kr($bedsteVindue['snit']) ?> kr/kWh</strong>
      <?php if ($aktuel !== null && $aktuel - $bedsteVindue['snit'] >= 0.01): ?>
        · <?= kr($aktuel - $bedsteVindue['snit']) ?> kr/kWh billigere end nu
      <?php elseif ($bedsteVindue['start'] <= $nu): ?>
        · det er nu
      <?php endif; ?>
    </div>
    <?php if ($sidsteTs && date('Y-m-d', $sidsteTs) === date('Y-m-d')): ?>
      <div class="note">Gælder kun i dag – morgendagens priser kommer omkring kl. 13.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <div class="label">Pris lige nu</div>
      <div class="big"><?= kr($aktuel) ?> <span class="unit">kr/kWh</span></div>
      <div class="note">Kl. <?= date('H:i', intdiv($nu, 3600) * 3600) ?>–<?= date('H:i', intdiv($nu, 3600) * 3600 + 3600) ?> · opdateret <?= $spotdata['hentet'] !== null ? $h(date('d-m-Y H:i', $spotdata['hentet'])) : '–' ?></div>
    </div>
    <?php if ($billigste): ?>
    <div class="card">
      <div class="label">Billigste time</div>
      <div class="mid"><?= kr($billigste['pris']) ?> <span class="unit">kr/kWh</span></div>
      <div class="note"><?= $h(tid($billigste['ts'])) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="section-title">Timepris, kr/kWh</h2>
    <p class="price-note">Tryk på en time for at se prisen. Stryg til siden for flere timer, eller brug piletasterne.</p>
    <?php foreach ($prisdage as $dato => $dagtimer): ?>
    <h3 class="day-title"><?= $h(ucfirst(dagNavn($dagtimer[0]['ts']))) ?> · <?= date('d/m', $dagtimer[0]['ts']) ?></h3>
    <div class="chart-scroll" tabindex="0" role="region" aria-label="<?= $h('Timepriser ' . dagNavn($dagtimer[0]['ts'])) ?>">
      <div class="chart" role="group" aria-label="Vælg en time">
      <?php foreach ($dagtimer as $t):
        $erBilligst = $billigste && $t['ts'] === $billigste['ts'];
        $klasse = $t['fortid'] ? 'past' : ($t['nu'] ? 'now' : (iVindue($t['ts']) ? 'cheap' : ''));
        $status = [];
        if ($t['nu']) $status[] = 'Nu';
        if ($erBilligst) $status[] = 'Billigste time';
        if (iVindue($t['ts'])) $status[] = 'Billigste 3 timer';
        if ($t['fortid']) $status[] = 'Tidligere i dag';
        $tekst = tid($t['ts']) . ': ' . kr($t['pris']) . ' kr/kWh' . ($status ? ' · ' . implode(' · ', $status) : '');
        $hoejde = max(4, round($t['pris'] / $skala * 85)); ?>
        <button type="button" class="hour <?= $klasse ?>" aria-pressed="false" aria-label="<?= $h($tekst) ?>" data-tip="<?= $h($tekst) ?>">
          <span class="plot" aria-hidden="true">
            <span class="marker"><?= $t['nu'] ? 'Nu' : ($erBilligst ? 'Lavest' : (iVindue($t['ts']) ? '★' : '')) ?></span>
            <span class="b" style="height:<?= $hoejde ?>%"></span>
          </span>
          <span class="hour-label" aria-hidden="true"><?= date('H:i', $t['ts']) ?></span>
        </button>
      <?php endforeach; ?>
      </div>
    </div>
    <div class="selection" role="status" aria-live="polite" aria-atomic="true">Vælg en time i grafen for at se pris og tidspunkt.</div>
    <?php endforeach; ?>
    <div class="legend">
      <span><i style="background:var(--bar-now)"></i>Nu</span>
      <span><i style="background:var(--bar-cheap)"></i>★ Billigste 3 timer</span>
      <span><i style="background:var(--bar)"></i>Kommende</span>
      <span><i style="background:var(--bar-past)"></i>Tidligere i dag</span>
    </div>
    <p class="note">
      <?php if ($sidsteTs && date('Y-m-d', $sidsteTs) === date('Y-m-d')): ?>
        Morgendagens priser offentliggøres normalt omkring kl. 13.
      <?php else: ?>
        Priser kendt til <?= $h(tid($sidsteTs + 3600)) ?>.
      <?php endif; ?>
      Laveste <?= kr($min) ?> · højeste <?= kr($maks) ?> kr/kWh.
    </p>

    <details>
      <summary>Vis som tabel</summary>
      <table>
        <tr><th scope="col">Tidspunkt</th><th scope="col">Status</th><th scope="col">kr/kWh</th></tr>
        <?php foreach ($liste as $t):
          $status = array_filter([$t['nu'] ? 'Nu' : null,
              $billigste && $t['ts'] === $billigste['ts'] ? 'Billigste time' : null,
              iVindue($t['ts']) ? 'Billigste 3 timer' : null]); ?>
          <tr class="<?= $t['fortid'] ? 'past' : ($t['nu'] ? 'now' : '') ?><?= iVindue($t['ts']) ? ' win' : '' ?>"><td><?= $h(tid($t['ts'])) ?></td><td><?= $h(implode(' · ', $status)) ?></td><td><?= kr($t['pris']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </details>
  </div>
<?php endif; ?>

  <p class="price-note">Den pris, du betaler, er altid den, Monta-appen viser.</p>
  <details class="card">
    <summary>Sådan beregnes prisen</summary>
    <p>Vi bruger Nord Pool-spotprisen for <?= $h(PRISOMRAADE) ?> fra Energinets Energi Data Service. Fire kvarterpriser samles til et gennemsnit for hver time.</p>
    <p>Spotprisen ganges med <?= $h(number_format(SPOT_FAKTOR, 4, ',', '.')) ?>, og derefter lægges et tillæg til for nettarif, afgifter og operatørens avance. Tillægget afhænger af tidspunkt og sæson.</p>
    <p class="note">Beregningen følger ECdrives prismodel, aflæst i Monta-appen. Den beregnede pris kan afvige fra appens pris. Servicen er gratis og stadig i beta.</p>
  </details>
</main>
<script nonce="<?= $nonce ?>">
const hours=[...document.querySelectorAll('.hour')];
function selectHour(button){
  hours.forEach(hour=>hour.setAttribute('aria-pressed',String(hour===button)));
  const selection=button.closest('.chart-scroll').nextElementSibling;
  if(selection) selection.textContent=button.dataset.tip;
}
hours.forEach(button=>{
  button.addEventListener('click',()=>selectHour(button));
  button.addEventListener('focus',()=>selectHour(button));
  button.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
    event.preventDefault();
    const index=hours.indexOf(button);
    const next=event.key==='Home'?0:event.key==='End'?hours.length-1:Math.max(0,Math.min(hours.length-1,index+(event.key==='ArrowRight'?1:-1)));
    hours[next].focus();
  });
});
const current=hours.find(button=>button.classList.contains('now'));
if(current){
  selectHour(current);
  const scroll=current.closest('.chart-scroll');
  scroll.scrollLeft=current.getBoundingClientRect().left-scroll.getBoundingClientRect().left+scroll.scrollLeft-scroll.clientWidth/2+current.clientWidth/2;
}
</script>
</body>
</html>
<?php
/* =====================================================================
 *  SÅDAN JUSTERER DU TILLÆGGENE (fx når sommertarifferne starter i april)
 *  1. Åbn standeren i Monta-appen og notér prisen for et par timer i hvert
 *     tidsrum (00–06, 06–17, 17–21, 21–24).
 *  2. Åbn index.php?format=json og find "spot" for de samme timer.
 *  3. Tillæg = app-pris − spot × SPOT_FAKTOR. Skriv tallet ind i tabellen.
 * ===================================================================== */
