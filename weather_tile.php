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

// Oltre questo punto la richiesta consuma quota OpenWeather. L'endpoint è
// deliberatamente pubblico (la mappa è consultabile senza login e i layer meteo
// sono offerti anche agli anonimi), quindi va protetto dall'abuso: senza limite
// chiunque conosca l'URL potrebbe prosciugare la quota dell'account iterando
// z/x/y. Le tile già in cache sono servite sopra e non contano nel limite.
if (!tile_rate_ok('weather', 1500)) {
    http_response_code(429);
    header('Retry-After: 3600');
    exit;
}

/**
 * Limite di chiamate upstream per IP e per ora, con contatore su file (niente
 * DB: questo endpoint viene invocato a raffica, decine di tile per pannata).
 * Ritorna false quando il limite è superato.
 */
function tile_rate_ok(string $bucket, int $maxPerHour): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = __DIR__ . '/cache/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return true; // in caso di problemi sul filesystem non blocchiamo il servizio
    }
    $slot = $bucket . '_' . date('YmdH') . '_' . hash('sha256', $ip);
    $file = "$dir/$slot";

    // Pulizia probabilistica dei contatori delle ore passate (1 richiesta su 200):
    // senza, questa directory crescerebbe indefinitamente come è già successo con
    // la cache delle tile.
    if (random_int(1, 200) === 1) {
        foreach (glob("$dir/*") ?: [] as $old) {
            if (is_file($old) && (time() - filemtime($old)) > 7200) {
                @unlink($old);
            }
        }
    }

    $n = (int) @file_get_contents($file);
    if ($n >= $maxPerHour) {
        return false;
    }
    @file_put_contents($file, (string) ($n + 1), LOCK_EX);
    return true;
}

/**
 * Eviction della cache delle tile: finora non ne esisteva alcuna — il TTL veniva
 * controllato solo in lettura, quindi una tile richiesta una volta e mai più
 * restava su disco per sempre. Rimuove i file molto più vecchi del TTL (una tile
 * di 7 giorni fa non verrà comunque mai servita). Probabilistica, 1 su 500.
 */
function tile_cache_evict(string $subdir, int $olderThan = 604800): void {
    if (random_int(1, 500) !== 1) {
        return;
    }
    $root = __DIR__ . '/cache/' . $subdir;
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $now = time();
    foreach ($it as $f) {
        if ($f->isFile() && ($now - $f->getMTime()) > $olderThan) {
            @unlink($f->getPathname());
        } elseif ($f->isDir()) {
            @rmdir($f->getPathname()); // riesce solo se rimasta vuota
        }
    }
}
tile_cache_evict('weather');

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
