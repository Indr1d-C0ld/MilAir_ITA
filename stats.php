<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';

// --------------------- FUNZIONI DI MAPPING NAZIONALITÀ ---------------------
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

function getCountryCode($hex, $reg, $callsign) {
    $country = getCountryFromReg($reg);
    if ($country !== null) return $country;
    $country = getCountryFromCallsign($callsign);
    if ($country !== null) return $country;
    return 'UN';
}

/**
 * Deriva il codice operatore/forza aerea a 3 lettere da un callsign (es.
 * "IAM9001" -> "IAM"), stessa logica di index.php — vedi lì per il dettaglio.
 */
function operatorFromCallsign($callsign) {
    $cs = strtoupper(trim((string)$callsign));
    if (preg_match('/^[A-Z]{3}\d/', $cs)) {
        return substr($cs, 0, 3);
    }
    return null;
}

/** Percorso web del logo compagnia/forza aerea da opflags/{CODICE}.*, o null. */
function getOperatorLogo($code) {
    static $memo = [];
    $c = strtoupper(trim((string)$code));
    if ($c === '' || !preg_match('/^[A-Z0-9]{2,4}$/', $c)) {
        return null;
    }
    if (array_key_exists($c, $memo)) {
        return $memo[$c];
    }
    foreach (['bmp', 'png', 'svg', 'gif'] as $ext) {
        $f = __DIR__ . '/opflags/' . $c . '.' . $ext;
        if (file_exists($f) && filesize($f) > 0) {
            return $memo[$c] = 'opflags/' . $c . '.' . $ext;
        }
    }
    return $memo[$c] = null;
}

/** Emoji bandiera da codice ISO 3166-1 alpha-2 (Regional Indicator Symbols). */
function isoToFlagEmoji($code) {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $offset = 0x1F1E6 - 65;
    return mb_chr(ord($code[0]) + $offset, 'UTF-8') . mb_chr(ord($code[1]) + $offset, 'UTF-8');
}

/** HTML bandiera: SVG locale se presente in flags/, altrimenti emoji. */
function getFlagHtml($code) {
    $c = strtoupper(trim((string)$code));
    if ($c === '' || $c === 'UN') {
        return '<span title="Nazionalità non determinata">🏳️</span>';
    }
    $svgFile = __DIR__ . '/flags/' . $c . '.svg';
    if (preg_match('/^[A-Z]{2}$/', $c) && file_exists($svgFile)) {
        return '<img src="flags/' . $c . '.svg" class="flag-icon" alt="' . $c . '" title="' . $c . '">';
    }
    $emoji = isoToFlagEmoji($c);
    return $emoji !== '' ? '<span title="' . htmlspecialchars($c) . '">' . $emoji . '</span>' : htmlspecialchars($c);
}

