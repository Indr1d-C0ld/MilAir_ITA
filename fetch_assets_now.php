<?php
/**
 * Recupero "su richiesta" di un singolo asset (silhouette, foto modello,
 * disegno tecnico, foto reale) per un contatto specifico — stessa identica
 * logica di download_silhouettes.php/download_photos.php/download_drawings.php/
 * download_fdb_photos.php (duplicata qui per lo stesso motivo: file cron
 * autonomi), ma eseguita subito, per un solo hex/modello, invece di attendere
 * il prossimo ciclo cron (fino a 6 ore). Risponde in JSON via fetch() da index.php.
 */
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

header('Content-Type: application/json; charset=utf-8');

function respond($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, ['error' => 'metodo non consentito']);
}
if ((ROLE_RANK[current_role()] ?? 0) < ROLE_RANK['collaboratore']) {
    http_response_code(is_logged_in() ? 403 : 401);
    respond(false, ['error' => 'accesso non autorizzato: effettua il login come collaboratore o admin']);
}
require_csrf();

$type = $_POST['type'] ?? '';
$hex = strtoupper(trim($_POST['hex'] ?? ''));
$modelRaw = trim($_POST['model_t'] ?? '');
$safeModel = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper($modelRaw));
$safeOperator = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['operator'] ?? '')));

function downloadToFile(string $url, string $localFile): bool {
    $data = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"],
    ]));
    if ($data === false || strlen($data) === 0) {
        return false;
    }
    return @file_put_contents($localFile, $data) !== false;
}

function fetchUrlCurl(string $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data === false ? false : $data;
}

function getFirstPhotoLinkFromFlightDB(string $hex): ?string {
    $html = fetchUrlCurl('https://www.flightdb.net/aircraft.php?modes=' . urlencode($hex));
    if ($html === false) return null;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//a[contains(@href, 'airport-data.com/aircraft/photo')]/img");
    if ($nodes->length === 0) $nodes = $xpath->query("//a[img]");
    if ($nodes->length === 0) return null;
    $href = $nodes->item(0)->parentNode->getAttribute('href');
    return preg_match('/^https?:\/\//i', $href) ? $href : 'https://www.flightdb.net' . $href;
}

function getImageUrlFromPhotoPage(string $photoPageUrl): ?string {
    $html = fetchUrlCurl($photoPageUrl);
    if ($html === false) return null;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query("//img") as $img) {
        $src = $img->getAttribute('src');
        $w = (int)$img->getAttribute('width');
        $h = (int)$img->getAttribute('height');
        if ($w > 0 && $w < 200) continue;
        if ($h > 0 && $h < 200) continue;
        if (preg_match('/\.(jpg|jpeg|png)$/i', $src)) {
            return preg_match('/^https?:\/\//i', $src) ? $src : 'https://' . parse_url($photoPageUrl, PHP_URL_HOST) . $src;
        }
    }
    $nodes = $xpath->query("//img[contains(@src, '.jpg') or contains(@src, '.jpeg') or contains(@src, '.png')]");
    if ($nodes->length > 0) {
        $src = $nodes->item(0)->getAttribute('src');
        return preg_match('/^https?:\/\//i', $src) ? $src : 'https://' . parse_url($photoPageUrl, PHP_URL_HOST) . $src;
    }
    return null;
}

