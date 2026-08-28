<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(5000);

// Crea tabelle se non esistono
$db->exec("CREATE TABLE IF NOT EXISTS country_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    country_code TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS row_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    bg_color TEXT,
    bold INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS note_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    note TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS marker_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    emoji TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    description TEXT
)");

// Migrazione: aggiunge la colonna "description" (annotazione libera sulla regola) se assente
function ensureColumn(SQLite3 $db, $table, $column, $definition) {
    $exists = false;
    $cols = $db->query("PRAGMA table_info($table)");
    while ($c = $cols->fetchArray(SQLITE3_ASSOC)) {
        if ($c['name'] === $column) { $exists = true; break; }
    }
    if (!$exists) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}
foreach (['country_rules', 'row_rules', 'note_rules', 'marker_rules'] as $t) {
    ensureColumn($db, $t, 'description', 'TEXT');
}

// Campi ammessi per tabella (usati sia per l'inserimento manuale che per l'import)
$allowedFields = [
    'country_rules' => ['hex', 'callsign', 'reg'],
    'row_rules'     => ['hex', 'callsign', 'reg', 'model_t', 'squawk'],
    'note_rules'    => ['hex', 'callsign', 'reg', 'model_t', 'squawk'],
    'marker_rules'  => ['hex', 'callsign', 'reg', 'model_t', 'squawk'],
    'alert_rules'   => ['hex', 'callsign', 'reg', 'model_t'],
];

// Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['add_country'])) {
        $field = $_POST['field'] ?? '';
        $pattern = trim($_POST['pattern'] ?? '');
        $country_code = strtoupper(trim($_POST['country_code'] ?? ''));
        $description = trim($_POST['country_description'] ?? '');
        if (in_array($field, $allowedFields['country_rules']) && $pattern !== '' && $country_code !== '') {
            $stmt = $db->prepare("INSERT INTO country_rules (field, pattern, country_code, description) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $country_code);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit_country'])) {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $pattern = trim($_POST['pattern'] ?? '');
        $country_code = strtoupper(trim($_POST['country_code'] ?? ''));
        $description = trim($_POST['country_description'] ?? '');
        if ($id > 0 && in_array($field, $allowedFields['country_rules']) && $pattern !== '' && $country_code !== '') {
            $stmt = $db->prepare("UPDATE country_rules SET field = ?, pattern = ?, country_code = ?, description = ? WHERE id = ?");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $country_code);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->bindValue(5, $id);
            $stmt->execute();
        }
    } elseif (isset($_POST['delete_country'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM country_rules WHERE id = " . $id);
    } elseif (isset($_POST['add_row'])) {
        $field = $_POST['row_field'] ?? '';
        $pattern = trim($_POST['row_pattern'] ?? '');
        $bg_color = trim($_POST['bg_color'] ?? '');
        $bold = isset($_POST['bold']) ? 1 : 0;
        $description = trim($_POST['row_description'] ?? '');
        if (in_array($field, $allowedFields['row_rules']) && $pattern !== '') {
            $stmt = $db->prepare("INSERT INTO row_rules (field, pattern, bg_color, bold, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $bg_color ?: null);
            $stmt->bindValue(4, $bold);
            $stmt->bindValue(5, $description !== '' ? $description : null);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit_row'])) {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['row_field'] ?? '';
        $pattern = trim($_POST['row_pattern'] ?? '');
        $bg_color = trim($_POST['bg_color'] ?? '');
        $bold = isset($_POST['bold']) ? 1 : 0;
        $description = trim($_POST['row_description'] ?? '');
        if ($id > 0 && in_array($field, $allowedFields['row_rules']) && $pattern !== '') {
            $stmt = $db->prepare("UPDATE row_rules SET field = ?, pattern = ?, bg_color = ?, bold = ?, description = ? WHERE id = ?");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $bg_color ?: null);
            $stmt->bindValue(4, $bold);
            $stmt->bindValue(5, $description !== '' ? $description : null);
            $stmt->bindValue(6, $id);
            $stmt->execute();
        }
    } elseif (isset($_POST['delete_row'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM row_rules WHERE id = " . $id);
    } elseif (isset($_POST['add_note'])) {
        $field = $_POST['note_field'] ?? '';
        $pattern = trim($_POST['note_pattern'] ?? '');
        $note = trim($_POST['note_text'] ?? '');
        $description = trim($_POST['note_description'] ?? '');
        if (in_array($field, $allowedFields['note_rules']) && $pattern !== '' && $note !== '') {
            $stmt = $db->prepare("INSERT INTO note_rules (field, pattern, note, description) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $note);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit_note'])) {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['note_field'] ?? '';
        $pattern = trim($_POST['note_pattern'] ?? '');
        $note = trim($_POST['note_text'] ?? '');
        $description = trim($_POST['note_description'] ?? '');
        if ($id > 0 && in_array($field, $allowedFields['note_rules']) && $pattern !== '' && $note !== '') {
            $stmt = $db->prepare("UPDATE note_rules SET field = ?, pattern = ?, note = ?, description = ? WHERE id = ?");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $note);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->bindValue(5, $id);
            $stmt->execute();
        }
    } elseif (isset($_POST['delete_note'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM note_rules WHERE id = " . $id);
    } elseif (isset($_POST['add_marker'])) {
        $field = $_POST['marker_field'] ?? '';
        $pattern = trim($_POST['marker_pattern'] ?? '');
        $emoji = trim($_POST['marker_emoji'] ?? '');
        $description = trim($_POST['marker_description'] ?? '');
        if (in_array($field, $allowedFields['marker_rules']) && $pattern !== '' && $emoji !== '') {
            $stmt = $db->prepare("INSERT INTO marker_rules (field, pattern, emoji, description) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $emoji);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit_marker'])) {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['marker_field'] ?? '';
        $pattern = trim($_POST['marker_pattern'] ?? '');
        $emoji = trim($_POST['marker_emoji'] ?? '');
        $description = trim($_POST['marker_description'] ?? '');
        if ($id > 0 && in_array($field, $allowedFields['marker_rules']) && $pattern !== '' && $emoji !== '') {
            $stmt = $db->prepare("UPDATE marker_rules SET field = ?, pattern = ?, emoji = ?, description = ? WHERE id = ?");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $emoji);
            $stmt->bindValue(4, $description !== '' ? $description : null);
            $stmt->bindValue(5, $id);
            $stmt->execute();
        }
    } elseif (isset($_POST['delete_marker'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM marker_rules WHERE id = " . $id);
    } elseif (isset($_POST['add_alert'])) {
        $field = $_POST['alert_field'] ?? '';
        $pattern = trim($_POST['alert_pattern'] ?? '');
        $description = trim($_POST['alert_description'] ?? '');
        if (in_array($field, $allowedFields['alert_rules']) && $pattern !== '') {
            $stmt = $db->prepare("INSERT INTO alert_rules (field, pattern, description) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $description !== '' ? $description : null);
            $stmt->execute();
        }
    } elseif (isset($_POST['edit_alert'])) {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['alert_field'] ?? '';
        $pattern = trim($_POST['alert_pattern'] ?? '');
        $description = trim($_POST['alert_description'] ?? '');
        if ($id > 0 && in_array($field, $allowedFields['alert_rules']) && $pattern !== '') {
            $stmt = $db->prepare("UPDATE alert_rules SET field = ?, pattern = ?, description = ? WHERE id = ?");
            $stmt->bindValue(1, $field);
            $stmt->bindValue(2, $pattern);
            $stmt->bindValue(3, $description !== '' ? $description : null);
            $stmt->bindValue(4, $id);
            $stmt->execute();
        }
    } elseif (isset($_POST['delete_alert'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM alert_rules WHERE id = " . $id);
    } elseif (isset($_POST['import_rules'])) {
        $msg = '';
        $msgtype = 'success';
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Nessun file valido selezionato.';
            $msgtype = 'error';
        } else {
            $content = file_get_contents($_FILES['import_file']['tmp_name']);
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $msg = 'Il file non contiene un JSON valido.';
                $msgtype = 'error';
            } else {
                $tableSpec = [
                    'country_rules' => ['required' => ['field', 'pattern', 'country_code'], 'optional' => ['description']],
                    'row_rules'     => ['required' => ['field', 'pattern'], 'optional' => ['bg_color', 'bold', 'description']],
                    'note_rules'    => ['required' => ['field', 'pattern', 'note'], 'optional' => ['description']],
                    'marker_rules'  => ['required' => ['field', 'pattern', 'emoji'], 'optional' => ['description']],
                    'alert_rules'   => ['required' => ['field', 'pattern'], 'optional' => ['description']],
                ];
                $imported = 0;
                $skipped = 0;
                try {
                    $db->exec('BEGIN');
                    foreach ($tableSpec as $table => $spec) {
                        if (!isset($data[$table]) || !is_array($data[$table])) continue;
                        foreach ($data[$table] as $entry) {
                            if (!is_array($entry) || !in_array($entry['field'] ?? '', $allowedFields[$table], true)) {
                                $skipped++;
                                continue;
                            }
                            $valid = true;
                            foreach ($spec['required'] as $col) {
                                if (!isset($entry[$col]) || $entry[$col] === '') { $valid = false; break; }
                            }
                            if (!$valid) { $skipped++; continue; }

                            $cols = [];
                            $vals = [];
                            foreach (array_merge($spec['required'], $spec['optional']) as $col) {
                                if (array_key_exists($col, $entry)) {
                                    $cols[] = $col;
                                    $vals[] = $entry[$col];
                                }
                            }
                            $stmt = $db->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")");
                            foreach ($vals as $i => $v) {
                                $stmt->bindValue($i + 1, $v);
                            }
                            $stmt->execute();
                            $imported++;
                        }
                    }
                    $db->exec('COMMIT');
                    $msg = "$imported regole importate" . ($skipped ? ", $skipped ignorate (dati mancanti o non validi)" : '') . '.';
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    $msg = "Errore durante l'importazione: " . $e->getMessage();
                    $msgtype = 'error';
                }
            }
        }
        header('Location: rules.php?msg=' . urlencode($msg) . '&msgtype=' . $msgtype);
        exit;
    }
    header('Location: rules.php');
    exit;
}

$flashMsg = $_GET['msg'] ?? '';
$flashType = ($_GET['msgtype'] ?? 'success') === 'error' ? 'error' : 'success';

$countryRules = $db->query("SELECT * FROM country_rules ORDER BY id DESC");
$rowRules = $db->query("SELECT * FROM row_rules ORDER BY id DESC");
$noteRules = $db->query("SELECT * FROM note_rules ORDER BY id DESC");
$markerRules = $db->query("SELECT * FROM marker_rules ORDER BY id DESC");
$alertRules = $db->query("SELECT * FROM alert_rules ORDER BY id DESC");

// Elenco nazioni per tooltip (codice ISO => nome), ordinato alfabeticamente per nome
$countryNames = [
    'IT' => 'Italia', 'FR' => 'Francia', 'DE' => 'Germania', 'GB' => 'Regno Unito', 'US' => 'Stati Uniti',
    'CA' => 'Canada', 'ES' => 'Spagna', 'PT' => 'Portogallo', 'NL' => 'Paesi Bassi', 'BE' => 'Belgio',
    'AT' => 'Austria', 'CH' => 'Svizzera', 'PL' => 'Polonia', 'CZ' => 'Rep. Ceca', 'SK' => 'Slovacchia',
    'HU' => 'Ungheria', 'RO' => 'Romania', 'BG' => 'Bulgaria', 'HR' => 'Croazia', 'SI' => 'Slovenia',
    'RS' => 'Serbia', 'MK' => 'Macedonia', 'GR' => 'Grecia', 'TR' => 'Turchia', 'RU' => 'Russia',
    'UA' => 'Ucraina', 'IL' => 'Israele', 'EG' => 'Egitto', 'MA' => 'Marocco', 'DZ' => 'Algeria',
    'TN' => 'Tunisia', 'LY' => 'Libia', 'AE' => 'Emirati Arabi', 'QA' => 'Qatar', 'KW' => 'Kuwait',
    'IN' => 'India', 'PK' => 'Pakistan', 'CN' => 'Cina', 'JP' => 'Giappone', 'KR' => 'Corea del Sud',
    'TH' => 'Thailandia', 'VN' => 'Vietnam', 'SG' => 'Singapore', 'ID' => 'Indonesia', 'MY' => 'Malesia',
    'PH' => 'Filippine', 'NZ' => 'Nuova Zelanda', 'AU' => 'Australia', 'MX' => 'Messico', 'BR' => 'Brasile',
    'AR' => 'Argentina', 'CL' => 'Cile', 'CO' => 'Colombia', 'PE' => 'Perù', 'VE' => 'Venezuela',
    'ZA' => 'Sudafrica', 'SE' => 'Svezia', 'NO' => 'Norvegia', 'DK' => 'Danimarca', 'FI' => 'Finlandia',
    'IE' => 'Irlanda', 'LU' => 'Lussemburgo', 'MT' => 'Malta', 'CY' => 'Cipro', 'IS' => 'Islanda',
    'LT' => 'Lituania', 'LV' => 'Lettonia', 'EE' => 'Estonia', 'AL' => 'Albania', 'ME' => 'Montenegro',
    'MD' => 'Moldavia', 'AM' => 'Armenia', 'AZ' => 'Azerbaigian', 'GE' => 'Georgia', 'KZ' => 'Kazakistan',
    'UZ' => 'Uzbekistan', 'TM' => 'Turkmenistan', 'KG' => 'Kirghizistan', 'TJ' => 'Tagikistan', 'AF' => 'Afghanistan',
    'NP' => 'Nepal', 'BT' => 'Bhutan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'MV' => 'Maldive',
    'MM' => 'Birmania', 'LA' => 'Laos', 'KH' => 'Cambogia', 'BN' => 'Brunei', 'TL' => 'Timor Est',
    'MN' => 'Mongolia', 'KP' => 'Corea del Nord', 'TW' => 'Taiwan', 'HK' => 'Hong Kong', 'MO' => 'Macao',
    'FJ' => 'Figi', 'PG' => 'Papua Nuova Guinea', 'SB' => 'Isole Salomone', 'VU' => 'Vanuatu', 'NC' => 'Nuova Caledonia',
    'PF' => 'Polinesia Francese', 'FM' => 'Micronesia', 'MH' => 'Isole Marshall', 'PW' => 'Palau', 'MP' => 'Isole Marianne',
    'GU' => 'Guam', 'AS' => 'Samoa Americane', 'CK' => 'Isole Cook', 'NU' => 'Niue', 'TK' => 'Tokelau',
    'WF' => 'Wallis e Futuna', 'WS' => 'Samoa', 'TO' => 'Tonga', 'TV' => 'Tuvalu', 'KI' => 'Kiribati',
    'NR' => 'Nauru',
    'NATO' => 'NATO (Alleanza Atlantica)',
    'UN' => 'Nazioni Unite (ONU)',
];
asort($countryNames, SORT_STRING | SORT_FLAG_CASE);

// Emoji disponibili per contrassegni
$emojiOptions = ['🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','⭐','💡','🔥','❄️','🚨','🚁','✈️','🛩️','🚀','🛰️','🌍','🌎','🌏','🔔','📌','📎','🗂️','🏁','🚩'];

/**
 * Costruisce l'emoji bandiera per un codice ISO 3166-1 alpha-2 componendo i due
 * "Regional Indicator Symbol" Unicode corrispondenti. Copre automaticamente
 * qualunque codice a due lettere (incluso 'UN', riconosciuto da Unicode come
 * bandiera ONU) senza dover mantenere una mappa statica.
 */
function isoToFlagEmoji($code) {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $offset = 0x1F1E6 - 65; // 'A' -> Regional Indicator Symbol Letter A
    return mb_chr(ord($code[0]) + $offset, 'UTF-8') . mb_chr(ord($code[1]) + $offset, 'UTF-8');
}

/**
 * Funzione per bandiere emoji (definita prima dell'uso). Gestisce anche gli
 * pseudo-codici non ISO come 'NATO', che non hanno una bandiera propria.
 */
function getFlagEmoji($code) {
    $code = strtoupper(trim($code));
    $special = [
        'NATO' => '🧭', // NATO non ha un codice ISO/bandiera propria: bussola come richiamo allo stemma dell'Alleanza
    ];
    if (isset($special[$code])) {
        return $special[$code];
    }
    return isoToFlagEmoji($code);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Regole Personalizzate – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .color-picker {
            vertical-align: middle;
            height: 35px;
            width: 50px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .rules-table-container {
            max-height: 300px;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 30px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
        }
        .country-help {
            position: relative;
            display: inline-block;
            margin-left: 5px;
            cursor: help;
        }
        .country-tooltip {
            visibility: hidden;
            width: 250px;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px;
            position: absolute;
            top: 20px;
            left: 0;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            z-index: 1000;
            font-size: 0.9em;
        }
        .country-help:hover .country-tooltip {
            visibility: visible;
        }
        .msg-banner {
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .msg-banner.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .msg-banner.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .export-import-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 15px;
            margin-bottom: 30px;
        }
        .export-import-bar form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .edit-row {
            display: none;
            background: #f8f9fa;
        }
        .edit-row form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
        }
        .edit-row label {
            display: flex;
            flex-direction: column;
            font-size: 0.85em;
            gap: 2px;
        }
        .actions-cell {
            display: flex;
            gap: 4px;
        }
        .rule-search-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .rule-search-bar input[type="search"] {
            flex: 1;
            max-width: 400px;
            padding: 8px 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .rule-search-bar #ruleSearchCount {
            color: #6c757d;
            font-size: 0.9em;
        }
        tr.no-match {
            display: none !important;
        }
    </style>
</head>
<body>
    <?php render_nav('rules.php'); ?>

    <h2>🛠️ Regole Personalizzate</h2>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <div class="rule-search-bar">
        <input type="search" id="ruleSearch" oninput="filterRules()" placeholder="🔍 Cerca in tutte le regole (campo, pattern, valore, annotazione...)">
        <button type="button" onclick="document.getElementById('ruleSearch').value=''; filterRules();">✖ Pulisci</button>
        <span id="ruleSearchCount"></span>
    </div>

    <div class="export-import-bar">
        <strong>📦 Esporta / Importa regole</strong>
        <a href="export_rules.php" class="btn">⬇️ Esporta regole (JSON)</a>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="import_rules" value="1">
            <input type="file" name="import_file" accept="application/json,.json" required>
            <button type="submit">⬆️ Importa</button>
        </form>
        <small>L'importazione <strong>aggiunge</strong> le regole del file a quelle esistenti, senza sostituirle.</small>
    </div>

    <h3>Regole per Nazionalità</h3>
    <p>Le regole vengono valutate prima dei mapping predefiniti. Puoi usare <code>*</code> come carattere jolly, oppure
       indicare un <strong>intervallo</strong> nella forma <code>BASSO - ALTO</code> (spazi attorno al trattino obbligatori),
       ad es. <code>E00000 - E3FFFF</code> o <code>E00* - E3F*</code>. Oltre ai codici ISO delle nazioni puoi usare anche
       gli pseudo-codici <code>NATO</code> e <code>UN</code> per contrassegnare rispettivamente velivoli dell'Alleanza
       Atlantica e delle Nazioni Unite.</p>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="add_country" value="1">
        <label>Campo:
            <select name="field">
                <option value="hex">HEX</option>
                <option value="callsign">Callsign</option>
                <option value="reg">Reg</option>
            </select>
        </label>
        <label>Prefisso/Pattern/Intervallo (es. 33*, *MM* o E00000 - E3FFFF): <input type="text" name="pattern" required></label>
        <label>Codice Nazione (ISO/NATO/UN): <input type="text" name="country_code" maxlength="4" required placeholder="IT"></label>
        <span class="country-help">❓
            <span class="country-tooltip">
                <?php foreach ($countryNames as $code => $name):
                    $flag = getFlagEmoji($code);
                    echo '<div style="white-space:nowrap;">' . $flag . ' ' . htmlspecialchars($code) . ' – ' . htmlspecialchars($name) . '</div>';
                endforeach; ?>
            </span>
        </span>
        <label>Annotazione (opzionale): <input type="text" name="country_description" placeholder="es. Blocco ICAO militare Italia"></label>
        <button type="submit">Aggiungi</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead><tr><th>ID</th><th>Campo</th><th>Prefisso/Intervallo</th><th>Nazione</th><th>Annotazione</th><th></th></tr></thead>
            <tbody>
            <?php while ($rule = $countryRules->fetchArray(SQLITE3_ASSOC)):
                $rowId = 'country-' . $rule['id']; ?>
                <tr id="view-<?= $rowId ?>">
                    <td><?= $rule['id'] ?></td>
                    <td><?= htmlspecialchars($rule['field']) ?></td>
                    <td><?= htmlspecialchars($rule['pattern']) ?></td>
                    <td><?= htmlspecialchars($rule['country_code']) ?></td>
                    <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                    <td class="actions-cell">
                        <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        <form method="post" onsubmit="return confirm('Eliminare la regola?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_country" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="btn">✖</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-<?= $rowId ?>" class="edit-row">
                    <td colspan="6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_country" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <label>Campo:
                                <select name="field">
                                    <option value="hex" <?= $rule['field'] === 'hex' ? 'selected' : '' ?>>HEX</option>
                                    <option value="callsign" <?= $rule['field'] === 'callsign' ? 'selected' : '' ?>>Callsign</option>
                                    <option value="reg" <?= $rule['field'] === 'reg' ? 'selected' : '' ?>>Reg</option>
                                </select>
                            </label>
                            <label>Prefisso/Pattern/Intervallo:
                                <input type="text" name="pattern" value="<?= htmlspecialchars($rule['pattern']) ?>" required>
                            </label>
                            <label>Codice Nazione (ISO/NATO/UN):
                                <input type="text" name="country_code" maxlength="4" value="<?= htmlspecialchars($rule['country_code']) ?>" required>
                            </label>
                            <label>Annotazione:
                                <input type="text" name="country_description" value="<?= htmlspecialchars($rule['description'] ?? '') ?>">
                            </label>
                            <button type="submit">💾 Salva</button>
                            <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <h3>Regole di Evidenziazione Righe</h3>
    <p>Applica un colore di sfondo e/o grassetto alle righe corrispondenti. Usa <code>*</code> come jolly, oppure un
       intervallo nella forma <code>BASSO - ALTO</code> (es. <code>E00000 - E3FFFF</code> o <code>E00* - E3F*</code>).</p>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="add_row" value="1">
        <label>Campo:
            <select name="row_field">
                <option value="hex">HEX</option>
                <option value="callsign">Callsign</option>
                <option value="reg">Reg</option>
                <option value="model_t">Modello</option>
                <option value="squawk">Squawk</option>
            </select>
        </label>
        <label>Prefisso/Pattern/Intervallo (es. 33* o *MM*): <input type="text" name="row_pattern" required></label>
        <label>Colore sfondo: <input type="color" name="bg_color" value="#ffcc00" class="color-picker"></label>
        <label>Grassetto: <input type="checkbox" name="bold" value="1"></label>
        <label>Annotazione (opzionale): <input type="text" name="row_description"></label>
        <button type="submit">Aggiungi</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead><tr><th>ID</th><th>Campo</th><th>Prefisso/Intervallo</th><th>Colore</th><th>Grassetto</th><th>Annotazione</th><th></th></tr></thead>
            <tbody>
            <?php while ($rule = $rowRules->fetchArray(SQLITE3_ASSOC)):
                $rowId = 'row-' . $rule['id']; ?>
                <tr id="view-<?= $rowId ?>">
                    <td><?= $rule['id'] ?></td>
                    <td><?= htmlspecialchars($rule['field']) ?></td>
                    <td><?= htmlspecialchars($rule['pattern']) ?></td>
                    <td><?= htmlspecialchars($rule['bg_color'] ?? '') ?></td>
                    <td><?= $rule['bold'] ? 'Sì' : 'No' ?></td>
                    <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                    <td class="actions-cell">
                        <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        <form method="post" onsubmit="return confirm('Eliminare la regola?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_row" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="btn">✖</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-<?= $rowId ?>" class="edit-row">
                    <td colspan="7">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_row" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <label>Campo:
                                <select name="row_field">
                                    <option value="hex" <?= $rule['field'] === 'hex' ? 'selected' : '' ?>>HEX</option>
                                    <option value="callsign" <?= $rule['field'] === 'callsign' ? 'selected' : '' ?>>Callsign</option>
                                    <option value="reg" <?= $rule['field'] === 'reg' ? 'selected' : '' ?>>Reg</option>
                                    <option value="model_t" <?= $rule['field'] === 'model_t' ? 'selected' : '' ?>>Modello</option>
                                    <option value="squawk" <?= $rule['field'] === 'squawk' ? 'selected' : '' ?>>Squawk</option>
                                </select>
                            </label>
                            <label>Prefisso/Pattern/Intervallo:
                                <input type="text" name="row_pattern" value="<?= htmlspecialchars($rule['pattern']) ?>" required>
                            </label>
                            <label>Colore sfondo:
                                <input type="color" name="bg_color" value="<?= htmlspecialchars($rule['bg_color'] ?: '#ffcc00') ?>" class="color-picker">
                            </label>
                            <label>Grassetto:
                                <input type="checkbox" name="bold" value="1" <?= $rule['bold'] ? 'checked' : '' ?>>
                            </label>
                            <label>Annotazione:
                                <input type="text" name="row_description" value="<?= htmlspecialchars($rule['description'] ?? '') ?>">
                            </label>
                            <button type="submit">💾 Salva</button>
                            <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <h3>Regole per Annotazioni Automatiche</h3>
    <p>Le note automatiche vengono accodate alle note esistenti del contatto, precedute da <code>[auto]</code>.
       Puoi usare <code>*</code> come jolly, oppure un intervallo nella forma <code>BASSO - ALTO</code>.</p>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="add_note" value="1">
        <label>Campo:
            <select name="note_field">
                <option value="hex">HEX</option>
                <option value="callsign">Callsign</option>
                <option value="reg">Reg</option>
                <option value="model_t">Modello</option>
                <option value="squawk">Squawk</option>
            </select>
        </label>
        <label>Prefisso/Pattern/Intervallo: <input type="text" name="note_pattern" required></label>
        <label>Nota da aggiungere: <input type="text" name="note_text" required></label>
        <label>Annotazione (opzionale): <input type="text" name="note_description"></label>
        <button type="submit">Aggiungi</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead><tr><th>ID</th><th>Campo</th><th>Prefisso/Intervallo</th><th>Nota</th><th>Annotazione</th><th></th></tr></thead>
            <tbody>
            <?php while ($rule = $noteRules->fetchArray(SQLITE3_ASSOC)):
                $rowId = 'note-' . $rule['id']; ?>
                <tr id="view-<?= $rowId ?>">
                    <td><?= $rule['id'] ?></td>
                    <td><?= htmlspecialchars($rule['field']) ?></td>
                    <td><?= htmlspecialchars($rule['pattern']) ?></td>
                    <td><?= htmlspecialchars($rule['note']) ?></td>
                    <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                    <td class="actions-cell">
                        <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        <form method="post" onsubmit="return confirm('Eliminare la regola?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_note" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="btn">✖</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-<?= $rowId ?>" class="edit-row">
                    <td colspan="6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_note" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <label>Campo:
                                <select name="note_field">
                                    <option value="hex" <?= $rule['field'] === 'hex' ? 'selected' : '' ?>>HEX</option>
                                    <option value="callsign" <?= $rule['field'] === 'callsign' ? 'selected' : '' ?>>Callsign</option>
                                    <option value="reg" <?= $rule['field'] === 'reg' ? 'selected' : '' ?>>Reg</option>
                                    <option value="model_t" <?= $rule['field'] === 'model_t' ? 'selected' : '' ?>>Modello</option>
                                    <option value="squawk" <?= $rule['field'] === 'squawk' ? 'selected' : '' ?>>Squawk</option>
                                </select>
                            </label>
                            <label>Prefisso/Pattern/Intervallo:
                                <input type="text" name="note_pattern" value="<?= htmlspecialchars($rule['pattern']) ?>" required>
                            </label>
                            <label>Nota da aggiungere:
                                <input type="text" name="note_text" value="<?= htmlspecialchars($rule['note']) ?>" required>
                            </label>
                            <label>Annotazione:
                                <input type="text" name="note_description" value="<?= htmlspecialchars($rule['description'] ?? '') ?>">
                            </label>
                            <button type="submit">💾 Salva</button>
                            <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <h3>Regole per Contrassegni Automatici</h3>
    <p>Assegna automaticamente un'emoji ai contatti che corrispondono al pattern. Puoi usare <code>*</code> come jolly,
       oppure un intervallo nella forma <code>BASSO - ALTO</code>.</p>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="add_marker" value="1">
        <label>Campo:
            <select name="marker_field">
                <option value="hex">HEX</option>
                <option value="callsign">Callsign</option>
                <option value="reg">Reg</option>
                <option value="model_t">Modello</option>
                <option value="squawk">Squawk</option>
            </select>
        </label>
        <label>Prefisso/Pattern/Intervallo: <input type="text" name="marker_pattern" required></label>
        <label>Emoji:
            <select name="marker_emoji" required>
                <option value="">Seleziona</option>
                <?php foreach ($emojiOptions as $emoji): ?>
                    <option value="<?= $emoji ?>"><?= $emoji ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Annotazione (opzionale): <input type="text" name="marker_description"></label>
        <button type="submit">Aggiungi</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead><tr><th>ID</th><th>Campo</th><th>Prefisso/Intervallo</th><th>Emoji</th><th>Annotazione</th><th></th></tr></thead>
            <tbody>
            <?php while ($rule = $markerRules->fetchArray(SQLITE3_ASSOC)):
                $rowId = 'marker-' . $rule['id'];
                $currentEmojiInList = in_array($rule['emoji'], $emojiOptions, true); ?>
                <tr id="view-<?= $rowId ?>">
                    <td><?= $rule['id'] ?></td>
                    <td><?= htmlspecialchars($rule['field']) ?></td>
                    <td><?= htmlspecialchars($rule['pattern']) ?></td>
                    <td><?= htmlspecialchars($rule['emoji']) ?></td>
                    <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                    <td class="actions-cell">
                        <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        <form method="post" onsubmit="return confirm('Eliminare la regola?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_marker" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="btn">✖</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-<?= $rowId ?>" class="edit-row">
                    <td colspan="6">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_marker" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <label>Campo:
                                <select name="marker_field">
                                    <option value="hex" <?= $rule['field'] === 'hex' ? 'selected' : '' ?>>HEX</option>
                                    <option value="callsign" <?= $rule['field'] === 'callsign' ? 'selected' : '' ?>>Callsign</option>
                                    <option value="reg" <?= $rule['field'] === 'reg' ? 'selected' : '' ?>>Reg</option>
                                    <option value="model_t" <?= $rule['field'] === 'model_t' ? 'selected' : '' ?>>Modello</option>
                                    <option value="squawk" <?= $rule['field'] === 'squawk' ? 'selected' : '' ?>>Squawk</option>
                                </select>
                            </label>
                            <label>Prefisso/Pattern/Intervallo:
                                <input type="text" name="marker_pattern" value="<?= htmlspecialchars($rule['pattern']) ?>" required>
                            </label>
                            <label>Emoji:
                                <select name="marker_emoji" required>
                                    <option value="">Seleziona</option>
                                    <?php if (!$currentEmojiInList): ?>
                                        <option value="<?= htmlspecialchars($rule['emoji']) ?>" selected><?= htmlspecialchars($rule['emoji']) ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($emojiOptions as $emoji): ?>
                                        <option value="<?= $emoji ?>" <?= ($rule['emoji'] === $emoji) ? 'selected' : '' ?>><?= $emoji ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Annotazione:
                                <input type="text" name="marker_description" value="<?= htmlspecialchars($rule['description'] ?? '') ?>">
                            </label>
                            <button type="submit">💾 Salva</button>
                            <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <h3>🎯 Regole di Notifica</h3>
    <p>Genera un alert nel sistema di notifiche (🔔 in alto) quando compare un contatto corrispondente — anche se
       non è ancora mai stato visto in tabella. Utile per essere avvertiti in anticipo su contatti attesi ma non
       ancora in database, per la loro rarità/importanza. Puoi usare <code>*</code> come jolly, oppure un
       intervallo nella forma <code>BASSO - ALTO</code>. L'annotazione comparirà nel testo della notifica, quindi
       conviene sempre spiegare perché quel contatto è rilevante.</p>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="add_alert" value="1">
        <label>Campo:
            <select name="alert_field">
                <option value="hex">HEX</option>
                <option value="callsign">Callsign</option>
                <option value="reg">Reg</option>
                <option value="model_t">Modello</option>
            </select>
        </label>
        <label>Prefisso/Pattern/Intervallo (es. 33*, *MM* o E00000 - E3FFFF): <input type="text" name="alert_pattern" required></label>
        <label>Annotazione (perché avvisare?): <input type="text" name="alert_description" placeholder="es. Prototipo atteso, mai visto finora" style="min-width:260px;"></label>
        <button type="submit">Aggiungi</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead><tr><th>ID</th><th>Campo</th><th>Prefisso/Intervallo</th><th>Annotazione</th><th></th></tr></thead>
            <tbody>
            <?php while ($rule = $alertRules->fetchArray(SQLITE3_ASSOC)):
                $rowId = 'alert-' . $rule['id']; ?>
                <tr id="view-<?= $rowId ?>">
                    <td><?= $rule['id'] ?></td>
                    <td><?= htmlspecialchars($rule['field']) ?></td>
                    <td><?= htmlspecialchars($rule['pattern']) ?></td>
                    <td><?= htmlspecialchars($rule['description'] ?? '') ?></td>
                    <td class="actions-cell">
                        <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        <form method="post" onsubmit="return confirm('Eliminare la regola?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_alert" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="btn">✖</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-<?= $rowId ?>" class="edit-row">
                    <td colspan="5">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_alert" value="1">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <label>Campo:
                                <select name="alert_field">
                                    <option value="hex" <?= $rule['field'] === 'hex' ? 'selected' : '' ?>>HEX</option>
                                    <option value="callsign" <?= $rule['field'] === 'callsign' ? 'selected' : '' ?>>Callsign</option>
                                    <option value="reg" <?= $rule['field'] === 'reg' ? 'selected' : '' ?>>Reg</option>
                                    <option value="model_t" <?= $rule['field'] === 'model_t' ? 'selected' : '' ?>>Modello</option>
                                </select>
                            </label>
                            <label>Prefisso/Pattern/Intervallo:
                                <input type="text" name="alert_pattern" value="<?= htmlspecialchars($rule['pattern']) ?>" required>
                            </label>
                            <label>Annotazione:
                                <input type="text" name="alert_description" value="<?= htmlspecialchars($rule['description'] ?? '') ?>" style="min-width:260px;">
                            </label>
                            <button type="submit">💾 Salva</button>
                            <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        function toggleEditRow(rowId) {
            var view = document.getElementById('view-' + rowId);
            var edit = document.getElementById('edit-' + rowId);
            if (!view || !edit) return;
            var editing = edit.style.display === 'table-row';
            edit.style.display = editing ? 'none' : 'table-row';
            view.style.display = editing ? '' : 'none';
        }

        function filterRules() {
            var query = document.getElementById('ruleSearch').value.trim().toLowerCase();
            var total = 0;
            document.querySelectorAll('tr[id^="view-"]').forEach(function (viewRow) {
                var rowId = viewRow.id.slice('view-'.length);
                var editRow = document.getElementById('edit-' + rowId);
                if (editRow && editRow.style.display === 'table-row') {
                    // richiude eventuali form di modifica aperti e ripristina la riga prima di filtrare
                    editRow.style.display = 'none';
                    viewRow.style.display = '';
                }
                var matches = query === '' || viewRow.textContent.toLowerCase().indexOf(query) !== -1;
                viewRow.classList.toggle('no-match', !matches);
                if (matches) total++;
            });
            var counter = document.getElementById('ruleSearchCount');
            if (counter) {
                counter.textContent = query === '' ? '' : (total + (total === 1 ? ' risultato' : ' risultati'));
            }
        }
    </script>
</body>
</html>
