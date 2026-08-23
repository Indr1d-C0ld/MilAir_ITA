#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica le silhouette mancanti per tutti i modelli di aeromobili presenti nel database.
 * Da eseguire via cron.
 */

$dbPath = __DIR__ . '/events.db';
$silhouetteDir = __DIR__ . '/silhouettes';

// Crea la directory se non esiste
if (!is_dir($silhouetteDir)) {
    mkdir($silhouetteDir, 0775, true);
}

// Connessione read-only al database
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

// Recupera tutti i model_t distinti non vuoti
$res = $db->query("SELECT DISTINCT model_t FROM aircraft WHERE model_t IS NOT NULL AND model_t != '' ORDER BY model_t");
$models = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $models[] = $row['model_t'];
}

$downloaded = 0;
$existing = 0;
$failed = 0;

foreach ($models as $model) {
    // Normalizza il nome del file
    $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($model)));
    if ($safeType === '') continue;

    $localFile = $silhouetteDir . '/' . $safeType . '.bmp';

    // Salta se esiste già e non è vuoto
    if (file_exists($localFile) && filesize($localFile) > 0) {
        $existing++;
        continue;
    }

    $remoteUrl = 'https://www.flightdb.net/img/silhouettes/' . $safeType . '.bmp';
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
echo "Silhouettes processate: $total | Scaricate: $downloaded | Già esistenti: $existing | Fallite: $failed\n";
