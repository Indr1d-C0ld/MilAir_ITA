<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$isAjax = isset($_POST['ajax']) || isset($_GET['ajax']);
$emojiList = ['🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','⭐','💡','🔥','❄️','🚨','❓','🚁','✈️','🛩️','🚀','🛰️','🌍','🌎','🌏','🔔','📌','📎','🗂️','🏁','🚩'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emoji'])) {
    require_csrf();
    $hex = $_POST['hex'] ?? '';
    $returnUrl = $_POST['return'] ?? 'index.php';
    $emoji = $_POST['emoji'];

    if (!$hex) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'hex mancante']);
            exit;
        }
        header('Location: index.php');
        exit;
    }

    if ($emoji === '' || in_array($emoji, $emojiList, true)) {
        try {
            $db = new SQLite3($dbPath);
            $db->enableExceptions(true);
            $db->busyTimeout(5000);
            $db->exec("CREATE TABLE IF NOT EXISTS markers (
                hex TEXT PRIMARY KEY,
                emoji TEXT
            )");
            if ($emoji === '') {
                $stmt = $db->prepare("DELETE FROM markers WHERE hex = ?");
                $stmt->bindValue(1, $hex);
            } else {
                $stmt = $db->prepare("INSERT OR REPLACE INTO markers (hex, emoji) VALUES (?, ?)");
                $stmt->bindValue(1, $hex);
                $stmt->bindValue(2, $emoji);
            }
            $stmt->execute();
        } catch (Exception $e) {
            if ($isAjax) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit;
            }
            die("Errore: " . htmlspecialchars($e->getMessage()));
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'hex' => $hex, 'emoji' => $emoji]);
            exit;
        }
        header('Location: ' . $returnUrl);
        exit;
    } elseif ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'emoji non valida']);
        exit;
    }
}

if ($isAjax) {
    // Elenco emoji disponibili, per popolare il picker inline lato client (sola lettura)
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'emojiList' => $emojiList]);
    exit;
}

$hex = $_GET['hex'] ?? '';
$returnUrl = $_GET['return'] ?? 'index.php';
if (!$hex) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contrassegna HEX <?= htmlspecialchars($hex) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php render_nav(); ?>
    <h2>Contrassegna <?= htmlspecialchars($hex) ?></h2>
    <p>Scegli un'emoji:</p>
    <form method="post" action="toggle_marker.php">
        <?= csrf_field() ?>
        <input type="hidden" name="hex" value="<?= htmlspecialchars($hex) ?>">
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnUrl) ?>">
        <?php foreach ($emojiList as $emoji): ?>
            <button type="submit" name="emoji" value="<?= $emoji ?>" style="font-size:2em;margin:5px;"><?= $emoji ?></button>
        <?php endforeach; ?>
        <br><br>
        <button type="submit" name="emoji" value="" style="font-size:1.2em;">Rimuovi</button>
        <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn">Annulla</a>
    </form>
</body>
</html>
