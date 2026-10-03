<?php
require dirname(__DIR__) . '/el/functions.php';
$checks = 0;
function checkFlex(bool $ok, string $why): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($why);
}
function kvarterer(string $utc, array $values): array {
    $start = strtotime($utc);
    return array_map(fn($i, $v) => ['t' => gmdate('Y-m-d\TH:i:s', $start + 900 * $i), 'spot' => $v], array_keys($values), $values);
}
$nu = strtotime('2026-10-03T10:30:00Z');
$r = samlTimepriser(kvarterer('2026-10-03T10:00:00Z', [1,1,1,1]), $nu);
checkFlex($r[0]['pris'] === 1.95, 'Winter total incl VAT');
foreach (['2026-10-03T03:00:00Z' => 1.68, '2026-10-03T15:00:00Z' => 2.74,
    '2026-10-03T19:00:00Z' => 1.95, '2026-07-03T15:00:00Z' => 2.07] as $utc => $expected) {
    $r = samlTimepriser(kvarterer($utc, [1,1,1,1]), strtotime($utc));
    checkFlex($r[0]['pris'] === $expected, 'Tariff boundary ' . $utc);
}
checkFlex(tillaeg(strtotime('2027-01-01T00:00:00+01:00')) === null, 'Expired rates are not reused');
checkFlex(samlTimepriser(kvarterer('2026-10-03T10:00:00Z', [1,1,1]), $nu) === [], 'Missing quarter must not give price');
$r = samlTimepriser(kvarterer('2026-10-03T10:00:00Z', [-3,-3,-3,-3]), $nu);
checkFlex($r[0]['pris'] < 0, 'Negative total retained');
$r = samlTimepriser(kvarterer('2026-10-25T00:00:00Z', [1,1,1,1,2,2,2,2]), strtotime('2026-10-25T00:30:00Z'));
checkFlex(count($r) === 2 && date('H:i', $r[0]['ts']) === date('H:i', $r[1]['ts']), 'Autumn repeated hours retained');
checkFlex(date('P', $r[0]['ts']) !== date('P', $r[1]['ts']), 'Repeated hours distinguished by offset');
checkFlex($r[0]['nu'] && !$r[1]['nu'], 'Only one current repeated hour');
$w = ['hourly_units' => ['time'=>'unixtime','temperature_2m'=>'°C','precipitation'=>'mm','wind_speed_10m'=>'m/s'],
    'hourly' => ['time'=>[1792886400,1792890000], 'temperature_2m'=>[0,null],
    'wind_speed_10m'=>[0,null], 'precipitation'=>[8,2], 'weather_code'=>[0,null], 'is_day'=>[0,1]]];
$rows = rensVejr($w);
checkFlex($rows[1792886400]['temperatur'] === 0.0 && $rows[1792886400]['vind'] === 0.0, 'Zero weather values retained');
checkFlex($rows[1792890000]['temperatur'] === null, 'Missing weather not zero');
checkFlex($rows[1792886400]['nedboer'] === 2.0, 'Precipitation mapped to correct interval');
checkFlex($rows[1792890000]['nedboer'] === null, 'Missing next interval remains unknown');
checkFlex(vejrSymbol($rows[1792886400])[0] === 'moon', 'Clear night icon');
$w['hourly_units']['wind_speed_10m'] = 'km/h';
checkFlex(rensVejr($w) === [], 'Wrong weather units rejected');
$gap = [['ts'=>1000, 'pris'=>1], ['ts'=>8200,'pris'=>1], ['ts'=>11800,'pris'=>1]];
checkFlex(billigsteVindue($gap) === null, 'Missing hours cannot create cheapest window');
echo "OK: $checks FlexEnergi checks\n";
