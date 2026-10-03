<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/functions.php';
$nu = time();
$spotdata = hentSpotpriser();
$vejrdata = hentVejr();
$prices = samlTimepriser($spotdata['priser'], $nu);
$byTime = array_column($prices, null, 'ts');
$start = strtotime('today'); $end = strtotime('tomorrow +1 day');
$liste = [];
for ($ts = $start; $ts < $end; $ts += 3600) {
    $liste[] = ($byTime[$ts] ?? ['ts' => $ts, 'spot' => null, 'pris' => null,
        'nu' => $nu >= $ts && $nu < $ts + 3600, 'fortid' => $ts + 3600 <= $nu])
        + ['vejr' => $vejrdata['timer'][$ts] ?? null];
}
$kommende = array_values(array_filter($liste, fn($r) => !$r['fortid'] && $r['pris'] !== null));
$billigste = null;
foreach ($kommende as $r) if (!$billigste || $r['pris'] < $billigste['pris']) $billigste = $r;
$bedsteVindue = billigsteVindue($kommende);
$aktuel = null;
foreach ($liste as $r) if ($r['nu']) $aktuel = $r;
$known = array_values(array_filter($liste, fn($r) => $r['pris'] !== null));
$maks = $known ? max(array_column($known, 'pris')) : 1;
$min = $known ? min(array_column($known, 'pris')) : 0;
// The zero baseline separates negative and positive prices truthfully.
$lo = min(0, $min); $hi = max(0.01, $maks); $range = $hi - $lo;
$zero = 100 * (0 - $lo) / $range;
$satserGyldige = date('Y-m-d') >= SATSER_FRA && date('Y-m-d') < SATSER_TIL;
$nonce = base64_encode(random_bytes(16));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'nonce-$nonce'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Cache-Control: no-cache');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['sted' => STED, 'enhed' => 'DKK/kWh', 'inkl_moms_transport_afgifter' => true,
        'faste_abonnementer_inkluderet' => false, 'netselskab' => 'Radius C',
        'satser_gyldige' => $satserGyldige, 'satser_til' => SATSER_TIL,
        'priser_opdateret' => $spotdata['hentet'] ? date('c', $spotdata['hentet']) : null,
        'priser_foraeldede' => $spotdata['foraeldet'],
        'vejr_opdateret' => $vejrdata['hentet'] ? date('c', $vejrdata['hentet']) : null,
        'vejr_foraeldet' => $vejrdata['foraeldet'],
        'timer' => array_map(fn($r) => ['tid' => date('c', $r['ts']), 'spot' => $r['spot'],
            'pris' => $r['pris'], 'vejr' => $r['vejr']], $liste)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tal(?float $v, int $decimals = 2): string { return $v === null ? '–' : number_format($v, $decimals, ',', '.'); }
