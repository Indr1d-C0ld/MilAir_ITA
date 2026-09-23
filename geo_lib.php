<?php
// geo_lib.php - Nazionalità, operatore e pattern matching: implementazioni CANONICHE.
//
// Quarta eccezione deliberata alla convenzione "nessun include condiviso" di
// questo progetto (dopo auth.php, news_lib.php e diary_lib.php), e l'unica nata
// da un bug vero anziché da una scelta di comodo.
//
// Perché: queste funzioni erano duplicate in 5 file (index.php, map.php,
// stats.php, update_rarity.php, diary_lib.php) e le copie sono DIVERGATE. In
// particolare getCountryCode() aveva 5 implementazioni diverse: due di esse
// ignoravano del tutto la tabella country_rules (le ~115 allocazioni ICAO per
// blocco hex), ripiegando su una manciata di prefissi reg/callsign. Le
// conseguenze reali, entrambe riscontrate in produzione:
//   - stats.php mostrava 221 contatti come nazionalità "UN" (sconosciuta)
//     quando i non identificati reali erano 1;
//   - update_rarity.php classificava 111 contatti su 743 (14,9%) nella fascia
//     di rarità sbagliata, perché ~200 finivano nello stesso calderone "ZZ"
//     azzerando il fattore "rarità della nazionalità" del punteggio composito.
//
// Duplicare funzioni di presentazione è una scelta di stile difendibile;
// duplicare la logica che DECIDE un dato (la nazionalità, e quindi la rarità)
// no: qui una copia che va fuori sincrono produce numeri sbagliati, non solo
// codice ripetuto. Da qui in avanti esiste una sola definizione.
//
// Nessuna dipendenza: sola logica pura più la lettura di flags/ e opflags/.

/**
 * Confronta un valore con un pattern che può contenere wildcard '*' oppure un
 * intervallo nella forma "BASSO - ALTO" (spazi obbligatori attorno al trattino),
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
 * Normalizza il pattern di una regola prima di salvarla (rules.php, anche in
 * import). null = pattern non utilizzabile. Casi prima salvati "muti", cioè
 * accettati ma senza mai corrispondere a nulla:
 *  - trattino tipografico (– —) incollato da documenti: non era un intervallo;
 *  - "E00000-E3FFFF" senza spazi sul campo hex (dove un '-' non può comparire):
 *    era letto come prefisso letterale;
 *  - estremi invertiti ("E3FFFF - E00000"): nessun valore può rientrarvi.
 * Sugli altri campi un '-' senza spazi resta un prefisso (es. "D-A*", "I-").
 */
function normalize_rule_pattern(string $field, $pattern): ?string {
    if (!is_scalar($pattern)) {
        return null;
    }
    $p = trim(str_replace(["\u{2013}", "\u{2014}"], ' - ', (string)$pattern));
    if ($p === '') {
        return null;
    }
    $spaced = preg_match('/^(\S+)\s+-\s+(\S+)$/', $p, $m);
    if (!$spaced && $field === 'hex') {
        $spaced = preg_match('/^([^\s-]+)\s*-\s*([^\s-]+)$/', $p, $m);
    }
    if ($spaced) {
        $low = strtoupper($m[1]);
        $high = strtoupper($m[2]);
        if (rtrim($low, '*') === '' || rtrim($high, '*') === '') {
            return null;
        }
        if (strcmp(rtrim($low, '*'), rtrim($high, '*')) > 0) {
            [$low, $high] = [$high, $low];
        }
        return $low . ' - ' . $high;
    }
    return preg_replace('/\s+/', ' ', $p);
}

/** Colore di sfondo di una regola: solo "#rrggbb" (finisce in un attributo style). */
function normalize_rule_color($color): ?string {
    return (is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($color))) ? strtolower(trim($color)) : null;
}

/**
 * Verifica se $value (già in maiuscolo) rientra nell'intervallo [$low, $high].
 * Gli estremi possono terminare con '*' per indicare un prefisso (es. "E00*").
 * Il confronto è lessicografico sui primi N caratteri, dove N è la lunghezza
 * dell'estremo senza wildcard: corretto per gli hex ICAO, che hanno lunghezza
 * fissa e in cui l'ordine ASCII coincide con quello numerico.
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

    // strcmp() e NON gli operatori >= / <=: in PHP 8, fra due stringhe che
    // "sembrano numeri" il confronto diventa numerico, e molti hex ICAO sono
    // letti come notazione scientifica. "7102E3" vale 7.102.000 (quindi >= "738000"
    // e dentro il blocco di Israele), e il limite "70E000" della regola cambogiana
    // vale appena 70, così ogni hex di sole cifre non già catturato finiva in KH.
    // Risultato riscontrato in produzione (23/09/2026): 10 aerei con nazionalità
    // sbagliata, fra cui due sauditi classificati israeliani e due greci cambogiani.
    return strcmp($vLow, $low) >= 0 && strcmp($vHigh, $high) <= 0;
}

/** Mappatura prefissi di registrazione -> codice nazione (fallback secondario). */
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
        'V2-' => 'AG', '8P-' => 'BB', 'J3-' => 'GD', '9Y-' => 'TT', 'PJ-' => 'SX',
        // Europa e Africa/Medio Oriente mancanti: si ricade qui solo quando nessuna
        // regola hex di rules.php corrisponde (es. 9G-EXE, Falcon del Ghana, era ZZ).
        'SX-' => 'GR', 'CS-' => 'PT', 'EI-' => 'IE', 'LN-' => 'NO', 'SE-' => 'SE',
        'OH-' => 'FI', 'OY-' => 'DK', 'LX-' => 'LU', 'ES-' => 'EE', 'YL-' => 'LV',
        'LY-' => 'LT', 'UR-' => 'UA', 'RA-' => 'RU', 'EW-' => 'BY', 'ER-' => 'MD',
        'ZA-' => 'AL', 'E7-' => 'BA', '4O-' => 'ME', '4K-' => 'AZ', 'HZ-' => 'SA',
        'A9C-' => 'BH', 'A4O-' => 'OM', 'YI-' => 'IQ', 'ZS-' => 'ZA', '9G-' => 'GH',
        '6V-' => 'SN', '5N-' => 'NG', '5Y-' => 'KE', 'ET-' => 'ET', 'ST-' => 'SD'
    ];
    if (empty($reg)) return null;
    $reg = strtoupper(trim($reg));
    foreach ($map as $prefix => $country) {
        if (strpos($reg, $prefix) === 0) return $country;
    }
    return null;
}

