<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
$canEdit = is_logged_in(); // Collaboratore o Admin: mostra le azioni di scrittura

$dbPath = __DIR__ . '/events.db';

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$hex      = $_GET['hex']       ?? '';
$callsign = $_GET['callsign']  ?? '';
$reg      = $_GET['reg']       ?? '';
$model    = $_GET['model']     ?? '';
$note_search = $_GET['note']   ?? '';
$rarity   = $_GET['rarity']    ?? '';
$country  = $_GET['country']   ?? '';
$category = $_GET['category']  ?? '';
$markered = $_GET['markered']  ?? '';
$manual   = $_GET['manual']    ?? '';
$squawkFilter = $_GET['squawk_filter'] ?? '';
$geofilter = $_GET['geofilter'] ?? '';   // Nuovo parametro
$dateView = ($_GET['dateview'] ?? '') === 'extended' ? 'extended' : 'compact';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

// Codici squawk di emergenza ufficiali (ICAO/DO-260B) e relativo significato, per
// evidenziazione in tabella e tooltip di riferimento.
$emergencySquawks = [
    '7500' => 'Interferenza illecita (dirottamento)',
    '7600' => 'Guasto radio / perdita comunicazioni',
    '7700' => 'Emergenza generale',
];

// Gestione ordinamento
$allowedSorts = [
    'hex'                => 'hex',
    'callsign'           => 'callsign',
    'reg'                => 'reg',
    'model_t'            => 'model_t',
    'ident_first_seen'   => 'ident_first_seen',
    'ident_last_seen'    => 'ident_last_seen',
    'hex_first_seen'     => 'hex_first_seen',
    'hex_last_seen'      => 'hex_last_seen',
    'total_days'         => 'total_days',
    'max_consecutive'    => 'max_consecutive_days',
    'rarity'             => 'rarity',
    'note'               => 'note',
    'country'            => 'country',
    'squawk'             => 'last_squawk'
];
$sort = $_GET['sort'] ?? 'ident_last_seen';
if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'ident_last_seen';
}
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

if (isset($_GET['today']))     { $dateFrom = date('Y-m-d'); $dateTo = date('Y-m-d'); }
if (isset($_GET['week']))      { $dateFrom = date('Y-m-d', strtotime('-7 days')); $dateTo = date('Y-m-d'); }
if (isset($_GET['month']))     { $dateFrom = date('Y-m-d', strtotime('-30 days')); $dateTo = date('Y-m-d'); }
if (isset($_GET['year']))      { $dateFrom = date('Y-m-d', strtotime('-1 year')); $dateTo = date('Y-m-d'); }
if (isset($_GET['all']))       { $dateFrom = ''; $dateTo = ''; }

$baseParams = array_intersect_key($_GET, array_flip(['hex','callsign','reg','model','note','rarity','country','category','markered','manual','squawk_filter','geofilter','sort','order','dateview']));

define('SILHOUETTE_DIR', __DIR__ . '/silhouettes');
define('FLAGS_DIR', __DIR__ . '/flags');
define('PHOTOS_DIR', __DIR__ . '/photos');
define('DRAWINGS_DIR', __DIR__ . '/drawings');
define('FDBPHOTOS_DIR', __DIR__ . '/fdbphotos');

/**
 * Confronta un valore con un pattern che può contenere wildcard '*'
 * oppure un intervallo nella forma "BASSO - ALTO" (spazi obbligatori attorno al trattino),
 * ad es. "E00000 - E3FFFF" o "E00* - E3F*".
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

/**
 * Verifica se $value (già in maiuscolo) rientra nell'intervallo [$lowPattern, $highPattern].
 * Gli estremi possono terminare con '*' per indicare un prefisso (es. "E00*" = tutto ciò che
 * inizia da "E00" in su). Il confronto avviene lessicograficamente sui primi N caratteri di
 * $value, dove N è la lunghezza dell'estremo (senza wildcard).
 */
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
 * Mappatura prefissi di registrazione -> codice nazione.
 */
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
        if (strpos($reg, $prefix) === 0) {
            return $country;
        }
    }
    return null;
}

