<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/geo_lib.php';
auth_bootstrap();
log_access();
$canEdit = is_logged_in(); // Collaboratore o Admin: mostra le azioni di scrittura

require_once __DIR__ . '/table_lib.php';

$dbPath = __DIR__ . '/events.db';

// Parametri di filtro/ordinamento: letti e normalizzati da table_lib.php, la
// stessa pipeline usata da export.php (pulsanti rapidi in ora italiana inclusi).
$params = table_params($_GET);
$dateFrom     = $params['date_from'];
$dateTo       = $params['date_to'];
$hex          = $params['hex'];
$callsign     = $params['callsign'];
$reg          = $params['reg'];
$model        = $params['model'];
$operator     = $params['operator'];
$note_search  = $params['note'];
$rarity       = $params['rarity'];
$country      = $params['country'];
$category     = $params['category'];
$markered     = $params['markered'];
$manual       = $params['manual'];
$squawkFilter = $params['squawk_filter'];
$geofilter    = $params['geofilter'];
$sort         = $params['sort'];
$order        = $params['order'];
$dateView = ($_GET['dateview'] ?? '') === 'extended' ? 'extended' : 'compact';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

// Codici squawk di emergenza (ICAO/DO-260B), per evidenziazione, tooltip e filtro.
$emergencySquawks = EMERGENCY_SQUAWKS;

$baseParams = array_intersect_key($_GET, array_flip(['hex','callsign','reg','model','operator','note','rarity','country','category','markered','manual','squawk_filter','geofilter','sort','order','dateview']));

define('SILHOUETTE_DIR', __DIR__ . '/silhouettes');
define('FLAGS_DIR', __DIR__ . '/flags');
define('PHOTOS_DIR', __DIR__ . '/photos');
define('DRAWINGS_DIR', __DIR__ . '/drawings');
define('FDBPHOTOS_DIR', __DIR__ . '/fdbphotos');
define('OPFLAGS_DIR', __DIR__ . '/opflags');
/**
 * Restituisce il percorso web (relativo) della silhouette se esiste in locale.
 */
function getSilhouettePath($type) {
    if (empty($type)) {
        return null;
    }

    $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($type)));
    if ($safeType === '') {
        return null;
    }

    $aliasMap = [
        'E390' => ['E390', 'C390', 'KC390', 'EMB390'],
        'FA6X' => ['FA6X', 'F6X', 'FALCON6X'],
        'GA6C' => ['GA6C', 'G600', 'GULFSTREAMG600'],
        'HRON' => ['HRON', 'HERON', 'HERON1'],
        'M345' => ['M345', 'M-345'],
        'M346' => ['M346', 'M-346'],
    ];
    $candidates = isset($aliasMap[$safeType]) ? $aliasMap[$safeType] : [$safeType];
    $extensions = ['bmp', 'png', 'svg', 'gif'];

    foreach ($candidates as $cand) {
        foreach ($extensions as $ext) {
            $localFile = SILHOUETTE_DIR . '/' . $cand . '.' . $ext;
            $webPath   = 'silhouettes/' . $cand . '.' . $ext;
            if (file_exists($localFile) && filesize($localFile) > 0) {
                return $webPath;
            }
        }
    }

    return null;
}

/**
 * Restituisce il percorso web (relativo) della foto del modello.
 */
function getModelPhotoPath($type) {
    if (empty($type)) {
        return null;
    }

    $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($type)));
    if ($safeType === '') {
        return null;
    }

    $localFile = PHOTOS_DIR . '/' . $safeType . '.jpg';
    $webPath   = 'photos/' . $safeType . '.jpg';

    if (file_exists($localFile) && filesize($localFile) > 0) {
        return $webPath;
    }

    return null;
}

/**
 * Restituisce il percorso web (relativo) del disegno tecnico.
 */
function getDrawingPath($type) {
    if (empty($type)) {
        return null;
    }

    $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($type)));
    if ($safeType === '') {
        return null;
    }

    $localFile = DRAWINGS_DIR . '/' . $safeType . '.jpg';
    $webPath   = 'drawings/' . $safeType . '.jpg';

    if (file_exists($localFile) && filesize($localFile) > 0) {
        return $webPath;
    }

    return null;
}

/**
 * Restituisce il percorso web (relativo) della foto reale da fdbphotos.
 */
function getFdbPhotoPath($hex) {
    if (empty($hex)) {
        return null;
    }

    $safeHex = strtoupper(trim($hex));
    $localFile = FDBPHOTOS_DIR . '/' . $safeHex . '.jpg';
    $webPath   = 'fdbphotos/' . $safeHex . '.jpg';

    if (file_exists($localFile) && filesize($localFile) > 0) {
        return $webPath;
    }

    return null;
}
/**
 * Funzione per generare il link di ordinamento.
 */
function sortLink($columnKey, $label, $currentSort, $currentOrder, $getParams) {
    $newOrder = ($currentSort === $columnKey && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $columnKey) {
        $arrow = ($currentOrder === 'asc') ? ' ▲' : ' ▼';
    }
    $merged = array_merge($getParams, ['sort' => $columnKey, 'order' => $newOrder, 'page' => 1]);
    return '<a href="?' . http_build_query($merged) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}

/**
 * Converte una data UTC in data/ora italiana.
 */
