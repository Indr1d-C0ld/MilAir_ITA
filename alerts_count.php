<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
require_role_json('collaboratore');

header('Content-Type: application/json');
echo json_encode(['count' => get_unread_alert_count()]);
