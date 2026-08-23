<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_role('admin');

$db = get_auth_db();

// 1. Richieste + IP unici per giorno (ultimi 30 giorni)
$dailyCounts = [];
$res = $db->query("SELECT substr(ts,1,10) AS day, COUNT(*) AS cnt, COUNT(DISTINCT ip) AS uniq
    FROM access_log WHERE ts >= date('now','-30 days') GROUP BY day");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $dailyCounts[$row['day']] = ['cnt' => (int)$row['cnt'], 'uniq' => (int)$row['uniq']];
}
$dates = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dates[] = ['day' => $d, 'cnt' => $dailyCounts[$d]['cnt'] ?? 0, 'uniq' => $dailyCounts[$d]['uniq'] ?? 0];
}

// 2. Pagine più visitate
$topPages = [];
$res = $db->query("SELECT path, COUNT(*) AS cnt FROM access_log GROUP BY path ORDER BY cnt DESC LIMIT 10");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $topPages[] = $row;

// 3. Distribuzione per ruolo
$roleCounts = [];
$res = $db->query("SELECT role, COUNT(*) AS cnt FROM access_log GROUP BY role");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $roleCounts[] = $row;

// 4. Top paesi (solo IP già risolti in cache — nessuna chiamata esterna qui)
$topCountries = [];
$res = $db->query("SELECT g.country_code, g.country_name, COUNT(*) AS cnt
    FROM access_log a JOIN ip_geo_cache g ON a.ip = g.ip
    WHERE g.country_code IS NOT NULL
    GROUP BY g.country_code ORDER BY cnt DESC LIMIT 15");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $topCountries[] = $row;

$unresolvedIps = (int)$db->querySingle("SELECT COUNT(DISTINCT a.ip) FROM access_log a
    LEFT JOIN ip_geo_cache g ON a.ip = g.ip WHERE g.ip IS NULL");
$resolvedIps = (int)$db->querySingle("SELECT COUNT(DISTINCT ip) FROM ip_geo_cache WHERE country_code IS NOT NULL");

// Riepilogo
$totalRequests = (int)$db->querySingle('SELECT COUNT(*) FROM access_log');
$totalUniqueIps = (int)$db->querySingle('SELECT COUNT(DISTINCT ip) FROM access_log');
$totalUsers = (int)$db->querySingle('SELECT COUNT(*) FROM users WHERE is_active = 1');
$totalLogins = (int)$db->querySingle("SELECT COUNT(*) FROM login_attempts WHERE success = 1");
$failedLogins24h = (int)$db->querySingle("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= datetime('now','-1 day')");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statistiche Accessi – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        canvas { max-width: 700px; margin: 0 auto 20px; display: block; }
        .stats-summary { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px 20px; min-width: 150px; }
        .stat-card .stat-value { font-size: 1.8em; font-weight: bold; color: #007bff; }
        .stat-card .stat-label { font-size: 0.9em; color: #495057; }
        .stat-card.warn .stat-value { color: #dc3545; }
        .geo-note { font-size: 0.85em; color: #6c757d; margin-bottom: 10px; }
    </style>
</head>
<body>
    <?php render_nav('admin_access_stats.php'); ?>

    <h2>📊 Statistiche Accessi</h2>

    <div class="stats-summary">
        <div class="stat-card"><div class="stat-value"><?= number_format($totalRequests) ?></div><div class="stat-label">Richieste totali</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalUniqueIps) ?></div><div class="stat-label">IP unici</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalUsers) ?></div><div class="stat-label">Account attivi</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format($totalLogins) ?></div><div class="stat-label">Login riusciti (totale)</div></div>
        <div class="stat-card <?= $failedLogins24h > 20 ? 'warn' : '' ?>"><div class="stat-value"><?= number_format($failedLogins24h) ?></div><div class="stat-label">Login falliti (24h)</div></div>
    </div>

    <h3>Richieste e IP unici per giorno (ultimi 30 giorni)</h3>
    <canvas id="dailyChart" width="700" height="220"></canvas>

    <h3>Pagine più visitate</h3>
    <canvas id="pagesChart" width="700" height="280"></canvas>

    <h3>Distribuzione per ruolo</h3>
    <canvas id="roleChart" width="400" height="280"></canvas>

    <h3>Paesi di provenienza</h3>
    <p class="geo-note">Basato solo sugli IP già risolti (visualizzati almeno una volta nel <a href="admin_access_log.php">log accessi</a>) — <?= number_format($resolvedIps) ?> risolti, <?= number_format($unresolvedIps) ?> non ancora risolti. Apri il log accessi per risolverne altri.</p>
    <canvas id="countryChart" width="700" height="300"></canvas>

    <script>
        new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($dates, 'day')) ?>,
                datasets: [
                    {
                        label: 'Richieste totali',
                        data: <?= json_encode(array_column($dates, 'cnt')) ?>,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.2
                    },
                    {
                        label: 'IP unici',
                        data: <?= json_encode(array_column($dates, 'uniq')) ?>,
                        borderColor: 'rgba(255, 159, 64, 1)',
                        backgroundColor: 'rgba(255, 159, 64, 0.2)',
                        tension: 0.2
                    }
                ]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });

        new Chart(document.getElementById('pagesChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($topPages, 'path')) ?>,
                datasets: [{
                    label: 'Richieste',
                    data: <?= json_encode(array_column($topPages, 'cnt')) ?>,
                    backgroundColor: 'rgba(255, 159, 64, 0.6)'
                }]
            },
            options: { indexAxis: 'y', scales: { x: { beginAtZero: true } } }
        });

        const roleLabels = <?= json_encode(array_column($roleCounts, 'role')) ?>;
        const roleColors = { 'pubblico': '#6c757d', 'collaboratore': '#007bff', 'admin': '#dc3545' };
        new Chart(document.getElementById('roleChart'), {
            type: 'pie',
            data: {
                labels: roleLabels,
                datasets: [{
                    data: <?= json_encode(array_column($roleCounts, 'cnt')) ?>,
                    backgroundColor: roleLabels.map(r => roleColors[r] || '#999')
                }]
            }
        });

        new Chart(document.getElementById('countryChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($c) => ($c['country_name'] ?: $c['country_code']), $topCountries)) ?>,
                datasets: [{
                    label: 'Richieste',
                    data: <?= json_encode(array_column($topCountries, 'cnt')) ?>,
                    backgroundColor: 'rgba(153, 102, 255, 0.6)'
                }]
            },
            options: { indexAxis: 'y', scales: { x: { beginAtZero: true } } }
        });
    </script>
</body>
</html>
