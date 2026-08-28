<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$dbPath = __DIR__ . '/events.db';
$isAjax = isset($_POST['ajax']) || isset($_GET['ajax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'metodo non consentito']);
    } else {
        echo 'Metodo non consentito.';
    }
    exit;
}
require_csrf();

$hex = $_POST['hex'] ?? '';
$action = $_POST['action'] ?? 'add';
$returnUrl = $_POST['return'] ?? 'index.php';

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

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec("CREATE TABLE IF NOT EXISTS favorites (
        hex TEXT PRIMARY KEY,
        note TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    if ($action === 'remove') {
        $stmt = $db->prepare("DELETE FROM favorites WHERE hex = ?");
    } else {
        $stmt = $db->prepare("INSERT OR IGNORE INTO favorites (hex) VALUES (?)");
    }
    $stmt->bindValue(1, $hex);
    $stmt->execute();

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'hex' => $hex, 'action' => $action]);
        exit;
    }
    header('Location: ' . $returnUrl);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    die("Errore: " . htmlspecialchars($e->getMessage()));
}
