<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

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
 * Confronta un valore con un pattern che può contenere wildcard '*'
 * oppure un intervallo nella forma "BASSO - ALTO" (spazi obbligatori attorno al trattino).
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

/**
 * Costruisce l'emoji bandiera per un codice ISO 3166-1 alpha-2 componendo i due
 * "Regional Indicator Symbol" Unicode corrispondenti. Copre automaticamente
 * qualunque codice a due lettere (incluso 'UN') senza mappa statica.
 */
function isoToFlagEmoji($code) {
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $offset = 0x1F1E6 - 65;
    return mb_chr(ord($code[0]) + $offset, 'UTF-8') . mb_chr(ord($code[1]) + $offset, 'UTF-8');
}

function countryToEmoji($code) {
    $code = strtoupper(trim($code));
    $special = [
        'NATO' => '🧭',
        'ZZ'   => '🏳️',
    ];
    if (isset($special[$code])) {
        return $special[$code];
    }
    return isoToFlagEmoji($code);
}

// Regole personalizzate per la nazionalità (stesse usate in index.php)
$customRules = [];
$resRules = $db->query("SELECT field, pattern, country_code FROM country_rules");
while ($rule = $resRules->fetchArray(SQLITE3_ASSOC)) {
    $customRules[] = $rule;
}

// Legge tutti i marker (limitati a 5000 per performance)
$res = $db->query("SELECT a.hex, a.callsign, a.reg, a.model_t, a.lat, a.lon, a.alt_ft, a.gs_kt, a.squawk, a.last_seen_utc, a.ground,
                          r.rarity AS rarity
                   FROM aircraft a
                   LEFT JOIN rarity_cache r ON a.hex = r.hex
                   WHERE a.lat IS NOT NULL AND a.lon IS NOT NULL
                   ORDER BY a.last_seen_utc DESC
                   LIMIT 5000");

$markers = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    // Nazionalità e bandiera
    $row['country'] = getCountryCode($row['hex'], $row['reg'], $row['callsign'], $customRules);
    $row['country_flag'] = countryToEmoji($row['country']);
    $row['rarity'] = $row['rarity'] ?: 'Common';

    // Aggiungi URL per filtri tabella
    $row['hex_url']    = 'index.php?hex=' . urlencode($row['hex']) . '&sort=ident_last_seen&order=desc';
    $row['reg_url']    = !empty($row['reg']) ? 'index.php?reg=' . urlencode($row['reg']) . '&sort=ident_last_seen&order=desc' : '';
    $row['model_url']  = !empty($row['model_t']) ? 'index.php?model=' . urlencode($row['model_t']) . '&sort=ident_last_seen&order=desc' : '';

    // Data formattata in italiano e ora italiana (ULTIMO avvistamento)
    $row['last_seen_it'] = formatDateIt($row['last_seen_utc']);

    // Aggiungi silhouette se esiste
    $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($row['model_t'] ?? '')));
    $silhouettePath = null;
    if ($safeType !== '') {
        $exts = ['bmp', 'png', 'svg', 'gif'];
        foreach ($exts as $ext) {
            $localFile = __DIR__ . '/silhouettes/' . $safeType . '.' . $ext;
            if (file_exists($localFile) && filesize($localFile) > 0) {
                $silhouettePath = 'silhouettes/' . $safeType . '.' . $ext;
                break;
            }
        }
    }
    $row['silhouette'] = $silhouettePath ?: null;

    $markers[] = $row;
}

// Ottieni il timestamp Unix per ogni marker per il filtraggio client-side
$markersJson = json_encode($markers);