/** Mappatura prefissi di callsign -> codice nazione (fallback secondario). */
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

/**
 * Determina il codice nazione (ISO 3166-1 alpha-2) per un velivolo.
 *
 * $customRules (tabella country_rules, gestita in rules.php) copre l'intera
 * allocazione ICAO per blocco hex ed è la fonte PRINCIPALE: va sempre passata.
 * Le mappature per prefisso qui sopra sono solo un ripiego per i casi non
 * coperti. Chiamare questa funzione senza $customRules non è un errore di
 * sintassi ma quasi sempre un bug — è esattamente così che sono nate le
 * divergenze descritte in cima a questo file.
 *
 * Restituisce 'ZZ' se la nazionalità non è determinabile.
 */
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

/**
 * Carica le regole di nazionalità da un database già aperto su events.db.
 *
 * Ordine = precedenza (vince la prima regola che corrisponde): dalla più recente
 * alla più vecchia, cioè lo stesso ordine in cui rules.php le mostra. Prima non
 * c'era ORDER BY e si applicava di fatto l'ordine di inserimento, l'opposto di
 * quello visualizzato — e una regola aggiunta dopo le allocazioni ICAO per blocco
 * (es. una regola dedicata a un singolo hex, o a un sotto-blocco come Hong Kong
 * 789000-789FFF, contenuto nel blocco cinese 780000-7BFFFF) non poteva mai
 * prevalere su di esse. Unico punto da cui tutte le pagine leggono le regole.
 */
function loadCountryRules(SQLite3 $db): array {
    $rules = [];
    try {
        $res = $db->query("SELECT field, pattern, country_code FROM country_rules ORDER BY id DESC");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $rules[] = $r;
        }
        $res->finalize();
    } catch (Exception $e) {
        // Tabella assente (deploy nuovo, rules.php mai aperta): si ripiega sui
        // prefissi predefiniti, senza interrompere la pagina.
    }
    return $rules;
}

/**
 * Deriva il codice operatore/forza aerea a 3 lettere da un callsign, secondo la
 * convenzione ICAO più diffusa (3 lettere + almeno una cifra, es. "IAM9001" ->
 * "IAM"). I nomignoli di reparto ("DRAGO142") non la rispettano: in quel caso
 * restituisce null, senza inventare nulla.
 */
function operatorFromCallsign($callsign) {
    $cs = strtoupper(trim((string)$callsign));
    if (preg_match('/^[A-Z]{3}\d/', $cs)) {
        return substr($cs, 0, 3);
    }
    return null;
}

/** Percorso web del logo operatore (opflags/CODICE.*), o null se assente. */
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

/**
 * Emoji bandiera da codice ISO 3166-1 alpha-2, componendo i due "Regional
 * Indicator Symbol" Unicode. Funziona per qualunque codice a due lettere,
 * quindi copre anche i codici aggiunti in futuro tramite le regole
 * personalizzate, senza mantenere una mappa statica.
 */
function isoToFlagEmoji($code) {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $offset = 0x1F1E6 - 65; // 'A' -> Regional Indicator Symbol Letter A
    return mb_chr(ord($code[0]) + $offset, 'UTF-8') . mb_chr(ord($code[1]) + $offset, 'UTF-8');
}

/** Emoji bandiera, con i pseudo-codici interni del progetto. */
function countryToEmoji($code) {
    $code = strtoupper(trim($code));
    $special = [
        'NATO' => '🧭', // NATO non ha un codice ISO proprio: bussola, richiamo allo stemma
        'ZZ'   => '🏳️', // codice interno per nazionalità non determinata
    ];
    if (isset($special[$code])) {
        return $special[$code];
    }
    return isoToFlagEmoji($code);
}

/** HTML bandiera: SVG locale da flags/ se presente, altrimenti emoji. */
function getFlagHtml($code) {
    $c = strtoupper(trim((string)$code));
    if ($c === '' || $c === 'ZZ') {
        return '<span title="Nazionalità non determinata">🏳️</span>';
    }
    $svgFile = __DIR__ . '/flags/' . $c . '.svg';
    if (preg_match('/^[A-Z]{2}$/', $c) && file_exists($svgFile)) {
        return '<img src="flags/' . $c . '.svg" class="flag-icon" alt="' . $c . '" title="' . $c . '">';
    }
    $emoji = countryToEmoji($c);
    return $emoji !== '' ? '<span title="' . htmlspecialchars($c) . '">' . $emoji . '</span>' : htmlspecialchars($c);
}