/**
 * Mappatura prefissi di callsign -> codice nazione.
 */
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
        if (strpos($callsign, $prefix) === 0) {
            return $country;
        }
    }
    return null;
}

/**
 * Determina il codice nazione (ISO 3166-1 alpha-2) per il velivolo.
 */
function getCountryCode($hex, $reg, $callsign, $customRules = []) {
    // Regole personalizzate
    foreach ($customRules as $rule) {
        $fieldValue = null;
        if ($rule['field'] === 'hex') $fieldValue = strtoupper(trim($hex));
        elseif ($rule['field'] === 'reg') $fieldValue = strtoupper(trim($reg ?? ''));
        elseif ($rule['field'] === 'callsign') $fieldValue = strtoupper(trim($callsign ?? ''));

        if ($fieldValue !== null && patternMatch($fieldValue, $rule['pattern'])) {
            return strtoupper($rule['country_code']);
        }
    }

    // Mapping predefiniti
    $country = getCountryFromReg($reg);
    if ($country !== null) return $country;

    $country = getCountryFromCallsign($callsign);
    if ($country !== null) return $country;

    return 'ZZ';
}

/**
 * Classifica un codice tipo ICAO (model_t) in elicottero/aereo/drone.
 * Non esiste una vera categoria ADS-B salvata nel database (il campo 'category'
 * trasmesso dai transponder non viene ancora catturato dalla pipeline di raccolta),
 * quindi si deduce dal codice modello con una mappa statica, sullo stesso principio
 * già usato per la nazionalità (getCountryFromReg/getCountryFromCallsign).
 */
function getAircraftCategory($modelT) {
    static $helicopters = [
        // Osservati nel database
        'A139', 'A169', 'A119', 'AS32', 'H60', 'EH10', 'EC45', 'NH90', 'H47',
        // Altri tipi elicottero comuni non ancora osservati, per copertura futura
        'A109', 'A129', 'AW09', 'AW139', 'AW169', 'AW189', 'AH64', 'UH1', 'CH47',
        'MI8', 'MI17', 'MI24', 'EC20', 'EC30', 'EC35', 'EC55', 'EC75', 'H145', 'H135',
        'H175', 'B412', 'B429', 'B206', 'R44', 'R66', 'S70', 'S76', 'S92', 'GAZL', 'LYNX',
    ];
    static $drones = [
        // Osservato nel database (Heron)
        'HRON',
        // Altri UAV militari comuni, per copertura futura
        'HERM', 'MQ9', 'MQ1', 'MQ1P', 'RQ4', 'RQ7', 'RQ11', 'RQ170', 'WK1', 'TB2',
    ];
    $modelT = strtoupper(trim($modelT));
    if ($modelT === '') {
        return null; // non identificato
    }
    if (in_array($modelT, $helicopters, true)) {
        return 'elicottero';
    }
    if (in_array($modelT, $drones, true)) {
        return 'drone';
    }
    return 'aereo'; // fallback: tutti gli altri codici osservati sono ad ala fissa
}

/**
 * Converte la categoria emettitore ADS-B (trasmessa dal transponder stesso, standard
 * ICAO Annex 10 / DO-260B: A7=rotorcraft, B6=UAV, A1-A6=ala fissa per classe di peso)
 * in elicottero/aereo/drone. Fonte autoritativa: quando disponibile ha priorità sulla
 * classificazione statica dedotta dal codice modello (getAircraftCategory).
 */
function mapAdsbCategory($code) {
    $code = strtoupper(trim((string)$code));
    if ($code === '') {
        return null;
    }
    if ($code === 'A7') return 'elicottero';
    if ($code === 'B6') return 'drone';
    if (preg_match('/^A[1-6]$/', $code)) return 'aereo';
    if (in_array($code, ['B1', 'B4'], true)) return 'aereo'; // aliante/ultraleggero: comunque ala fissa
    return null; // altre categorie (palloni, paracadutisti, veicoli di terra...): non classificate
}

