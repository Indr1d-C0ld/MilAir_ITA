<?php
require_once __DIR__ . '/percorsi.php';
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica e analizza la mappa NOTAM Italia da notaminfo.com/ITALYMAP e ne salva
 * una copia strutturata (aree circolari/poligonali) in locale, per l'overlay su map.php.
 *
 * Pensato per essere lanciato periodicamente via cron (non ad ogni caricamento di
 * map.php): notaminfo.com dichiara nel proprio robots.txt un Crawl-delay di 10
 * secondi, quindi va interrogato con moderazione (ogni 2-3 ore è più che sufficiente,
 * i NOTAM non cambiano con frequenza oraria).
 *
 * Dati per gentile concessione di notaminfo.com — https://notaminfo.com/ITALYMAP
 */

$dbPath = EVENTS_DB_PATH;
$sourceUrl = 'https://notaminfo.com/ITALYMAP';
$logPrefix = '[' . date('Y-m-d H:i:s') . ']';

function extractNotamCalls($html) {
    $calls = [];
    $parts = explode("\nnotam(", $html);
    array_shift($parts);
    $n = count($parts);
    foreach ($parts as $i => $part) {
        if ($i < $n - 1 && substr($part, -2) === ');') {
            // explode ha già isolato esattamente una call: basta togliere il ");" finale
            $calls[] = substr($part, 0, -2);
            continue;
        }
        // ultima porzione (contiene anche il resto del file) o caso anomalo
        $endPos = strpos($part, ");\n");
        if ($endPos === false) $endPos = strpos($part, ');');
        if ($endPos === false) continue;
        $calls[] = substr($part, 0, $endPos);
    }
    return $calls;
}

function splitTopLevelArgs($str) {
    $args = [];
    $depth = 0;
    $inStr = false;
    $current = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        if ($inStr) {
            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $ch . $str[$i + 1];
                $i++;
                continue;
            }
            $current .= $ch;
            if ($ch === '"') $inStr = false;
            continue;
        }
        if ($ch === '"') { $inStr = true; $current .= $ch; continue; }
        if ($ch === '[') { $depth++; $current .= $ch; continue; }
        if ($ch === ']') { $depth--; $current .= $ch; continue; }
        if ($ch === ',' && $depth === 0) {
            $args[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if (trim($current) !== '') $args[] = trim($current);
    return $args;
}

function unquote($s) {
    $s = trim($s);
    if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
        $s = substr($s, 1, -1);
        $s = str_replace(['\\"', '\\\\'], ['"', '\\'], $s);
    }
    return $s;
}

// ---------------------------------------------------------------------------

$ctx = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "User-Agent: Mozilla/5.0 (compatible; MilAirItaBot/1.0; overlay NOTAM personale, uso non commerciale)\r\n",
    'timeout' => 20,
]]);
$html = @file_get_contents($sourceUrl, false, $ctx);
if ($html === false) {
    echo "$logPrefix Errore: impossibile scaricare $sourceUrl\n";
    exit(1);
}

$calls = extractNotamCalls($html);
if (count($calls) === 0) {
    echo "$logPrefix Nessun dato estratto, la struttura della pagina sorgente potrebbe essere cambiata. Interrompo senza toccare la cache esistente.\n";
    exit(1);
}

$rows = [];
$skipped = 0;
foreach ($calls as $argsStr) {
    $args = splitTopLevelArgs($argsStr);
    if (count($args) < 15) { $skipped++; continue; }

    $id = unquote($args[0]);
    $lat = (float)$args[1];
    $lng = (float)$args[2];
    $reference = unquote($args[5] ?? '');
    $meaning = unquote($args[6] ?? '');
    $qcode = unquote($args[7] ?? '');
    $text = unquote($args[8] ?? '');
    $validity = unquote($args[9] ?? '');
    $fl = unquote($args[10] ?? '');
    $tops = unquote($args[11] ?? '');
    $hasPoly = (int)($args[14] ?? 0);
    $pointsRaw = $args[15] ?? null;

    $areaType = 'point';
    $radiusNm = null;
    $polygonJson = null;

    if ($hasPoly === 2 && $pointsRaw !== null) {
        $areaType = 'circle';
        $radiusNm = (float)$pointsRaw;
    } elseif ($hasPoly === 1 && $pointsRaw !== null) {
        $areaType = 'polygon';
        preg_match_all('/\[\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*\]/', $pointsRaw, $m, PREG_SET_ORDER);
        $polygon = [];
        foreach ($m as $pair) {
            $polygon[] = [(float)$pair[1], (float)$pair[2]];
        }
        if (count($polygon) < 3) {
            // area degenere, tratta come punto
            $areaType = 'point';
        } elseif (count($polygon) > 150) {
            // poligoni enormi (es. confine dell'intero FIR) sono NOTAM amministrativi
            // validi per tutto il territorio nazionale, non aree geografiche localizzate:
            // sovrapposti sulla mappa creano un unico blocco rosso illeggibile.
            // Li conserviamo comunque in cache (area_type='region') ma map.php non li
            // disegna come overlay riempito.
            $areaType = 'region';
            $polygonJson = json_encode($polygon);
        } else {
            $polygonJson = json_encode($polygon);
        }
    }

    if ($id === '' || ($lat === 0.0 && $lng === 0.0)) { $skipped++; continue; }

    $rows[] = [$id, $lat, $lng, $areaType, $radiusNm, $polygonJson, $reference, $meaning, $qcode, $text, $validity, $fl, $tops];
}


