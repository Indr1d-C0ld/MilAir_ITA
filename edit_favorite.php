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
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec("CREATE TABLE IF NOT EXISTS favorites (hex TEXT PRIMARY KEY, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $stmt = $db->prepare("UPDATE favorites SET note = ? WHERE hex = ?");
    $stmt->bindValue(1, $note);
    $stmt->bindValue(2, $hex);
    $stmt->execute();
    header("Location: favorites.php");
    exit;
}

$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$stmt = $db->prepare("SELECT note FROM favorites WHERE hex = ?");
$stmt->bindValue(1, $hex);
$res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$note = $res ? ($res['note'] ?? '') : '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota preferito per <?= htmlspecialchars($hex) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php render_nav(); ?>
    <h2>Nota preferito per HEX <?= htmlspecialchars($hex) ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <textarea name="note" rows="5" cols="50"><?= htmlspecialchars($note) ?></textarea><br>
        <button type="submit" class="btn">Salva</button>
        <a href="favorites.php" class="btn">Annulla</a>
    </form>
</body>
</html>
