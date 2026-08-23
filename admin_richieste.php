<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('admin');

$db = get_auth_db();
$me = current_user();
$flashMsg = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['update_request'])) {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $adminNote = trim($_POST['admin_note'] ?? '');

        if (!in_array($status, ['new', 'reviewed', 'approved', 'rejected'], true)) {
            $flashMsg = 'Stato non valido.';
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("UPDATE requests SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $status);
            $stmt->bindValue(2, $adminNote !== '' ? $adminNote : null);
            $stmt->bindValue(3, $me['id']);
            $stmt->bindValue(4, $id);
            $stmt->execute();
            $flashMsg = 'Richiesta aggiornata.';
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';

$where = [];
$params = [];
if (in_array($statusFilter, ['new', 'reviewed', 'approved', 'rejected'], true)) {
    $where[] = 'r.status = :status';
    $params[':status'] = $statusFilter;
}
if (in_array($typeFilter, ['contact', 'collab_access'], true)) {
    $where[] = 'r.request_type = :type';
    $params[':type'] = $typeFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT r.*, u.username AS reviewed_by_name
    FROM requests r LEFT JOIN users u ON r.reviewed_by = u.id
    $whereSql ORDER BY r.created_at DESC");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$res = $stmt->execute();
$requests = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $requests[] = $row;
}

$countNew = (int)$db->querySingle("SELECT COUNT(*) FROM requests WHERE status = 'new'");
$countTotal = (int)$db->querySingle("SELECT COUNT(*) FROM requests");

$statusLabels = ['new' => 'Nuova', 'reviewed' => 'In esame', 'approved' => 'Approvata', 'rejected' => 'Respinta'];
$typeLabels = ['contact' => 'Contatto', 'collab_access' => 'Accesso collaboratore'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Richieste – Amministrazione MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .msg-banner { padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .msg-banner.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-banner.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .stats-summary { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px 20px; min-width: 150px; }
        .stat-card .stat-value { font-size: 1.8em; font-weight: bold; color: #007bff; }
        .stat-card.warn .stat-value { color: #dc3545; }
        .stat-card .stat-label { font-size: 0.9em; color: #495057; }
        .req-card { border: 1px solid #dee2e6; border-radius: 8px; padding: 16px 20px; margin-bottom: 14px; background: #fff; }
        .req-card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 8px; }
        .req-card-head .who { font-weight: bold; }
        .req-card-head .when { font-size: 0.85em; color: #6c757d; }
        .req-message { white-space: pre-wrap; background: #f8f9fa; border-radius: 6px; padding: 10px 12px; margin: 8px 0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: bold; }
        .badge.new { background: #007bff; color: #fff; }
        .badge.reviewed { background: #ffc107; color: #212529; }
        .badge.approved { background: #28a745; color: #fff; }
        .badge.rejected { background: #dc3545; color: #fff; }
        .badge.type-contact { background: #6c757d; color: #fff; }
        .badge.type-collab_access { background: #17a2b8; color: #fff; }
        .req-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; margin-top: 10px; }
        .req-form label { display: flex; flex-direction: column; font-size: 0.85em; gap: 2px; }
        .req-form textarea { width: 260px; min-height: 50px; font-family: inherit; }
        @media (max-width: 768px) {
            .req-form { flex-direction: column; align-items: stretch; }
            .req-form textarea { width: 100%; }
        }
    </style>
</head>
<body>
    <?php render_nav('admin_richieste.php'); ?>

    <h2>📬 Richieste</h2>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <div class="stats-summary">
        <div class="stat-card"><div class="stat-value"><?= number_format($countTotal) ?></div><div class="stat-label">Totali</div></div>
        <div class="stat-card <?= $countNew > 0 ? 'warn' : '' ?>"><div class="stat-value"><?= number_format($countNew) ?></div><div class="stat-label">Nuove</div></div>
    </div>

    <form method="get" class="filter-bar">
        <label>Stato:
            <select name="status">
                <option value="">Tutti</option>
                <?php foreach ($statusLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Tipo:
            <select name="type">
                <option value="">Tutti</option>
                <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Filtra</button>
        <a href="admin_richieste.php" class="btn">Reset</a>
    </form>

    <?php if (empty($requests)): ?>
        <p style="color:#6c757d;">Nessuna richiesta per il filtro corrente.</p>
    <?php endif; ?>

    <?php foreach ($requests as $r): ?>
        <div class="req-card">
            <div class="req-card-head">
                <div class="who">
                    <?= htmlspecialchars($r['name']) ?> &lt;<?= htmlspecialchars($r['email']) ?>&gt;
                    <span class="badge type-<?= htmlspecialchars($r['request_type']) ?>"><?= $typeLabels[$r['request_type']] ?? htmlspecialchars($r['request_type']) ?></span>
                    <span class="badge <?= htmlspecialchars($r['status']) ?>"><?= $statusLabels[$r['status']] ?? htmlspecialchars($r['status']) ?></span>
                </div>
                <div class="when">
                    <?= htmlspecialchars($r['created_at']) ?> · IP <?= htmlspecialchars($r['ip'] ?? '-') ?>
                    <?php if ($r['reviewed_by_name']): ?>
                        · esaminata da <?= htmlspecialchars($r['reviewed_by_name']) ?> il <?= htmlspecialchars($r['reviewed_at']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="req-message"><?= nl2br(htmlspecialchars($r['message'])) ?></div>
            <?php if ($r['admin_note']): ?>
                <div><strong>Nota interna:</strong> <?= nl2br(htmlspecialchars($r['admin_note'])) ?></div>
            <?php endif; ?>
            <form method="post" class="req-form">
                <?= csrf_field() ?>
                <input type="hidden" name="update_request" value="1">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <label>Stato
                    <select name="status">
                        <?php foreach ($statusLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $r['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nota interna
                    <textarea name="admin_note"><?= htmlspecialchars($r['admin_note'] ?? '') ?></textarea>
                </label>
                <button type="submit">💾 Salva</button>
            </form>
        </div>
    <?php endforeach; ?>
</body>
</html>
