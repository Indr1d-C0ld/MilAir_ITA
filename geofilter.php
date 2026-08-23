<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);

// Crea tabella
$db->exec("CREATE TABLE IF NOT EXISTS geo_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    note TEXT,
    geojson TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['save_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $geojson = $_POST['geojson'] ?? '';
        if ($name !== '' && $geojson !== '') {
            $stmt = $db->prepare("INSERT INTO geo_profiles (name, note, geojson) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $name);
            $stmt->bindValue(2, $note);
            $stmt->bindValue(3, $geojson);
            $stmt->execute();
        }
        header('Location: geofilter.php');
        exit;
    } elseif (isset($_POST['delete_profile'])) {
        $id = (int)($_POST['id'] ?? 0);
        $db->exec("DELETE FROM geo_profiles WHERE id = " . $id);
        header('Location: geofilter.php');
        exit;
    } elseif (isset($_POST['import_geojson'])) {
        $name = trim($_POST['import_name'] ?? '');
        $geojson = trim($_POST['import_geojson'] ?? '');
        if ($name !== '' && $geojson !== '') {
            $stmt = $db->prepare("INSERT INTO geo_profiles (name, note, geojson) VALUES (?, 'Importato', ?)");
            $stmt->bindValue(1, $name);
            $stmt->bindValue(2, $geojson);
            $stmt->execute();
        }
        header('Location: geofilter.php');
        exit;
    }
}

// Carica profili
$profiles = $db->query("SELECT * FROM geo_profiles ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Filtro Geografico – MILAIR ITA</title>
    <link rel="stylesheet" href="leaflet/leaflet.css" />
    <!-- Leaflet.draw da CDN per risolvere problema icone -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />
    <link rel="stylesheet" href="style.css">
    <script src="leaflet/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <style>
        #map { height: 500px; width: 100%; margin-bottom: 20px; }
        .geo-form { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .geo-form textarea { width: 100%; height: 80px; }
        .profile-list { display: flex; flex-wrap: wrap; gap: 15px; }
        .profile-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            width: 250px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .profile-card h4 { margin: 0 0 5px; }
        .profile-card .actions { margin-top: 5px; }
        .profile-card .actions a, .profile-card .actions button {
            font-size: 0.9em;
            margin-right: 5px;
        }
        @media (max-width: 768px) {
            .profile-card { width: 100%; }
        }
    </style>
</head>
<body>
    <?php render_nav('geofilter.php'); ?>

    <h2>🌍 Filtro Geografico</h2>
    <p>Disegna uno o più poligoni sulla mappa, poi salvali come profilo. Potrai applicare il filtro dalla tabella.</p>

    <!-- Mappa per disegno -->
    <div id="map"></div>

    <!-- Form salvataggio profilo corrente -->
    <div class="geo-form">
        <form method="post" id="saveForm">
            <?= csrf_field() ?>
            <input type="hidden" name="save_profile" value="1">
            <label>Nome profilo: <input type="text" name="name" required></label>
            <label>Nota: <input type="text" name="note"></label>
            <input type="hidden" name="geojson" id="geojsonInput">
            <button type="submit">Salva profilo</button>
        </form>
        <small>Disegna un poligono con il pulsante a sinistra in alto sulla mappa.</small>
    </div>

    <!-- Import GeoJSON -->
    <div class="geo-form">
        <h3>Importa GeoJSON</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="import_geojson" value="1">
            <label>Nome: <input type="text" name="import_name" required></label><br>
            <label>Incolla GeoJSON:<br><textarea name="import_geojson" required></textarea></label><br>
            <button type="submit">Importa</button>
        </form>
    </div>

    <!-- Elenco profili salvati -->
    <h3>Profili salvati</h3>
    <div class="profile-list">
        <?php while ($p = $profiles->fetchArray(SQLITE3_ASSOC)): ?>
            <div class="profile-card">
                <h4><?= htmlspecialchars($p['name']) ?></h4>
                <small><?= htmlspecialchars($p['note'] ?? '') ?></small>
                <div class="actions">
                    <a href="index.php?geofilter=<?= $p['id'] ?>" target="_blank">Applica</a>
                    <a href="export_geojson.php?id=<?= $p['id'] ?>" target="_blank">Esporta</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare profilo?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_profile" value="1">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" style="border:none;background:none;color:#dc3545;cursor:pointer;">Elimina</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <script>
        // Inizializza mappa
        const map = L.map('map').setView([42.5, 12.5], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // FeatureGroup per i layer disegnati
        const drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        // Controllo disegno (Leaflet.draw)
        const drawControl = new L.Control.Draw({
            edit: { featureGroup: drawnItems },
            draw: {
                polygon: { allowIntersection: false, showArea: true },
                rectangle: {},
                polyline: false,
                circle: false,
                marker: false,
                circlemarker: false
            }
        });
        map.addControl(drawControl);

        // Evento layer aggiunto
        map.on('draw:created', function (e) {
            const layer = e.layer;
            drawnItems.addLayer(layer);
            updateGeojson();
        });

        // Evento modifica/elimina layer
        map.on('draw:edited', function () { updateGeojson(); });
        map.on('draw:deleted', function () { updateGeojson(); });

        function updateGeojson() {
            const geojson = drawnItems.toGeoJSON();
            document.getElementById('geojsonInput').value = JSON.stringify(geojson);
        }
    </script>
</body>
</html>