function st_bar($v, $max, $w = 90) {
    $px = $max > 0 ? max(2, (int) round($w * $v / $max)) : 2;
    return '<span class="bar" style="width:' . $px . 'px"></span>';
}
// ---------------------------------------------------------------------------

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);

    $eventsExist = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='aircraft'");
    if (!$eventsExist) {
        throw new Exception("Tabella 'aircraft' mancante. Esegui prima l'import CSV.");
    }

    // ================== STATISTICHE DATABASE ==================
    $dbSizeBytes = filesize($dbPath);
    $dbSize = round($dbSizeBytes / 1024, 1) . ' KB';
    if ($dbSizeBytes > 1024 * 1024) {
        $dbSize = round($dbSizeBytes / (1024 * 1024), 2) . ' MB';
    }

    $numEvents = $db->querySingle("SELECT COUNT(*) FROM events");
    $numAircraft = $db->querySingle("SELECT COUNT(*) FROM aircraft");
    $numIdentities = $db->querySingle("SELECT COUNT(*) FROM aircraft_identity");
    $numActiveDays = $db->querySingle("SELECT COUNT(DISTINCT date) FROM daily_hex");
    $numNotes = $db->querySingle("SELECT COUNT(*) FROM notes WHERE note IS NOT NULL AND note != ''");

    // ================== QUERY PER GRAFICI ==================

    // 1. Altitudini
    $altBins = $db->query("SELECT
        COALESCE(SUM(CASE WHEN alt_ft BETWEEN 0 AND 9999 THEN 1 ELSE 0 END),0) AS '0-10k',
        COALESCE(SUM(CASE WHEN alt_ft BETWEEN 10000 AND 19999 THEN 1 ELSE 0 END),0) AS '10-20k',
        COALESCE(SUM(CASE WHEN alt_ft BETWEEN 20000 AND 29999 THEN 1 ELSE 0 END),0) AS '20-30k',
        COALESCE(SUM(CASE WHEN alt_ft >= 30000 THEN 1 ELSE 0 END),0) AS '30k+'
        FROM aircraft WHERE alt_ft IS NOT NULL")->fetchArray(SQLITE3_ASSOC);

    // 2. Modelli più frequenti
    $models = [];
    $res = $db->query("SELECT model_t, COUNT(*) as cnt FROM aircraft WHERE model_t IS NOT NULL AND model_t != '' GROUP BY model_t ORDER BY cnt DESC LIMIT 10");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $models[] = $row;

    // 3. Rarità
    $rarity = [];
    $res = $db->query("SELECT rarity, COUNT(*) as cnt FROM rarity_cache GROUP BY rarity");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rarity[] = $row;

    // 4. Velocità media per modello
    $speedData = [];
    $res = $db->query("SELECT model_t, AVG(gs_kt) as avg_speed FROM aircraft WHERE gs_kt > 0 GROUP BY model_t ORDER BY avg_speed DESC LIMIT 10");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $speedData[] = $row;

    // 5. Eventi per giorno (ultimi 30 giorni)
    $dailyCounts = [];
    $res = $db->query("SELECT substr(last_seen_utc,1,10) as day, COUNT(*) as cnt FROM aircraft WHERE last_seen_utc >= date('now','-30 days') GROUP BY day ORDER BY day ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $dailyCounts[$row['day']] = $row['cnt'];
    }
    $dates = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dates[] = ['day' => $d, 'cnt' => $dailyCounts[$d] ?? 0];
    }

    // 6. Top 10 HEX più frequenti
    $hexs = [];
    $res = $db->query("SELECT hex, COUNT(*) as cnt FROM events GROUP BY hex ORDER BY cnt DESC LIMIT 10");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $hexs[] = $row;

    // 7. Nazionalità
    $countryCounts = [];
    $res = $db->query("SELECT reg, callsign FROM aircraft");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $country = getCountryCode($row['hex'] ?? '', $row['reg'], $row['callsign']);
        $countryCounts[$country] = ($countryCounts[$country] ?? 0) + 1;
    }
    arsort($countryCounts);
    $countryLabels = array_keys($countryCounts);
    $countryValues = array_values($countryCounts);

    // 7b. Classifica Forze Aeree/Compagnie (codice operatore a 3 lettere derivato dal callsign)
    $operatorCounts = [];
    $res = $db->query("SELECT callsign FROM aircraft WHERE callsign IS NOT NULL AND callsign != ''");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $op = operatorFromCallsign($row['callsign']);
        if ($op !== null) {
            $operatorCounts[$op] = ($operatorCounts[$op] ?? 0) + 1;
        }
    }
    arsort($operatorCounts);
    $topOperators = array_slice($operatorCounts, 0, 15, true);

    // 7c. Classifica Callsign (esatti, storico completo su events)
    $topCallsigns = [];
    $res = $db->query("SELECT callsign, COUNT(*) as cnt FROM events WHERE callsign IS NOT NULL AND callsign != '' GROUP BY callsign ORDER BY cnt DESC LIMIT 15");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $topCallsigns[] = $row;

    // 7d. Classifica Registrazioni (esatte, storico completo su events)
    $topRegs = [];
    $res = $db->query("SELECT reg, COUNT(*) as cnt FROM events WHERE reg IS NOT NULL AND reg != '' GROUP BY reg ORDER BY cnt DESC LIMIT 15");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $topRegs[] = $row;

    // 8. Record e primati
    $recordAlt = $db->query("SELECT hex, callsign, model_t, alt_ft FROM aircraft
        WHERE alt_ft IS NOT NULL AND alt_ft != '' AND alt_ft > 0
        ORDER BY CAST(alt_ft AS INTEGER) DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    $recordSpeed = $db->query("SELECT hex, callsign, model_t, gs_kt FROM aircraft
        WHERE gs_kt IS NOT NULL AND gs_kt != '' AND gs_kt > 0
        ORDER BY CAST(gs_kt AS REAL) DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    $recordStreak = $db->query("SELECT hex, callsign, model_t, max_consecutive_days FROM aircraft
        ORDER BY max_consecutive_days DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    $recordConsistency = $db->query("SELECT hex, callsign, model_t, seen_count FROM aircraft
        ORDER BY seen_count DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    $busiestDay = $db->query("SELECT substr(first_seen_utc,1,10) as day, COUNT(*) as cnt
        FROM events GROUP BY day ORDER BY cnt DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);

    // 9. Codici squawk
    $squawkTop = [];
    $res = $db->query("SELECT squawk, COUNT(*) as cnt FROM events
        WHERE squawk IS NOT NULL AND squawk != '' AND squawk != '0000'
        GROUP BY squawk ORDER BY cnt DESC LIMIT 10");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $squawkTop[] = $row;

    $emergencySquawks = [];
    $res = $db->query("SELECT first_seen_utc, hex, callsign, reg, model_t, squawk, alt_ft
        FROM events WHERE squawk IN ('7500','7600','7700') ORDER BY first_seen_utc DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $emergencySquawks[] = $row;

    // 10. Heatmap attività: giorno della settimana x ora (UTC)
    $heatmapDayNames = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    $heatmapMatrix = array_fill(0, 7, array_fill(0, 24, 0));
    $heatmapMax = 0;
    $res = $db->query("SELECT
            CAST(strftime('%w', substr(first_seen_utc,1,19)) AS INTEGER) as dow,
            CAST(strftime('%H', substr(first_seen_utc,1,19)) AS INTEGER) as hh,
            COUNT(*) as cnt
        FROM events
        WHERE first_seen_utc IS NOT NULL AND first_seen_utc != ''
        GROUP BY dow, hh");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $heatmapMatrix[(int)$row['dow']][(int)$row['hh']] = (int)$row['cnt'];
        if ((int)$row['cnt'] > $heatmapMax) $heatmapMax = (int)$row['cnt'];
    }

} catch (Exception $e) {
    http_response_code(500);
    echo "Errore database: " . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statistiche MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        canvas { max-width: 600px; margin: 0 auto 20px; display: block; }
        .op-logo { height: 15px; width: auto; vertical-align: middle; margin-right: 4px; }
        .flag-icon { height: 14px; width: auto; vertical-align: middle; margin-right: 4px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; align-items: start; margin-bottom: 30px; }
        .stats-card { border: 1px solid #dee2e6; border-radius: 8px; padding: 12px 14px; overflow-x: auto; background: #fff; }
        .stats-card h3 { margin: 0 0 8px; font-size: 1rem; }
        .stats-card table { font-size: 0.86rem; width: 100%; }
        .stats-card th, .stats-card td { padding: 4px 6px; white-space: nowrap; }
        .stats-card td:first-child { white-space: normal; }
        .stats-card td .bar { max-width: 90px; }
        .bar { background: #007bff; height: 10px; border-radius: 3px; display: inline-block; vertical-align: middle; max-width: 100%; }
        .stats-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px 20px;
            min-width: 150px;
        }
        .stat-card .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #007bff;
        }
        .stat-card .stat-label {
            font-size: 0.9em;
            color: #495057;
        }
        .record-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }
        .record-card {
            background: #fff8e6;
            border: 1px solid #ffe4a3;
            border-radius: 8px;
            padding: 15px 20px;
            min-width: 200px;
        }
        .record-card .record-icon {
            font-size: 1.4em;
        }
        .record-card .record-value {
            font-size: 1.5em;
            font-weight: bold;
            color: #b8860b;
        }
        .record-card .record-label {
            font-size: 0.85em;
            color: #495057;
            margin-bottom: 4px;
        }
        .record-card .record-detail {
            font-size: 0.85em;
            color: #6c757d;
        }
        .record-card .record-detail a {
            color: #007bff;
            text-decoration: none;
        }
        .record-card .record-detail a:hover {
            text-decoration: underline;
        }
        .heatmap-wrap {
            overflow-x: auto;
            margin-bottom: 30px;
        }
        table.heatmap {
            border-collapse: collapse;
            font-size: 0.8em;
            white-space: nowrap;
        }
        table.heatmap th, table.heatmap td {
            padding: 3px 5px;
            text-align: center;
            border: 1px solid #f1f1f1;
        }
        table.heatmap th {
            background: #f8f9fa;
            font-weight: normal;
            color: #495057;
        }
        table.heatmap td.hm-cell {
            color: #212529;
        }
        .squawk-alert-banner {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .squawk-alert-banner.has-emergency {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .squawk-alert-banner.no-emergency {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .squawk-alert-banner ul {
            margin: 8px 0 0;
            padding-left: 20px;
            font-weight: normal;
        }
        .squawk-alert-banner a {
            color: inherit;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php render_nav('stats.php'); ?>
    <h2>📊 Statistiche e Grafici</h2>

    <!-- Dettagli database -->
    <div class="stats-summary">
        <div class="stat-card">
            <div class="stat-value"><?= htmlspecialchars($dbSize) ?></div>
            <div class="stat-label">Dimensione DB</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($numEvents) ?></div>
            <div class="stat-label">Eventi registrati</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($numAircraft) ?></div>
            <div class="stat-label">Aeromobili (hex unici)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($numIdentities) ?></div>
            <div class="stat-label">Identità (hex+callsign+reg)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($numActiveDays) ?></div>
            <div class="stat-label">Giorni di attività</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($numNotes) ?></div>
            <div class="stat-label">Note annotate</div>
        </div>
    </div>

    <h3>🏆 Record e Primati</h3>
    <div class="record-cards">
        <?php if ($recordAlt): ?>
        <div class="record-card">
            <div class="record-label">🚀 Quota massima registrata</div>
            <div class="record-value"><?= number_format((int)$recordAlt['alt_ft']) ?> ft</div>
            <div class="record-detail">
                <a href="index.php?hex=<?= urlencode($recordAlt['hex']) ?>"><?= htmlspecialchars($recordAlt['callsign'] ?: $recordAlt['hex']) ?></a>
                <?= $recordAlt['model_t'] ? ' · ' . htmlspecialchars($recordAlt['model_t']) : '' ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($recordSpeed): ?>
        <div class="record-card">
            <div class="record-label">⚡ Velocità massima registrata</div>
            <div class="record-value"><?= number_format((float)$recordSpeed['gs_kt'], 1) ?> kt</div>
            <div class="record-detail">
                <a href="index.php?hex=<?= urlencode($recordSpeed['hex']) ?>"><?= htmlspecialchars($recordSpeed['callsign'] ?: $recordSpeed['hex']) ?></a>
                <?= $recordSpeed['model_t'] ? ' · ' . htmlspecialchars($recordSpeed['model_t']) : '' ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($recordStreak && $recordStreak['max_consecutive_days'] > 0): ?>
        <div class="record-card">
            <div class="record-label">🔥 Streak di giorni consecutivi più lunga</div>
            <div class="record-value"><?= number_format($recordStreak['max_consecutive_days']) ?> giorni</div>
            <div class="record-detail">
                <a href="index.php?hex=<?= urlencode($recordStreak['hex']) ?>"><?= htmlspecialchars($recordStreak['callsign'] ?: $recordStreak['hex']) ?></a>
                <?= $recordStreak['model_t'] ? ' · ' . htmlspecialchars($recordStreak['model_t']) : '' ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($recordConsistency && $recordConsistency['seen_count'] > 0): ?>
        <div class="record-card">
            <div class="record-label">📅 Aeromobile più costante (totale giorni visti)</div>
            <div class="record-value"><?= number_format($recordConsistency['seen_count']) ?> giorni</div>
            <div class="record-detail">
                <a href="index.php?hex=<?= urlencode($recordConsistency['hex']) ?>"><?= htmlspecialchars($recordConsistency['callsign'] ?: $recordConsistency['hex']) ?></a>
                <?= $recordConsistency['model_t'] ? ' · ' . htmlspecialchars($recordConsistency['model_t']) : '' ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($busiestDay): ?>
        <div class="record-card">
            <div class="record-label">📈 Giorno più intenso</div>
            <div class="record-value"><?= number_format($busiestDay['cnt']) ?> eventi</div>
            <div class="record-detail"><?= htmlspecialchars(date('d/m/Y', strtotime($busiestDay['day']))) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <h3>Eventi giornalieri (ultimi 30 giorni)</h3>
    <canvas id="dailyChart" width="600" height="180"></canvas>

    <h3>🕐 Attività per ora del giorno e giorno della settimana (UTC)</h3>
    <div class="heatmap-wrap">
        <table class="heatmap">
            <thead>
                <tr>
                    <th></th>
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <th><?= sprintf('%02d', $h) ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($heatmapDayNames as $dowIndex => $dayName): ?>
                    <tr>
                        <th><?= htmlspecialchars($dayName) ?></th>
                        <?php for ($h = 0; $h < 24; $h++):
                            $cnt = $heatmapMatrix[$dowIndex][$h];
                            $ratio = $heatmapMax > 0 ? $cnt / $heatmapMax : 0;
                            $bg = $cnt > 0 ? sprintf('rgba(75, 192, 192, %.2f)', 0.15 + $ratio * 0.85) : 'transparent';
                        ?>
                            <td class="hm-cell" style="background: <?= $bg ?>;" title="<?= htmlspecialchars($dayName) ?> <?= sprintf('%02d:00', $h) ?> – <?= $cnt ?> eventi"><?= $cnt > 0 ? $cnt : '' ?></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>🎖️ Classifiche</h2>
    <div class="stats-grid">
        <div class="stats-card">
            <h3>Forze Aeree / Compagnie</h3>
            <table>
                <?php $opMax = $topOperators ? max($topOperators) : 0; ?>
                <?php foreach ($topOperators as $op => $cnt): $logo = getOperatorLogo($op); ?>
                    <tr>
                        <td>
                            <a href="index.php?operator=<?= urlencode($op) ?>" title="Filtra per <?= htmlspecialchars($op) ?> (sempre)">
                                <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" class="op-logo" alt=""><?php endif; ?>
                                <?= htmlspecialchars($op) ?>
                            </a>
                        </td>
                        <td><?= number_format($cnt) ?></td>
                        <td><?= st_bar($cnt, $opMax) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topOperators): ?><tr><td colspan="3">Nessun operatore identificabile dai callsign in archivio.</td></tr><?php endif; ?>
            </table>
        </div>
        <div class="stats-card">
            <h3>Nazionalità</h3>
            <table>
                <?php $ccMax = $countryValues ? max($countryValues) : 0; ?>
                <?php foreach ($countryCounts as $cc => $cnt): ?>
                    <tr>
                        <td><a href="index.php?country=<?= urlencode($cc) ?>" title="Filtra per <?= htmlspecialchars($cc) ?> (sempre)"><?= getFlagHtml($cc) ?> <?= htmlspecialchars($cc) ?></a></td>
                        <td><?= number_format($cnt) ?></td>
                        <td><?= st_bar($cnt, $ccMax) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="stats-card">
            <h3>Callsign più frequenti</h3>
            <table>
                <?php $csMax = $topCallsigns ? max(array_column($topCallsigns, 'cnt')) : 0; ?>
                <?php foreach ($topCallsigns as $r): ?>
                    <tr>
                        <td><a href="index.php?callsign=<?= urlencode($r['callsign']) ?>" title="Filtra per <?= htmlspecialchars($r['callsign']) ?> (sempre)"><?= htmlspecialchars($r['callsign']) ?></a></td>
                        <td><?= number_format($r['cnt']) ?></td>
                        <td><?= st_bar($r['cnt'], $csMax) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topCallsigns): ?><tr><td colspan="3">Nessun dato.</td></tr><?php endif; ?>
            </table>
        </div>
        <div class="stats-card">
            <h3>Registrazioni più frequenti</h3>
            <table>
                <?php $rgMax = $topRegs ? max(array_column($topRegs, 'cnt')) : 0; ?>
                <?php foreach ($topRegs as $r): ?>
                    <tr>
                        <td><a href="index.php?reg=<?= urlencode($r['reg']) ?>" title="Filtra per <?= htmlspecialchars($r['reg']) ?> (sempre)"><?= htmlspecialchars($r['reg']) ?></a></td>
                        <td><?= number_format($r['cnt']) ?></td>
                        <td><?= st_bar($r['cnt'], $rgMax) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topRegs): ?><tr><td colspan="3">Nessun dato.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <h3>Distribuzione Altitudini</h3>
    <canvas id="altChart" width="600" height="180"></canvas>

    <h3>Top 10 Modelli più avvistati</h3>
    <canvas id="modelChart" width="600" height="250"></canvas>

    <h3>Distribuzione Rarità</h3>
    <canvas id="rarityChart" width="400" height="250"></canvas>

    <h3>Velocità media per modello (Top 10)</h3>
    <canvas id="speedChart" width="600" height="250"></canvas>

    <h3>Top 10 HEX più frequenti</h3>
    <canvas id="hexChart" width="600" height="250"></canvas>

    <h3>Distribuzione per Nazionalità</h3>
    <canvas id="countryChart" width="600" height="300"></canvas>

    <h3>📡 Codici Squawk</h3>
    <?php if (!empty($emergencySquawks)): ?>
        <div class="squawk-alert-banner has-emergency">
            ⚠️ <?= count($emergencySquawks) ?> codice/i di emergenza (7500/7600/7700) registrato/i in archivio:
            <ul>
                <?php foreach ($emergencySquawks as $es): ?>
                    <li>
                        <?= htmlspecialchars($es['first_seen_utc']) ?> —
                        squawk <strong><?= htmlspecialchars($es['squawk']) ?></strong> —
                        <a href="index.php?hex=<?= urlencode($es['hex']) ?>"><?= htmlspecialchars($es['callsign'] ?: $es['hex']) ?></a>
                        <?= $es['model_t'] ? ' (' . htmlspecialchars($es['model_t']) . ')' : '' ?>
                        <?= $es['alt_ft'] !== '' ? ' — ' . number_format((int)$es['alt_ft']) . ' ft' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="squawk-alert-banner no-emergency">
            ✅ Nessun codice di emergenza (7500/7600/7700) registrato in archivio.
        </div>
    <?php endif; ?>
    <canvas id="squawkChart" width="600" height="250"></canvas>

    <script>
        // Eventi giornalieri
        new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($dates, 'day')) ?>,
                datasets: [{
                    label: 'Numero voli',
                    data: <?= json_encode(array_column($dates, 'cnt')) ?>,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.2
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Conteggio' } }
                }
            }
        });

        // Altitudini
        new Chart(document.getElementById('altChart'), {
            type: 'bar',
            data: {
                labels: ['0-10k', '10-20k', '20-30k', '30k+'],
                datasets: [{
                    label: 'Numero voli',
                    data: [<?= implode(',', $altBins) ?>],
                    backgroundColor: 'rgba(54, 162, 235, 0.6)'
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Conteggio' } }
                }
            }
        });

        // Modelli
        new Chart(document.getElementById('modelChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($models, 'model_t')) ?>,
                datasets: [{
                    label: 'Conteggio',
                    data: <?= json_encode(array_column($models, 'cnt')) ?>,
                    backgroundColor: 'rgba(255, 159, 64, 0.6)'
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Conteggio' } }
                }
            }
        });

        // Rarità
        const rarityLabels = <?= json_encode(array_column($rarity, 'rarity')) ?>;
        const rarityCounts = <?= json_encode(array_column($rarity, 'cnt')) ?>;
        const rarityColors = {
            'Mythic': '#dc3545',
            'Legendary': '#ff8c00',
            'Epic': '#6f42c1',
            'Rare': '#007bff',
            'Uncommon': '#28a745',
            'Common': '#6c757d'
        };
        new Chart(document.getElementById('rarityChart'), {
            type: 'pie',
            data: {
                labels: rarityLabels,
                datasets: [{
                    data: rarityCounts,
                    backgroundColor: rarityLabels.map(l => rarityColors[l] || '#999')
                }]
            }
        });

        // Velocità per modello
        new Chart(document.getElementById('speedChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($speedData, 'model_t')) ?>,
                datasets: [{
                    label: 'Velocità media (kt)',
                    data: <?= json_encode(array_column($speedData, 'avg_speed')) ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.6)'
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Nodi' } }
                }
            }
        });

        // HEX più frequenti
        new Chart(document.getElementById('hexChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($hexs, 'hex')) ?>,
                datasets: [{
                    label: 'Numero eventi',
                    data: <?= json_encode(array_column($hexs, 'cnt')) ?>,
                    backgroundColor: 'rgba(153, 102, 255, 0.6)'
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Numero eventi' } }
                }
            }
        });

        // Nazionalità
        new Chart(document.getElementById('countryChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($countryLabels) ?>,
                datasets: [{
                    label: 'Numero aeromobili',
                    data: <?= json_encode($countryValues) ?>,
                    backgroundColor: 'rgba(255, 205, 86, 0.6)'
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Numero aeromobili' } }
                }
            }
        });

        // Codici squawk
        new Chart(document.getElementById('squawkChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($squawkTop, 'squawk')) ?>,
                datasets: [{
                    label: 'Numero eventi',
                    data: <?= json_encode(array_column($squawkTop, 'cnt')) ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.6)'
                }]
            },
            options: {
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Numero eventi' } }
                }
            }
        });
    </script>
</body>
</html>