// Scrittura con BEGIN IMMEDIATE, attesa lunga e fino a 3 tentativi (il logger o
// una pagina web possono tenere il lock per qualche istante). Si scrivono solo le
// voci nuove, cambiate o sparite: prima l'intera cache (~650 voci con i testi)
// veniva cancellata e riscritta ogni 3 ore anche se i NOTAM erano gli stessi.
$saved = false;
for ($attempt = 1; $attempt <= 3 && !$saved; $attempt++) {
    $db = null;
    try {
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);
        $db->busyTimeout(30000);
        $db->exec("CREATE TABLE IF NOT EXISTS notams_cache (
            id TEXT PRIMARY KEY,
            lat REAL NOT NULL,
            lon REAL NOT NULL,
            area_type TEXT NOT NULL,
            radius_nm REAL,
            polygon_json TEXT,
            reference TEXT,
            meaning TEXT,
            qcode TEXT,
            notam_text TEXT,
            validity TEXT,
            fl_lower TEXT,
            fl_upper TEXT,
            fetched_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        // IMMEDIATE: il lock di scrittura si prende subito, dove busyTimeout è
        // rispettato (vedi update_rarity.php per la storia completa).
        // Che cosa c'e' gia', confrontato per id sugli stessi campi che si scrivono
        $colonne = 'id, lat, lon, area_type, radius_nm, polygon_json, reference, meaning, qcode, notam_text, validity, fl_lower, fl_upper';
        $esistenti = [];
        $res = $db->query("SELECT $colonne FROM notams_cache");
        while ($e = $res->fetchArray(SQLITE3_NUM)) {
            $esistenti[(string)$e[0]] = serialize(array_map(fn($v) => $v === null ? null : (string)$v, $e));
        }
        $res->finalize();
        $nuovi = [];
        foreach ($rows as $r) {
            $nuovi[(string)$r[0]] = $r;
        }
        $daScrivere = array_filter($nuovi, fn($r, $id) => ($esistenti[$id] ?? null)
            !== serialize(array_map(fn($v) => $v === null ? null : (string)$v, $r)), ARRAY_FILTER_USE_BOTH);
        $daTogliere = array_diff_key($esistenti, $nuovi);

        if ($daScrivere || $daTogliere) {
            $db->exec('BEGIN IMMEDIATE');
            $del = $db->prepare("DELETE FROM notams_cache WHERE id = ?");
            foreach (array_keys($daTogliere) as $id) {
                $del->bindValue(1, $id);
                $del->execute();
                $del->reset();
            }
            $stmt = $db->prepare("INSERT OR REPLACE INTO notams_cache ($colonne) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($daScrivere as $r) {
                for ($i = 0; $i < count($r); $i++) {
                    $stmt->bindValue($i + 1, $r[$i]);
                }
                $stmt->execute();
                $stmt->reset();
            }
            $stmt->close();
            $db->exec('COMMIT');
        }
        $saved = true;
        // L'ora del controllo riuscito, in RAM: la mappa mostra questa, non la
        // data dell'ultima voce cambiata (che ora resta quella della scrittura)
        @mkdir(MILAIR_RAM . '/cache', 0775, true);
        @touch(MILAIR_RAM . '/cache/notams-controllati');

        echo "$logPrefix NOTAM: " . count($rows) . " in vigore, " . count($daScrivere) . " nuovi o cambiati, "
            . count($daTogliere) . " tolti, $skipped voci scartate" . ($attempt > 1 ? " (tentativo $attempt)" : '') . ".\n";
    } catch (Exception $e) {
        if ($db) { try { $db->exec('ROLLBACK'); } catch (Exception $ignored) {} }
        echo "$logPrefix Errore database (tentativo $attempt/3): " . $e->getMessage() . "\n";
        if ($attempt < 3) {
            sleep(10);
        }
    } finally {
        if ($db) { $db->close(); }
    }
}
if (!$saved) {
    exit(1);
}