function formatDateIt($utcString) {
    if (empty($utcString)) {
        return '';
    }
    $clean = str_replace(' UTC', '', trim($utcString));
    try {
        $date = new DateTime($clean, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Rome'));
        return $date->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return htmlspecialchars($utcString);
    }
}
try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

    $data = table_load($db, $params);
    $filtered           = $data['rows'];
    $availableCountries = $data['availableCountries'];
    $rowRules           = $data['rowRules'];
    $manualOverrides    = $data['manualOverrides'];
    $favoritesHex       = $data['favoritesHex'];

    $totalRows  = count($filtered);
    $totalPages = (int) ceil($totalRows / $perPage);
    // Una pagina oltre l'ultima (link vecchio, filtri cambiati) mostrava una
    // tabella vuota senza spiegazioni: si riporta all'ultima pagina esistente.
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }
    $offset   = ($page - 1) * $perPage;
    $rowsPage = array_slice($filtered, $offset, $perPage);
} catch (Exception $e) {
    error_log('index.php: ' . $e->getMessage());
    die("Errore nel caricamento dei dati. Riprova tra qualche minuto.");
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ADS‑B MILAIR ITA – Aerei Militari</title>
    <?php if ($canEdit): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <style>
        tr.mythic-row { background-color: #ffe6e6; }
        tr.mythic-row:hover td { background-color: #ffd4d4; }
        .copy-btn { text-decoration: none; margin-left: 4px; font-size: 0.9em; cursor: pointer; }
        .anom-link { text-decoration: none; margin-left: 4px; font-size: 0.9em; }
        table a { color: #007bff; text-decoration: none; }
        table a:hover { color: #0056b3; text-decoration: none; }
        .model-silhouette { height: 18px; width: auto; vertical-align: middle; margin-right: 4px; }
        .flag-icon { height: 14px; width: auto; vertical-align: middle; margin-right: 4px; }
        .op-logo { height: 13px; width: auto; vertical-align: middle; margin-right: 3px; }
        .operator-badge { display: inline-flex; align-items: center; }
        .model-photo { height: 26px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .model-drawing { height: 26px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .fdb-photo { height: 26px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .thumb-col { text-align: center; }
        /* Righe a altezza fissa e compatta: solo per la tabella principale dei
           contatti (.contacts-table), non tocca le altre tabelle del portale
           che condividono le regole generiche di style.css. */
        .contacts-table th, .contacts-table td { padding: 4px 8px; line-height: 1.3; }
        .contacts-table tbody tr { height: 42px; }
        /* Nota troncata su una riga, con tooltip personalizzato al passaggio del
           mouse per leggerla per intero senza intaccare l'altezza della riga. */
        .note-preview {
            display: inline-block; max-width: 140px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
            cursor: help; border-bottom: 1px dotted #adb5bd;
        }
        .note-tooltip { position: relative; }
        .note-tooltip .note-tooltip-box {
            display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 6px;
            background: #212529; color: #fff; padding: 8px 10px; border-radius: 6px;
            font-size: 0.85rem; line-height: 1.4; white-space: normal;
            width: max-content; max-width: 280px; box-shadow: 0 4px 14px rgba(0,0,0,0.25);
            z-index: 60;
        }
        .note-tooltip:hover .note-tooltip-box { display: block; }
        /* Anteprima ingrandita delle miniature al passaggio del mouse: overlay
           position:fixed (non CSS puro) perché .table-scroll ha overflow-x:auto,
           che taglierebbe qualunque popup posizionato in modo relativo alla riga. */
        #thumbPreview {
            display: none; position: fixed; max-width: 240px; max-height: 240px;
            border: 3px solid #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,0.4);
            z-index: 9999; pointer-events: none; background: #fff;
        }
        .thumb-zoomable { cursor: zoom-in; }
        .bold-row { font-weight: bold; }
        .rarity-legend {
            background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;
            padding: 12px 15px; margin-bottom: 20px; display: flex;
            flex-wrap: wrap; gap: 10px 20px; font-size: 0.9em; align-items: center;
        }
        .period-cell { white-space: nowrap; line-height: 1.3; }
        .period-cell .period-last { display: block; }
        .period-cell .period-first { display: block; font-size: 0.8em; color: #6c757d; }
        .squawk-col { text-align: center; }
        .squawk-emergency { background-color: #f8d7da; font-weight: bold; color: #721c24; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .legend-color { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
        .mark-btn {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.1em;
            padding: 2px 4px;
            border-radius: 4px;
        }
        .mark-btn:hover { background: #f1f3f5; }
        .marker-picker {
            display: none;
            position: absolute;
            z-index: 2000;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            padding: 8px;
            width: 230px;
        }
        .marker-picker .picker-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
        }
        .marker-picker .picker-grid button {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.3em;
            padding: 4px;
            border-radius: 4px;
            width: 30px;
        }
        .marker-picker .picker-grid button:hover { background: #f1f3f5; }
        .marker-picker .picker-remove {
            display: block;
            width: 100%;
            margin-top: 6px;
            padding: 5px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #f8f9fa;
            cursor: pointer;
            font-size: 0.85em;
        }
        .marker-picker .picker-remove:hover { background: #e9ecef; }

        .identity-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }
        .identity-modal-backdrop.open { display: flex; }
        .identity-modal {
            background: #fff;
            border-radius: 10px;
            padding: 20px 24px;
            width: 420px;
            max-width: 92vw;
            max-height: 88vh;
            overflow-y: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
        }
        .identity-modal h3 { margin-top: 0; }
        .identity-modal label {
            display: block;
            margin-bottom: 12px;
            font-size: 0.9em;
        }
        .identity-modal input[type="text"],
        .identity-modal input[type="file"] {
            display: block;
            width: 100%;
            box-sizing: border-box;
            margin-top: 4px;
            padding: 6px 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .identity-modal .modal-hint {
            font-size: 0.8em;
            color: #6c757d;
            margin: -8px 0 14px;
        }
        .identity-modal .modal-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            gap: 8px;
        }
        .identity-modal .modal-status {
            font-size: 0.85em;
            margin-top: 10px;
            min-height: 1.2em;
        }
        .identity-modal .modal-status.error { color: #dc3545; }
        .identity-modal .modal-status.success { color: #28a745; }
    </style>
</head>
<body>
    <?php render_nav('index.php'); ?>

    <h2>✈️ Aerei Militari – Italia</h2>

    <div class="rarity-legend" title="Rarità composita: combina frequenza degli avvistamenti, rarità dell'operatore/forza aerea e rarità della nazionalità (vedi update_rarity.php). Soglie fisse, non ricalcolate sulla popolazione corrente.">
        <span class="legend-item"><span class="legend-color" style="background:#dc3545;"></span> <strong>Mythic</strong></span>
        <span class="legend-item"><span class="legend-color" style="background:#ff8c00;"></span> <strong>Legendary</strong></span>
        <span class="legend-item"><span class="legend-color" style="background:#6f42c1;"></span> <strong>Epic</strong></span>
        <span class="legend-item"><span class="legend-color" style="background:#007bff;"></span> <strong>Rare</strong></span>
        <span class="legend-item"><span class="legend-color" style="background:#28a745;"></span> <strong>Uncommon</strong></span>
        <span class="legend-item"><span class="legend-color" style="background:#212529;"></span> <strong>Common</strong></span>
    </div>

    <div class="filter-bar">
        <form method="get">
            <?php /* Ordinamento e vista date scelti dall'utente vanno conservati
                     quando si cambia un filtro: prima "Cerca" li riportava ai
                     valori predefiniti. */ ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            <?php if ($dateView === 'extended'): ?><input type="hidden" name="dateview" value="extended"><?php endif; ?>
            <label>Data ultimo avvistamento da: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
            <label>a: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
            <label>HEX: <input type="text" name="hex" placeholder="es. 4B..." value="<?= htmlspecialchars($hex) ?>"></label>
            <label>Callsign: <input type="text" name="callsign" placeholder="es. IAM..." value="<?= htmlspecialchars($callsign) ?>"></label>
            <label>Reg: <input type="text" name="reg" placeholder="es. MM..." value="<?= htmlspecialchars($reg) ?>"></label>
            <label>Modello: <input type="text" name="model" placeholder="es. C130" value="<?= htmlspecialchars($model) ?>"></label>
            <label>Operatore: <input type="text" name="operator" placeholder="es. IAM" maxlength="3" style="text-transform:uppercase;" value="<?= htmlspecialchars($operator) ?>"></label>
            <label>Note: <input type="text" name="note" placeholder="cerca nelle note..." value="<?= htmlspecialchars($note_search) ?>"></label>
            <label>Rarità:
                <select name="rarity">
                    <option value="">Tutte</option>
                    <option value="Common" <?= $rarity == 'Common' ? 'selected' : '' ?>>Common</option>
                    <option value="Uncommon" <?= $rarity == 'Uncommon' ? 'selected' : '' ?>>Uncommon</option>
                    <option value="Rare" <?= $rarity == 'Rare' ? 'selected' : '' ?>>Rare</option>
                    <option value="Epic" <?= $rarity == 'Epic' ? 'selected' : '' ?>>Epic</option>
                    <option value="Legendary" <?= $rarity == 'Legendary' ? 'selected' : '' ?>>Legendary</option>
                    <option value="Mythic" <?= $rarity == 'Mythic' ? 'selected' : '' ?>>Mythic</option>
                </select>
            </label>
            <label>Nazionalità:
                <select name="country">
                    <option value="">Tutte</option>
                    <?php foreach ($availableCountries as $code): ?>
                        <?php $emoji = countryToEmoji($code); ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= ($country == $code) ? 'selected' : '' ?>>
                            <?= $emoji ? $emoji . ' ' : '' ?><?= htmlspecialchars($code) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Categoria:
                <select name="category">
                    <option value="">Tutte</option>
                    <option value="aereo" <?= $category === 'aereo' ? 'selected' : '' ?>>✈️ Aerei</option>
                    <option value="elicottero" <?= $category === 'elicottero' ? 'selected' : '' ?>>🚁 Elicotteri</option>
                    <option value="drone" <?= $category === 'drone' ? 'selected' : '' ?>>🛸 Droni</option>
                    <option value="none" <?= $category === 'none' ? 'selected' : '' ?>>❓ Non identificato</option>
                </select>
            </label>
            <label>Contrassegnati:
                <select name="markered">
                    <option value="">Tutti</option>
                    <option value="1" <?= $markered === '1' ? 'selected' : '' ?>>Qualsiasi contrassegno</option>
                    <option value="watch" <?= $markered === 'watch' ? 'selected' : '' ?>>❓ Solo da tenere d'occhio</option>
                </select>
            </label>
            <label>Correzioni manuali:
                <input type="checkbox" name="manual" value="1" <?= $manual ? 'checked' : '' ?> title="Mostra solo contatti con dati corretti manualmente dall'analista">
                🛠️
            </label>
            <label>Squawk:
                <select name="squawk_filter">
                    <option value="">Tutti</option>
                    <option value="emergency" <?= $squawkFilter === 'emergency' ? 'selected' : '' ?>>⚠️ Tutte le emergenze (7500/7600/7700)</option>
                    <?php foreach ($emergencySquawks as $code => $meaning): $code = (string)$code; ?>
                        <option value="<?= $code ?>" <?= $squawkFilter === $code ? 'selected' : '' ?>>⚠️ <?= $code ?> — <?= htmlspecialchars($meaning) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Profilo geografico:
                <select name="geofilter">
                    <option value="">Nessuno</option>
                    <?php
                    $db2 = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
                    $db2->busyTimeout(5000);
                    $res2 = $db2->query("SELECT id, name FROM geo_profiles ORDER BY name");
                    while ($gp = $res2->fetchArray(SQLITE3_ASSOC)) {
                        echo '<option value="' . $gp['id'] . '"' . ($geofilter == $gp['id'] ? ' selected' : '') . '>' . htmlspecialchars($gp['name']) . '</option>';
                    }
                    ?>
                </select>
            </label>
            <button type="submit" class="btn">Cerca</button>
            <a href="index.php" class="btn">Reset</a>
        </form>
        <div class="quick-buttons">
            <a href="?<?= http_build_query(array_merge($baseParams, ['today' => 1])) ?>" class="btn">Oggi</a>
            <a href="?<?= http_build_query(array_merge($baseParams, ['week' => 1])) ?>" class="btn">Ultimi 7 giorni</a>
            <a href="?<?= http_build_query(array_merge($baseParams, ['month' => 1])) ?>" class="btn">Ultimo mese</a>
            <a href="?<?= http_build_query(array_merge($baseParams, ['year' => 1])) ?>" class="btn">Ultimo anno</a>
            <a href="?<?= http_build_query($baseParams) ?>" class="btn">Sempre</a>
        </div>
    </div>

    <div style="margin: 10px 0; display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
        <a href="export.php?format=csv&<?= http_build_query($_GET) ?>" class="btn">Esporta CSV</a>
        <a href="export.php?format=pdf&<?= http_build_query($_GET) ?>" target="_blank" class="btn">Versione stampabile</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['dateview' => $dateView === 'extended' ? 'compact' : 'extended'])) ?>" class="btn" title="Mostra/nascondi separatamente primo e ultimo avvistamento per identità e per hex">
            <?= $dateView === 'extended' ? '📅 Colonne data compatte' : '📅 Separa colonne data' ?>
        </a>
        <label style="margin-left:auto;font-size:0.9em;display:flex;align-items:center;gap:6px;">
            🔄 Aggiorna automaticamente:
            <select id="autoRefreshSelect" onchange="setAutoRefresh(this.value)">
                <option value="0">Disattivato</option>
                <option value="30">30 secondi</option>
                <option value="60">1 minuto</option>
                <option value="120">2 minuti</option>
                <option value="300">5 minuti</option>
            </select>
        </label>
        <span id="autoRefreshCountdown" style="color:#6c757d;font-size:0.85em;min-width:3.5em;"></span>
    </div>

    <img id="thumbPreview" alt="">

    <div class="table-scroll">
    <table class="contacts-table">
        <thead>
            <tr>
                <th><?= sortLink('hex', 'HEX', $sort, $order, $_GET) ?></th>
                <th>Mappa</th>
                <th><?= sortLink('country', 'Naz.', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('callsign', 'Callsign', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('operator', 'Operatore', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('reg', 'Reg', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('model_t', 'Modello', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('squawk', 'Squawk', $sort, $order, $_GET) ?></th>
                <th class="thumb-col">Foto reale</th>
                <th class="thumb-col">Foto modello</th>
                <th class="thumb-col">Disegno tecnico</th>
                <th>Ultima pos.</th>
                <?php if ($dateView === 'extended'): ?>
                    <th><?= sortLink('ident_first_seen', 'Primo avvist. (ID)', $sort, $order, $_GET) ?></th>
                    <th><?= sortLink('ident_last_seen', 'Ultimo avvist. (ID)', $sort, $order, $_GET) ?></th>
                    <th><?= sortLink('hex_first_seen', 'Primo avvist. (Hex)', $sort, $order, $_GET) ?></th>
                    <th><?= sortLink('hex_last_seen', 'Ultimo avvist. (Hex)', $sort, $order, $_GET) ?></th>
                <?php else: ?>
                    <th title="Primo/ultimo avvistamento di questa specifica identità (hex+callsign+reg)"><?= sortLink('ident_last_seen', 'Periodo Identità', $sort, $order, $_GET) ?></th>
                    <th title="Primo/ultimo avvistamento di questo hex, con qualunque identità"><?= sortLink('hex_last_seen', 'Periodo Hex', $sort, $order, $_GET) ?></th>
                <?php endif; ?>
                <th><?= sortLink('total_days', 'Giorni tot.', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('max_consecutive', 'Max consec.', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('rarity', 'Rarità', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('note', 'Note', $sort, $order, $_GET) ?></th>
                <th>Mark</th>
                <th>Fav</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rowsPage as $row): ?>
            <?php
                $countryCode = $row['country'];
                $flagFile = 'flags/' . strtoupper($countryCode) . '.svg';
                $flagPath = file_exists(FLAGS_DIR . '/' . strtoupper($countryCode) . '.svg') ? $flagFile : null;
                $modelPhotoPath = getModelPhotoPath($row['model_t']);
                $drawingPath = getDrawingPath($row['model_t']);
                $fdbPhotoPath = getFdbPhotoPath($row['hex']);
                $isFav = in_array($row['hex'], $favoritesHex);
                $returnUrl = $_SERVER['REQUEST_URI'];

                // Applica regole di evidenziazione
                $bgColor = null;
                $bold = false;
                foreach ($rowRules as $rule) {
                    $fieldValue = null;
                    if ($rule['field'] === 'hex') $fieldValue = strtoupper(trim($row['hex']));
                    elseif ($rule['field'] === 'callsign') $fieldValue = strtoupper(trim($row['callsign'] ?? ''));
                    elseif ($rule['field'] === 'reg') $fieldValue = strtoupper(trim($row['reg'] ?? ''));
                    elseif ($rule['field'] === 'model_t') $fieldValue = strtoupper(trim($row['model_t'] ?? ''));
                    elseif ($rule['field'] === 'squawk') $fieldValue = strtoupper(trim($row['last_squawk'] ?? ''));

                    if ($fieldValue !== null && patternMatch($fieldValue, $rule['pattern'])) {
                        if (!empty($rule['bg_color'])) {
                            $bgColor = $rule['bg_color'];
                        }
                        if ($rule['bold']) {
                            $bold = true;
                        }
                        break;
                    }
                }
                $trStyle = $bgColor ? ' style="background-color:' . htmlspecialchars($bgColor) . ';"' : '';
                $trClass = ($row['rarity'] == 'Mythic' && !$bgColor) ? 'mythic-row' : '';
                if ($bold) $trClass .= ' bold-row';
            ?>
            <tr class="<?= $trClass ?>"<?= $trStyle ?>>
                <td>
                    <a href="https://www.flightdb.net/aircraft.php?modes=<?= urlencode($row['hex']) ?>" target="_blank" title="Apri scheda FlightDB"><?= htmlspecialchars($row['hex']) ?></a>
                    <?php if (!empty($row['hex'])): ?>
                        <?php /* URL root-relative: Flight Anomaly Monitor e' servito dallo
                                 stesso vhost, cosi' il codice non e' legato a un hostname. */ ?>
                        <a href="/flight_anom/?event_type=&hex=<?= urlencode($row['hex']) ?>&callsign=&date_from=&date_to=&sort=date&dir=DESC&page=1" target="_blank" title="Cerca anomalie per HEX" class="anom-link">🔍</a>
                    <?php endif; ?>
                    <a href="#" onclick="copyToClipboard('<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>'); return false;" title="Copia HEX" class="copy-btn">📋</a>
                    <?php if ($row['is_new_today']): ?>
                        <span title="Nuovo oggi">💡</span>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                    <a href="#" onclick="openIdentityEditor(event, this); return false;"
                       data-hex="<?= htmlspecialchars($row['hex']) ?>"
                       data-reg="<?= htmlspecialchars($row['reg'] ?? '') ?>"
                       data-callsign="<?= htmlspecialchars($row['callsign'] ?? '') ?>"
                       data-model="<?= htmlspecialchars($row['model_t'] ?? '') ?>"
                       data-ovr-reg="<?= htmlspecialchars($manualOverrides[$row['hex']]['reg'] ?? '') ?>"
                       data-ovr-callsign="<?= htmlspecialchars($manualOverrides[$row['hex']]['callsign'] ?? '') ?>"
                       data-ovr-model="<?= htmlspecialchars($manualOverrides[$row['hex']]['model_t'] ?? '') ?>"
                       data-has-override="<?= $row['has_override'] ? '1' : '0' ?>"
                       title="Correggi manualmente identità/foto" class="copy-btn">
                       <?= $row['has_override'] ? '🛠️' : '✏️' ?>
                    </a>
                    <?php elseif ($row['has_override']): ?>
                        <span title="Dati corretti manualmente da un collaboratore">🛠️</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="map.php?focus=<?= urlencode($row['hex']) ?>" title="Mostra su mappa">🗺️</a>
                    <a href="heatmap.php?hex=<?= urlencode($row['hex']) ?>" title="Heatmap di questo contatto" target="_blank">🔥</a>
                    <?php if (!empty($row['callsign'])): ?>
                        <a href="https://www.flightradar24.com/<?= urlencode($row['callsign']) ?>" target="_blank" title="Traccia su Flightradar24">✈️</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($flagPath): ?>
                        <img src="<?= htmlspecialchars($flagPath) ?>" class="flag-icon" alt="<?= $countryCode ?>" title="<?= $countryCode ?>">
                    <?php elseif ($flagEmoji = countryToEmoji($countryCode)): ?>
                        <span title="<?= htmlspecialchars($countryCode) ?>"><?= $flagEmoji ?> <?= htmlspecialchars($countryCode) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($countryCode) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['callsign'])): ?>
                        <a href="https://www.planespotters.net/search?q=<?= urlencode($row['callsign']) ?>" target="_blank" title="Cerca su Planespotters"><?= htmlspecialchars($row['callsign']) ?></a>
                        <a href="#" onclick="copyToClipboard('<?= htmlspecialchars($row['callsign'], ENT_QUOTES) ?>'); return false;" title="Copia Callsign" class="copy-btn">📋</a>
                    <?php else: ?>
                        <?= htmlspecialchars($row['callsign']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['operator'])): ?>
                        <?php $opLogo = getOperatorLogo($row['operator']); ?>
                        <a href="?<?= http_build_query(['operator' => $row['operator']]) ?>" class="operator-badge" title="Filtra per operatore/forza aerea <?= htmlspecialchars($row['operator']) ?> (sempre)">
                            <?php if ($opLogo): ?>
                                <img src="<?= htmlspecialchars($opLogo) ?>" class="op-logo" alt="<?= htmlspecialchars($row['operator']) ?>">
                            <?php endif; ?>
                            <?= htmlspecialchars($row['operator']) ?>
                        </a>
                        <?php if ($canEdit && !$opLogo): ?>
                            <a href="#" class="copy-btn" title="Cerca logo operatore ora" onclick="fetchAssetNow('operator_logo', null, null, this, '<?= htmlspecialchars($row['operator'], ENT_QUOTES) ?>'); return false;">🔄</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['reg'])): ?>
                        <a href="https://www.jetphotos.com/photo/keyword/<?= urlencode($row['reg']) ?>" target="_blank" title="Cerca foto su JetPhotos"><?= htmlspecialchars($row['reg']) ?></a>
                        <a href="#" onclick="copyToClipboard('<?= htmlspecialchars($row['reg'], ENT_QUOTES) ?>'); return false;" title="Copia Reg" class="copy-btn">📋</a>
                    <?php else: ?>
                        <?= htmlspecialchars($row['reg']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['model_t'])): ?>
                        <?php $silhouettePath = getSilhouettePath($row['model_t']); ?>
                        <?php if ($silhouettePath): ?>
                            <img src="<?= htmlspecialchars($silhouettePath) ?>" class="model-silhouette thumb-zoomable" alt="<?= htmlspecialchars($row['model_t']) ?>" title="<?= htmlspecialchars($row['model_t']) ?>">
                        <?php elseif ($canEdit): ?>
                            <a href="#" class="copy-btn" title="Cerca silhouette ora" onclick="fetchAssetNow('silhouette', null, '<?= htmlspecialchars($row['model_t'], ENT_QUOTES) ?>', this); return false;">🔄</a>
                        <?php endif; ?>
                        <a href="https://doc8643.com/aircraft/<?= urlencode($row['model_t']) ?>" target="_blank" title="Apri scheda DOC 8643"><?= htmlspecialchars($row['model_t']) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($row['model_t']) ?>
                    <?php endif; ?>
                </td>
                <td class="squawk-col <?= $row['squawk_is_emergency'] ? 'squawk-emergency' : '' ?>">
                    <?php if (!empty($row['last_squawk'])): ?>
                        <span <?php if ($row['squawk_is_emergency']): ?>title="⚠️ <?= htmlspecialchars($emergencySquawks[$row['last_squawk']]) ?>"<?php endif; ?>>
                            <?= $row['squawk_is_emergency'] ? '⚠️ ' : '' ?><?= htmlspecialchars($row['last_squawk']) ?>
                        </span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td class="thumb-col">
                    <?php if ($fdbPhotoPath): ?>
                        <a href="<?= htmlspecialchars($fdbPhotoPath) ?>" target="_blank" title="Foto reale di <?= htmlspecialchars($row['hex']) ?>">
                            <img src="<?= htmlspecialchars($fdbPhotoPath) ?>" class="fdb-photo thumb-zoomable" alt="<?= htmlspecialchars($row['hex']) ?>">
                        </a>
                    <?php elseif ($canEdit): ?>
                        <a href="#" class="copy-btn" title="Cerca foto reale ora" onclick="fetchAssetNow('fdb_photo', '<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>', null, this); return false;">🔄</a>
                    <?php endif; ?>
                </td>
                <td class="thumb-col">
                    <?php if (!empty($row['model_t'])): ?>
                        <?php if ($modelPhotoPath): ?>
                            <a href="<?= htmlspecialchars($modelPhotoPath) ?>" target="_blank" title="Foto di <?= htmlspecialchars($row['model_t']) ?>">
                                <img src="<?= htmlspecialchars($modelPhotoPath) ?>" class="model-photo thumb-zoomable" alt="<?= htmlspecialchars($row['model_t']) ?>">
                            </a>
                        <?php elseif ($canEdit): ?>
                            <a href="#" class="copy-btn" title="Cerca foto modello ora" onclick="fetchAssetNow('model_photo', null, '<?= htmlspecialchars($row['model_t'], ENT_QUOTES) ?>', this); return false;">🔄</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="thumb-col">
                    <?php if (!empty($row['model_t'])): ?>
                        <?php if ($drawingPath): ?>
                            <a href="<?= htmlspecialchars($drawingPath) ?>" target="_blank" title="Disegno tecnico di <?= htmlspecialchars($row['model_t']) ?>">
                                <img src="<?= htmlspecialchars($drawingPath) ?>" class="model-drawing thumb-zoomable" alt="<?= htmlspecialchars($row['model_t']) ?>">
                            </a>
                        <?php elseif ($canEdit): ?>
                            <a href="#" class="copy-btn" title="Cerca disegno tecnico ora" onclick="fetchAssetNow('drawing', null, '<?= htmlspecialchars($row['model_t'], ENT_QUOTES) ?>', this); return false;">🔄</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['last_lat']) && !empty($row['last_lon'])): ?>
                        <a href="map.php?focus=<?= urlencode($row['hex']) ?>" title="Mostra su mappa">
                            <?= htmlspecialchars($row['last_lat']) ?>, <?= htmlspecialchars($row['last_lon']) ?>
                        </a>
                    <?php else: ?>
                        N/D
                    <?php endif; ?>
                </td>
                <?php if ($dateView === 'extended'): ?>
                    <td><?= htmlspecialchars(formatDateIt($row['ident_first_seen'])) ?></td>
                    <td><?= htmlspecialchars(formatDateIt($row['ident_last_seen'])) ?></td>
                    <td><?= htmlspecialchars(formatDateIt($row['hex_first_seen'])) ?></td>
                    <td><?= htmlspecialchars(formatDateIt($row['hex_last_seen'])) ?></td>
                <?php else: ?>
                <td class="period-cell">
                    <span class="period-last"><?= htmlspecialchars(formatDateIt($row['ident_last_seen'])) ?></span>
                    <span class="period-first">dal <?= htmlspecialchars(formatDateIt($row['ident_first_seen'])) ?></span>
                </td>
                <td class="period-cell">
                    <span class="period-last"><?= htmlspecialchars(formatDateIt($row['hex_last_seen'])) ?></span>
                    <span class="period-first">dal <?= htmlspecialchars(formatDateIt($row['hex_first_seen'])) ?></span>
                </td>
                <?php endif; ?>
                <td><?= $row['total_days'] ?></td>
                <td><?= $row['max_consecutive_days'] ?></td>
                <td class="rarity-<?= $row['rarity'] ?>"><?= $row['rarity'] ?></td>
                <td>
                    <?php if (!empty($row['combined_note'])): ?>
                        <span class="note-tooltip">
                            <span class="note-preview"><?= htmlspecialchars($row['combined_note']) ?></span>
                            <span class="note-tooltip-box"><?= htmlspecialchars($row['combined_note']) ?></span>
                        </span>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <a href="edit_note.php?hex=<?= urlencode($row['hex']) ?>&amp;return=<?= urlencode('index.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>" title="Modifica nota" style="font-size:0.8em;">✏️</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                        <button type="button" class="mark-btn" data-hex="<?= htmlspecialchars($row['hex']) ?>" onclick="openMarkerPicker(event, this)" title="Cambia contrassegno">
                            <?= !empty($row['marker_emoji']) ? htmlspecialchars($row['marker_emoji']) : '🔖' ?>
                        </button>
                    <?php elseif (!empty($row['marker_emoji'])): ?>
                        <?= htmlspecialchars($row['marker_emoji']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                        <a href="#" class="fav-link" data-hex="<?= htmlspecialchars($row['hex']) ?>" onclick="toggleFavorite('<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>', <?= $isFav ? 'true' : 'false' ?>, this); return false;" title="<?= $isFav ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti' ?>">
                            <?= $isFav ? '⭐' : '☆' ?>
                        </a>
                    <?php elseif ($isFav): ?>
                        ⭐
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalRows === 0): ?>
        <p style="padding:12px;color:#6c757d;">Nessun contatto corrisponde ai filtri selezionati.</p>
    <?php endif; ?>

    <div class="pagination">
        <span style="color:#6c757d;margin-right:8px;"><?= number_format($totalRows, 0, ',', '.') ?> righe</span>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"<?= $i === $page ? ' style="font-weight:bold;"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
    </div>

    <!-- Picker emoji condiviso per la colonna Mark: una sola istanza, riposizionata al volo -->
    <div class="marker-picker" id="markerPicker">
        <div class="picker-grid" id="markerPickerGrid"></div>
        <button type="button" class="picker-remove" onclick="selectMarkerEmoji('')">🗑️ Rimuovi contrassegno</button>
    </div>

    <!-- Modale correzione manuale identità + upload foto -->
    <div class="identity-modal-backdrop" id="identityModalBackdrop" onclick="if (event.target === this) closeIdentityEditor();">
        <div class="identity-modal">
            <h3>🛠️ Correggi dati contatto</h3>
            <p class="modal-hint">HEX: <strong id="identityModalHex"></strong> — le correzioni hanno priorità sui dati ricevuti automaticamente. Lascia un campo vuoto per non modificarlo.</p>
            <form id="identityForm">
                <label>Registrazione
                    <input type="text" id="identityReg" name="reg" placeholder="es. MM82185">
                </label>
                <label>Callsign
                    <input type="text" id="identityCallsign" name="callsign" placeholder="es. FIAMM04">
                </label>
                <label>Modello (codice ICAO)
                    <input type="text" id="identityModel" name="model_t" placeholder="es. A139">
                </label>
                <label>Foto reale (sostituisce quella in tabella)
                    <input type="file" name="photo_real" accept="image/jpeg,image/png,image/gif,image/webp">
                </label>
                <label>Foto modello (sostituisce quella in tabella)
                    <input type="file" name="photo_model" accept="image/jpeg,image/png,image/gif,image/webp">
                </label>
                <div class="modal-actions">
                    <button type="button" onclick="clearIdentityOverride()" id="identityClearBtn" style="display:none;">🗑️ Rimuovi correzioni</button>
                    <span style="flex:1;"></span>
                    <button type="button" onclick="closeIdentityEditor()">Annulla</button>
                    <button type="submit">💾 Salva</button>
                </div>
                <div class="modal-status" id="identityModalStatus"></div>
            </form>
        </div>
    </div>

    <script>
    const MARKER_EMOJI_LIST = ['🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','⭐','💡','🔥','❄️','🚨','❓','🚁','✈️','🛩️','🚀','🛰️','🌍','🌎','🌏','🔔','📌','📎','🗂️','🏁','🚩'];
    let markerPickerTargetBtn = null;

    const MARKER_EMOJI_TITLES = { '❓': 'Sconosciuto — da tenere d\'occhio' };

    (function initMarkerPickerGrid() {
        const grid = document.getElementById('markerPickerGrid');
        MARKER_EMOJI_LIST.forEach(emoji => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = emoji;
            if (MARKER_EMOJI_TITLES[emoji]) btn.title = MARKER_EMOJI_TITLES[emoji];
            btn.onclick = function () { selectMarkerEmoji(emoji); };
            grid.appendChild(btn);
        });
    })();

    function openMarkerPicker(event, btn) {
        event.stopPropagation();
        const picker = document.getElementById('markerPicker');
        markerPickerTargetBtn = btn;

        // Deve essere visibile prima di poterne misurare le dimensioni reali
        picker.style.display = 'block';
        const rect = btn.getBoundingClientRect();
        const pickerRect = picker.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = document.documentElement.clientHeight;
        const margin = 8;

        // Clamp orizzontale: non far uscire il popup a destra (né a sinistra)
        let left = rect.left + window.scrollX;
        const maxLeft = window.scrollX + viewportWidth - pickerRect.width - margin;
        left = Math.min(left, Math.max(window.scrollX + margin, maxLeft));
        left = Math.max(left, window.scrollX + margin);

        // Se non c'è spazio sotto, apri il popup sopra il bottone
        let top = rect.bottom + window.scrollY + 4;
        if (rect.bottom + pickerRect.height + margin > viewportHeight) {
            top = rect.top + window.scrollY - pickerRect.height - 4;
        }

        picker.style.left = left + 'px';
        picker.style.top = top + 'px';
    }

    function closeMarkerPicker() {
        document.getElementById('markerPicker').style.display = 'none';
        markerPickerTargetBtn = null;
    }

    function selectMarkerEmoji(emoji) {
        if (!markerPickerTargetBtn) return;
        const btn = markerPickerTargetBtn;
        const hex = btn.dataset.hex;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        fetch('toggle_marker.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ajax: '1', hex: hex, emoji: emoji, csrf_token: csrf})
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    // Tutte le righe di quell'hex (una per identità callsign/reg), non
                    // solo quella cliccata: prima le altre restavano col vecchio simbolo.
                    document.querySelectorAll('.mark-btn[data-hex="' + CSS.escape(hex) + '"]')
                        .forEach(b => { b.textContent = emoji === '' ? '🔖' : emoji; });
                } else {
                    alert('Errore: ' + (data.error || 'operazione non riuscita'));
                }
                closeMarkerPicker();
            })
            .catch(() => {
                alert('Errore di rete durante il salvataggio del contrassegno.');
                closeMarkerPicker();
            });
    }

    document.addEventListener('click', function (e) {
        const picker = document.getElementById('markerPicker');
        if (picker.style.display === 'block' && !picker.contains(e.target)) {
            closeMarkerPicker();
        }
    });

    function toggleFavorite(hex, isFav, link) {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        fetch('toggle_favorite.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ajax: '1', hex: hex, action: isFav ? 'remove' : 'add', csrf_token: csrf})
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    // Come per i contrassegni: aggiorna ogni riga dello stesso hex.
                    const links = document.querySelectorAll('.fav-link[data-hex="' + CSS.escape(hex) + '"]');
                    (links.length ? links : [link]).forEach(l => {
                        l.textContent = isFav ? '☆' : '⭐';
                        l.setAttribute('onclick', "toggleFavorite('" + hex + "', " + (!isFav) + ", this); return false;");
                        l.title = isFav ? 'Aggiungi ai preferiti' : 'Rimuovi dai preferiti';
                    });
                } else {
                    alert('Errore: ' + (data.error || 'operazione non riuscita'));
                }
            })
            .catch(() => alert('Errore di rete durante l\'aggiornamento dei preferiti.'));
    }

    // ---------------- Modale correzione manuale identità ----------------
    let identityCurrentHex = null;
    let identityCurrentModel = '';

    function openIdentityEditor(event, link) {
        event.stopPropagation();
        identityCurrentHex = link.dataset.hex;
        identityCurrentModel = link.dataset.model || '';
        document.getElementById('identityForm').reset();
        document.getElementById('identityModalHex').textContent = identityCurrentHex;
        // I campi mostrano solo le correzioni GIÀ salvate; i dati ricevuti via
        // ADS-B compaiono come suggerimento (placeholder). Precompilarli con i
        // valori osservati faceva sì che un semplice caricamento di foto li
        // salvasse come correzione manuale, congelandoli per sempre.
        const setField = (id, ovr, observed) => {
            const el = document.getElementById(id);
            el.value = ovr || '';
            el.placeholder = observed ? 'attuale: ' + observed : '';
        };
        setField('identityReg', link.dataset.ovrReg, link.dataset.reg);
        setField('identityCallsign', link.dataset.ovrCallsign, link.dataset.callsign);
        setField('identityModel', link.dataset.ovrModel, identityCurrentModel);
        document.getElementById('identityClearBtn').style.display = link.dataset.hasOverride === '1' ? 'inline-block' : 'none';
        document.getElementById('identityModalStatus').textContent = '';
        document.getElementById('identityModalStatus').className = 'modal-status';
        document.getElementById('identityModalBackdrop').classList.add('open');
    }

    function closeIdentityEditor() {
        document.getElementById('identityModalBackdrop').classList.remove('open');
        identityCurrentHex = null;
    }

    function submitIdentityForm(clear) {
        if (!identityCurrentHex) return;
        const statusEl = document.getElementById('identityModalStatus');
        statusEl.textContent = 'Salvataggio in corso...';
        statusEl.className = 'modal-status';

        const form = document.getElementById('identityForm');
        const fd = new FormData(form);
        fd.set('hex', identityCurrentHex);
        fd.set('current_model_t', identityCurrentModel);
        fd.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        if (clear) fd.set('clear_override', '1');

        fetch('save_identity.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    statusEl.textContent = 'Errore: ' + (data.error || 'operazione non riuscita');
                    statusEl.className = 'modal-status error';
                    return;
                }
                if (data.upload_errors && data.upload_errors.length) {
                    statusEl.textContent = 'Salvato, ma con avvisi: ' + data.upload_errors.join('; ');
                    statusEl.className = 'modal-status error';
                    setTimeout(() => window.location.reload(), 2500);
                } else {
                    statusEl.textContent = 'Salvato, ricarico la pagina...';
                    statusEl.className = 'modal-status success';
                    setTimeout(() => window.location.reload(), 500);
                }
            })
            .catch(() => {
                statusEl.textContent = 'Errore di rete durante il salvataggio.';
                statusEl.className = 'modal-status error';
            });
    }

    function clearIdentityOverride() {
        if (!confirm('Rimuovere le correzioni manuali per questo contatto? Le foto caricate manualmente NON verranno eliminate.')) return;
        submitIdentityForm(true);
    }

    document.getElementById('identityForm').addEventListener('submit', function (e) {
        e.preventDefault();
        submitIdentityForm(false);
    });
    // ----------------------------------------------------------------------
    </script>

    <script>
    // ---------------------------------------------------------------------
    // Aggiornamento automatico della tabella, con intervallo regolabile
    // (persistito in localStorage) — in pausa se un modale/popup è aperto,
    // per non interrompere una modifica in corso.
    // ---------------------------------------------------------------------
    let autoRefreshTimer = null;
    let autoRefreshCountdownTimer = null;
    let autoRefreshSecondsLeft = 0;

    function isAnyOverlayOpen() {
        var identityModal = document.getElementById('identityModalBackdrop');
        var markerPicker = document.getElementById('markerPicker');
        var alertDropdown = document.getElementById('alertDropdown');
        return (identityModal && identityModal.classList.contains('open'))
            || (markerPicker && markerPicker.style.display === 'block')
            || (alertDropdown && alertDropdown.classList.contains('open'));
    }

    function setAutoRefresh(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        try { localStorage.setItem('milair_autorefresh', seconds); } catch (e) {}
        clearInterval(autoRefreshTimer);
        clearInterval(autoRefreshCountdownTimer);
        var countdownEl = document.getElementById('autoRefreshCountdown');
        if (seconds <= 0) {
            countdownEl.textContent = '';
            return;
        }
        autoRefreshSecondsLeft = seconds;
        autoRefreshCountdownTimer = setInterval(function() {
            autoRefreshSecondsLeft--;
            countdownEl.textContent = autoRefreshSecondsLeft > 0 ? '(' + autoRefreshSecondsLeft + 's)' : '';
        }, 1000);
        autoRefreshTimer = setInterval(function() {
            if (isAnyOverlayOpen()) {
                autoRefreshSecondsLeft = seconds; // rimanda finché il modale resta aperto
                return;
            }
            location.reload();
        }, seconds * 1000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var select = document.getElementById('autoRefreshSelect');
        var saved = '0';
        try { saved = localStorage.getItem('milair_autorefresh') || '0'; } catch (e) {}
        if (select.querySelector('option[value="' + saved + '"]')) {
            select.value = saved;
        }
        setAutoRefresh(select.value);
    });

    // Anteprima ingrandita delle miniature (.thumb-zoomable) al passaggio del
    // mouse: un unico overlay position:fixed riusato per tutte, spostato con
    // il cursore. Delegato su .table-scroll invece che per singola immagine,
    // per non appesantire il rendering di tabelle da centinaia di righe.
    (function() {
        var preview = document.getElementById('thumbPreview');
        var scrollWrap = document.querySelector('.table-scroll');
        if (!preview || !scrollWrap) return;

        scrollWrap.addEventListener('mouseover', function(e) {
            var img = e.target.closest('.thumb-zoomable');
            if (!img) return;
            preview.src = img.src;
            preview.style.display = 'block';
        });
        scrollWrap.addEventListener('mousemove', function(e) {
            if (preview.style.display !== 'block') return;
            var x = e.clientX + 20;
            var y = e.clientY + 20;
            if (x > window.innerWidth - 260) x = e.clientX - 260;
            if (y > window.innerHeight - 260) y = window.innerHeight - 260;
            preview.style.left = x + 'px';
            preview.style.top = y + 'px';
        });
        scrollWrap.addEventListener('mouseout', function(e) {
            var img = e.target.closest('.thumb-zoomable');
            if (!img) return;
            if (e.relatedTarget && img.contains(e.relatedTarget)) return;
            preview.style.display = 'none';
        });
    })();

    function fetchAssetNow(type, hex, modelT, el, operator) {
        var original = el.textContent;
        el.textContent = '⏳';
        el.style.pointerEvents = 'none';
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        fetch('fetch_assets_now.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({type: type, hex: hex || '', model_t: modelT || '', operator: operator || '', csrf_token: csrf})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                location.reload();
            } else {
                alert('Non trovato: ' + (data.error || 'nessun risultato'));
                el.textContent = original;
                el.style.pointerEvents = '';
            }
        })
        .catch(function() {
            alert('Errore di rete durante la ricerca.');
            el.textContent = original;
            el.style.pointerEvents = '';
        });
    }
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function() {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }
    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
    </script>
</body>
</html>
