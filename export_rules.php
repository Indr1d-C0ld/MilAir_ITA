<?php
// Esporta tutte le regole personalizzate (nazionalità, evidenziazione righe, note automatiche, contrassegni) in JSON.
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(5000);

$db->exec("CREATE TABLE IF NOT EXISTS country_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    country_code TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS row_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    bg_color TEXT,
    bold INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS note_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    note TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS marker_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    emoji TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    field TEXT NOT NULL,
    pattern TEXT NOT NULL,
    description TEXT
)");

function fetchRulesTable(SQLite3 $db, $table) {
    $rows = [];
    $res = $db->query("SELECT * FROM $table ORDER BY id");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        unset($r['id']);
        $rows[] = $r;
    }
    return $rows;
}

$export = [
    'exported_at'   => date('c'),
    'country_rules' => fetchRulesTable($db, 'country_rules'),
    'row_rules'     => fetchRulesTable($db, 'row_rules'),
    'note_rules'    => fetchRulesTable($db, 'note_rules'),
    'marker_rules'  => fetchRulesTable($db, 'marker_rules'),
    'alert_rules'   => fetchRulesTable($db, 'alert_rules'),
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="milair_rules_' . date('Ymd_His') . '.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
