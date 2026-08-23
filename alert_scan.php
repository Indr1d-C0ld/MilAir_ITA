#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scanner periodico per il sistema di alert. Esegue una scansione incremen-
 * tale (via checkpoint su rowid) delle nuove righe in events.db e genera
 * notifiche in auth.db per: contatti in watchlist (marker ❓) che ricompaiono,
 * squawk di emergenza, contatti mai visti prima con rarità Mythic/Legendary, e
 * corrispondenze con le regole di notifica personalizzate (rules.php) — utili
 * per essere avvertiti su contatti attesi ma non ancora presenti in database.
 *
 * Da eseguire via cron (vedi crontab.txt) — non installa da sé la voce di
 * crontab, va aggiunta manualmente al crontab reale del sistema.
 */

require_once __DIR__ . '/auth.php'; // solo per get_auth_db()/create_alert(): NON chiama auth_bootstrap() (nessuna sessione in CLI)

const ALERT_COOLDOWN_MINUTES = 60;
const RARITY_PENDING_MAX_HOURS = 6;

$emergencySquawks = [
    '7500' => 'Interferenza illecita (dirottamento)',
    '7600' => 'Guasto radio / perdita comunicazioni',
    '7700' => 'Emergenza generale',
];

$eventsDb = new SQLite3(__DIR__ . '/events.db', SQLITE3_OPEN_READONLY);
$eventsDb->enableExceptions(true);
$eventsDb->busyTimeout(5000);

$authDb = get_auth_db();

/**
 * Confronta un valore con un pattern che può contenere wildcard '*' oppure un
 * intervallo nella forma "BASSO - ALTO" — stessa logica già in uso in
 * rules.php/index.php/map.php per le regole personalizzate.
 */
function patternMatch($value, $pattern) {
    $value = strtoupper(trim($value));
    $pattern = trim($pattern);

    if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $pattern, $m)) {
        return rangeMatch($value, $m[1], $m[2]);
    }

    $pattern = strtoupper($pattern);
    if (strpos($pattern, '*') === false) {
        return strpos($value, $pattern) === 0;
    }

    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
    return preg_match($regex, $value) === 1;
}

function rangeMatch($value, $lowPattern, $highPattern) {
    $low = strtoupper(rtrim(trim($lowPattern), '*'));
    $high = strtoupper(rtrim(trim($highPattern), '*'));
    if ($low === '' || $high === '') {
        return false;
    }
    $lowLen = strlen($low);
    $highLen = strlen($high);
    $vLow = strlen($value) >= $lowLen ? substr($value, 0, $lowLen) : str_pad($value, $lowLen, '0');
    $vHigh = strlen($value) >= $highLen ? substr($value, 0, $highLen) : str_pad($value, $highLen, '0');
    return $vLow >= $low && $vHigh <= $high;
}

function hexLabel(SQLite3 $eventsDb, string $hex): string {
    $stmt = $eventsDb->prepare("SELECT callsign, reg, model_t FROM aircraft WHERE hex = ?");
    $stmt->bindValue(1, $hex);
    $a = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$a) return $hex;
    $bits = array_filter([$a['callsign'], $a['reg'], $a['model_t']]);
    return $hex . ($bits ? ' (' . implode(' / ', $bits) . ')' : '');
}

function onCooldown(SQLite3 $authDb, string $hex, string $type): bool {
    $stmt = $authDb->prepare("SELECT last_alerted_at FROM alert_cooldown WHERE hex = ? AND alert_type = ?");
    $stmt->bindValue(1, $hex);
    $stmt->bindValue(2, $type);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return false;
    return (time() - strtotime($row['last_alerted_at'])) < ALERT_COOLDOWN_MINUTES * 60;
}

function touchCooldown(SQLite3 $authDb, string $hex, string $type): void {
    $stmt = $authDb->prepare("INSERT INTO alert_cooldown (hex, alert_type, last_alerted_at) VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(hex, alert_type) DO UPDATE SET last_alerted_at = CURRENT_TIMESTAMP");
    $stmt->bindValue(1, $hex);
    $stmt->bindValue(2, $type);
    $stmt->execute();
}

// --- 1. Checkpoint ---
$authDb->exec("INSERT OR IGNORE INTO alert_scan_checkpoint (id, last_events_rowid) VALUES (1, 0)");
$lastRowid = (int)$authDb->querySingle("SELECT last_events_rowid FROM alert_scan_checkpoint WHERE id = 1");

// Regole di notifica personalizzate (gestite in rules.php) — caricate una volta per run.
// La tabella potrebbe non esistere ancora se rules.php non è mai stata aperta dopo il deploy.
$alertRules = [];
try {
    $resRules = $eventsDb->query("SELECT id, field, pattern, description FROM alert_rules");
    while ($r = $resRules->fetchArray(SQLITE3_ASSOC)) {
        $alertRules[] = $r;
    }
} catch (Exception $e) {
    // tabella assente: nessuna regola personalizzata da valutare in questo run
}

$stmt = $eventsDb->prepare("SELECT rowid, hex, first_seen_utc, squawk, callsign, reg, model_t FROM events WHERE rowid > ? ORDER BY rowid ASC");
$stmt->bindValue(1, $lastRowid, SQLITE3_INTEGER);
$res = $stmt->execute();

