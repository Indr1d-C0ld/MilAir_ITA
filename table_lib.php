<?php
// table_lib.php - Pipeline dei dati della tabella principale: lettura, correzioni
// manuali, nazionalità, regole automatiche, filtri e ordinamento.
//
// Usata da index.php (la tabella) e da export.php (CSV e versione stampabile).
// Fino al 23/09/2026 export.php aveva una sua versione ridotta della pipeline, e
// le due erano divergenti: l'export ignorava i pulsanti rapidi (dopo "Oggi"
// esportava tutte le 2.157 righe), i filtri per operatore, nazione, categoria,
// contrassegno, squawk e profilo geografico, le correzioni manuali, le note
// automatiche e il carattere jolly '*'. Una sola pipeline garantisce che
// l'export contenga esattamente ciò che la tabella mostra.
//
// Richiede geo_lib.php (nazionalità, operatore, patternMatch).

require_once __DIR__ . '/geo_lib.php';

const TABLE_TZ = 'Europe/Rome';

// Codici squawk di emergenza ufficiali (ICAO/DO-260B) e relativo significato.
const EMERGENCY_SQUAWKS = [
    '7500' => 'Interferenza illecita (dirottamento)',
    '7600' => 'Guasto radio / perdita comunicazioni',
    '7700' => 'Emergenza generale',
];

// Chiavi di ordinamento ammesse -> campo della riga su cui ordinare.
const TABLE_SORTS = [
    'hex'              => 'hex',
    'callsign'         => 'callsign',
    'reg'              => 'reg',
    'model_t'          => 'model_t',
    'ident_first_seen' => 'ident_first_seen',
    'ident_last_seen'  => 'ident_last_seen',
    'hex_first_seen'   => 'hex_first_seen',
    'hex_last_seen'    => 'hex_last_seen',
    'total_days'       => 'total_days',
    'max_consecutive'  => 'max_consecutive_days',
    'rarity'           => 'rarity',
    // La colonna "Note" mostra la nota combinata (manuale + automatiche): ordinare
    // sulla sola nota manuale non ordinava nulla, dato che le note visibili sono
    // quasi tutte automatiche.
    'note'             => 'combined_note',
    'country'          => 'country',
    'squawk'           => 'last_squawk',
    'operator'         => 'operator',
];

