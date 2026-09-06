<?php
// diary_lib.php - "Diario di bordo": sintesi giornaliera dei contatti.
//
// Ogni giorno (fuso Europe/Rome) ha una riga in diary_days (dentro events.db):
//   - digest_json  : aggregati DETERMINISTICI (solo SQL/PHP), sempre rigenerabili
//   - narrative_md : sintesi discorsiva OPZIONALE (assistente IA locale)
//   - published    : 0 = bozza (solo admin) / 1 = visibile nel diario pubblico
//
// La pagina è diary.php (pubblica in lettura, solo voci pubblicate). La
// rigenerazione del digest, la generazione della narrativa e la pubblicazione
// sono azioni riservate all'admin.
//
// Terza eccezione deliberata alla convenzione "nessun include condiviso" di
// questo progetto (dopo auth.php e news_lib.php): diary.php è l'unico
// consumatore, ma tenere schema/aggregazione in un solo posto evita che
// un domani si debba ritoccare la stessa logica in più file.

const DIARY_LIST_MAX = 120; // righe max nella lista del diario

/** true se $d è una data valida "YYYY-MM-DD". */
function diary_valid_day(?string $d): bool {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $t = DateTime::createFromFormat('!Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

/** "Oggi" nel fuso Europe/Rome (YYYY-MM-DD). */
function diary_today(): string {
    return (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
}

/**
 * [inizio, fine) del giorno locale $day come stringhe "Y-m-d H:i:s UTC" —
 * stesso formato esatto con cui first_seen_utc è memorizzato in events.db,
 * necessario perché il confronto è testuale (colonna TEXT), non temporale.
 */
function diary_day_bounds_utc(string $day): array {
    $rome = new DateTimeZone('Europe/Rome');
    $utc  = new DateTimeZone('UTC');
    $a = (new DateTime($day . ' 00:00:00', $rome))->setTimezone($utc);
    $b = (new DateTime($day . ' 00:00:00', $rome))->modify('+1 day')->setTimezone($utc);
    return [$a->format('Y-m-d H:i:s') . ' UTC', $b->format('Y-m-d H:i:s') . ' UTC'];
}

function diary_ensure_schema(SQLite3 $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS diary_days (
        day                    TEXT PRIMARY KEY,
        digest_json            TEXT NOT NULL,
        digest_generated_at    TEXT NOT NULL,
        event_count            INTEGER NOT NULL DEFAULT 0,
        narrative_md           TEXT,
        narrative_model        TEXT,
        narrative_generated_at TEXT,
        published              INTEGER NOT NULL DEFAULT 0,
        published_at           TEXT,
        updated_at             TEXT
    )");
}

/** Riga di diary_days per $day, o null. */
function diary_get(SQLite3 $db, string $day): ?array {
    diary_ensure_schema($db);
    $s = $db->prepare("SELECT * FROM diary_days WHERE day = ?");
    $s->bindValue(1, $day, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

// --------------------- Funzioni di derivazione (stessa logica di
// --------------------- index.php/stats.php, duplicate per convenzione) ------

function operatorFromCallsign($callsign) {
    $cs = strtoupper(trim((string) $callsign));
    if (preg_match('/^[A-Z]{3}\d/', $cs)) {
        return substr($cs, 0, 3);
    }
    return null;
}

function getOperatorLogo($code) {
    static $memo = [];
    $c = strtoupper(trim((string) $code));
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

function getCountryCode($hex, $reg, $callsign, $customRules = []) {
    foreach ($customRules as $rule) {
        $fieldValue = null;
        if ($rule['field'] === 'hex') $fieldValue = strtoupper(trim($hex));
        elseif ($rule['field'] === 'reg') $fieldValue = strtoupper(trim($reg ?? ''));
        elseif ($rule['field'] === 'callsign') $fieldValue = strtoupper(trim($callsign ?? ''));
        if ($fieldValue !== null && patternMatch($fieldValue, $rule['pattern'])) {
            return strtoupper($rule['country_code']);
        }
    }
    $country = getCountryFromReg($reg);
    if ($country !== null) return $country;
    $country = getCountryFromCallsign($callsign);
    if ($country !== null) return $country;
    return 'ZZ';
}

function isoToFlagEmoji($code) {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $offset = 0x1F1E6 - 65;
    return mb_chr(ord($code[0]) + $offset, 'UTF-8') . mb_chr(ord($code[1]) + $offset, 'UTF-8');
}

function getFlagHtml($code) {
    $c = strtoupper(trim((string) $code));
    if ($c === '' || $c === 'ZZ') {
        return '<span title="Nazionalità non determinata">🏳️</span>';
    }
    $svgFile = __DIR__ . '/flags/' . $c . '.svg';
    if (preg_match('/^[A-Z]{2}$/', $c) && file_exists($svgFile)) {
        return '<img src="flags/' . $c . '.svg" class="flag-icon" alt="' . $c . '" title="' . $c . '">';
    }
    $emoji = isoToFlagEmoji($c);
    return $emoji !== '' ? '<span title="' . htmlspecialchars($c) . '">' . $emoji . '</span>' : htmlspecialchars($c);
}

/**
 * Aggregati deterministici del giorno. Sola lettura di events/rarity_cache.
 * A differenza di FlightAnom, operatore e nazionalità non sono colonne: si
 * ricalcolano riga per riga con le funzioni sopra (stesso approccio già
 * usato in stats.php). Gli hex sono inclusi così la pagina (e la narrativa
 * IA) possono linkare a index.php?hex=... (non esiste un permalink per
 * singolo avvistamento in questo progetto, a differenza di FlightAnom).
 */
function diary_build_digest(SQLite3 $db, string $day): array {
    [$a, $b] = diary_day_bounds_utc($day);

    // Regole personalizzate di nazionalità (stessa fonte di index.php/stats.php)
    $customRules = [];
    $resRules = $db->query("SELECT field, pattern, country_code FROM country_rules");
    while ($rule = $resRules->fetchArray(SQLITE3_ASSOC)) {
        $customRules[] = $rule;
    }

    $stmt = $db->prepare("SELECT hex, callsign, reg, model_t, squawk, first_seen_utc
                           FROM events WHERE first_seen_utc >= ? AND first_seen_utc < ?
                           ORDER BY first_seen_utc");
    $stmt->bindValue(1, $a, SQLITE3_TEXT);
    $stmt->bindValue(2, $b, SQLITE3_TEXT);
    $res = $stmt->execute();

    $total = 0;
    $byHour = array_fill(0, 24, 0);
    $byOperator = [];
    $byCountry = [];
    $byModel = [];
    $hexCounts = [];   // hex => n. avvistamenti nel giorno
    $hexInfo = [];     // hex => ultima callsign/reg/model_t/operator/country viste
    $emergencies = [];
    $squawkLabels = [
        '7500' => 'Interferenza illecita (dirottamento)',
        '7600' => 'Guasto radio / perdita comunicazioni',
        '7700' => 'Emergenza generale',
    ];

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $total++;
        $hour = (int) substr($row['first_seen_utc'], 11, 2);
        if ($hour >= 0 && $hour <= 23) {
            $byHour[$hour]++;
        }

        $op = operatorFromCallsign($row['callsign']);
        $cc = getCountryCode($row['hex'], $row['reg'], $row['callsign'], $customRules);

        if ($op !== null) {
            $byOperator[$op] = ($byOperator[$op] ?? 0) + 1;
        }
        $byCountry[$cc] = ($byCountry[$cc] ?? 0) + 1;
        if (!empty($row['model_t'])) {
            $byModel[$row['model_t']] = ($byModel[$row['model_t']] ?? 0) + 1;
        }

        $hex = $row['hex'];
        $hexCounts[$hex] = ($hexCounts[$hex] ?? 0) + 1;
        $hexInfo[$hex] = [
            'hex' => $hex, 'callsign' => $row['callsign'], 'reg' => $row['reg'],
            'model_t' => $row['model_t'], 'operator' => $op, 'country' => $cc,
        ];

        if (in_array($row['squawk'], ['7500', '7600', '7700'], true)) {
            $emergencies[] = [
                'first_seen_utc' => $row['first_seen_utc'], 'hex' => $hex,
                'callsign' => $row['callsign'], 'squawk' => $row['squawk'],
                'label' => $squawkLabels[$row['squawk']] ?? '',
            ];
        }
    }

    arsort($byOperator);
    arsort($byCountry);
    arsort($byModel);

    // Mezzi ricorrenti nel giorno (stesso hex >= 2 volte)
    $repeatAircraft = [];
    foreach ($hexCounts as $hex => $n) {
        if ($n >= 2) {
            $repeatAircraft[] = $hexInfo[$hex] + ['n' => $n];
        }
    }
    usort($repeatAircraft, fn($x, $y) => $y['n'] <=> $x['n']);
    $repeatAircraft = array_slice($repeatAircraft, 0, 20);

    // Contatti rari avvistati oggi (Mythic/Legendary secondo rarity_cache):
    // equivalente concettuale dell'"alta confidenza" di FlightAnom, qui non
    // esistendo un punteggio di confidenza per singolo evento.
    $rareContacts = [];
    if (!empty($hexCounts)) {
        $placeholders = implode(',', array_fill(0, count($hexCounts), '?'));
        $rStmt = $db->prepare("SELECT hex, rarity, composite_score FROM rarity_cache
                                WHERE hex IN ($placeholders) AND rarity IN ('Mythic','Legendary')");
        $i = 1;
        foreach (array_keys($hexCounts) as $hex) {
            $rStmt->bindValue($i++, $hex, SQLITE3_TEXT);
        }
        $rRes = $rStmt->execute();
        while ($r = $rRes->fetchArray(SQLITE3_ASSOC)) {
            $rareContacts[] = ($hexInfo[$r['hex']] ?? ['hex' => $r['hex']]) + [
                'rarity' => $r['rarity'], 'n' => $hexCounts[$r['hex']] ?? 1,
            ];
        }
        usort($rareContacts, fn($x, $y) => ($x['rarity'] === 'Mythic' ? 0 : 1) <=> ($y['rarity'] === 'Mythic' ? 0 : 1));
    }

    // Baseline dei 14 giorni precedenti: operatori/nazioni "nuovi", callsign ricorrenti.
    $pa = (new DateTime(rtrim($a, ' UTC'), new DateTimeZone('UTC')))->modify('-14 days')->format('Y-m-d H:i:s') . ' UTC';
    $prevStmt = $db->prepare("SELECT callsign, reg, hex, first_seen_utc FROM events
                               WHERE first_seen_utc >= ? AND first_seen_utc < ?");
    $prevStmt->bindValue(1, $pa, SQLITE3_TEXT);
    $prevStmt->bindValue(2, $a, SQLITE3_TEXT);
    $prevRes = $prevStmt->execute();
    $prevOperators = [];
    $prevCountries = [];
    $callsignDays = []; // callsign => set di giorni distinti visti nelle 2 settimane precedenti (esclude oggi)
    while ($row = $prevRes->fetchArray(SQLITE3_ASSOC)) {
        $op = operatorFromCallsign($row['callsign']);
        if ($op !== null) $prevOperators[$op] = true;
        $cc = getCountryCode($row['hex'], $row['reg'], $row['callsign'], $customRules);
        if ($cc !== 'ZZ') $prevCountries[$cc] = true;
        if (!empty($row['callsign'])) {
            $callsignDays[$row['callsign']][substr($row['first_seen_utc'], 0, 10)] = true;
        }
    }

    $newOperators = array_values(array_diff(array_keys($byOperator), array_keys($prevOperators)));
    sort($newOperators);
    $newCountries = array_values(array_diff(array_keys(array_filter($byCountry, fn($v, $k) => $k !== 'ZZ', ARRAY_FILTER_USE_BOTH)), array_keys($prevCountries)));
    sort($newCountries);

    $recurringCallsigns = [];
    foreach (array_keys($hexInfo) as $hex) {
        $cs = $hexInfo[$hex]['callsign'];
        if (empty($cs)) continue;
        $days = $callsignDays[$cs] ?? [];
        $days[$day] = true; // include oggi stesso nel conteggio
        if (count($days) >= 3) {
            $recurringCallsigns[$cs] = ['callsign' => $cs, 'days' => count($days), 'n' => $hexCounts[$hex]];
        }
    }
    usort($recurringCallsigns, fn($x, $y) => [$y['days'], $y['n']] <=> [$x['days'], $x['n']]);

    $toRows = function (array $assoc, string $keyName, int $limit = 12): array {
        $out = [];
        $i = 0;
        foreach ($assoc as $k => $n) {
            if ($i++ >= $limit) break;
            $out[] = [$keyName => $k, 'n' => $n];
        }
        return $out;
    };

    return [
        'day'          => $day,
        'window_utc'   => [$a, $b],
        'generated_at' => gmdate('c'),
        'totals'       => [
            'events'      => $total,
            'distinct_hex' => count($hexCounts),
            'rare'        => count($rareContacts),
            'emergencies' => count($emergencies),
        ],
        'by_hour'             => $byHour,
        'by_operator'         => $toRows($byOperator, 'operator'),
        'by_country'          => $toRows($byCountry, 'country'),
        'by_model'            => $toRows($byModel, 'model_t'),
        'repeat_aircraft'     => $repeatAircraft,
        'rare_contacts'       => array_values($rareContacts),
        'emergencies'         => $emergencies,
        'new_operators'       => $newOperators,
        'new_countries'       => $newCountries,
        'recurring_callsigns' => array_values($recurringCallsigns),
    ];
}

/** Inserisce/aggiorna il digest del giorno (non tocca narrativa / published). */
function diary_store_digest(SQLite3 $db, string $day, array $digest): void {
    diary_ensure_schema($db);
    $now  = gmdate('Y-m-d H:i:s') . ' UTC';
    $json = json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = $db->prepare(
        "INSERT INTO diary_days (day, digest_json, digest_generated_at, event_count, updated_at)
         VALUES (:d, :j, :g, :n, :u)
         ON CONFLICT(day) DO UPDATE SET
            digest_json = :j, digest_generated_at = :g, event_count = :n, updated_at = :u"
    );
    $s->bindValue(':d', $day, SQLITE3_TEXT);
    $s->bindValue(':j', $json, SQLITE3_TEXT);
    $s->bindValue(':g', $now, SQLITE3_TEXT);
    $s->bindValue(':n', (int) ($digest['totals']['events'] ?? 0), SQLITE3_INTEGER);
    $s->bindValue(':u', $now, SQLITE3_TEXT);
    $s->execute();
}

/**
 * Restituisce la riga del giorno, (ri)calcolando il digest se manca, se è il
 * giorno in corso, o se $force. Da usare SOLO in contesti admin: il pubblico
 * legge esclusivamente le voci già pubblicate (diary_get).
 */
function diary_ensure_day(SQLite3 $db, string $day, bool $force = false): array {
    $row   = diary_get($db, $day);
    $stale = !$row || $force || $day >= diary_today();
    if ($stale) {
        diary_store_digest($db, $day, diary_build_digest($db, $day));
        $row = diary_get($db, $day);
    }
    return $row;
}

/** Righe recenti per la lista del diario. */
function diary_recent(SQLite3 $db, bool $onlyPublished, int $limit = DIARY_LIST_MAX): array {
    diary_ensure_schema($db);
    $sql = "SELECT day, event_count, published, published_at, digest_json,
                   (narrative_md IS NOT NULL AND narrative_md <> '') AS has_narrative
            FROM diary_days";
    if ($onlyPublished) {
        $sql .= " WHERE published = 1";
    }
    $sql .= " ORDER BY day DESC LIMIT " . (int) $limit;
    $out = [];
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $r;
    }
    return $out;
}

/** Mini-renderer Markdown -> HTML per la narrativa (sottoinsieme sicuro). */
function diary_md_to_html(string $s): string {
    $s = str_replace("\r\n", "\n", trim($s));
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $lines = explode("\n", $s);
    $out = '';
    $in_ul = false;
    $flush_ul = function () use (&$out, &$in_ul) {
        if ($in_ul) {
            $out .= "</ul>\n";
            $in_ul = false;
        }
    };
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '') {
            $flush_ul();
            continue;
        }
        if (preg_match('/^#{2,4}\s+(.*)$/', $t, $m)) {
            $flush_ul();
            $out .= '<h3>' . $m[1] . "</h3>\n";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if (!$in_ul) {
                $out .= "<ul>\n";
                $in_ul = true;
            }
            $out .= '<li>' . $m[1] . "</li>\n";
            continue;
        }
        $flush_ul();
        $out .= '<p>' . $t . "</p>\n";
    }
    $flush_ul();

    $out = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $out);
    // Cita hex tra backtick markdown -> link a index.php?hex=...&(sempre)
    $out = preg_replace_callback('/`([0-9A-Fa-f]{6})`/', function ($m) {
        $hex = strtoupper($m[1]);
        return '<a href="index.php?hex=' . $hex . '"><code>' . $hex . '</code></a>';
    }, $out);
    return $out;
}
