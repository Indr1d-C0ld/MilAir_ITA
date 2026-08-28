#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Ricalcola la cache di rarità (rarity_cache), eseguito ogni ora via cron.
 *
 * Sistema di rarità composita, ispirato ai loot table dei GdR: a differenza
 * di un vero drop table (dove la rarità è una regola fissa decisa a monte),
 * qui non possiamo "autorare" a mano una probabilità per ciascun aeromobile —
 * la deriviamo da tre segnali osservati, ciascuno convertito in "punti
 * rarità" (0-5, più alto = più raro) tramite la stessa scala a soglie fisse
 * su "quanti elementi condividono questo stesso valore":
 *
 *   - seen_count: quante volte abbiamo avvistato QUESTO hex (peso doppio:
 *     è il segnale primario, riflette direttamente il comportamento osservato)
 *   - operatore: quanti hex condividono lo stesso codice operatore/forza
 *     aerea a 3 lettere derivato dal callsign (peso singolo, modificatore)
 *   - nazionalità: quanti hex condividono lo stesso codice paese (peso
 *     singolo, modificatore)
 *
 * I punti si sommano in un punteggio composito (0-20), che decide la fascia
 * finale tramite SOGLIE FISSE (non percentili sulla popolazione corrente) —
 * un vero drop table non retrocede un oggetto Leggendario a Comune solo
 * perché il gruppo ha trovato altri 50 oggetti dopo, e questa logica fa lo
 * stesso: la fascia di un hex resta stabile nel tempo (a parità di
 * seen_count/operatore/nazionalità), non viene ricalcolata "relativamente"
 * a cosa c'è oggi nel database.
 *
 * Le soglie sono state calibrate sulla distribuzione reale del database al
 * 28/08/2026 (532 hex): vanno probabilmente ritoccate in futuro quando
 * l'archivio sarà molto più maturo (esattamente come un drop table di un
 * gioco viene ribilanciato tra una patch e l'altra) — non è previsto un
 * ricalcolo automatico, è una scelta deliberata.
 */

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
// events.db non usa WAL (scelta deliberata, vedi fix_permissions.sh): scritture
// concorrenti di csv_to_db.py/alert_scan.php (ogni 5 min) possono tenere un lock
// per una frazione di secondo. Senza busyTimeout, SQLite3 lancia subito
// "database is locked" invece di attendere — nel log storico (rarity.log) questo
// ha fatto fallire il 73% delle esecuzioni orarie del cron. alert_scan.php ha
// già lo stesso fix (busyTimeout(5000)) per lo stesso motivo.
$db->busyTimeout(5000);

$db->exec("CREATE TABLE IF NOT EXISTS rarity_cache (hex TEXT PRIMARY KEY, seen_count INTEGER, rarity TEXT, composite_score INTEGER)");
// Migrazione idempotente: aggiunge la colonna se la cache esisteva già dalla versione precedente (solo percentili).
$hasScoreCol = false;
$cols = $db->query("PRAGMA table_info(rarity_cache)");
while ($c = $cols->fetchArray(SQLITE3_ASSOC)) {
    if ($c['name'] === 'composite_score') { $hasScoreCol = true; break; }
}
if (!$hasScoreCol) {
    $db->exec("ALTER TABLE rarity_cache ADD COLUMN composite_score INTEGER");
}

/**
 * Deriva il codice operatore/forza aerea a 3 lettere da un callsign
 * (es. "IAM9001" -> "IAM"). Stessa logica di index.php/stats.php.
 */
function operatorFromCallsign($callsign) {
    $cs = strtoupper(trim((string)$callsign));
    if (preg_match('/^[A-Z]{3}\d/', $cs)) {
        return substr($cs, 0, 3);
    }
    return null;
}

/** Stessa mappatura prefissi reg -> nazione usata altrove nel portale. */
function getCountryFromReg($reg) {
    $map = [
        'MM' => 'IT', 'I-' => 'IT', 'F-' => 'FR', 'D-' => 'DE', 'G-' => 'GB',
        'EC-' => 'ES', 'PH-' => 'NL', 'OO-' => 'BE', 'HB-' => 'CH', 'OE-' => 'AT',
        'OK-' => 'CZ', 'OM-' => 'SK', 'SP-' => 'PL', 'HA-' => 'HU', 'YR-' => 'RO',
        'LZ-' => 'BG', '9A-' => 'HR', 'S5-' => 'SI', 'YU-' => 'RS', 'Z3-' => 'MK',
        'T7-' => 'SM', '3A-' => 'MC', '9H-' => 'MT', '5B-' => 'CY', 'TC-' => 'TR',
        '4X-' => 'IL', 'SU-' => 'EG', '5A-' => 'LY', 'CN-' => 'MA', '7T-' => 'DZ',
        'TS-' => 'TN', 'JY-' => 'JO', 'OD-' => 'LB', 'YK-' => 'SY', 'EP-' => 'IR',
        'A6-' => 'AE', 'A7-' => 'QA', '9K-' => 'KW', 'VT-' => 'IN', 'AP-' => 'PK',
        'B-' => 'CN', 'JA-' => 'JP', 'HL-' => 'KR', 'HS-' => 'TH', 'VN-' => 'VN',
        '9V-' => 'SG', 'PK-' => 'ID', '9M-' => 'MY', 'RP-' => 'PH', 'ZK-' => 'NZ',
        'VH-' => 'AU', 'C-' => 'CA', 'N' => 'US', 'XA-' => 'MX', 'XB-' => 'MX',
        'XC-' => 'MX', 'PT-' => 'BR', 'LV-' => 'AR', 'CC-' => 'CL', 'HK-' => 'CO',
        'OB-' => 'PE', 'YV-' => 'VE', 'TI-' => 'CR', 'TG-' => 'GT', 'HR-' => 'HN',
        'YS-' => 'SV', 'YN-' => 'NI', 'HP-' => 'PA', 'CU-' => 'CU', 'HI-' => 'DO',
        'V2-' => 'AG', '8P-' => 'BB', 'J3-' => 'GD', '9Y-' => 'TT', 'PJ-' => 'SX'
    ];
    if (empty($reg)) return null;
    $reg = strtoupper(trim($reg));
    foreach ($map as $prefix => $country) {
        if (strpos($reg, $prefix) === 0) return $country;
    }
    return null;
}