// Layer NOTAM (aree geografiche delimitate), se la cache esiste
$notamAreas = [];
$notamUpdatedAt = null;
$notamsTableExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='notams_cache'");
if ($notamsTableExists) {
    $resNotam = $db->query("SELECT id, lat, lon, area_type, radius_nm, polygon_json, reference, meaning, notam_text, validity, fl_lower, fl_upper, fetched_at
        FROM notams_cache
        WHERE area_type IN ('circle', 'polygon')
        ORDER BY id");
    while ($row = $resNotam->fetchArray(SQLITE3_ASSOC)) {
        if ($row['area_type'] === 'polygon' && !empty($row['polygon_json'])) {
            $row['polygon'] = json_decode($row['polygon_json'], true);
        }
        unset($row['polygon_json']);
        $notamAreas[] = $row;
        if ($notamUpdatedAt === null || $row['fetched_at'] > $notamUpdatedAt) {
            $notamUpdatedAt = $row['fetched_at'];
        }
    }
}
$notamAreasJson = json_encode($notamAreas);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mappa Voli Militari</title>
    <link rel="stylesheet" href="leaflet/leaflet.css" />
    <link rel="stylesheet" href="style.css">
    <script src="leaflet/leaflet.js"></script>
    <style>
        body { margin: 0; padding: 0; }
        #map { height: calc(100vh - 60px); width: 100%; }
        /* Navbar centrata in alto, larghezza limitata per non invadere gli angoli
           dove Leaflet mette i controlli zoom (in alto a sinistra) e layer
           (in alto a destra) — evita la sovrapposizione invece di rincorrerla. */
        .nav-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 4px 12px;
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            width: max-content;
            max-width: calc(100vw - 180px);
            z-index: 1000;
            background: rgba(255,255,255,0.92);
            padding: 6px 14px;
            border-radius: 5px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.92em;
        }
        .nav-links-primary {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 3px 10px;
        }
        .nav-links-secondary {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            margin-left: 0;
        }
        .nav-links a { margin-right: 0; text-decoration: none; font-weight: 500; color: #007bff; }
        .nav-links a:hover { text-decoration: underline; }
        .alert-bell-wrap { padding-right: 8px; margin-right: 0; border-right: 1px solid rgba(0,0,0,0.15); }
        .alert-bell { font-size: 0.95em; padding: 2px 6px; }

        .leaflet-popup-content a { color: #007bff; text-decoration: none; }
        .leaflet-popup-content a:hover { color: #0056b3; text-decoration: none; }

        .time-controls {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .time-controls label { font-weight: 500; }
        .time-controls select, .time-controls input[type="range"] {
            vertical-align: middle;
        }
        .quick-buttons a {
            padding: 4px 8px;
            background: #007bff;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
        }
        .quick-buttons a:hover { background: #0056b3; }

        .map-legend {
            position: absolute;
            top: 60px;
            right: 10px;
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            padding: 10px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.85em;
            max-width: 220px;
            max-height: 60vh;
            overflow-y: auto;
            display: none;
        }
        .map-legend h4 { margin: 0 0 6px; font-size: 0.95em; }
        .map-legend .gradient-bar {
            height: 12px;
            border-radius: 3px;
            margin-bottom: 4px;
        }
        .map-legend .gradient-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.85em;
            color: #495057;
        }
        .map-legend .legend-swatch {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
        }
        .map-legend .legend-row {
            white-space: nowrap;
            margin-bottom: 3px;
        }
        .track-info {
            position: absolute;
            top: 60px;
            left: 10px;
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            padding: 6px 10px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.85em;
            display: none;
        }
        .track-info button {
            margin-left: 8px;
            border: none;
            background: none;
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }
        .notam-controls {
            position: absolute;
            bottom: 20px;
            right: 10px;
            z-index: 1000;
            max-width: 260px;
            background: rgba(255,255,255,0.95);
            padding: 8px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.85em;
        }
        .notam-controls .notam-meta {
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 3px;
        }
        .leaflet-popup-content .notam-text {
            font-size: 0.85em;
            max-height: 200px;
            overflow-y: auto;
            display: block;
            margin-top: 4px;
        }

        /* Su schermi molto stretti la formula "centrata con margine calcolato"
           non lascia spazio a sufficienza: la navbar scende sotto il controllo
           zoom di Leaflet invece di provare a stargli accanto. */
        @media (max-width: 480px) {
            .nav-links {
                top: 82px;
                left: 8px;
                right: 8px;
                transform: none;
                width: auto;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <?php render_nav('map.php'); ?>
    <div id="map"></div>

    <!-- Info layer opzionali (attivabili dal selettore layer in alto a destra) -->
    <div class="notam-controls">
        <div class="notam-meta">
            <?php if ($notamUpdatedAt): ?>
                🚧 NOTAM: <?= count($notamAreas) ?> aree · agg. <?= htmlspecialchars(formatDateIt($notamUpdatedAt)) ?><br>
            <?php else: ?>
                🚧 NOTAM: cache non ancora popolata.<br>
            <?php endif; ?>
            Fonti: <a href="https://notaminfo.com/ITALYMAP" target="_blank">notaminfo.com</a> ·
            <a href="https://dataspace.copernicus.eu/" target="_blank">Copernicus</a> ·
            <a href="https://openweathermap.org/" target="_blank">OpenWeather</a>
        </div>
    </div>

    <!-- Legenda colore dinamica -->
    <div class="map-legend" id="mapLegend"></div>

    <!-- Traccia storica attiva -->
    <div class="track-info" id="trackInfo">
        🛰️ Traccia: <span id="trackHexLabel"></span>
        <button type="button" onclick="hideTrack()">✖ Nascondi</button>
    </div>

    <!-- Controlli temporali -->
    <div class="time-controls">
        <div class="quick-buttons">
            <a href="#" data-period="today">Oggi</a>
            <a href="#" data-period="week">7 giorni</a>
            <a href="#" data-period="month">Mese</a>
            <a href="#" data-period="year">Anno</a>
            <a href="#" data-period="all">Sempre</a>
        </div>
        <label>Unità:
            <select id="timeUnit">
                <option value="hours">Ore</option>
                <option value="days" selected>Giorni</option>
                <option value="weeks">Settimane</option>
                <option value="months">Mesi</option>
            </select>
        </label>
        <label>Periodo:
            <input type="range" id="timeSlider" min="1" max="3650" value="7" style="width: 200px;">
            <span id="sliderValue">7</span>
        </label>
        <label>Colora per:
            <select id="colorMode">
                <option value="none">Nessuna (icona standard)</option>
                <option value="alt">Altitudine</option>
                <option value="speed">Velocità</option>
                <option value="country">Nazionalità</option>
                <option value="rarity">Rarità</option>
            </select>
        </label>
        <label>
            <input type="checkbox" id="emergencyOnly">
            🚨 Solo squawk emergenza
        </label>
    </div>

    <script>
        const map = L.map('map').setView([42.5, 12.5], 6);

        const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const satelliteLayer = L.tileLayer('satellite_tile.php?z={z}&x={x}&y={y}', {
            minZoom: 4,
            maxZoom: 16,
            attribution: 'Immagini satellitari: <a href="https://dataspace.copernicus.eu/" target="_blank">Copernicus Sentinel-2</a>'
        });

        const weatherLayerDefs = {
            '☁️ Nuvole': 'clouds_new',
            '🌧️ Precipitazioni': 'precipitation_new',
            '💨 Vento': 'wind_new',
            '🌡️ Temperatura': 'temp_new'
        };
        const weatherLayers = {};
        Object.keys(weatherLayerDefs).forEach(label => {
            weatherLayers[label] = L.tileLayer('weather_tile.php?layer=' + weatherLayerDefs[label] + '&z={z}&x={x}&y={y}', {
                maxZoom: 18,
                opacity: 0.7,
                attribution: 'Meteo: <a href="https://openweathermap.org/" target="_blank">OpenWeather</a>'
            });
        });

        const planeIcon = L.icon({
            iconUrl: 'leaflet/marker-icon-2x-blue.png',
            shadowUrl: 'leaflet/marker-shadow.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowSize: [41, 41]
        });

        const allMarkers = <?= $markersJson ?>;
        let layerGroup = L.layerGroup().addTo(map);
        let trackLayerGroup = L.layerGroup().addTo(map);
        const focusHex = <?= json_encode($_GET['focus'] ?? '') ?>;

        // ---------------- Layer NOTAM (aree geografiche) ----------------
        const notamAreas = <?= $notamAreasJson ?>;
        let notamLayerGroup = L.layerGroup();

        function notamPopup(n) {
            return `
                <b>${n.reference || n.id}</b><br>
                ${n.meaning || ''}<br>
                <small>FL ${n.fl_lower ?? '?'} - ${n.fl_upper ?? '?'}</small><br>
                <small>${n.validity || ''}</small>
                <span class="notam-text">${n.notam_text || ''}</span>
            `;
        }

        function renderNotamLayer() {
            notamLayerGroup.clearLayers();
            notamAreas.forEach(n => {
                let shape;
                if (n.area_type === 'circle' && n.radius_nm) {
                    shape = L.circle([n.lat, n.lon], {
                        radius: n.radius_nm * 1852,
                        color: '#dc3545',
                        weight: 2,
                        opacity: 0.8,
                        dashArray: '6, 4',
                        fillColor: '#dc3545',
                        fillOpacity: 0.12
                    });
                } else if (n.area_type === 'polygon' && Array.isArray(n.polygon)) {
                    shape = L.polygon(n.polygon, {
                        color: '#dc3545',
                        weight: 2,
                        opacity: 0.8,
                        dashArray: '6, 4',
                        fillColor: '#dc3545',
                        fillOpacity: 0.12
                    });
                } else {
                    return;
                }
                shape.bindPopup(notamPopup(n), {maxWidth: 400});
                notamLayerGroup.addLayer(shape);
            });
        }
        renderNotamLayer();
        // ------------------------------------------------------------------

        // ---------------- Selettore layer unificato ----------------
        const baseLayers = {
            '🗺️ Stradale (OSM)': osmLayer,
            '🛰️ Satellite (Copernicus)': satelliteLayer
        };
        const overlayLayers = Object.assign({
            '🚧 NOTAM (Italia)': notamLayerGroup
        }, weatherLayers);
        L.control.layers(baseLayers, overlayLayers, {collapsed: true}).addTo(map);
        // ------------------------------------------------------------------

        // ---------------- Colorazione dinamica dei pin ----------------
        const ALT_MAX = 45000;   // ft, tetto usato per la scala
        const SPEED_MAX = 600;   // kt, tetto usato per la scala
        const RARITY_COLORS = {
            'Mythic': '#dc3545', 'Legendary': '#ff8c00', 'Epic': '#6f42c1',
            'Rare': '#007bff', 'Uncommon': '#28a745', 'Common': '#6c757d'
        };

        function gradientColor(value, max) {
            const ratio = Math.max(0, Math.min(1, (value || 0) / max));
            const hue = 240 - ratio * 240; // blu (basso) -> rosso (alto)
            return `hsl(${hue}, 85%, 48%)`;
        }
        function countryColor(code) {
            const c = code || 'ZZ';
            let hash = 0;
            for (let i = 0; i < c.length; i++) hash = c.charCodeAt(i) + ((hash << 5) - hash);
            const hue = Math.abs(hash) % 360;
            return `hsl(${hue}, 65%, 48%)`;
        }
        function rarityColor(r) {
            return RARITY_COLORS[r] || '#adb5bd';
        }

        function markerColor(mode, m) {
            switch (mode) {
                case 'alt': return gradientColor(m.alt_ft, ALT_MAX);
                case 'speed': return gradientColor(m.gs_kt, SPEED_MAX);
                case 'country': return countryColor(m.country);
                case 'rarity': return rarityColor(m.rarity);
                default: return null;
            }
        }

        function updateLegend(mode, visibleMarkers) {
            const legend = document.getElementById('mapLegend');
            if (mode === 'none') {
                legend.style.display = 'none';
                legend.innerHTML = '';
                return;
            }
            legend.style.display = 'block';
            if (mode === 'alt' || mode === 'speed') {
                const max = mode === 'alt' ? ALT_MAX : SPEED_MAX;
                const unit = mode === 'alt' ? 'ft' : 'kt';
                const title = mode === 'alt' ? 'Altitudine' : 'Velocità';
                const stops = [0, 0.25, 0.5, 0.75, 1].map(r => `hsl(${240 - r * 240}, 85%, 48%)`).join(', ');
                legend.innerHTML = `
                    <h4>${title}</h4>
                    <div class="gradient-bar" style="background: linear-gradient(to right, ${stops});"></div>
                    <div class="gradient-labels"><span>0 ${unit}</span><span>${max.toLocaleString('it-IT')}+ ${unit}</span></div>
                `;
            } else if (mode === 'rarity') {
                let html = '<h4>Rarità</h4>';
                Object.keys(RARITY_COLORS).forEach(r => {
                    html += `<div class="legend-row"><span class="legend-swatch" style="background:${RARITY_COLORS[r]};"></span>${r}</div>`;
                });
                legend.innerHTML = html;
            } else if (mode === 'country') {
                const seen = {};
                visibleMarkers.forEach(m => { seen[m.country || 'ZZ'] = (m.country_flag || '') + ' ' + (m.country || 'ZZ'); });
                const codes = Object.keys(seen).sort();
                let html = '<h4>Nazionalità</h4>';
                codes.forEach(code => {
                    html += `<div class="legend-row"><span class="legend-swatch" style="background:${countryColor(code)};"></span>${seen[code]}</div>`;
                });
                legend.innerHTML = html || '<h4>Nazionalità</h4><em>Nessun contatto</em>';
            }
        }
        // ----------------------------------------------------------------

        // Funzione per calcolare il timestamp di cutoff
        function getCutoff(unit, value) {
            const now = new Date();
            switch(unit) {
                case 'hours':
                    return new Date(now.getTime() - value * 60 * 60 * 1000);
                case 'days':
                    return new Date(now.getTime() - value * 24 * 60 * 60 * 1000);
                case 'weeks':
                    return new Date(now.getTime() - value * 7 * 24 * 60 * 60 * 1000);
                case 'months':
                    return new Date(now.getFullYear(), now.getMonth() - value, now.getDate());
                default:
                    return new Date(0);
            }
        }

        // ---------------- Traccia storica on-demand ----------------
        function hideTrack() {
            trackLayerGroup.clearLayers();
            document.getElementById('trackInfo').style.display = 'none';
        }

        function showTrack(hex, label) {
            trackLayerGroup.clearLayers();
            const unit = document.getElementById('timeUnit').value;
            const value = parseInt(document.getElementById('sliderValue').textContent, 10);
            const cutoff = getCutoff(unit, value);

            fetch('track.php?hex=' + encodeURIComponent(hex) + '&from=' + encodeURIComponent(cutoff.toISOString()))
                .then(r => r.json())
                .then(points => {
                    if (!Array.isArray(points) || points.length < 2) {
                        alert('Traccia non disponibile per il periodo selezionato (servono almeno 2 posizioni).');
                        return;
                    }
                    const latlngs = points.map(p => [p.lat, p.lon]);
                    const line = L.polyline(latlngs, {color: '#ff6600', weight: 3, opacity: 0.8});
                    trackLayerGroup.addLayer(line);
                    points.forEach(p => {
                        const dot = L.circleMarker([p.lat, p.lon], {
                            radius: 3, color: '#ff6600', fillColor: '#ff6600', fillOpacity: 0.9, weight: 1
                        }).bindPopup(`Alt: ${p.alt_ft || '?'} ft, Vel: ${p.gs_kt || '?'} kt<br><small>${p.first_seen_utc}</small>`);
                        trackLayerGroup.addLayer(dot);
                    });
                    map.fitBounds(line.getBounds(), {padding: [30, 30]});

                    document.getElementById('trackHexLabel').textContent = (label || hex) + ' (' + points.length + ' punti)';
                    document.getElementById('trackInfo').style.display = 'block';
                })
                .catch(() => alert('Errore nel caricamento della traccia.'));
        }
        // -------------------------------------------------------------

        const EMERGENCY_SQUAWKS = ['7500', '7600', '7700'];
        const EMERGENCY_SQUAWK_LABELS = {
            '7500': 'Interferenza illecita (dirottamento)',
            '7600': 'Guasto radio / perdita comunicazioni',
            '7700': 'Emergenza generale'
        };
        function isEmergencySquawk(squawk) {
            return EMERGENCY_SQUAWKS.includes(String(squawk || '').trim());
        }

        function updateMarkers() {
            const unit = document.getElementById('timeUnit').value;
            const value = parseInt(document.getElementById('sliderValue').textContent, 10);
            const cutoff = getCutoff(unit, value);
            const colorMode = document.getElementById('colorMode').value;
            const emergencyOnly = document.getElementById('emergencyOnly').checked;

            // Filtra marker in base a last_seen_utc (ed eventualmente solo squawk di emergenza)
            const filtered = allMarkers.filter(m => {
                const date = new Date(m.last_seen_utc);
                if (date < cutoff) return false;
                if (emergencyOnly && !isEmergencySquawk(m.squawk)) return false;
                return true;
            });

            layerGroup.clearLayers();
            hideTrack();

            filtered.forEach(m => {
                const popupContent = `
                    <b>${m.callsign || 'N/D'}</b><br>
                    ${m.silhouette ? `<img src="${m.silhouette}" style="height:20px;vertical-align:middle;"><br>` : ''}
                    HEX: <a href="${m.hex_url}" target="_blank">${m.hex}</a><br>
                    Naz: ${m.country_flag || ''} ${m.country || '-'}<br>
                    Reg: ${m.reg_url ? `<a href="${m.reg_url}" target="_blank">${m.reg}</a>` : (m.reg || '-')}<br>
                    Modello: ${m.model_url ? `<a href="${m.model_url}" target="_blank">${m.model_t}</a>` : (m.model_t || '-')}<br>
                    Alt: ${m.alt_ft || '?'} ft, Vel: ${m.gs_kt || '?'} kt<br>
                    Squawk: ${isEmergencySquawk(m.squawk) ? `<strong style="color:#dc3545;">⚠️ ${m.squawk} — ${EMERGENCY_SQUAWK_LABELS[String(m.squawk).trim()]}</strong>` : (m.squawk || '-')} | Rarità: ${m.rarity || '-'} | Ground: ${m.ground ? 'Sì' : 'No'}<br>
                    <small>${m.last_seen_it}</small><br>
                    <a href="#" onclick="showTrack('${m.hex}'); return false;">🛰️ Mostra traccia storica</a><br>
                    <a href="heatmap.php?hex=${m.hex}" target="_blank">🔥 Heatmap di questo contatto</a>
                `;

                const emergency = isEmergencySquawk(m.squawk);
                const color = markerColor(colorMode, m);
                let marker;
                if (emergency) {
                    // Uno squawk di emergenza ha sempre priorità visiva, indipendentemente dalla colorazione attiva
                    marker = L.circleMarker([m.lat, m.lon], {
                        radius: 12,
                        fillColor: '#dc3545',
                        color: '#ffffff',
                        weight: 2,
                        fillOpacity: 0.95
                    }).bindPopup(popupContent);
                } else if (color) {
                    marker = L.circleMarker([m.lat, m.lon], {
                        radius: 8,
                        fillColor: color,
                        color: '#ffffff',
                        weight: 1.5,
                        fillOpacity: 0.9
                    }).bindPopup(popupContent);
                } else {
                    marker = L.marker([m.lat, m.lon], {icon: planeIcon}).bindPopup(popupContent);
                }
                layerGroup.addLayer(marker);

                if (focusHex && m.hex.toUpperCase() === focusHex.toUpperCase()) {
                    marker.openPopup();
                }
            });

            updateLegend(colorMode, filtered);

            if (filtered.length === 0) {
                alert('Nessun contatto nel periodo selezionato.');
            }
        }

        // Aggiorna slider in base ai pulsanti rapidi
        document.querySelectorAll('.quick-buttons a').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const period = btn.dataset.period;
                const unitSelect = document.getElementById('timeUnit');
                const slider = document.getElementById('timeSlider');
                const sliderVal = document.getElementById('sliderValue');
                switch(period) {
                    case 'today':
                        unitSelect.value = 'days';
                        slider.value = 1;
                        break;
                    case 'week':
                        unitSelect.value = 'days';
                        slider.value = 7;
                        break;
                    case 'month':
                        unitSelect.value = 'months';
                        slider.value = 1;
                        break;
                    case 'year':
                        unitSelect.value = 'months';
                        slider.value = 12;
                        break;
                    case 'all':
                        unitSelect.value = 'days';
                        slider.value = 3650;
                        break;
                }
                sliderVal.textContent = slider.value;
                updateMarkers();
            });
        });

        // Slider e unità
        document.getElementById('timeSlider').addEventListener('input', function() {
            document.getElementById('sliderValue').textContent = this.value;
            updateMarkers();
        });
        document.getElementById('timeUnit').addEventListener('change', updateMarkers);
        document.getElementById('colorMode').addEventListener('change', updateMarkers);
        document.getElementById('emergencyOnly').addEventListener('change', updateMarkers);

        // Inizializza
        updateMarkers();
    </script>
</body>
</html>
