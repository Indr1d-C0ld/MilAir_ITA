<?php
// Restituisce in JSON la traccia storica (posizioni successive) di un singolo hex,
// usata dalla mappa (map.php) per disegnare il percorso on-demand di un aeromobile.
require_once __DIR__ . '/auth.php';
auth_bootstrap();

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

header('Content-Type: application/json; charset=utf-8');

$hex = strtolower(trim($_GET['hex'] ?? ''));
if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
    http_response_code(400);
    echo json_encode(['error' => 'hex non valido']);
    exit;
}

$where = 'hex = :hex';
$params = [':hex' => $hex];

$from = trim($_GET['from'] ?? '');
if ($from !== '') {
    $ts = strtotime($from);
    if ($ts !== false) {
        $where .= ' AND first_seen_utc >= :from';
        $params[':from'] = gmdate('Y-m-d H:i:s', $ts) . ' UTC';
    }
}

try {
    $stmt = $db->prepare("SELECT first_seen_utc, lat, lon, alt_ft, gs_kt
        FROM events
        WHERE $where AND lat IS NOT NULL AND lon IS NOT NULL
        ORDER BY first_seen_utc ASC
        LIMIT 3000");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $res = $stmt->execute();

    $points = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $points[] = $row;
    }
    echo json_encode($points);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