/**
 * Costruisce l'emoji bandiera per un codice ISO 3166-1 alpha-2 componendo i due
 * "Regional Indicator Symbol" Unicode corrispondenti. Funziona per qualunque
 * codice a due lettere (incluso 'UN', riconosciuto da Unicode come bandiera ONU),
 * quindi copre automaticamente anche i codici aggiunti in futuro tramite le
 * regole personalizzate, senza dover mantenere una mappa statica.
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
 * Converte codice nazione (o pseudo-codice come 'NATO' o 'ZZ' per sconosciuto)
 * in emoji bandiera.
 */
function countryToEmoji($code) {
    $code = strtoupper(trim($code));
    $special = [
        'NATO' => '🧭', // NATO non ha un codice ISO/bandiera propria: bussola come richiamo allo stemma dell'Alleanza
        'ZZ'   => '🏳️', // codice interno per nazionalità non determinata
    ];
    if (isset($special[$code])) {
        return $special[$code];
    }
    return isoToFlagEmoji($code);
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

/**
 * Confronta due righe per l'ordinamento in PHP.
 */
function compareRows($a, $b, $sort, $order) {
    $rarityOrder = ['Mythic'=>0, 'Legendary'=>1, 'Epic'=>2, 'Rare'=>3, 'Uncommon'=>4, 'Common'=>5];
    $fieldMap = [
        'hex' => 'hex',
        'callsign' => 'callsign',
        'reg' => 'reg',
        'model_t' => 'model_t',
        'ident_first_seen' => 'ident_first_seen',
        'ident_last_seen' => 'ident_last_seen',
        'hex_first_seen' => 'hex_first_seen',
        'hex_last_seen' => 'hex_last_seen',
        'total_days' => 'total_days',
        'max_consecutive' => 'max_consecutive_days',
        'rarity' => 'rarity',
        'note' => 'note',
        'country' => 'country',
        'squawk' => 'last_squawk'
    ];
    $key = $fieldMap[$sort] ?? 'ident_last_seen';
    $va = $a[$key] ?? '';
    $vb = $b[$key] ?? '';

    if ($sort == 'rarity') {
        $oa = $rarityOrder[$va] ?? 99;
        $ob = $rarityOrder[$vb] ?? 99;
        $cmp = $oa <=> $ob;
    } elseif (in_array($sort, ['total_days', 'max_consecutive'])) {
        $cmp = ((int)$va) <=> ((int)$vb);
    } elseif (in_array($sort, ['ident_first_seen', 'ident_last_seen', 'hex_first_seen', 'hex_last_seen'])) {
        $cmp = strcmp((string)$va, (string)$vb);
    } else {
        $cmp = strcasecmp((string)$va, (string)$vb);
    }
    return ($order === 'asc') ? $cmp : -$cmp;
}

/**
 * Verifica se un punto (lat, lon) è dentro un poligono GeoJSON.
 * Supporta FeatureCollection, Polygon e MultiPolygon.
 */
function pointInGeoJSON($lat, $lon, $geojson) {
    if ($lat === null || $lon === null || empty($geojson)) return false;
    $data = json_decode($geojson, true);
    if (!$data) return false;

    $polygons = [];
    if ($data['type'] === 'FeatureCollection') {
        foreach ($data['features'] as $feature) {
            $geom = $feature['geometry'];
            if ($geom['type'] === 'Polygon') {
                $polygons[] = $geom['coordinates'];
            } elseif ($geom['type'] === 'MultiPolygon') {
                foreach ($geom['coordinates'] as $poly) {
                    $polygons[] = $poly;
                }
            }
        }
    } elseif ($data['type'] === 'Polygon') {
        $polygons[] = $data['coordinates'];
    } elseif ($data['type'] === 'MultiPolygon') {
        foreach ($data['coordinates'] as $poly) {
            $polygons[] = $poly;
        }
    }

    foreach ($polygons as $poly) {
        if (pointInPolygonRings($lat, $lon, $poly)) {
            return true;
        }
    }
    return false;
}

function pointInPolygonRings($lat, $lon, $rings) {
    // anello esterno
    if (!pointInRing($lat, $lon, $rings[0])) return false;
    // buchi
    for ($i = 1; $i < count($rings); $i++) {
        if (pointInRing($lat, $lon, $rings[$i])) return false;
    }
    return true;
}

function pointInRing($lat, $lon, $ring) {
    $inside = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][1]; // lat
        $yi = $ring[$i][0]; // lon
        $xj = $ring[$j][1];
        $yj = $ring[$j][0];

        if (($yi > $lon) != ($yj > $lon) &&
            ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi + 1e-9) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);

    // Regole personalizzate per la nazionalità
    $customRules = [];
    $resRules = $db->query("SELECT field, pattern, country_code FROM country_rules");
    while ($rule = $resRules->fetchArray(SQLITE3_ASSOC)) {
        $customRules[] = $rule;
    }

    // Regole di evidenziazione righe
    $rowRules = [];
    $resRowRules = $db->query("SELECT field, pattern, bg_color, bold FROM row_rules");
    while ($rule = $resRowRules->fetchArray(SQLITE3_ASSOC)) {
        $rowRules[] = $rule;
    }

    // Regole di annotazione automatica
    $noteRules = [];
    $resNoteRules = $db->query("SELECT field, pattern, note FROM note_rules");
    while ($rule = $resNoteRules->fetchArray(SQLITE3_ASSOC)) {
        $noteRules[] = $rule;
    }

    // Regole di contrassegno automatico
    $markerRules = [];
    $resMarkerRules = $db->query("SELECT field, pattern, emoji FROM marker_rules");
    while ($rule = $resMarkerRules->fetchArray(SQLITE3_ASSOC)) {
        $markerRules[] = $rule;
    }

    // Contrassegni manuali
    $markersData = [];
    $resMarkers = $db->query("SELECT hex, emoji FROM markers");
    while ($m = $resMarkers->fetchArray(SQLITE3_ASSOC)) {
        $markersData[$m['hex']] = $m['emoji'];
    }

    // Correzioni manuali dell'analista (reg/callsign/model_t) per contatti identificati
    // solo parzialmente: hanno precedenza sui dati grezzi ricevuti dal transponder.
    $manualOverrides = [];
    $overridesTableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='manual_overrides'");
    if ($overridesTableExists) {
        $resOverrides = $db->query("SELECT hex, reg, callsign, model_t FROM manual_overrides");
        while ($o = $resOverrides->fetchArray(SQLITE3_ASSOC)) {
            $manualOverrides[$o['hex']] = $o;
        }
    }

    // Preferiti
    $favoritesHex = [];
    $resFav = $db->query("SELECT hex FROM favorites");
    while ($fav = $resFav->fetchArray(SQLITE3_ASSOC)) {
        $favoritesHex[] = $fav['hex'];
    }

    // Carica profilo geografico se selezionato
    $geoJSON = null;
    if ($geofilter) {
        $stmt = $db->prepare("SELECT geojson FROM geo_profiles WHERE id = ?");
        $stmt->bindValue(1, (int)$geofilter);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($res) {
            $geoJSON = $res['geojson'];
        }
    }

    $sql = "
        SELECT ai.hex, ai.callsign, ai.reg, ai.model_t,
               ai.first_seen_utc AS ident_first_seen, ai.last_seen_utc AS ident_last_seen,
               a.first_seen_utc AS hex_first_seen, a.last_seen_utc AS hex_last_seen,
               a.seen_count AS total_days, a.max_consecutive_days,
               a.lat AS last_lat, a.lon AS last_lon,
               a.category AS transponder_category, a.squawk AS last_squawk,
               r.rarity, n.note
        FROM aircraft_identity ai
        JOIN aircraft a ON ai.hex = a.hex
        LEFT JOIN rarity_cache r ON a.hex = r.hex
        LEFT JOIN notes n ON a.hex = n.hex
    ";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute();

    $allData = [];
    $availableCountries = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Applica eventuali correzioni manuali (solo sui campi effettivamente impostati)
        $row['has_override'] = false;
        if (isset($manualOverrides[$row['hex']])) {
            $ov = $manualOverrides[$row['hex']];
            foreach (['reg', 'callsign', 'model_t'] as $f) {
                if (!empty($ov[$f])) {
                    $row[$f] = $ov[$f];
                    $row['has_override'] = true;
                }
            }
        }

        $row['country'] = getCountryCode($row['hex'], $row['reg'], $row['callsign'], $customRules);
        $row['category'] = mapAdsbCategory($row['transponder_category'] ?? null) ?? getAircraftCategory($row['model_t']);
        $row['last_squawk'] = trim($row['last_squawk'] ?? '');
        $row['squawk_is_emergency'] = isset($emergencySquawks[$row['last_squawk']]);

        // Note automatiche
        $autoNotes = [];
        foreach ($noteRules as $rule) {
            $fieldValue = null;
            if ($rule['field'] === 'hex') $fieldValue = strtoupper(trim($row['hex']));
            elseif ($rule['field'] === 'callsign') $fieldValue = strtoupper(trim($row['callsign'] ?? ''));
            elseif ($rule['field'] === 'reg') $fieldValue = strtoupper(trim($row['reg'] ?? ''));
            elseif ($rule['field'] === 'model_t') $fieldValue = strtoupper(trim($row['model_t'] ?? ''));
            elseif ($rule['field'] === 'squawk') $fieldValue = strtoupper(trim($row['last_squawk'] ?? ''));

            if ($fieldValue !== null && patternMatch($fieldValue, $rule['pattern'])) {
                $autoNotes[] = $rule['note'];
            }
        }

        $combinedNote = $row['note'];
        if (!empty($autoNotes)) {
            $autoText = implode(' | ', $autoNotes);
            if (!empty($combinedNote)) {
                $combinedNote .= ' | [auto] ' . $autoText;
            } else {
                $combinedNote = '[auto] ' . $autoText;
            }
        }
        $row['combined_note'] = $combinedNote;

        // Contrassegno automatico
        $autoMarker = null;
        foreach ($markerRules as $rule) {
            $fieldValue = null;
            if ($rule['field'] === 'hex') $fieldValue = strtoupper(trim($row['hex']));
            elseif ($rule['field'] === 'callsign') $fieldValue = strtoupper(trim($row['callsign'] ?? ''));
            elseif ($rule['field'] === 'reg') $fieldValue = strtoupper(trim($row['reg'] ?? ''));
            elseif ($rule['field'] === 'model_t') $fieldValue = strtoupper(trim($row['model_t'] ?? ''));
            elseif ($rule['field'] === 'squawk') $fieldValue = strtoupper(trim($row['last_squawk'] ?? ''));

            if ($fieldValue !== null && patternMatch($fieldValue, $rule['pattern'])) {
                $autoMarker = $rule['emoji'];
                break;
            }
        }
        $row['marker_emoji'] = $markersData[$row['hex']] ?? $autoMarker;

        // Nuovo contatto del giorno
        $today = date('Y-m-d');
        $firstDay = substr($row['hex_first_seen'], 0, 10);
        $row['is_new_today'] = ($firstDay === $today);

        $allData[] = $row;
        if (!empty($row['country'])) {
            $availableCountries[$row['country']] = $row['country'];
        }
    }
    ksort($availableCountries);

    // Filtri inclusi geofilter
    $filtered = array_filter($allData, function($r) use ($dateFrom, $dateTo, $hex, $callsign, $reg, $model, $note_search, $rarity, $country, $category, $markered, $manual, $squawkFilter, $geoJSON) {
        if ($dateFrom && strtotime($r['ident_last_seen']) < strtotime($dateFrom . ' 00:00:00')) return false;
        if ($dateTo && strtotime($r['ident_last_seen']) > strtotime($dateTo . ' 23:59:59')) return false;

        if ($hex) {
            if (strpos($hex, '*') !== false) {
                if (!patternMatch($r['hex'], $hex)) return false;
            } else {
                if (stripos($r['hex'], $hex) !== 0) return false;
            }
        }

        if ($callsign) {
            if (strpos($callsign, '*') !== false) {
                if (!patternMatch($r['callsign'], $callsign)) return false;
            } else {
                if (stripos($r['callsign'], $callsign) !== 0) return false;
            }
        }

        if ($reg) {
            if (strpos($reg, '*') !== false) {
                if (!patternMatch($r['reg'], $reg)) return false;
            } else {
                if (stripos($r['reg'], $reg) !== 0) return false;
            }
        }

        if ($model) {
            if (strpos($model, '*') !== false) {
                if (!patternMatch($r['model_t'], $model)) return false;
            } else {
                if (stripos($r['model_t'], $model) === false) return false;
            }
        }

        if ($note_search && stripos($r['combined_note'] ?? '', $note_search) === false) return false;

        if ($rarity && $r['rarity'] !== $rarity) return false;
        if ($country && $r['country'] !== $country) return false;
        if ($category) {
            if ($category === 'none') {
                if ($r['category'] !== null) return false;
            } elseif ($r['category'] !== $category) {
                return false;
            }
        }
        if ($markered === 'watch') {
            if (($r['marker_emoji'] ?? '') !== '❓') return false;
        } elseif ($markered && empty($r['marker_emoji'])) {
            return false;
        }
        if ($manual && empty($r['has_override'])) return false;
        if ($squawkFilter) {
            if ($squawkFilter === 'emergency') {
                if (empty($r['squawk_is_emergency'])) return false;
            } elseif (($r['last_squawk'] ?? '') !== $squawkFilter) {
                return false;
            }
        }

        if ($geoJSON) {
            if (!pointInGeoJSON($r['last_lat'], $r['last_lon'], $geoJSON)) return false;
        }

        return true;
    });

    usort($filtered, function($a, $b) use ($sort, $order) {
        return compareRows($a, $b, $sort, $order);
    });

    $totalRows = count($filtered);
    $totalPages = ceil($totalRows / $perPage);
    $offset = ($page - 1) * $perPage;
    $rowsPage = array_slice($filtered, $offset, $perPage);
} catch (Exception $e) {
    die("Errore DB: " . htmlspecialchars($e->getMessage()));
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
        .model-silhouette { height: 20px; width: auto; vertical-align: middle; margin-right: 4px; }
        .flag-icon { height: 14px; width: auto; vertical-align: middle; margin-right: 4px; }
        .model-photo { height: 35px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .model-drawing { height: 35px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .fdb-photo { height: 35px; width: auto; vertical-align: middle; border-radius: 2px; display: inline-block; margin-right: 4px; }
        .thumb-col { text-align: center; }
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

    <div class="rarity-legend">
        <span class="legend-item"><span class="legend-color" style="background:#dc3545;"></span> <strong>Mythic</strong> (≤0.1%)</span>
        <span class="legend-item"><span class="legend-color" style="background:#ff8c00;"></span> <strong>Legendary</strong> (≤1%)</span>
        <span class="legend-item"><span class="legend-color" style="background:#6f42c1;"></span> <strong>Epic</strong> (≤5%)</span>
        <span class="legend-item"><span class="legend-color" style="background:#007bff;"></span> <strong>Rare</strong> (≤15%)</span>
        <span class="legend-item"><span class="legend-color" style="background:#28a745;"></span> <strong>Uncommon</strong> (≤30%)</span>
        <span class="legend-item"><span class="legend-color" style="background:#212529;"></span> <strong>Common</strong> (restante)</span>
    </div>

    <div class="filter-bar">
        <form method="get">
            <label>Data ultimo avvistamento da: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
            <label>a: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
            <label>HEX: <input type="text" name="hex" placeholder="es. 4B..." value="<?= htmlspecialchars($hex) ?>"></label>
            <label>Callsign: <input type="text" name="callsign" placeholder="es. IAM..." value="<?= htmlspecialchars($callsign) ?>"></label>
            <label>Reg: <input type="text" name="reg" placeholder="es. MM..." value="<?= htmlspecialchars($reg) ?>"></label>
            <label>Modello: <input type="text" name="model" placeholder="es. C130" value="<?= htmlspecialchars($model) ?>"></label>
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
                    $res2 = $db2->query("SELECT id, name FROM geo_profiles ORDER BY name");
                    while ($gp = $res2->fetchArray(SQLITE3_ASSOC)) {
                        echo '<option value="' . $gp['id'] . '"' . ($geofilter == $gp['id'] ? ' selected' : '') . '>' . htmlspecialchars($gp['name']) . '</option>';
                    }
                    ?>
                </select>
            </label>
            <button type="submit">Cerca</button>
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

    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th><?= sortLink('hex', 'HEX', $sort, $order, $_GET) ?></th>
                <th>Mappa</th>
                <th><?= sortLink('country', 'Naz.', $sort, $order, $_GET) ?></th>
                <th><?= sortLink('callsign', 'Callsign', $sort, $order, $_GET) ?></th>
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
                        <a href="https://timrouter.dns.army/flight_anom/?event_type=&hex=<?= urlencode($row['hex']) ?>&callsign=&date_from=&date_to=&sort=date&dir=DESC&page=1" target="_blank" title="Cerca anomalie per HEX" class="anom-link">🔍</a>
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
                            <img src="<?= htmlspecialchars($silhouettePath) ?>" class="model-silhouette" alt="<?= htmlspecialchars($row['model_t']) ?>" title="<?= htmlspecialchars($row['model_t']) ?>">
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
                            <img src="<?= htmlspecialchars($fdbPhotoPath) ?>" class="fdb-photo" alt="<?= htmlspecialchars($row['hex']) ?>">
                        </a>
                    <?php elseif ($canEdit): ?>
                        <a href="#" class="copy-btn" title="Cerca foto reale ora" onclick="fetchAssetNow('fdb_photo', '<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>', null, this); return false;">🔄</a>
                    <?php endif; ?>
                </td>
                <td class="thumb-col">
                    <?php if (!empty($row['model_t'])): ?>
                        <?php if ($modelPhotoPath): ?>
                            <a href="<?= htmlspecialchars($modelPhotoPath) ?>" target="_blank" title="Foto di <?= htmlspecialchars($row['model_t']) ?>">
                                <img src="<?= htmlspecialchars($modelPhotoPath) ?>" class="model-photo" alt="<?= htmlspecialchars($row['model_t']) ?>">
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
                                <img src="<?= htmlspecialchars($drawingPath) ?>" class="model-drawing" alt="<?= htmlspecialchars($row['model_t']) ?>">
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
                        <?= htmlspecialchars($row['combined_note']) ?>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <a href="edit_note.php?hex=<?= urlencode($row['hex']) ?>" title="Modifica nota" style="font-size:0.8em;">✏️</a>
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
                        <a href="#" onclick="toggleFavorite('<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>', <?= $isFav ? 'true' : 'false' ?>, this); return false;" title="<?= $isFav ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti' ?>">
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

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
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
                    btn.textContent = emoji === '' ? '🔖' : emoji;
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
                    link.textContent = isFav ? '☆' : '⭐';
                    link.setAttribute('onclick', "toggleFavorite('" + hex + "', " + (!isFav) + ", this); return false;");
                    link.title = isFav ? 'Aggiungi ai preferiti' : 'Rimuovi dai preferiti';
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
        document.getElementById('identityReg').value = link.dataset.reg || '';
        document.getElementById('identityCallsign').value = link.dataset.callsign || '';
        document.getElementById('identityModel').value = identityCurrentModel;
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

    function fetchAssetNow(type, hex, modelT, el) {
        var original = el.textContent;
        el.textContent = '⏳';
        el.style.pointerEvents = 'none';
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        fetch('fetch_assets_now.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({type: type, hex: hex || '', model_t: modelT || '', csrf_token: csrf})
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
