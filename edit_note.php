<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$hex = trim($_GET['hex'] ?? $_POST['hex'] ?? '');
if (!$hex) { die("HEX mancante."); }
// Pagina da cui si è arrivati (con i suoi filtri e la sua pagina): prima dopo il
// salvataggio si tornava sempre alla prima pagina di index.php senza filtri.
$returnUrl = safe_local_url($_GET['return'] ?? $_POST['return'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $note = trim(str_replace("\r\n", "\n", $_POST['note'] ?? ''));
    try {
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true); // senza, un errore di scrittura non arrivava al catch
        $db->busyTimeout(5000);
        $db->exec("CREATE TABLE IF NOT EXISTS notes (hex TEXT PRIMARY KEY, note TEXT)");
        if ($note === '') {
            // Nota svuotata = nota rimossa: prima restava una riga con testo vuoto
            // (22 righe così nel database), contata come "nota" in alcuni conteggi.
            $stmt = $db->prepare("DELETE FROM notes WHERE hex = ?");
            $stmt->bindValue(1, $hex);
        } else {
            $stmt = $db->prepare("INSERT OR REPLACE INTO notes (hex, note) VALUES (?, ?)");
            $stmt->bindValue(1, $hex);
            $stmt->bindValue(2, $note);
        }
        $stmt->execute();
        header('Location: ' . $returnUrl);
        exit;
    } catch (Exception $e) {
        error_log('edit_note.php: ' . $e->getMessage());
        die("Errore durante il salvataggio della nota.");
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
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modifica nota per <?= htmlspecialchars($hex) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php render_nav(); ?>
    <h2>Modifica nota per HEX <?= htmlspecialchars($hex) ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">
        <textarea name="note" rows="5" cols="50"><?= htmlspecialchars($note) ?></textarea><br>
        <small>Lascia vuoto e salva per rimuovere la nota.</small><br>
        <button type="submit">Salva</button>
        <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn">Annulla</a>
    </form>
</body>
</html>