/** "Y-m-d" valida (anche come data di calendario reale)? */
function table_valid_ymd(?string $s): bool {
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/**
 * Inizio del giorno $ymd (o del successivo, con $nextDay) in ora italiana,
 * convertito nel formato con cui le date sono memorizzate ("Y-m-d H:i:s UTC").
 *
 * Il confronto con le date del database va fatto così: PHP gira in UTC (nessun
 * date.timezone configurato), mentre la tabella mostra l'ora italiana. Prima un
 * contatto visto alle 00:18 del 23/09 (ora italiana, cioè le 22:18 UTC del 22)
 * compariva filtrando il 22 ma non il 23, e "Oggi" o il 💡 "nuovo oggi" fra
 * mezzanotte e le 2 (l'1 d'inverno) si riferivano al giorno sbagliato.
 */
function table_day_start_utc(string $ymd, bool $nextDay = false): string {
    $d = new DateTime($ymd . ' 00:00:00', new DateTimeZone(TABLE_TZ));
    if ($nextDay) {
        $d->modify('+1 day');
    }
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s') . ' UTC';
}

/**
 * Legge e normalizza i parametri di filtro/ordinamento da $_GET (o equivalente).
 * I pulsanti rapidi (today/week/month/year/all) sono tradotti in date_from/date_to
 * sul calendario italiano: "Ultimi 7 giorni" copre esattamente 7 giorni, oggi compreso.
 */
function table_params(array $get): array {
    $p = [
        'date_from'     => $get['date_from'] ?? '',
        'date_to'       => $get['date_to'] ?? '',
        'hex'           => trim($get['hex'] ?? ''),
        'callsign'      => trim($get['callsign'] ?? ''),
        'reg'           => trim($get['reg'] ?? ''),
        'model'         => trim($get['model'] ?? ''),
        'operator'      => strtoupper(trim($get['operator'] ?? '')),
        'note'          => trim($get['note'] ?? ''),
        'rarity'        => $get['rarity'] ?? '',
        'country'       => $get['country'] ?? '',
        'category'      => $get['category'] ?? '',
        'markered'      => $get['markered'] ?? '',
        'manual'        => $get['manual'] ?? '',
        'squawk_filter' => $get['squawk_filter'] ?? '',
        'geofilter'     => $get['geofilter'] ?? '',
    ];

    $today = new DateTime('today', new DateTimeZone(TABLE_TZ));
    $todayYmd = $today->format('Y-m-d');
    if (isset($get['today'])) { $p['date_from'] = $todayYmd; $p['date_to'] = $todayYmd; }
    if (isset($get['week']))  { $p['date_from'] = (clone $today)->modify('-6 days')->format('Y-m-d');  $p['date_to'] = $todayYmd; }
    if (isset($get['month'])) { $p['date_from'] = (clone $today)->modify('-29 days')->format('Y-m-d'); $p['date_to'] = $todayYmd; }
    if (isset($get['year']))  { $p['date_from'] = (clone $today)->modify('-1 year +1 day')->format('Y-m-d'); $p['date_to'] = $todayYmd; }
    if (isset($get['all']))   { $p['date_from'] = ''; $p['date_to'] = ''; }

    // Date non valide ignorate (prima una data malformata produceva confronti
    // con strtotime() === false, dall'esito imprevedibile).
    if (!table_valid_ymd($p['date_from'])) $p['date_from'] = '';
    if (!table_valid_ymd($p['date_to']))   $p['date_to'] = '';

    $sort = $get['sort'] ?? 'ident_last_seen';
    $p['sort']  = array_key_exists($sort, TABLE_SORTS) ? $sort : 'ident_last_seen';
    $p['order'] = ($get['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    return $p;
}

/**
 * Categoria (aereo/elicottero/drone) dedotta dal codice modello: il transponder
 * spesso non trasmette la categoria ADS-B, quindi si ripiega su una mappa statica.
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
    $modelT = strtoupper(trim((string)$modelT));
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
 * Categoria emettitore ADS-B trasmessa dal transponder (ICAO Annex 10 / DO-260B:
 * A7 = rotorcraft, B6 = UAV, A1-A6 = ala fissa): quando c'è, prevale sulla
 * classificazione dedotta dal modello.
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
    return null; // palloni, paracadutisti, veicoli di terra...: non classificati
}

/** Confronta due righe per l'ordinamento. */
function compareRows($a, $b, $sort, $order) {
    static $rarityOrder = ['Mythic' => 0, 'Legendary' => 1, 'Epic' => 2, 'Rare' => 3, 'Uncommon' => 4, 'Common' => 5];
    $key = TABLE_SORTS[$sort] ?? 'ident_last_seen';
    $va = $a[$key] ?? '';
    $vb = $b[$key] ?? '';

    if ($sort === 'rarity') {
        $cmp = ($rarityOrder[$va] ?? 99) <=> ($rarityOrder[$vb] ?? 99);
    } elseif (in_array($sort, ['total_days', 'max_consecutive'], true)) {
        $cmp = ((int)$va) <=> ((int)$vb);
    } elseif (in_array($sort, ['ident_first_seen', 'ident_last_seen', 'hex_first_seen', 'hex_last_seen'], true)) {
        $cmp = strcmp((string)$va, (string)$vb);
    } else {
        $cmp = strcasecmp((string)$va, (string)$vb);
    }
    return ($order === 'asc') ? $cmp : -$cmp;
}

/**
 * Estrae i poligoni da un GeoJSON (FeatureCollection, Feature, Polygon,
 * MultiPolygon). Una Feature singola prima non era gestita, e un profilo
 * salvato in quella forma svuotava la tabella.
 */
function geojson_polygons(?string $geojson): array {
    if (empty($geojson)) return [];
    $data = json_decode($geojson, true);
    if (!is_array($data) || !isset($data['type'])) return [];

    $geometries = [];
    if ($data['type'] === 'FeatureCollection') {
        foreach ($data['features'] ?? [] as $feature) {
            if (isset($feature['geometry'])) $geometries[] = $feature['geometry'];
        }
    } elseif ($data['type'] === 'Feature') {
        if (isset($data['geometry'])) $geometries[] = $data['geometry'];
    } else {
        $geometries[] = $data;
    }

    $polygons = [];
    foreach ($geometries as $geom) {
        if (($geom['type'] ?? '') === 'Polygon') {
            $polygons[] = $geom['coordinates'];
        } elseif (($geom['type'] ?? '') === 'MultiPolygon') {
            foreach ($geom['coordinates'] as $poly) {
                $polygons[] = $poly;
            }
        }
    }
    return $polygons;
}

/** Il punto (lat, lon) cade in almeno uno dei poligoni? */
function pointInPolygons($lat, $lon, array $polygons): bool {
    if ($lat === null || $lon === null || $lat === '' || $lon === '') return false;
    foreach ($polygons as $poly) {
        if (pointInPolygonRings((float)$lat, (float)$lon, $poly)) {
            return true;
        }
    }
    return false;
}

/** Compatibilità: stessa firma della vecchia funzione di index.php. */
function pointInGeoJSON($lat, $lon, $geojson) {
    return pointInPolygons($lat, $lon, geojson_polygons($geojson));
}

function pointInPolygonRings($lat, $lon, $rings) {
    if (empty($rings[0]) || !pointInRing($lat, $lon, $rings[0])) return false; // anello esterno
    for ($i = 1; $i < count($rings); $i++) {                                    // buchi
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

/** Il valore del campo indicato dalla regola, normalizzato per il confronto. */
function table_rule_field_value(array $rule, array $row): ?string {
    switch ($rule['field']) {
        case 'hex':      return strtoupper(trim($row['hex']));
        case 'callsign': return strtoupper(trim($row['callsign'] ?? ''));
        case 'reg':      return strtoupper(trim($row['reg'] ?? ''));
        case 'model_t':  return strtoupper(trim($row['model_t'] ?? ''));
        case 'squawk':   return strtoupper(trim($row['last_squawk'] ?? ''));
    }
    return null;
}

/** Filtro testuale: prefisso senza '*', pattern con '*' o intervallo "A - B". */
function table_text_match(?string $value, string $filter, bool $contains = false): bool {
    $value = (string)$value;
    if (strpos($filter, '*') !== false || preg_match('/^\S+\s+-\s+\S+$/', $filter)) {
        return patternMatch($value, $filter);
    }
    return $contains ? stripos($value, $filter) !== false : stripos($value, $filter) === 0;
}

/** Carica le righe di una tabella di regole; [] se la tabella non esiste ancora. */
function table_load_rules(SQLite3 $db, string $sql): array {
    $out = [];
    try {
        $res = $db->query($sql);
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
        $res->finalize();
    } catch (Exception $e) {
        // tabella assente (deploy nuovo, rules.php mai aperta)
    }
    return $out;
}

/**
 * Esegue la pipeline completa. Restituisce:
 *   rows               righe filtrate e ordinate (tutte: la paginazione è del chiamante)
 *   availableCountries nazioni presenti nell'intero archivio (per il menu dei filtri)
 *   rowRules           regole di evidenziazione (servono al rendering della tabella)
 *   manualOverrides    correzioni manuali per hex
 *   favoritesHex       hex nei preferiti
 */
function table_load(SQLite3 $db, array $p): array {
    $customRules  = loadCountryRules($db);
    $rowRules     = table_load_rules($db, "SELECT field, pattern, bg_color, bold FROM row_rules");
    $noteRules    = table_load_rules($db, "SELECT field, pattern, note FROM note_rules");
    $markerRules  = table_load_rules($db, "SELECT field, pattern, emoji FROM marker_rules");

    $markersData = [];
    foreach (table_load_rules($db, "SELECT hex, emoji FROM markers") as $m) {
        $markersData[$m['hex']] = $m['emoji'];
    }
    $manualOverrides = [];
    foreach (table_load_rules($db, "SELECT hex, reg, callsign, model_t FROM manual_overrides") as $o) {
        $manualOverrides[$o['hex']] = $o;
    }
    $favoritesHex = array_column(table_load_rules($db, "SELECT hex FROM favorites"), 'hex');

    $geoPolygons = null;
    if ($p['geofilter'] !== '') {
        $stmt = $db->prepare("SELECT geojson FROM geo_profiles WHERE id = ?");
        $stmt->bindValue(1, (int)$p['geofilter'], SQLITE3_INTEGER);
        $g = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $geoPolygons = $g ? geojson_polygons($g['geojson']) : [];
    }

    $fromUtc  = $p['date_from'] !== '' ? table_day_start_utc($p['date_from']) : null;
    $toUtc    = $p['date_to']   !== '' ? table_day_start_utc($p['date_to'], true) : null; // esclusivo
    $todayUtc = table_day_start_utc((new DateTime('today', new DateTimeZone(TABLE_TZ)))->format('Y-m-d'));

    $res = $db->query("
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
    ");

    $rows = [];
    $availableCountries = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        // Correzioni manuali (solo sui campi effettivamente impostati)
        $row['has_override'] = false;
        if (isset($manualOverrides[$row['hex']])) {
            foreach (['reg', 'callsign', 'model_t'] as $f) {
                if (!empty($manualOverrides[$row['hex']][$f])) {
                    $row[$f] = $manualOverrides[$row['hex']][$f];
                    $row['has_override'] = true;
                }
            }
        }

        $row['country']  = getCountryCode($row['hex'], $row['reg'], $row['callsign'], $customRules);
        $row['category'] = mapAdsbCategory($row['transponder_category'] ?? null) ?? getAircraftCategory($row['model_t']);
        $row['last_squawk'] = trim($row['last_squawk'] ?? '');
        $row['squawk_is_emergency'] = isset(EMERGENCY_SQUAWKS[$row['last_squawk']]);
        $row['operator'] = operatorFromCallsign($row['callsign'] ?? '');

        $autoNotes = [];
        foreach ($noteRules as $rule) {
            $v = table_rule_field_value($rule, $row);
            if ($v !== null && patternMatch($v, $rule['pattern'])) $autoNotes[] = $rule['note'];
        }
        $combined = (string)($row['note'] ?? '');
        if ($autoNotes) {
            $auto = implode(' | ', $autoNotes);
            $combined = $combined !== '' ? $combined . ' | [auto] ' . $auto : '[auto] ' . $auto;
        }
        $row['combined_note'] = $combined;

        $autoMarker = null;
        foreach ($markerRules as $rule) {
            $v = table_rule_field_value($rule, $row);
            if ($v !== null && patternMatch($v, $rule['pattern'])) { $autoMarker = $rule['emoji']; break; }
        }
        $row['marker_emoji'] = $markersData[$row['hex']] ?? $autoMarker;

        // Primo avvistamento dell'hex da mezzanotte (ora italiana) in poi
        $row['is_new_today'] = ($row['hex_first_seen'] ?? '') >= $todayUtc;

        if (!empty($row['country'])) {
            $availableCountries[$row['country']] = $row['country'];
        }

        // --- Filtri ---
        if ($fromUtc && $row['ident_last_seen'] < $fromUtc) continue;
        if ($toUtc && $row['ident_last_seen'] >= $toUtc) continue;
        if ($p['hex'] !== ''      && !table_text_match($row['hex'], $p['hex'])) continue;
        if ($p['callsign'] !== '' && !table_text_match($row['callsign'], $p['callsign'])) continue;
        if ($p['reg'] !== ''      && !table_text_match($row['reg'], $p['reg'])) continue;
        if ($p['model'] !== ''    && !table_text_match($row['model_t'], $p['model'], true)) continue;
        if ($p['operator'] !== '' && ($row['operator'] ?? '') !== $p['operator']) continue;
        if ($p['note'] !== ''     && stripos($row['combined_note'], $p['note']) === false) continue;
        if ($p['rarity'] !== ''   && $row['rarity'] !== $p['rarity']) continue;
        if ($p['country'] !== ''  && $row['country'] !== $p['country']) continue;
        if ($p['category'] !== '') {
            if ($p['category'] === 'none' ? $row['category'] !== null : $row['category'] !== $p['category']) continue;
        }
        if ($p['markered'] === 'watch') {
            if (($row['marker_emoji'] ?? '') !== '❓') continue;
        } elseif ($p['markered'] !== '' && empty($row['marker_emoji'])) {
            continue;
        }
        if ($p['manual'] !== '' && empty($row['has_override'])) continue;
        if ($p['squawk_filter'] !== '') {
            if ($p['squawk_filter'] === 'emergency' ? empty($row['squawk_is_emergency']) : $row['last_squawk'] !== $p['squawk_filter']) continue;
        }
        if ($geoPolygons !== null && !pointInPolygons($row['last_lat'], $row['last_lon'], $geoPolygons)) continue;

        $rows[] = $row;
    }
    $res->finalize();
    ksort($availableCountries);

    $sort = $p['sort']; $order = $p['order'];
    usort($rows, fn($a, $b) => compareRows($a, $b, $sort, $order));

    return [
        'rows'               => $rows,
        'availableCountries' => $availableCountries,
        'rowRules'           => $rowRules,
        'manualOverrides'    => $manualOverrides,
        'favoritesHex'       => $favoritesHex,
    ];
}
