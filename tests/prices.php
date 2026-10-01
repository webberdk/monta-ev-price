<?php
// Kør med PHP: php tests/prices.php. Indlæs funktionerne uden at hente API-data.
$source = file_get_contents(dirname(__DIR__) . '/index.php');
$functions = strstr($source, '/* --- Saml kvarterpriser til timer', true);
if ($functions === false) throw new RuntimeException('Funktionsafsnittet mangler');
eval(substr($functions, 5));

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function quarters(string $utc, array $spots): array {
    $start = strtotime($utc);
    $rows = [];
    foreach ($spots as $i => $spot) {
        $rows[] = ['t' => gmdate('Y-m-d\TH:i:s', $start + $i * 900), 'spot' => $spot];
    }
    return $rows;
}

// De to lokale 02-timer ved vintertid skal beholde hver sin pris og UTC-offset.
$dst = quarters('2026-10-25T00:00:00Z', [1,1,1,1,3,3,3,3]);
$hours = samlTimepriser($dst, strtotime('2026-10-25T00:30:00Z'));
check(count($hours) === 2, 'Vintertid: to timer skal bevares');
check($hours[0]['spot'] === 1.0 && $hours[1]['spot'] === 3.0, 'Vintertid: priser blandes');
check($hours[0]['nu'] && !$hours[1]['nu'], 'Vintertid: kun én time er aktuel');
check(date('c', $hours[0]['ts']) === '2026-10-25T02:00:00+02:00', 'Første UTC-offset');
check(date('c', $hours[1]['ts']) === '2026-10-25T02:00:00+01:00', 'Andet UTC-offset');

$nu = strtotime('2026-10-01T10:30:00Z');
$complete = quarters('2026-10-01T10:00:00Z', [1,2,3,4]);
check(samlTimepriser(array_slice($complete, 0, 3), $nu) === [], 'Tre kvarterer må ikke blive en time');
check(samlTimepriser(array_fill(0, 4, $complete[0]), $nu) === [], 'Dubletter må ikke erstatte manglende kvarterer');
$hours = samlTimepriser(array_reverse(array_merge($complete, [$complete[0]])), $nu);
check(count($hours) === 1 && $hours[0]['spot'] === 2.5, 'Komplet time med identisk dublet');
check($hours[0]['pris'] === round(2.5 * SPOT_FAKTOR + 2.57, 2), 'Timepris og tillæg');
$conflict = $complete[0]; $conflict['spot'] = 9;
check(samlTimepriser(array_merge($complete, [$conflict]), $nu) === [], 'Modstridende dubletter skal afvises');
check(rensPriser([['t'=>'2026-02-30T10:00:00', 'spot'=>1]], 't', 'spot', 1) === [], 'Ugyldig kalenderdato');

$start = strtotime('2026-10-01T10:00:00Z');
$gap = [['ts'=>$start, 'pris'=>1], ['ts'=>$start+7200, 'pris'=>1], ['ts'=>$start+10800, 'pris'=>1]];
check(billigsteVindue($gap) === null, 'Hul mellem timer skal afvises');
$continuous = [['ts'=>$start, 'pris'=>3], ['ts'=>$start+3600, 'pris'=>2],
               ['ts'=>$start+7200, 'pris'=>1], ['ts'=>$start+10800, 'pris'=>0]];
$window = billigsteVindue($continuous);
check($window['start'] === $start+3600 && $window['snit'] === 1, 'Billigste sammenhængende vindue');
$spring = quarters('2026-03-29T00:00:00Z', array_fill(0, 12, 1));
$hours = samlTimepriser($spring, strtotime('2026-03-29T00:00:00Z'));
check(count($hours) === 3 && billigsteVindue($hours) !== null, 'Sommertid: tre faktiske timer er sammenhængende');

// Kør hele siden i en isoleret mappe med netværk slået fra og kontroller cache-status.
$fixture = sys_get_temp_dir() . '/monta-prices-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/cache', 0700);
file_put_contents($fixture . '/index.php', $source);
function requestFixture(string $fixture, bool $json = true) {
    $command = [PHP_BINARY, '-n', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_init',
                '-r', ($json ? '$_GET["format"]="json"; ' : '') . 'require ' . var_export($fixture . '/index.php', true) . ';'];
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Kunne ikke starte PHP');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $errors !== '') throw new RuntimeException('PHP-fejl: ' . $errors);
    return $json ? json_decode($output, true, 512, JSON_THROW_ON_ERROR) : $output;
}
try {
    $cacheFile = $fixture . '/cache/spot-DK2-utc.json';
    $rows = quarters(gmdate('Y-m-d\TH:00:00\Z'), [1,1,1,1]);
    $fetched = time() - 3600;
    file_put_contents($cacheFile, json_encode(['priser'=>$rows, 'hentet'=>$fetched]));
    touch($cacheFile, time()-3600);
    $result = requestFixture($fixture);
    check($result['foraeldet'] && $result['aktuel'] !== null, 'API-fejl: gammel cache bruges og markeres');
    check($result['opdateret'] === date('c', $fetched), 'API-fejl: oprindeligt hentetidspunkt bevares');
    $result = requestFixture($fixture);
    check($result['foraeldet'] && $result['opdateret'] === date('c', $fetched), 'Retry-pause må ikke skjule forældet cache');
    $html = requestFixture($fixture, false);
    check(strpos($html, 'Der vises forældede data fra ' . date('d-m-Y H:i', $fetched)) !== false, 'HTML: advarsel med faktisk hentetidspunkt');
    check(strpos($html, 'opdateret ' . date('d-m-Y H:i', $fetched)) !== false, 'HTML: opdateret må ikke vise sidevisningstidspunkt');
    $fetched = time();
    file_put_contents($cacheFile, json_encode(['priser'=>$rows, 'hentet'=>$fetched]));
    touch($cacheFile, time());
    $result = requestFixture($fixture);
    check(!$result['foraeldet'] && count($result['timer']) === 1, 'Frisk UTC-cache bruges uden netværk');
    unlink($cacheFile);
    file_put_contents($fixture . '/cache/spot-DK2.json', json_encode($rows));
    $result = requestFixture($fixture);
    check($result['timer'] === [] && $result['opdateret'] === null, 'Gammel lokal cache må ikke læses som UTC');
} finally {
    foreach (glob($fixture . '/cache/*') as $file) unlink($file);
    if (is_file($fixture . '/cache/.htaccess')) unlink($fixture . '/cache/.htaccess');
    rmdir($fixture . '/cache');
    unlink($fixture . '/index.php');
    rmdir($fixture);
}
echo "OK: $checks kontroller\n";