/** Stessa mappatura prefissi callsign -> nazione usata altrove nel portale. */
function getCountryFromCallsign($callsign) {
    $map = [
        'IAM' => 'IT', 'RCH' => 'US', 'CNV' => 'US', 'CTM' => 'FR',
        'PLF' => 'PL', 'GAF' => 'DE', 'BAF' => 'BE', 'RNLAF' => 'NL', 'HUAF' => 'HU',
        'ROF' => 'RO', 'SVK' => 'SK', 'CZE' => 'CZ', 'ASH' => 'US', 'RFR' => 'US',
        'RRS' => 'GB', 'RRR' => 'GB', 'SNAKE' => 'US', 'VIPER' => 'US', 'LION' => 'FR'
    ];
    if (empty($callsign)) return null;
    $callsign = strtoupper(trim($callsign));
    foreach ($map as $prefix => $country) {
        if (strpos($callsign, $prefix) === 0) return $country;
    }
    return null;
}

function getCountryCode($reg, $callsign) {
    $c = getCountryFromReg($reg);
    if ($c !== null) return $c;
    $c = getCountryFromCallsign($callsign);
    if ($c !== null) return $c;
    return 'ZZ';
}

/**
 * Converte la dimensione di un gruppo (quanti elementi condividono questo
 * stesso valore) in punti rarità 0-5: più piccolo il gruppo, più alto il
 * punteggio. Soglie fisse, non ricalcolate sulla popolazione — stesso
 * principio per tutti e tre i fattori (seen_count, operatore, nazionalità).
 */
function rarityPoints($groupSize) {
    if ($groupSize <= 1)  return 5;
    if ($groupSize == 2)  return 4;
    if ($groupSize <= 4)  return 3;
    if ($groupSize <= 9)  return 2;
    if ($groupSize <= 19) return 1;
    return 0;
}

// --- Passata 1: raccogli i dati e conta le dimensioni dei gruppi ----------
$rows = [];
$operatorCounts = [];
$countryCounts = [];
$res = $db->query("SELECT hex, seen_count, reg, callsign FROM aircraft");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $op = operatorFromCallsign($r['callsign']);
    $cc = getCountryCode($r['reg'], $r['callsign']);
    $r['op'] = $op;
    $r['cc'] = $cc;
    if ($op !== null) {
        $operatorCounts[$op] = ($operatorCounts[$op] ?? 0) + 1;
    }
    $countryCounts[$cc] = ($countryCounts[$cc] ?? 0) + 1;
    $rows[] = $r;
}

// --- Passata 2: punteggio composito e fascia finale ------------------------
// Soglie sul punteggio composito (0-20 = seen_count[0-5]*2 + operatore[0-5] + nazionalità[0-5]),
// calibrate sulla distribuzione reale del 28/08/2026 per dare una curva discendente
// "da loot table": Common la fascia più popolata, Mythic genuinamente eccezionale.
function rarityTier($score) {
    if ($score >= 18) return 'Mythic';
    if ($score >= 16) return 'Legendary';
    if ($score >= 14) return 'Epic';
    if ($score >= 12) return 'Rare';
    if ($score >= 9)  return 'Uncommon';
    return 'Common';
}

$db->exec("BEGIN TRANSACTION");
$db->exec("DELETE FROM rarity_cache");
$stmt = $db->prepare("INSERT INTO rarity_cache (hex, seen_count, rarity, composite_score) VALUES (?, ?, ?, ?)");

$tierCounts = ['Mythic' => 0, 'Legendary' => 0, 'Epic' => 0, 'Rare' => 0, 'Uncommon' => 0, 'Common' => 0];
foreach ($rows as $r) {
    $scPts = rarityPoints((int)$r['seen_count']);
    // Operatore non derivabile dal callsign (es. nomignoli di reparto irregolari): punteggio
    // neutro (metà scala), per non premiare né penalizzare un formato di callsign atipico.
    $opPts = $r['op'] !== null ? rarityPoints($operatorCounts[$r['op']]) : 2.5;
    $ccPts = rarityPoints($countryCounts[$r['cc']]);

    $composite = (int) round($scPts * 2 + $opPts + $ccPts);
    $tier = rarityTier($composite);
    $tierCounts[$tier]++;

    $stmt->bindValue(1, $r['hex'], SQLITE3_TEXT);
    $stmt->bindValue(2, $r['seen_count'], SQLITE3_INTEGER);
    $stmt->bindValue(3, $tier, SQLITE3_TEXT);
    $stmt->bindValue(4, $composite, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();
}
$db->exec("COMMIT");

$count = array_sum($tierCounts);
echo "Cache rarità aggiornata: $count hex classificati.\n";
foreach ($tierCounts as $tier => $n) {
    $pct = $count > 0 ? round($n / $count * 100, 1) : 0;
    echo "  $tier: $n ($pct%)\n";
}
