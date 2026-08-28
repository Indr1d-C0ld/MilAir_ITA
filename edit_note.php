<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$hex = $_GET['hex'] ?? $_POST['hex'] ?? '';
if (!$hex) { die("HEX mancante."); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $note = $_POST['note'] ?? '';
    try {
        $db = new SQLite3($dbPath);
        $db->busyTimeout(5000);
        $db->exec("CREATE TABLE IF NOT EXISTS notes (hex TEXT PRIMARY KEY, note TEXT)");
        $stmt = $db->prepare("INSERT OR REPLACE INTO notes (hex, note) VALUES (?, ?)");
        $stmt->bindValue(1, $hex);
        $stmt->bindValue(2, $note);
        $stmt->execute();
        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        die("Errore salvataggio: " . htmlspecialchars($e->getMessage()));
    }
}

// Leggi nota esistente
$note = '';
try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
    $stmt = $db->prepare("SELECT note FROM notes WHERE hex = ?");
    $stmt->bindValue(1, $hex);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($res) $note = $res['note'];
} catch (Exception $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modifica nota per <?= htmlspecialchars($hex) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h2>Modifica nota per HEX <?= htmlspecialchars($hex) ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <textarea name="note" rows="5" cols="50"><?= htmlspecialchars($note) ?></textarea><br>
        <button type="submit">Salva</button>
        <a href="index.php" class="btn">Annulla</a>
    </form>
</body>
</html>