function dag(int $ts): string { return date('Y-m-d', $ts) === date('Y-m-d') ? 'I dag' : 'I morgen'; }
function klok(int $ts): string { return date('H:i', $ts) . (date('I', $ts) ? ' CEST' : ' CET'); }
function tidspunkt(int $ts): string { return dag($ts) . ' kl. ' . klok($ts); }
function vindue(int $ts): bool {
    global $bedsteVindue;
    return $bedsteVindue && $ts >= $bedsteVindue['start'] && $ts < $bedsteVindue['start'] + 10800;
}
function vejrTekst(?array $w): string {
    if (!$w) return 'Vejr mangler';
    return vejrSymbol($w)[1] . ' · ' . tal($w['temperatur'], 1) . ' °C · '
        . tal($w['nedboer'], 1) . ' mm · vind ' . tal($w['vind'], 1) . ' m/s';
}
$dage = [];
foreach ($liste as $r) $dage[date('Y-m-d', $r['ts'])][] = $r;
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="300"><title>FlexEnergi & vejr · Greve</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg"><link rel="stylesheet" href="style.css">
</head>
<body><main>
<h1>FlexEnergi & vejr · Greve</h1>
<p class="sub">Timepriser inklusive moms, transport og afgifter · Radius C · faste abonnementer kommer oveni.</p>
<?php if (!$satserGyldige): ?><div class="warn">Prissatserne skal opdateres. Samlede elpriser vises igen, når satserne er kontrolleret.</div><?php endif; ?>
<?php if (!$prices): ?><div class="warn">Spotpriserne kunne ikke hentes. Vejret vises fortsat, hvis det er tilgængeligt.</div>
<?php elseif ($spotdata['foraeldet']): ?><div class="warn">Spotpriserne kunne ikke opdateres. Sidst hentet <?= esc(date('d/m H:i', $spotdata['hentet'])) ?>.</div><?php endif; ?>
<?php if (!$vejrdata['timer']): ?><div class="warn">Vejret kunne ikke hentes. Elpriserne vises fortsat.</div>
<?php elseif ($vejrdata['foraeldet']): ?><div class="warn">Vejrudsigten kunne ikke opdateres. Sidst hentet <?= esc(date('d/m H:i', $vejrdata['hentet'])) ?>.</div><?php endif; ?>
<?php if ($bedsteVindue): ?>
<div class="card hero"><div class="label">Billigste 3 timer i træk</div>
<div class="when"><?= esc(tidspunkt($bedsteVindue['start'])) ?>–<?= esc(klok($bedsteVindue['start'] + 10800)) ?><?= date('Y-m-d', $bedsteVindue['start']) !== date('Y-m-d', $bedsteVindue['start'] + 10800) ? ' i morgen' : '' ?></div>
<div class="note">Gennemsnit <strong><?= tal($bedsteVindue['snit']) ?> kr/kWh</strong> · blandt de kendte priser fra nu.</div></div>
<?php endif; ?>
<div class="grid"><div class="card"><div class="label">Pris lige nu</div>
<div class="big"><?= tal($aktuel['pris'] ?? null) ?> <span class="unit">kr/kWh</span></div>
<div class="note"><?= esc(klok($aktuel['ts'])) ?> · <?= esc(vejrTekst($aktuel['vejr'])) ?></div></div>
<div class="card"><div class="label">Billigste kommende time</div>
<div class="mid"><?= tal($billigste['pris'] ?? null) ?> <span class="unit">kr/kWh</span></div>
<div class="note"><?= $billigste ? esc(tidspunkt($billigste['ts'])) : 'Afventer priser' ?></div></div></div>
<div class="card"><h2 class="section-title">Elpris og vejr time for time</h2>
<p class="price-note">Tryk på en time for detaljer. Stryg til siden for flere timer.</p>
<?php foreach ($dage as $timer): ?>
<h3 class="day-title"><?= esc(dag($timer[0]['ts'])) ?> · <?= date('d/m', $timer[0]['ts']) ?></h3>
<div class="chart-scroll" tabindex="0" role="region" aria-label="<?= esc(dag($timer[0]['ts'])) ?> priser og vejr"><div class="chart" role="group" aria-label="Vælg en time">
<?php foreach ($timer as $r):
    $w = $r['vejr']; [$symbol, $description] = vejrSymbol($w);
    $class = $r['fortid'] ? 'past' : ($r['nu'] ? 'now' : (vindue($r['ts']) ? 'cheap' : ''));
    $label = tidspunkt($r['ts']) . ' · ' . ($r['pris'] === null ? 'Pris afventer' : tal($r['pris']) . ' kr/kWh') . ' · ' . vejrTekst($w);
    $height = $r['pris'] === null ? 0 : abs($r['pris']) / $range * 85;
    $bottom = $r['pris'] !== null && $r['pris'] < 0 ? $zero * .85 - $height : $zero * .85;
