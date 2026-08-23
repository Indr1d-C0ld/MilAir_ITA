<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('admin');

$db = get_auth_db();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$pathFilter = $_GET['path']    ?? '';
$roleFilter = $_GET['role']    ?? '';
$search   = trim($_GET['search'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

$allowedSorts = ['ts' => 'ts', 'ip' => 'ip', 'path' => 'path', 'role' => 'role', 'status_code' => 'status_code', 'username' => 'username'];
$sort = $_GET['sort'] ?? 'ts';
if (!array_key_exists($sort, $allowedSorts)) $sort = 'ts';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$baseParams = array_intersect_key($_GET, array_flip(['date_from', 'date_to', 'path', 'role', 'search', 'sort', 'order']));

$where = [];
$params = [];
if ($dateFrom !== '') { $where[] = 'ts >= :dfrom'; $params[':dfrom'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '')   { $where[] = 'ts <= :dto';   $params[':dto']   = $dateTo . ' 23:59:59'; }
if ($pathFilter !== '') { $where[] = 'path = :path'; $params[':path'] = $pathFilter; }
if ($roleFilter !== '') { $where[] = 'role = :role'; $params[':role'] = $roleFilter; }
if ($search !== '') {
    $where[] = '(ip LIKE :search OR username LIKE :search OR user_agent LIKE :search OR referer LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM access_log $whereSql");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$total = (int)$countStmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM access_log $whereSql ORDER BY {$allowedSorts[$sort]} $order, id DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$res = $stmt->execute();

$logs = [];
$ipsOnPage = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $logs[] = $row;
    $ipsOnPage[$row['ip']] = true;
}

// Geolocalizzazione: risolta solo per gli IP mostrati in questa pagina (cache-first)
$geo = [];
foreach (array_keys($ipsOnPage) as $ip) {
    $geo[$ip] = resolve_ip_geo($ip);
}

// Riepilogo generale (non filtrato dalla ricerca corrente)
$totalRequests = (int)$db->querySingle('SELECT COUNT(*) FROM access_log');
$totalUniqueIps = (int)$db->querySingle('SELECT COUNT(DISTINCT ip) FROM access_log');
$totalToday = (int)$db->querySingle("SELECT COUNT(*) FROM access_log WHERE ts >= datetime('now','start of day')");

$distinctPaths = [];
$resP = $db->query('SELECT DISTINCT path FROM access_log ORDER BY path');
while ($r = $resP->fetchArray(SQLITE3_ASSOC)) $distinctPaths[] = $r['path'];

function sortLinkLog($columnKey, $label, $currentSort, $currentOrder, $getParams) {
    $newOrder = ($currentSort === $columnKey && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = array_merge($getParams, ['sort' => $columnKey, 'order' => $newOrder]);
    $arrow = '';
    if ($currentSort === $columnKey) $arrow = $currentOrder === 'asc' ? ' ▲' : ' ▼';
    return '<a href="?' . http_build_query($params) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log Accessi – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .rules-table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .stats-summary { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px 20px; min-width: 150px; }
        .stat-card .stat-value { font-size: 1.8em; font-weight: bold; color: #007bff; }
        .stat-card .stat-label { font-size: 0.9em; color: #495057; }
        table { font-size: 0.9em; }
        .status-ok { color: #28a745; }
        .status-redirect { color: #007bff; }
        .status-error { color: #dc3545; font-weight: bold; }
        .ip-cell { white-space: nowrap; }
        .ip-actions a { margin-left: 4px; font-size: 0.85em; text-decoration: none; }
        .role-badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 0.8em; }
        .role-badge.admin { background: #dc3545; color: #fff; }
        .role-badge.collaboratore { background: #007bff; color: #fff; }
        .role-badge.pubblico { background: #6c757d; color: #fff; }
        .ua-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85em; color: #6c757d; }
    </style>
</head>
<body>
    <?php render_nav('admin_access_log.php'); ?>

    <h2>📜 Log Accessi</h2>

    <div class="stats-summary">
        <div class="stat-card"><div class="stat-value"><?= number_format($totalRequests) ?></div><div class="stat-label">Richieste totali</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalUniqueIps) ?></div><div class="stat-label">IP unici</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalToday) ?></div><div class="stat-label">Richieste oggi</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($total) ?></div><div class="stat-label">Risultati filtro corrente</div></div>
    </div>

    <form method="get" class="filter-bar">
        <label>Dal: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
        <label>Al: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
        <label>Pagina:
            <select name="path">
                <option value="">Tutte</option>
                <?php foreach ($distinctPaths as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $pathFilter === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ruolo:
            <select name="role">
                <option value="">Tutti</option>
                <option value="pubblico" <?= $roleFilter === 'pubblico' ? 'selected' : '' ?>>Pubblico</option>
                <option value="collaboratore" <?= $roleFilter === 'collaboratore' ? 'selected' : '' ?>>Collaboratore</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </label>
        <label>Cerca (IP, username, user agent): <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="es. 93.45 o mozilla"></label>
        <button type="submit">Filtra</button>
        <a href="admin_access_log.php" class="btn">Reset</a>
    </form>

    <div class="rules-table-container" style="max-height:none;">
        <table>
            <thead>
                <tr>
                    <th><?= sortLinkLog('ts', 'Data/ora', $sort, $order, $baseParams) ?></th>
                    <th><?= sortLinkLog('ip', 'IP', $sort, $order, $baseParams) ?></th>
                    <th>Paese</th>
                    <th><?= sortLinkLog('username', 'Utente', $sort, $order, $baseParams) ?></th>
                    <th><?= sortLinkLog('role', 'Ruolo', $sort, $order, $baseParams) ?></th>
                    <th><?= sortLinkLog('path', 'Pagina', $sort, $order, $baseParams) ?></th>
                    <th>Metodo</th>
                    <th><?= sortLinkLog('status_code', 'Stato', $sort, $order, $baseParams) ?></th>
                    <th>User agent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log):
                    $g = $geo[$log['ip']] ?? ['country_code' => null, 'country_name' => null];
                    $flag = ipgeo_flag_emoji($g['country_code']);
                    $statusClass = 'status-ok';
                    if ((int)$log['status_code'] >= 300 && (int)$log['status_code'] < 400) $statusClass = 'status-redirect';
                    elseif ((int)$log['status_code'] >= 400) $statusClass = 'status-error';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($log['ts']) ?></td>
                        <td class="ip-cell">
                            <?= htmlspecialchars($log['ip']) ?>
                            <span class="ip-actions">
                                <a href="https://ipinfo.io/<?= urlencode($log['ip']) ?>" target="_blank" title="Dettagli IP (ipinfo.io)">🔍</a>
                                <a href="https://whois.domaintools.com/<?= urlencode($log['ip']) ?>" target="_blank" title="Whois">🌐</a>
                            </span>
                        </td>
                        <td><?= $flag ? $flag . ' ' : '' ?><?= htmlspecialchars($g['country_name'] ?? ($g['country_code'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars($log['username'] ?? '-') ?></td>
                        <td><span class="role-badge <?= htmlspecialchars($log['role']) ?>"><?= htmlspecialchars($log['role']) ?></span></td>
                        <td><?= htmlspecialchars($log['path']) ?></td>
                        <td><?= htmlspecialchars($log['method']) ?></td>
                        <td class="<?= $statusClass ?>"><?= htmlspecialchars((string)$log['status_code']) ?></td>
                        <td class="ua-cell" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>"><?= htmlspecialchars($log['user_agent'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" style="text-align:center;color:#6c757d;">Nessun risultato per il filtro corrente.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>"<?= $i === $page ? ' style="font-weight:bold;"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
    </div>
</body>
</html>