switch ($type) {
    case 'silhouette':
        if ($safeModel === '') respond(false, ['error' => 'modello mancante']);
        $local = __DIR__ . '/silhouettes/' . $safeModel . '.bmp';
        if (file_exists($local) && filesize($local) > 0) {
            respond(true, ['path' => 'silhouettes/' . $safeModel . '.bmp', 'already' => true]);
        }
        if (downloadToFile('https://www.flightdb.net/img/silhouettes/' . $safeModel . '.bmp', $local)) {
            respond(true, ['path' => 'silhouettes/' . $safeModel . '.bmp']);
        }
        respond(false, ['error' => 'silhouette non trovata su FlightDB per questo modello']);

    case 'model_photo':
        if ($safeModel === '') respond(false, ['error' => 'modello mancante']);
        $local = __DIR__ . '/photos/' . $safeModel . '.jpg';
        if (file_exists($local) && filesize($local) > 0) {
            respond(true, ['path' => 'photos/' . $safeModel . '.jpg', 'already' => true]);
        }
        if (downloadToFile('https://doc8643.com/static/img/aircraft/large/' . $safeModel . '.jpg', $local)) {
            respond(true, ['path' => 'photos/' . $safeModel . '.jpg']);
        }
        respond(false, ['error' => 'foto modello non trovata su doc8643.com']);

    case 'drawing':
        if ($safeModel === '') respond(false, ['error' => 'modello mancante']);
        $local = __DIR__ . '/drawings/' . $safeModel . '.jpg';
        if (file_exists($local) && filesize($local) > 0) {
            respond(true, ['path' => 'drawings/' . $safeModel . '.jpg', 'already' => true]);
        }
        if (downloadToFile('https://doc8643.com/static/img/aircraft/3D/' . $safeModel . '.jpg', $local)) {
            respond(true, ['path' => 'drawings/' . $safeModel . '.jpg']);
        }
        respond(false, ['error' => 'disegno tecnico non trovato su doc8643.com']);

    case 'operator_logo':
        if ($safeOperator === '' || strlen($safeOperator) < 2 || strlen($safeOperator) > 4) {
            respond(false, ['error' => 'codice operatore non valido']);
        }
        $local = null;
        foreach (['bmp', 'png', 'svg', 'gif'] as $ext) {
            $f = __DIR__ . '/opflags/' . $safeOperator . '.' . $ext;
            if (file_exists($f) && filesize($f) > 0) {
                respond(true, ['path' => 'opflags/' . $safeOperator . '.' . $ext, 'already' => true]);
            }
        }
        // Pacchetto VRS OperatorFlags: ~3MB, nessuna autenticazione richiesta.
        // Scaricato per intero anche per un solo codice, non essendoci un mirror
        // per-file affidabile (stessa limitazione documentata in download_opflags.php).
        $zipData = fetchUrlCurl('https://raw.githubusercontent.com/rikgale/VRSOperatorFlags/main/AirlineLogos.zip');
        if ($zipData === false || strlen($zipData) < 1000) {
            respond(false, ['error' => 'download del pacchetto loghi fallito']);
        }
        $tmpZip = sys_get_temp_dir() . '/milair_opflags_' . getmypid() . '.zip';
        if (@file_put_contents($tmpZip, $zipData) === false) {
            respond(false, ['error' => 'impossibile scrivere il file temporaneo']);
        }
        $za = new ZipArchive();
        if ($za->open($tmpZip) !== true) {
            @unlink($tmpZip);
            respond(false, ['error' => 'pacchetto loghi non apribile']);
        }
        $found = false;
        foreach (['bmp', 'png'] as $ext) {
            $idx = $za->locateName($safeOperator . '.' . $ext, ZipArchive::FL_NOCASE);
            if ($idx !== false) {
                $logoData = $za->getFromIndex($idx);
                if ($logoData !== false && strlen($logoData) > 100) {
                    @file_put_contents(__DIR__ . '/opflags/' . $safeOperator . '.' . $ext, $logoData);
                    $found = true;
                }
                break;
            }
        }
        $za->close();
        @unlink($tmpZip);
        if ($found) {
            $ext = file_exists(__DIR__ . '/opflags/' . $safeOperator . '.bmp') ? 'bmp' : 'png';
            respond(true, ['path' => 'opflags/' . $safeOperator . '.' . $ext]);
        }
        respond(false, ['error' => 'nessun logo trovato per questo codice operatore']);

    case 'fdb_photo':
        if (!preg_match('/^[0-9A-F]{6}$/', $hex)) respond(false, ['error' => 'hex non valido']);
        $local = __DIR__ . '/fdbphotos/' . $hex . '.jpg';
        if (file_exists($local) && filesize($local) > 0) {
            respond(true, ['path' => 'fdbphotos/' . $hex . '.jpg', 'already' => true]);
        }
        $photoPageUrl = getFirstPhotoLinkFromFlightDB($hex);
        if (!$photoPageUrl) respond(false, ['error' => 'nessuna foto reale trovata su FlightDB per questo hex']);
        $imageUrl = getImageUrlFromPhotoPage($photoPageUrl);
        if (!$imageUrl) respond(false, ['error' => 'pagina foto trovata ma impossibile estrarre l\'immagine']);
        $imageData = fetchUrlCurl($imageUrl);
        if ($imageData === false || strlen($imageData) < 1000) respond(false, ['error' => 'download immagine fallito']);
        if (@file_put_contents($local, $imageData) === false) respond(false, ['error' => 'impossibile salvare l\'immagine sul server']);
        respond(true, ['path' => 'fdbphotos/' . $hex . '.jpg']);

    default:
        http_response_code(400);
        respond(false, ['error' => 'tipo di asset non valido']);
}
