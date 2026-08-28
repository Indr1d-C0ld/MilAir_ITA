#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica le foto degli aeromobili da FlightDB per ogni HEX univoco.
 * Per ogni HEX: apre la pagina FlightDB, trova il link alla prima foto,
 * poi da quella pagina scarica l'immagine reale e la salva in /fdbphotos/{HEX}.jpg
 */

$dbPath = __DIR__ . '/events.db';
$photosDir = __DIR__ . '/fdbphotos';

if (!is_dir($photosDir)) {
    mkdir($photosDir, 0775, true);
}

// Funzione per scaricare una URL con cURL
function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $data = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        echo "Errore cURL: $error\n";
        return false;
    }
    return $data;
}

// Funzione per estrarre il link alla prima foto da FlightDB
function getFirstPhotoLinkFromFlightDB($hex) {
    $url = 'https://www.flightdb.net/aircraft.php?modes=' . urlencode(strtoupper($hex));
    $html = fetchUrl($url);
    if ($html === false) return null;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Cerchiamo un'immagine dentro un link che contiene "airport-data.com/aircraft/photo"
    $nodes = $xpath->query("//a[contains(@href, 'airport-data.com/aircraft/photo')]/img");
    if ($nodes->length > 0) {
        $anchor = $nodes->item(0)->parentNode;
        $href = $anchor->getAttribute('href');
        if (!preg_match('/^https?:\/\//i', $href)) {
            $href = 'https://www.flightdb.net' . $href;
        }
        return $href;
    }

    // Fallback: primo img dentro un link qualsiasi
    $nodes = $xpath->query("//a[img]");
    if ($nodes->length > 0) {
        $anchor = $nodes->item(0);
        $href = $anchor->getAttribute('href');
        if (!preg_match('/^https?:\/\//i', $href)) {
            $href = 'https://www.flightdb.net' . $href;
        }
        return $href;
    }

    return null;
}

// Funzione per estrarre l'URL dell'immagine reale dalla pagina della foto
function getImageUrlFromPhotoPage($photoPageUrl) {
    $html = fetchUrl($photoPageUrl);
    if ($html === false) return null;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Cerchiamo l'immagine principale: spesso ha src che contiene 'photo' o è grande
    // Prendiamo la prima img con src che termina in .jpg/.jpeg/.png e larghezza > 200
    $nodes = $xpath->query("//img");
    foreach ($nodes as $img) {
        $src = $img->getAttribute('src');
        $width = (int)$img->getAttribute('width');
        $height = (int)$img->getAttribute('height');

        // Scarta immagini piccole e loghi
        if ($width > 0 && $width < 200) continue;
        if ($height > 0 && $height < 200) continue;

        if (preg_match('/\.(jpg|jpeg|png)$/i', $src)) {
            if (!preg_match('/^https?:\/\//i', $src)) {
                $src = 'https://' . parse_url($photoPageUrl, PHP_URL_HOST) . $src;
            }
            return $src;
        }
    }

    // Fallback: qualsiasi immagine jpg
    $nodes = $xpath->query("//img[contains(@src, '.jpg') or contains(@src, '.jpeg') or contains(@src, '.png')]");
    if ($nodes->length > 0) {
        $src = $nodes->item(0)->getAttribute('src');
        if (!preg_match('/^https?:\/\//i', $src)) {
            $src = 'https://' . parse_url($photoPageUrl, PHP_URL_HOST) . $src;
        }
        return $src;
    }

    return null;
}

// Connessione al database
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);
$db->busyTimeout(5000);

// Ottieni tutti gli HEX univoci
$res = $db->query("SELECT DISTINCT hex FROM aircraft WHERE hex IS NOT NULL AND hex != '' ORDER BY hex");
$hexes = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $hexes[] = $row['hex'];
}

$total = count($hexes);
$downloaded = 0;
$skipped = 0;
$failed = 0;

foreach ($hexes as $hex) {
    $targetFile = $photosDir . '/' . strtoupper($hex) . '.jpg';

    // Salta se già esistente
    if (file_exists($targetFile) && filesize($targetFile) > 0) {
        $skipped++;
        continue;
    }

    $photoPageUrl = getFirstPhotoLinkFromFlightDB($hex);
    if (!$photoPageUrl) {
        echo "Nessuna foto trovata per $hex\n";
        $failed++;
        continue;
    }

    $imageUrl = getImageUrlFromPhotoPage($photoPageUrl);
    if (!$imageUrl) {
        echo "Nessuna immagine reale per $hex (pagina: $photoPageUrl)\n";
        $failed++;
        continue;
    }

    $imageData = fetchUrl($imageUrl);
    if ($imageData === false || strlen($imageData) < 1000) {
        echo "Download immagine fallito per $hex ($imageUrl)\n";
        $failed++;
        continue;
    }

    if (file_put_contents($targetFile, $imageData) === false) {
        echo "Errore scrittura per $hex\n";
        $failed++;
        continue;
    }

    echo "Scaricata foto per $hex da $imageUrl\n";
    $downloaded++;
}

echo "\nRiepilogo: $total HEX processati | Scaricate: $downloaded | Già esistenti: $skipped | Fallite: $failed\n";
