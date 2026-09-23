<?php
// export.php - Esporta in CSV (o in versione stampabile) esattamente le righe che
// la tabella di index.php mostra con gli stessi parametri: stessa pipeline
// (table_lib.php), quindi stessi filtri, pulsanti rapidi, correzioni manuali,
// note automatiche e ordinamento. Nessuna paginazione: si esporta tutto.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/table_lib.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';
$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
$params = table_params($_GET);

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $rows = table_load($db, $params)['rows'];
} catch (Exception $e) {
    http_response_code(500);
    error_log('export.php: ' . $e->getMessage());
    echo "Errore durante l'esportazione. Riprova tra qualche minuto.";
    exit;
}

// Colonne esportate: le prime 12 nell'ordine storico del file (compatibilità con
// fogli già impostati), poi nazionalità e operatore. "note" è la nota mostrata in
// tabella (manuale + automatiche), non la sola nota manuale.
$columns = [
    'hex'                  => 'hex',
    'callsign'             => 'callsign',
    'reg'                  => 'reg',
    'model_t'              => 'model_t',
    'ident_first_seen'     => 'ident_first_seen',
    'ident_last_seen'      => 'ident_last_seen',
    'hex_first_seen'       => 'hex_first_seen',
    'hex_last_seen'        => 'hex_last_seen',
    'total_days'           => 'total_days',
    'max_consecutive_days' => 'max_consecutive_days',
    'rarity'               => 'rarity',
    'note'                 => 'combined_note',
    'country'              => 'country',
    'operator'             => 'operator',
];

/**
 * Neutralizza la "formula injection": un foglio di calcolo esegue come formula
 * una cella che inizia con = + - @ (o tab/CR). Callsign e registrazioni arrivano
 * dai transponder, le note dai collaboratori: nessuno dei due è fidato.
 */
function csv_safe($v): string {
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="milair_export.csv"');
    $out = fopen('php://output', 'w');
    // Il quinto argomento (escape) va passato esplicitamente: da PHP 8.4 ometterlo
    // è deprecato; '' dà un CSV conforme a RFC 4180 (le virgolette si raddoppiano).
    fputcsv($out, array_keys($columns), ',', '"', '');
    foreach ($rows as $r) {
        $line = [];
        foreach ($columns as $field) {
            $line[] = csv_safe($r[$field] ?? '');
        }
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

// Versione stampabile (PDF via browser): date in ora italiana, come in tabella.
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
<p><?= number_format(count($rows), 0, ',', '.') ?> righe — generato il <?= htmlspecialchars(format_date_it(gmdate('Y-m-d H:i:s'))) ?></p>
<div class="table-scroll">
<table>
<thead><tr>
    <th>HEX</th>
    <th>Naz.</th>
    <th>Callsign</th>
    <th>Operatore</th>
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
<?php foreach ($rows as $row): ?>
<tr>
    <td><?= htmlspecialchars($row['hex']) ?></td>
    <td><?= htmlspecialchars($row['country'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['callsign'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['operator'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['reg'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['model_t'] ?? '') ?></td>
    <td><?= htmlspecialchars(format_date_it($row['ident_first_seen'])) ?></td>
    <td><?= htmlspecialchars(format_date_it($row['ident_last_seen'])) ?></td>
    <td><?= htmlspecialchars(format_date_it($row['hex_first_seen'])) ?></td>
    <td><?= htmlspecialchars(format_date_it($row['hex_last_seen'])) ?></td>
    <td><?= (int)$row['total_days'] ?></td>
    <td><?= (int)$row['max_consecutive_days'] ?></td>
    <td><?= htmlspecialchars($row['rarity'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['combined_note'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</body>
</html>