$maxRowidSeen = $lastRowid;
$newCount = 0;

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $newCount++;
    $maxRowidSeen = max($maxRowidSeen, (int)$row['rowid']);
    $hex = $row['hex'];
    if (!$hex) continue;

    // --- Watchlist: hex marcato ❓ che produce un nuovo evento ---
    $stmtM = $eventsDb->prepare("SELECT 1 FROM markers WHERE hex = ? AND emoji = '❓'");
    $stmtM->bindValue(1, $hex);
    if ($stmtM->execute()->fetchArray(SQLITE3_ASSOC) && !onCooldown($authDb, $hex, 'watchlist')) {
        create_alert('watchlist', $hex, null,
            'Contatto in watchlist rilevato: ' . hexLabel($eventsDb, $hex),
            'Nuovo evento alle ' . format_date_it($row['first_seen_utc']) . ' per un contatto contrassegnato "da tenere d\'occhio".');
        touchCooldown($authDb, $hex, 'watchlist');
    }

    // --- Emergency squawk ---
    $squawk = trim((string)($row['squawk'] ?? ''));
    if (isset($emergencySquawks[$squawk]) && !onCooldown($authDb, $hex, 'emergency_squawk')) {
        create_alert('emergency_squawk', $hex, null,
            'Squawk di emergenza ' . $squawk . ': ' . hexLabel($eventsDb, $hex),
            $emergencySquawks[$squawk] . ' — rilevato alle ' . format_date_it($row['first_seen_utc']) . '.');
        touchCooldown($authDb, $hex, 'emergency_squawk');
    }

    // --- Rare contact: prima apparizione assoluta di questo hex ---
    $stmtA = $eventsDb->prepare("SELECT first_seen_utc FROM aircraft WHERE hex = ?");
    $stmtA->bindValue(1, $hex);
    $aRow = $stmtA->execute()->fetchArray(SQLITE3_ASSOC);
    if ($aRow && $aRow['first_seen_utc'] === $row['first_seen_utc']) {
        $stmtR = $eventsDb->prepare("SELECT rarity FROM rarity_cache WHERE hex = ?");
        $stmtR->bindValue(1, $hex);
        $rRow = $stmtR->execute()->fetchArray(SQLITE3_ASSOC);
        if ($rRow === false) {
            // Non ancora classificato (rarity_cache si ricostruisce una volta l'ora): rimando.
            $stmtP = $authDb->prepare("INSERT OR IGNORE INTO alert_rarity_pending (hex, events_rowid, first_seen_utc) VALUES (?, ?, ?)");
            $stmtP->bindValue(1, $hex);
            $stmtP->bindValue(2, (int)$row['rowid']);
            $stmtP->bindValue(3, $row['first_seen_utc']);
            $stmtP->execute();
        } elseif (in_array($rRow['rarity'], ['Mythic', 'Legendary'], true)) {
            create_alert('rare_contact', $hex, null,
                'Contatto mai visto prima (' . $rRow['rarity'] . '): ' . hexLabel($eventsDb, $hex),
                'Prima apparizione registrata alle ' . format_date_it($row['first_seen_utc']) . '.');
        }
    }

    // --- Regole di notifica personalizzate (rules.php): contatti attesi ma non ancora in database ---
    $fieldValues = [
        'hex' => $hex,
        'callsign' => (string)($row['callsign'] ?? ''),
        'reg' => (string)($row['reg'] ?? ''),
        'model_t' => (string)($row['model_t'] ?? ''),
    ];
    foreach ($alertRules as $rule) {
        $value = $fieldValues[$rule['field']] ?? '';
        if ($value === '' || !patternMatch($value, $rule['pattern'])) continue;
        $cooldownKey = 'custom_rule:' . $rule['id'];
        if (onCooldown($authDb, $hex, $cooldownKey)) continue;

        $desc = trim((string)($rule['description'] ?? ''));
        create_alert('custom_rule', $hex, null,
            'Regola corrispondente (' . $rule['field'] . ': ' . $rule['pattern'] . '): ' . hexLabel($eventsDb, $hex),
            ($desc !== '' ? $desc . ' — ' : '') . 'rilevato alle ' . format_date_it($row['first_seen_utc']) . '.');
        touchCooldown($authDb, $hex, $cooldownKey);
    }
}

// --- 2. Rivaluta le prime apparizioni in attesa di classificazione rarità ---
$pending = $authDb->query("SELECT hex, events_rowid, first_seen_utc, discovered_at FROM alert_rarity_pending");
while ($p = $pending->fetchArray(SQLITE3_ASSOC)) {
    $stmtR = $eventsDb->prepare("SELECT rarity FROM rarity_cache WHERE hex = ?");
    $stmtR->bindValue(1, $p['hex']);
    $rRow = $stmtR->execute()->fetchArray(SQLITE3_ASSOC);

    $stmtDel = $authDb->prepare("DELETE FROM alert_rarity_pending WHERE hex = ?");

    if ($rRow !== false) {
        if (in_array($rRow['rarity'], ['Mythic', 'Legendary'], true)) {
            create_alert('rare_contact', $p['hex'], null,
                'Contatto mai visto prima (' . $rRow['rarity'] . '): ' . hexLabel($eventsDb, $p['hex']),
                'Prima apparizione registrata alle ' . format_date_it($p['first_seen_utc']) . '.');
        }
        $stmtDel->bindValue(1, $p['hex']);
        $stmtDel->execute();
    } elseif ((time() - strtotime($p['discovered_at'])) > RARITY_PENDING_MAX_HOURS * 3600) {
        $stmtDel->bindValue(1, $p['hex']);
        $stmtDel->execute();
    }
}

// --- 3. Aggiorna il checkpoint (dopo aver processato tutto, per idempotenza in caso di crash a metà) ---
if ($maxRowidSeen > $lastRowid) {
    $stmt = $authDb->prepare("UPDATE alert_scan_checkpoint SET last_events_rowid = ? WHERE id = 1");
    $stmt->bindValue(1, $maxRowidSeen, SQLITE3_INTEGER);
    $stmt->execute();
}

echo "Scansione completata: {$newCount} nuovi eventi processati, checkpoint a rowid {$maxRowidSeen}.\n";
