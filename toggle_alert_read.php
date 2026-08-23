<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
require_role('collaboratore');

$isAjax = isset($_POST['ajax']) || isset($_GET['ajax']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}
require_csrf();

header('Content-Type: application/json');

if (isset($_POST['mark_all'])) {
    mark_all_alerts_read();
    echo json_encode(['ok' => true]);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID non valido.']);
    exit;
}
$isRead = ($_POST['is_read'] ?? '1') === '1';
mark_alert_read($id, $isRead);
echo json_encode(['ok' => true, 'id' => $id, 'is_read' => $isRead]);
