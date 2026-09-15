<?php
// Proxy per tile satellitari Copernicus Sentinel-2 (Sentinel Hub Process API).
// Gestisce OAuth2 (client_credentials) e mette in cache su disco: le immagini
// satellitari non cambiano nel giro di poche ore (rivisitazione Sentinel-2 ~5 giorni),
// quindi cache aggressiva per contenere le chiamate a pagamento/a quota.
require __DIR__ . '/map_secrets.php';

$z = (int)($_GET['z'] ?? -1);
$x = (int)($_GET['x'] ?? -1);
$y = (int)($_GET['y'] ?? -1);
// Zoom limitato: oltre serve troppe chiamate Process API per area coperta
if ($z < 4 || $z > 16 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit;
}

function tileToBBox($z, $x, $y) {
    $n = 2 ** $z;
    $lonMin = $x / $n * 360.0 - 180.0;
    $lonMax = ($x + 1) / $n * 360.0 - 180.0;
    $latFromY = function ($yy) use ($n) {
        return rad2deg(atan(sinh(M_PI * (1 - 2 * $yy / $n))));
    };
    $latMax = $latFromY($y);
    $latMin = $latFromY($y + 1);
    return [$lonMin, $latMin, $lonMax, $latMax];
}

function getSentinelHubToken() {
    $tokenCacheFile = __DIR__ . '/cache/sentinelhub_token.php';
    if (file_exists($tokenCacheFile)) {
        $cached = include $tokenCacheFile;
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 30) {
            return $cached['access_token'];
        }
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => SENTINELHUB_CLIENT_ID,
            'client_secret' => SENTINELHUB_CLIENT_SECRET,
        ]),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents('https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token', false, $ctx);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    if (!isset($data['access_token'])) return null;

    $cacheDir = dirname($tokenCacheFile);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $payload = "<?php\nreturn " . var_export([
        'access_token' => $data['access_token'],
        'expires_at' => time() + (int)($data['expires_in'] ?? 3000),
    ], true) . ";\n";
    file_put_contents($tokenCacheFile, $payload);
    return $data['access_token'];
}

$cacheDir = __DIR__ . "/cache/satellite/$z/$x";
$cacheFile = "$cacheDir/$y.jpg";
$maxAge = 86400; // 24 ore

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=' . $maxAge);
    readfile($cacheFile);
    exit;
}

// Oltre questo punto la richiesta consuma quota Sentinel Hub (Process API, a
// consumo). L'endpoint è deliberatamente pubblico — la mappa è consultabile
// senza login e il layer satellitare è offerto anche agli anonimi — quindi va
// protetto dall'abuso: senza limite chiunque conosca l'URL potrebbe prosciugare
// la quota iterando z/x/y. Le tile già in cache sono servite sopra e non contano.
// Soglia più bassa del meteo: qui ogni chiamata è più costosa.
if (!tile_rate_ok('satellite', 500)) {
    http_response_code(429);
    header('Retry-After: 3600');
    exit;
}

/**
 * Limite di chiamate upstream per IP e per ora, con contatore su file (niente
 * DB: questo endpoint viene invocato a raffica, decine di tile per pannata).
 * Duplicata in weather_tile.php per la stessa ragione delle altre funzioni
 * ripetute nel progetto: nessun include condiviso fra i due proxy.
 */
function tile_rate_ok(string $bucket, int $maxPerHour): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = __DIR__ . '/cache/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return true; // in caso di problemi sul filesystem non blocchiamo il servizio
    }
    $slot = $bucket . '_' . date('YmdH') . '_' . hash('sha256', $ip);
    $file = "$dir/$slot";

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
 * Eviction della cache delle tile (finora inesistente: il TTL era controllato
 * solo in lettura, quindi una tile richiesta una volta e mai più restava su
 * disco per sempre). Qui la soglia è più alta che per il meteo: le immagini
 * satellitari cambiano di rado e ricrearle costa quota. Probabilistica, 1 su 500.
 */
function tile_cache_evict(string $subdir, int $olderThan = 2592000): void {
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
tile_cache_evict('satellite');

$token = getSentinelHubToken();
if (!$token) {
    if (file_exists($cacheFile)) { header('Content-Type: image/jpeg'); readfile($cacheFile); exit; }
    http_response_code(502);
    exit;
}

[$lonMin, $latMin, $lonMax, $latMax] = tileToBBox($z, $x, $y);

$evalscript = <<<'JS'
//VERSION=3
function setup() {
  return { input: ["B02", "B03", "B04"], output: { bands: 3 } };
}
function evaluatePixel(sample) {
  return [2.5 * sample.B04, 2.5 * sample.B03, 2.5 * sample.B02];
}
JS;

$body = json_encode([
    'input' => [
        'bounds' => [
            'bbox' => [$lonMin, $latMin, $lonMax, $latMax],
            'properties' => ['crs' => 'http://www.opengis.net/def/crs/OGC/1.3/CRS84'],
        ],
        'data' => [[
            'type' => 'sentinel-2-l2a',
            'dataFilter' => [
                'timeRange' => [
                    'from' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days')),
                    'to' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                'mosaickingOrder' => 'leastCC',
            ],
        ]],
    ],
    'output' => [
        'width' => 256,
        'height' => 256,
        'responses' => [[
            'identifier' => 'default',
            'format' => ['type' => 'image/jpeg'],
        ]],
    ],
    'evalscript' => $evalscript,
]);

$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
    'content' => $body,
    'timeout' => 25,
    'ignore_errors' => true,
]]);
$img = @file_get_contents('https://sh.dataspace.copernicus.eu/api/v1/process', false, $ctx);

if ($img === false || strlen($img) < 200) {
    if (file_exists($cacheFile)) { header('Content-Type: image/jpeg'); readfile($cacheFile); exit; }
    http_response_code(502);
    exit;
}

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
file_put_contents($cacheFile, $img);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=' . $maxAge);
echo $img;
