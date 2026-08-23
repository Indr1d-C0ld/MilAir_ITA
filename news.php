<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/news_lib.php';
auth_bootstrap();
log_access();

$db = get_news_db();

$search = trim($_GET['search'] ?? '');
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(a.title LIKE :search OR a.body_text LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
if ($keyword !== '') {
    $where[] = 'a.id IN (SELECT article_id FROM article_keywords WHERE keyword = :keyword)';
    $params[':keyword'] = mb_strtolower($keyword, 'UTF-8');
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM articles a $whereSql");
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$total = (int)$countStmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT a.*, f.name AS feed_name FROM articles a
    JOIN feed_sources f ON a.feed_id = f.id
    $whereSql
    ORDER BY COALESCE(a.published_at, a.fetched_at) DESC
    LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$res = $stmt->execute();
$articles = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $articles[] = $row;
}

$totalAll = (int)$db->querySingle('SELECT COUNT(*) FROM articles');
$baseParams = array_intersect_key($_GET, array_flip(['search', 'keyword']));

function newsExcerpt(string $text, int $len = 220): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text, 'UTF-8') <= $len) {
        return $text;
    }
    return mb_substr($text, 0, $len, 'UTF-8') . '…';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notizie – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .news-card { border: 1px solid #dee2e6; border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.04); }
        .news-card h3 { margin: 0 0 6px; font-size: 1.15em; }
        .news-card h3 a { color: #212529; text-decoration: none; }
        .news-card h3 a:hover { color: #007bff; text-decoration: underline; }
        .news-meta { font-size: 0.8em; color: #6c757d; margin-bottom: 8px; }
        .news-meta .feed-badge { display: inline-block; background: #eaf4ff; color: #007bff; border-radius: 6px; padding: 1px 8px; font-weight: 500; margin-right: 6px; }
        .news-excerpt { color: #495057; font-size: 0.92em; }
        .keyword-active-banner { background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 6px; padding: 10px 15px; margin-bottom: 20px; }
        .keyword-active-banner a { color: #856404; font-weight: 600; }
    </style>
</head>
<body>
    <?php render_nav('news.php'); ?>

    <h2>📰 Notizie</h2>

    <?php if ($keyword !== ''): ?>
        <div class="keyword-active-banner">
            Filtro attivo per parola chiave: <strong>«<?= htmlspecialchars($keyword) ?>»</strong>
            — <a href="news.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>">rimuovi filtro</a>
        </div>
    <?php endif; ?>

    <form method="get" class="filter-bar">
        <?php if ($keyword !== ''): ?><input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword) ?>"><?php endif; ?>
        <label>Cerca: <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="titolo o testo dell'articolo..."></label>
        <button type="submit">Cerca</button>
        <a href="news.php" class="btn">Reset</a>
        <span style="margin-left:auto;color:#6c757d;font-size:0.9em;"><?= number_format($total) ?> di <?= number_format($totalAll) ?> articoli</span>
    </form>

    <?php if (empty($articles)): ?>
        <p style="color:#6c757d;">Nessun articolo trovato per il filtro corrente.</p>
    <?php endif; ?>

    <?php foreach ($articles as $a): ?>
        <div class="news-card">
            <h3><a href="news_article.php?id=<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['title']) ?></a></h3>
            <div class="news-meta">
                <span class="feed-badge"><?= htmlspecialchars($a['feed_name']) ?></span>
                <?= $a['published_at'] ? htmlspecialchars(format_date_it($a['published_at'])) : htmlspecialchars(format_date_it($a['fetched_at'])) ?>
                <?php if ($a['author']): ?> · <?= htmlspecialchars($a['author']) ?><?php endif; ?>
            </div>
            <div class="news-excerpt"><?= htmlspecialchars(newsExcerpt($a['body_text'])) ?></div>
        </div>
    <?php endforeach; ?>

    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($baseParams, ['page' => $i])) ?>"<?= $i === $page ? ' style="font-weight:bold;"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
    </div>
</body>
</html>
