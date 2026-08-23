<?php
// Proxy per le tile meteo OpenWeather: nasconde la API key lato server e mette in
// cache su disco per non consumare quota ad ogni richiesta (le mappe OpenWeather si
// aggiornano circa ogni 10 minuti, non serve rinfrescare più spesso).
require __DIR__ . '/map_secrets.php';

$allowedLayers = ['clouds_new', 'precipitation_new', 'wind_new', 'temp_new', 'pressure_new'];
$layer = $_GET['layer'] ?? '';
if (!in_array($layer, $allowedLayers, true)) {
    http_response_code(400);
    exit;
}
$z = (int)($_GET['z'] ?? -1);
$x = (int)($_GET['x'] ?? -1);
$y = (int)($_GET['y'] ?? -1);
if ($z < 0 || $z > 18 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit;
}

$cacheDir = __DIR__ . "/cache/weather/$layer/$z/$x";
$cacheFile = "$cacheDir/$y.png";
$maxAge = 600; // 10 minuti

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . $maxAge);
    readfile($cacheFile);
    exit;
}

$url = "https://tile.openweathermap.org/map/$layer/$z/$x/$y.png?appid=" . OPENWEATHER_API_KEY;
$ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
$data = @file_get_contents($url, false, $ctx);

if ($data === false || strlen($data) < 100) {
    if (file_exists($cacheFile)) {
        header('Content-Type: image/png');
        readfile($cacheFile);
        exit;
    }
    http_response_code(502);
    exit;
}

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
file_put_contents($cacheFile, $data);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=' . $maxAge);
echo $data;
