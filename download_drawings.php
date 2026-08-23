#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica i disegni tecnici mancanti (in /drawings) per tutti i modelli presenti nel database.
 * Usa URL https://doc8643.com/static/img/aircraft/3D/MODELLO.jpg
 */

$dbPath = __DIR__ . '/events.db';
$drawingsDir = __DIR__ . '/drawings';

if (!is_dir($drawingsDir)) {
    mkdir($drawingsDir, 0775, true);
}

$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

$res = $db->query("SELECT DISTINCT model_t FROM aircraft WHERE model_t IS NOT NULL AND model_t != '' ORDER BY model_t");
$models = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $models[] = $row['model_t'];
}

$downloaded = 0;
$existing = 0;
$failed = 0;

foreach ($models as $model) {
    $safeModel = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($model)));
    if ($safeModel === '') continue;

    $localFile = $drawingsDir . '/' . $safeModel . '.jpg';

    if (file_exists($localFile) && filesize($localFile) > 0) {
        $existing++;
        continue;
    }

    $remoteUrl = 'https://doc8643.com/static/img/aircraft/3D/' . $safeModel . '.jpg';
    $imageData = @file_get_contents($remoteUrl, false, stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: Mozilla/5.0\r\n"
        ]
    ]));

    if ($imageData !== false && strlen($imageData) > 0) {
        if (@file_put_contents($localFile, $imageData) !== false) {
            $downloaded++;
        } else {
            $failed++;
            echo "Errore scrittura: $localFile\n";
        }
    } else {
        $failed++;
        echo "Download fallito: $remoteUrl\n";
    }
}

$total = count($models);
echo "Disegni processati: $total | Scaricati: $downloaded | Già esistenti: $existing | Falliti: $failed\n";