?>
<button type="button" class="hour <?= esc($class) ?>" aria-pressed="false" aria-label="<?= esc($label) ?>" data-tip="<?= esc($label) ?>">
<span class="plot" aria-hidden="true">
<span class="marker"><?= $r['nu'] ? 'Nu' : ($billigste && $r['ts'] === $billigste['ts'] ? 'Lavest' : (vindue($r['ts']) ? '★' : '')) ?></span>
<span style="position:absolute;left:0;right:0;bottom:<?= $zero * .85 ?>%;border-top:1px solid var(--line)"></span>
<?php if ($r['pris'] !== null): ?><span class="b <?= $r['pris'] < 0 ? 'negative' : '' ?>" style="position:absolute;bottom:<?= $bottom ?>%;height:<?= $height ?>%"></span><?php else: ?><span class="b missing"></span><?php endif; ?>
</span><span class="hour-label" aria-hidden="true"><?= date('H:i', $r['ts']) ?></span>
<span class="weather" aria-hidden="true"><span class="weather-icon"><?php if ($symbol): ?><img src="icons/<?= esc($symbol) ?>.svg" width="24" height="24" alt=""><?php else: ?>–<?php endif; ?></span><span><?= tal($w['temperatur'] ?? null, 0) ?>°</span><span class="weather-small"><?= tal($w['nedboer'] ?? null, 1) ?> mm</span><span class="weather-small"><?= tal($w['vind'] ?? null, 1) ?> m/s</span></span>
</button>
<?php endforeach; ?></div></div>
<div class="selection" role="status" aria-live="polite" aria-atomic="true">Vælg en time for at se pris og vejr.</div>
<?php endforeach; ?>
<div class="legend"><span><i style="background:var(--bar-now)"></i>Nu</span><span><i style="background:var(--bar-cheap)"></i>Billigste 3 timer</span><span><i style="background:var(--bar)"></i>Kommende</span><span><i style="background:var(--bar-past)"></i>Tidligere</span></div>
<p class="note">Morgendagens priser vises, når de er offentliggjort. Andel oplyser normalt omkring kl. 14. En manglende pris vises som “–”.</p>
<details><summary>Vis priser og vejr som tabel</summary><div class="table-scroll"><table>
<thead><tr><th scope="col">Tid</th><th scope="col">kr/kWh</th><th scope="col">Vejr</th><th scope="col">°C</th><th scope="col">mm</th><th scope="col">m/s</th></tr></thead><tbody>
<?php foreach ($liste as $r): $w = $r['vejr']; ?>
<tr class="<?= $r['nu'] ? 'now' : ($r['fortid'] ? 'past' : '') ?><?= vindue($r['ts']) ? ' win' : '' ?>"><td><?= esc(tidspunkt($r['ts'])) ?></td><td><?= tal($r['pris']) ?></td><td><?= esc(vejrSymbol($w)[1]) ?></td><td><?= tal($w['temperatur'] ?? null, 1) ?></td><td><?= tal($w['nedboer'] ?? null, 1) ?></td><td><?= tal($w['vind'] ?? null, 1) ?></td></tr>
<?php endforeach; ?></tbody></table></div></details></div>
<p class="note forecast-note">Spotpriser opdateret <?= $spotdata['hentet'] ? esc(date('d/m H:i', $spotdata['hentet'])) : '–' ?> · vejr opdateret <?= $vejrdata['hentet'] ? esc(date('d/m H:i', $vejrdata['hentet'])) : '–' ?>.</p>
<p class="note">Vejr: <a href="https://open-meteo.com/">Open-Meteo</a> (CC BY 4.0). Prognose for Greve; nedbør er summen for det viste timeinterval.</p>
<details class="card"><summary>Sådan beregnes elprisen</summary>
<p>Fire kvarterpriser fra Energinet for DK2 samles til timegennemsnit. Spotpris × 1,25 + Andels tillæg + Radius’ nettarif + Energinets tariffer + elafgift. Alle beløb i visningen er inklusive moms.</p>
<p>Andels tillæg er <?= tal(ANDEL_TILLAEG * 100) ?> øre/kWh inklusive moms. Radius kundekategori C er lagt til grund. Faste el-, net- og systemabonnementer er ikke fordelt på kWh og betales oveni.</p>
<p>Hvis din måler afregnes hvert 15. minut, er timevisningen et gennemsnit. Din faktiske regning afhænger af, hvornår i timen du bruger strømmen. Kontrollér dit personlige pristillæg og netselskab på din regning.</p>
<p class="note">Satser kontrolleret 3. oktober 2026, gyldighed i denne side til <?= esc(SATSER_TIL) ?>. Derefter afventer samlede priser opdaterede satser.</p>
<p class="note">Kilder: <a href="https://andelenergi.dk/el/flexenergi/">Andel</a> · <a href="https://radiuselnet.dk/priser/alle/">Radius</a> · <a href="https://energinet.dk/media/5v3pikp3/energinets_tarifkatalog_2026.pdf">Energinet 2026</a> · <a href="https://skat.dk/erhverv/afgifter-paa-varer-og-ydelser-punktafgifter/nyhedsbrev-afgifter/midlertidig-nedsaettelse-af-elafgiften-i-2026-og-2027">Skattestyrelsen</a>.</p>
</details>
</main>
<script nonce="<?= esc($nonce) ?>">
const hours=[...document.querySelectorAll('.hour')];
function selectHour(button){
  hours.forEach(hour=>hour.setAttribute('aria-pressed',String(hour===button)));
  button.closest('.chart-scroll').nextElementSibling.textContent=button.dataset.tip;
}
hours.forEach(button=>{
  button.addEventListener('click',()=>selectHour(button));
  button.addEventListener('focus',()=>selectHour(button));
  button.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
    event.preventDefault(); const index=hours.indexOf(button);
    const next=event.key==='Home'?0:event.key==='End'?hours.length-1:Math.max(0,Math.min(hours.length-1,index+(event.key==='ArrowRight'?1:-1)));
    hours[next].focus();
  });
});
const current=hours.find(button=>button.classList.contains('now'));
if(current){selectHour(current);const scroll=current.closest('.chart-scroll');scroll.scrollLeft=current.offsetLeft-scroll.offsetLeft-scroll.clientWidth/2+current.clientWidth/2;}
</script>
</body></html>
