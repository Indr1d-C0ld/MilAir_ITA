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

require_once __DIR__ . '/geo_lib.php';

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
// events.db non usa WAL (scelta deliberata, vedi fix_permissions.sh) e questo
// script è, con csv_to_db.py (ogni 5 min), l'unico altro SCRITTORE del database:
// la ricostruzione della cache è una transazione lunga (DELETE + ~750 INSERT)
// che collide regolarmente con l'import.
//
// Storia del problema: senza busyTimeout falliva il 73% delle esecuzioni orarie;
// con busyTimeout(5000) + "BEGIN TRANSACTION" falliva ancora il 56% (165 successi
// contro 212 "database is locked" in rarity.log). Il motivo è che BEGIN TRANSACTION
// è DEFERRED: il lock di scrittura viene richiesto solo alla prima INSERT/DELETE,
// e in quel punto SQLite può restituire SQLITE_BUSY senza nemmeno invocare il busy
// handler (non può attendere senza rischiare un deadlock). BEGIN IMMEDIATE invece
// acquisisce subito il lock di scrittura, dove il busy handler si applica davvero.
// Per confronto: alert_scan.php gira ogni 5 min sullo stesso database e non è mai
// fallito, perché apre events.db in sola lettura.
$db->busyTimeout(30000);

$db->exec("CREATE TABLE IF NOT EXISTS rarity_cache (hex TEXT PRIMARY KEY, seen_count INTEGER, rarity TEXT, composite_score INTEGER)");
// Migrazione idempotente: aggiunge la colonna se la cache esisteva già dalla versione precedente (solo percentili).
$hasScoreCol = false;
$cols = $db->query("PRAGMA table_info(rarity_cache)");
while ($c = $cols->fetchArray(SQLITE3_ASSOC)) {
    if ($c['name'] === 'composite_score') { $hasScoreCol = true; break; }
}
// finalize() OBBLIGATORIO: il break sopra interrompe la lettura a metà, e una
// query non completata né finalizzata tiene aperta la transazione di lettura
// (lock SHARED) finché lo script non termina. Con il BEGIN IMMEDIATE più sotto,
// che attende fino a 30s il lock di scrittura, si creava un deadlock con
// csv_to_db.py: l'import teneva il lock di scrittura e al commit aspettava che
// questo script rilasciasse la lettura; questo script aspettava l'import. Ne
// usciva l'import, al timeout di 5s, con "database is locked" — ogni ora, allo
// scoccare delle :00 quando partono entrambi (104 volte fra il 15 e il 23/09).
// Riprodotto e verificato in modo deterministico su una copia del database.
$cols->finalize();
if (!$hasScoreCol) {
    $db->exec("ALTER TABLE rarity_cache ADD COLUMN composite_score INTEGER");
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
// Regole personalizzate di nazionalità: stessa fonte usata da index.php, map.php
// e stats.php. Senza di esse la nazionalità qui calcolata divergerebbe da quella
// mostrata all'utente, falsando il punteggio di rarità (vedi getCountryCode()).
$customRules = loadCountryRules($db); // geo_lib.php: unica fonte, ordine = precedenza

$rows = [];
$operatorCounts = [];
$countryCounts = [];
$res = $db->query("SELECT hex, seen_count, reg, callsign FROM aircraft");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $op = operatorFromCallsign($r['callsign']);
    $cc = getCountryCode($r['hex'], $r['reg'], $r['callsign'], $customRules);
    $r['op'] = $op;
    $r['cc'] = $cc;
    if ($op !== null) {
        $operatorCounts[$op] = ($operatorCounts[$op] ?? 0) + 1;
    }
    $countryCounts[$cc] = ($countryCounts[$cc] ?? 0) + 1;
    $rows[] = $r;
}
// Stessa precauzione per le altre letture, anche se completate: SQLite non
// garantisce che un'istruzione arrivata in fondo rilasci subito il lock finché
// non viene resettata o finalizzata. Prima di chiedere il lock di scrittura,
// nessuna lettura deve restare aperta.
$res->finalize(); // (le regole di nazionalità le legge e finalizza loadCountryRules())

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

// Scrittura in transazione, con ritentativi: se l'import (csv_to_db.py) sta
// scrivendo proprio adesso, aspettare e riprovare è preferibile sia a fallire
// con un fatal error (che lasciava la cache non aggiornata per un'ora intera)
// sia a lasciare la vecchia cache senza dirlo. La cache viene comunque
// rigenerata per intero al tentativo successivo: nessun rischio di stato parziale.
$tierCounts = ['Mythic' => 0, 'Legendary' => 0, 'Epic' => 0, 'Rare' => 0, 'Uncommon' => 0, 'Common' => 0];
$maxAttempts = 3;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $tierCounts = array_fill_keys(array_keys($tierCounts), 0);
    try {
        // BEGIN IMMEDIATE (non DEFERRED): acquisisce subito il lock di scrittura,
        // l'unico punto in cui busyTimeout viene effettivamente rispettato.
        $db->exec("BEGIN IMMEDIATE");
        $db->exec("DELETE FROM rarity_cache");
        $stmt = $db->prepare("INSERT INTO rarity_cache (hex, seen_count, rarity, composite_score) VALUES (?, ?, ?, ?)");

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
        break; // riuscito
    } catch (Exception $e) {
        // Il ROLLBACK va protetto a sua volta: con enableExceptions(true) un
        // "cannot rollback - no transaction is active" (caso tipico quando è
        // stato proprio il BEGIN a fallire) lancerebbe una NUOVA eccezione,
        // che sfuggirebbe a questo catch mascherando l'errore originale.
        // L'operatore @ non basta: sopprime i warning, non le eccezioni.
        try { $db->exec("ROLLBACK"); } catch (Exception $ignored) {}
        if ($attempt < $maxAttempts) {
            sleep(5 * $attempt); // backoff: 5s, poi 10s
            continue;
        }
        fwrite(STDERR, date('c') . " update_rarity: cache NON aggiornata dopo $maxAttempts tentativi: "
            . $e->getMessage() . " (la cache precedente resta valida)\n");
        exit(1);
    }
}

$count = array_sum($tierCounts);
echo "Cache rarità aggiornata: $count hex classificati.\n";
foreach ($tierCounts as $tier => $n) {
    $pct = $count > 0 ? round($n / $count * 100, 1) : 0;
    echo "  $tier: $n ($pct%)\n";
}
