<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

// Heatmap opzionalmente filtrata su un singolo contatto (hex)
$hexFilter = strtolower(trim($_GET['hex'] ?? ''));
if (!preg_match('/^[0-9a-f]{6}$/', $hexFilter)) {
    $hexFilter = '';
}

if ($hexFilter !== '') {
    $stmt = $db->prepare("SELECT lat, lon, first_seen_utc FROM events WHERE lat IS NOT NULL AND lon IS NOT NULL AND hex = :hex LIMIT 50000");
    $stmt->bindValue(':hex', $hexFilter);
    $res = $stmt->execute();
} else {
    $res = $db->query("SELECT lat, lon, first_seen_utc FROM events WHERE lat IS NOT NULL AND lon IS NOT NULL LIMIT 50000");
}

$points = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $points[] = [
        'lat' => $row['lat'],
        'lon' => $row['lon'],
        'date' => $row['first_seen_utc']
    ];
}
$pointsJson = json_encode($points);

$pageTitle = $hexFilter !== '' ? 'Heatmap — HEX ' . strtoupper($hexFilter) : 'Heatmap Voli Militari';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="leaflet/leaflet.css" />
    <link rel="stylesheet" href="style.css">
    <script src="leaflet/leaflet.js"></script>
    <script src="leaflet/leaflet-heat.js"></script>
    <style>
        body { margin: 0; padding: 0; }
        #map { height: calc(100vh - 60px); width: 100%; }
        /* Navbar centrata in alto, larghezza limitata per non invadere l'angolo
           dove Leaflet mette il controllo zoom (in alto a sinistra). */
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
        .quick-buttons a {
            padding: 4px 8px;
            background: #007bff;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
        }
        .quick-buttons a:hover { background: #0056b3; }
        .hex-filter-banner {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            padding: 8px 12px;
            border-radius: 5px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 0.9em;
        }
        .hex-filter-banner a { color: #007bff; text-decoration: none; margin-left: 8px; }
        .hex-filter-banner a:hover { text-decoration: underline; }

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
    <?php render_nav('heatmap.php'); ?>
    <div id="map"></div>

    <?php if ($hexFilter !== ''): ?>
    <div class="hex-filter-banner">
        🎯 Heatmap singolo contatto: <strong><?= htmlspecialchars(strtoupper($hexFilter)) ?></strong>
        <a href="heatmap.php">✖ Vedi heatmap globale</a>
    </div>
    <?php endif; ?>

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
    </div>

    <script>
        const map = L.map('map').setView([42.5, 12.5], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const allPoints = <?= $pointsJson ?>;
        const isHexFiltered = <?= json_encode($hexFilter !== '') ?>;
        let heatLayer = null;
        let hasFitBounds = false;

        function getCutoff(unit, value) {
            const now = new Date();
            switch(unit) {
                case 'hours': return new Date(now.getTime() - value * 60 * 60 * 1000);
                case 'days': return new Date(now.getTime() - value * 24 * 60 * 60 * 1000);
                case 'weeks': return new Date(now.getTime() - value * 7 * 24 * 60 * 60 * 1000);
                case 'months': return new Date(now.getFullYear(), now.getMonth() - value, now.getDate());
                default: return new Date(0);
            }
        }

        function updateHeatmap() {
            const unit = document.getElementById('timeUnit').value;
            const value = parseInt(document.getElementById('sliderValue').textContent, 10);
            const cutoff = getCutoff(unit, value);

            const filtered = allPoints.filter(p => {
                const date = new Date(p.date);
                return date >= cutoff;
            });

            if (heatLayer) {
                map.removeLayer(heatLayer);
            }

            if (filtered.length > 0) {
                const points = filtered.map(p => [p.lat, p.lon]);
                heatLayer = L.heatLayer(points, {
                    radius: isHexFiltered ? 25 : 18,
                    blur: isHexFiltered ? 15 : 12,
                    maxZoom: 10,
                    max: isHexFiltered ? 0.5 : 0.08
                }).addTo(map);

                // Per un singolo contatto centra automaticamente sulla sua area di attività
                if (isHexFiltered && !hasFitBounds) {
                    map.fitBounds(points, { padding: [40, 40], maxZoom: 11 });
                    hasFitBounds = true;
                }
            } else {
                alert('Nessun punto nel periodo selezionato.');
            }
        }

        document.querySelectorAll('.quick-buttons a').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const period = btn.dataset.period;
                const unitSelect = document.getElementById('timeUnit');
                const slider = document.getElementById('timeSlider');
                const sliderVal = document.getElementById('sliderValue');
                switch(period) {
                    case 'today': unitSelect.value = 'days'; slider.value = 1; break;
                    case 'week': unitSelect.value = 'days'; slider.value = 7; break;
                    case 'month': unitSelect.value = 'months'; slider.value = 1; break;
                    case 'year': unitSelect.value = 'months'; slider.value = 12; break;
                    case 'all': unitSelect.value = 'days'; slider.value = 3650; break;
                }
                sliderVal.textContent = slider.value;
                updateHeatmap();
            });
        });

        document.getElementById('timeSlider').addEventListener('input', function() {
            document.getElementById('sliderValue').textContent = this.value;
            updateHeatmap();
        });
        document.getElementById('timeUnit').addEventListener('change', updateHeatmap);

        updateHeatmap();
    </script>
</body>
</html>
