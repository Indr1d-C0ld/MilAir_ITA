<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('collaboratore');

$db = get_auth_db();
$me = current_user();
$flashMsg = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['save_note'])) {
        $id = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $stmt = $db->prepare("UPDATE alerts SET note = ?, note_updated_at = CURRENT_TIMESTAMP, note_updated_by = ? WHERE id = ?");
        $stmt->bindValue(1, $note !== '' ? $note : null);
        $stmt->bindValue(2, $me['id']);
        $stmt->bindValue(3, $id);
        $stmt->execute();
        $flashMsg = 'Nota salvata.';
    } elseif (isset($_POST['toggle_read'])) {
        $id = (int)($_POST['id'] ?? 0);
        $newState = (int)($_POST['new_state'] ?? 1);
        mark_alert_read($id, $newState === 1);
        $flashMsg = $newState === 1 ? 'Alert segnato come letto.' : 'Alert segnato come non letto.';
    } elseif (isset($_POST['mark_all'])) {
        mark_all_alerts_read();
        $flashMsg = 'Tutte le notifiche sono state segnate come lette.';
    }
}

$typeFilter = $_GET['type'] ?? '';
$readFilter = $_GET['read'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$allowedSorts = ['created_at' => 'created_at', 'alert_type' => 'alert_type', 'hex' => 'hex', 'is_read' => 'is_read'];
$sort = $_GET['sort'] ?? 'created_at';
if (!array_key_exists($sort, $allowedSorts)) $sort = 'created_at';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$baseParams = array_intersect_key($_GET, array_flip(['type', 'read', 'date_from', 'date_to', 'search', 'sort', 'order']));

$where = [];
$params = [];
if (in_array($typeFilter, ['watchlist', 'new_request', 'emergency_squawk', 'rare_contact', 'new_article', 'custom_rule'], true)) {
    $where[] = 'alert_type = :type';
    $params[':type'] = $typeFilter;
}
if ($readFilter === 'read') {
    $where[] = 'is_read = 1';
} elseif ($readFilter === 'unread') {
    $where[] = 'is_read = 0';
}
if ($dateFrom !== '') { $where[] = 'created_at >= :dfrom'; $params[':dfrom'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '')   { $where[] = 'created_at <= :dto';   $params[':dto']   = $dateTo . ' 23:59:59'; }
if ($search !== '') {
    $where[] = '(title LIKE :search OR detail LIKE :search OR hex LIKE :search OR note LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM alerts $whereSql");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$total = (int)$countStmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT a.*, u.username AS note_updated_by_name FROM alerts a
    LEFT JOIN users u ON a.note_updated_by = u.id
    $whereSql ORDER BY {$allowedSorts[$sort]} $order, a.id DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$res = $stmt->execute();
$alerts = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $alerts[] = $row;
}

$totalAll = (int)$db->querySingle('SELECT COUNT(*) FROM alerts');
$totalUnread = (int)$db->querySingle('SELECT COUNT(*) FROM alerts WHERE is_read = 0');
$totalToday = (int)$db->querySingle("SELECT COUNT(*) FROM alerts WHERE created_at >= datetime('now','start of day')");

$typeLabels = [
    'watchlist'        => '❓ Watchlist',
    'new_request'      => '📬 Nuova richiesta',
    'emergency_squawk' => '🚨 Squawk emergenza',
    'rare_contact'     => '✨ Contatto raro',
    'new_article'      => '📰 Nuovo articolo',
    'custom_rule'      => '🎯 Regola personalizzata',
];

function sortLinkAlert($columnKey, $label, $currentSort, $currentOrder, $getParams) {
    $newOrder = ($currentSort === $columnKey && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $columnKey) $arrow = $currentOrder === 'asc' ? ' ▲' : ' ▼';
    $params = array_merge($getParams, ['sort' => $columnKey, 'order' => $newOrder]);
    return '<a href="?' . http_build_query($params) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifiche – MILAIR ITA</title>
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
        .rules-table-container { max-height: none; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 20px; border: 1px solid #dee2e6; border-radius: 6px; }
        table { font-size: 0.9em; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: bold; }
        .badge.unread { background: #dc3545; color: #fff; }
        .badge.readstate { background: #6c757d; color: #fff; }
        .detail-cell { max-width: 260px; color: #495057; }
        .note-cell { max-width: 220px; }
        .edit-row { display: none; background: #f8f9fa; }
        .edit-row form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; padding: 8px 0; }
        .edit-row textarea { width: 260px; min-height: 50px; font-family: inherit; }
        tr.unread-row td { background: #eaf4ff; }
        .inline-form { display: inline; }
        @media (max-width: 768px) {
            .edit-row form { flex-direction: column; align-items: stretch; }
            .edit-row textarea { width: 100%; }
        }
    </style>
</head>
<body>
    <?php render_nav('alerts.php'); ?>

    <h2>🔔 Notifiche</h2>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <div class="stats-summary">
        <div class="stat-card"><div class="stat-value"><?= number_format($totalAll) ?></div><div class="stat-label">Totali</div></div>
        <div class="stat-card <?= $totalUnread > 0 ? 'warn' : '' ?>"><div class="stat-value"><?= number_format($totalUnread) ?></div><div class="stat-label">Non lette</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalToday) ?></div><div class="stat-label">Oggi</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($total) ?></div><div class="stat-label">Risultati filtro corrente</div></div>
    </div>

    <form method="get" class="filter-bar">
        <label>Tipo:
            <select name="type">
                <option value="">Tutti</option>
                <?php foreach ($typeLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Stato:
            <select name="read">
                <option value="">Tutti</option>
                <option value="unread" <?= $readFilter === 'unread' ? 'selected' : '' ?>>Non lette</option>
                <option value="read" <?= $readFilter === 'read' ? 'selected' : '' ?>>Lette</option>
            </select>
        </label>
        <label>Dal: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
        <label>Al: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
        <label>Cerca: <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="titolo, hex, nota..."></label>
        <button type="submit">Filtra</button>
        <a href="alerts.php" class="btn">Reset</a>
    </form>

    <form method="post" style="margin-bottom:15px;">
        <?= csrf_field() ?>
        <input type="hidden" name="mark_all" value="1">
        <button type="submit" class="btn">✅ Segna tutte come lette</button>
    </form>

    <div class="rules-table-container">
        <table>
            <thead>
                <tr>
                    <th><?= sortLinkAlert('created_at', 'Data/ora', $sort, $order, $baseParams) ?></th>
                    <th><?= sortLinkAlert('alert_type', 'Tipo', $sort, $order, $baseParams) ?></th>
                    <th><?= sortLinkAlert('hex', 'Hex', $sort, $order, $baseParams) ?></th>
                    <th>Titolo</th>
                    <th>Dettaglio</th>
                    <th><?= sortLinkAlert('is_read', 'Stato', $sort, $order, $baseParams) ?></th>
                    <th>Nota</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alerts as $a): $rowId = 'alert-' . $a['id']; $isUnread = (int)$a['is_read'] === 0; ?>
                    <tr id="view-<?= $rowId ?>" class="<?= $isUnread ? 'unread-row' : '' ?>">
                        <td><?= htmlspecialchars(format_date_it($a['created_at'])) ?></td>
                        <td><?= $typeLabels[$a['alert_type']] ?? htmlspecialchars($a['alert_type']) ?></td>
                        <td><?php if ($a['hex']): ?><a href="index.php?hex=<?= urlencode($a['hex']) ?>&sort=ident_last_seen&order=desc" target="_blank"><?= htmlspecialchars($a['hex']) ?></a><?php else: ?>-<?php endif; ?></td>
                        <td><?php if ($a['hex']): ?><a href="index.php?hex=<?= urlencode($a['hex']) ?>&sort=ident_last_seen&order=desc" target="_blank"><?= htmlspecialchars($a['title']) ?></a><?php elseif (!empty($a['article_id'])): ?><a href="news_article.php?id=<?= (int)$a['article_id'] ?>" target="_blank"><?= htmlspecialchars($a['title']) ?></a><?php else: ?><?= htmlspecialchars($a['title']) ?><?php endif; ?></td>
                        <td class="detail-cell"><?= htmlspecialchars($a['detail'] ?? '') ?></td>
                        <td><span class="badge <?= $isUnread ? 'unread' : 'readstate' ?>"><?= $isUnread ? 'non letta' : 'letta' ?></span></td>
                        <td class="note-cell"><?= $a['note'] ? htmlspecialchars($a['note']) : '' ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="toggle_read" value="1">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="new_state" value="<?= $isUnread ? 1 : 0 ?>">
                                <button type="submit" class="btn" title="<?= $isUnread ? 'Segna come letta' : 'Segna come non letta' ?>"><?= $isUnread ? '✔️' : '↩️' ?></button>
                            </form>
                            <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        </td>
                    </tr>
                    <tr id="edit-<?= $rowId ?>" class="edit-row">
                        <td colspan="8">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="save_note" value="1">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <label>Nota
                                    <textarea name="note"><?= htmlspecialchars($a['note'] ?? '') ?></textarea>
                                </label>
                                <?php if ($a['note_updated_by_name']): ?>
                                    <span style="font-size:0.8em;color:#6c757d;">ultima modifica di <?= htmlspecialchars($a['note_updated_by_name']) ?> il <?= htmlspecialchars($a['note_updated_at']) ?></span>
                                <?php endif; ?>
                                <button type="submit">💾 Salva</button>
                                <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($alerts)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#6c757d;">Nessuna notifica per il filtro corrente.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>"<?= $i === $page ? ' style="font-weight:bold;"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
    </div>

    <script>
        function toggleEditRow(rowId) {
            var edit = document.getElementById('edit-' + rowId);
            if (!edit) return;
            edit.style.display = (edit.style.display === 'table-row') ? 'none' : 'table-row';
        }
    </script>
</body>
</html>
