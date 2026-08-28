#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica/aggiorna i loghi compagnia/forza aerea (VRS OperatorFlags,
 * github.com/rikgale/VRSOperatorFlags, GPL-3.0) in /opflags/{CODICE}.bmp.
 * Il pacchetto AirlineLogos.zip è ~3MB e viene riscaricato per intero ad ogni
 * esecuzione (nessuna API GitHub, nessuna autenticazione richiesta) — vengono
 * estratti solo i file "per codice operatore" (2-4 caratteri alfanumerici,
 * nessun trattino), coerente con la stessa lista di codici che
 * operatorFromCallsign() può derivare dai nostri callsign in tabella.
 */

$zipUrl = 'https://raw.githubusercontent.com/rikgale/VRSOperatorFlags/main/AirlineLogos.zip';
$dir = __DIR__ . '/opflags';
$tmpZip = sys_get_temp_dir() . '/milair_opflags_' . getmypid() . '.zip';

if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    fwrite(STDERR, "Impossibile creare $dir\n");
    exit(1);
}

$ctx = stream_context_create(['http' => [
    'timeout' => 30,
    'header'  => "User-Agent: Mozilla/5.0 (MilAir_ITA opflags fetch)\r\n",
]]);
$data = @file_get_contents($zipUrl, false, $ctx);
if ($data === false || strlen($data) < 1000) {
    fwrite(STDERR, "Download del pacchetto loghi fallito: $zipUrl\n");
    exit(1);
}
if (@file_put_contents($tmpZip, $data) === false) {
    fwrite(STDERR, "Impossibile scrivere il file temporaneo $tmpZip\n");
    exit(1);
}

$za = new ZipArchive();
if ($za->open($tmpZip) !== true) {
    fwrite(STDERR, "Zip non apribile: $tmpZip\n");
    @unlink($tmpZip);
    exit(1);
}

$extracted = 0;
$skipped = 0;
for ($i = 0; $i < $za->numFiles; $i++) {
    $name = $za->getNameIndex($i);
    // solo i loghi "per codice operatore": CODICE.bmp/png, nessun trattino/spazio
    if (!preg_match('#^[A-Za-z0-9]{2,4}\.(bmp|png)$#i', $name)) {
        $skipped++;
        continue;
    }
    $fileData = $za->getFromIndex($i);
    if ($fileData !== false && strlen($fileData) > 100) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $code = strtoupper(pathinfo($name, PATHINFO_FILENAME));
        file_put_contents($dir . '/' . $code . '.' . $ext, $fileData);
        $extracted++;
    }
}
$za->close();
@unlink($tmpZip);

echo date('c') . " loghi estratti=$extracted scartati(nome non valido)=$skipped in $dir\n";
