<?php
// /var/www/html/milair_ita/export.php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';
$format = $_GET['format'] ?? 'csv';

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$hex      = $_GET['hex']       ?? '';
$callsign = $_GET['callsign']  ?? '';
$reg      = $_GET['reg']       ?? '';
$model    = $_GET['model']     ?? '';
$note_search = $_GET['note']   ?? '';
$rarity   = $_GET['rarity']    ?? '';

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

    $where = [];
    $params = [];
    if ($dateFrom) { $where[] = "ai.last_seen_utc >= :from"; $params[':from'] = $dateFrom . ' 00:00:00'; }
    if ($dateTo)   { $where[] = "ai.last_seen_utc <= :to";   $params[':to']   = $dateTo . ' 23:59:59'; }
    if ($hex)      { $where[] = "ai.hex LIKE :hex";          $params[':hex']  = $hex . '%'; }
    if ($callsign) { $where[] = "ai.callsign LIKE :cs";      $params[':cs']   = $callsign . '%'; }
    if ($reg)      { $where[] = "ai.reg LIKE :reg";          $params[':reg']  = $reg . '%'; }
    if ($model)    { $where[] = "ai.model_t LIKE :model";    $params[':model']= '%' . $model . '%'; }
    if ($note_search) { $where[] = "n.note LIKE :note";      $params[':note'] = '%' . $note_search . '%'; }
    if ($rarity)   { $where[] = "r.rarity = :rarity";        $params[':rarity'] = $rarity; }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT ai.hex, ai.callsign, ai.reg, ai.model_t,
               ai.first_seen_utc AS ident_first_seen, ai.last_seen_utc AS ident_last_seen,
               a.first_seen_utc AS hex_first_seen, a.last_seen_utc AS hex_last_seen,
               a.seen_count AS total_days, a.max_consecutive_days,
               r.rarity, n.note
        FROM aircraft_identity ai
        JOIN aircraft a ON ai.hex = a.hex
        LEFT JOIN rarity_cache r ON a.hex = r.hex
        LEFT JOIN notes n ON a.hex = n.hex
        $whereSQL
        ORDER BY ai.last_seen_utc DESC
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $result = $stmt->execute();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="milair_export.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['hex','callsign','reg','model_t','ident_first_seen','ident_last_seen',
                       'hex_first_seen','hex_last_seen','total_days','max_consecutive_days','rarity','note']);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // Versione stampabile (PDF via browser) – colonna Mappa rimossa
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Report MILAIR ITA</title>
        <link rel="stylesheet" href="style.css">
        <style>
            @media print { body { margin: 10mm; } }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 4px; font-size: 0.8em; }
            th { background: #eee; }
        </style>
    </head>
    <body onload="window.print()">
    <h2>Report Voli Militari – Italia</h2>
    <div class="table-scroll">
    <table>
    <thead><tr>
        <th>HEX</th>
        <th>Callsign</th>
        <th>Reg</th>
        <th>Modello</th>
        <th>Primo id.</th>
        <th>Ultimo id.</th>
        <th>Primo vel.</th>
        <th>Ultimo vel.</th>
        <th>Giorni tot.</th>
        <th>Max consec.</th>
        <th>Rarità</th>
        <th>Note</th>
    </tr></thead>
    <tbody>
    <?php while ($row = $result->fetchArray(SQLITE3_ASSOC)): ?>
    <tr>
        <td><?= htmlspecialchars($row['hex']) ?></td>
        <td><?= htmlspecialchars($row['callsign']) ?></td>
        <td><?= htmlspecialchars($row['reg']) ?></td>
        <td>
            <?php if (!empty($row['model_t'])): ?>
                <?= htmlspecialchars($row['model_t']) ?>
            <?php else: ?>
                <?= htmlspecialchars($row['model_t']) ?>
            <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($row['ident_first_seen']) ?></td>
        <td><?= htmlspecialchars($row['ident_last_seen']) ?></td>
        <td><?= htmlspecialchars($row['hex_first_seen']) ?></td>
        <td><?= htmlspecialchars($row['hex_last_seen']) ?></td>
        <td><?= $row['total_days'] ?></td>
        <td><?= $row['max_consecutive_days'] ?></td>
        <td><?= $row['rarity'] ?></td>
        <td><?= htmlspecialchars($row['note'] ?? '') ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
    </table>
    </div>
    </body>
    </html>
    <?php
} catch (Exception $e) {
    http_response_code(500);
    echo "Errore: " . htmlspecialchars($e->getMessage());
}
