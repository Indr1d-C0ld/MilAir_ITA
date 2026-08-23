<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/news_lib.php';
auth_bootstrap();
log_access();

$db = get_news_db();
$id = (int)($_GET['id'] ?? 0);
$flashMsg = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['post_comment'])) {
        require_role('collaboratore');
        $body = trim($_POST['body'] ?? '');
        $user = current_user();
        if ($body === '' || mb_strlen($body) > 2000) {
            $flashMsg = 'Il commento deve avere tra 1 e 2000 caratteri.';
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("INSERT INTO comments (article_id, user_id, username_snapshot, body) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $id);
            $stmt->bindValue(2, $user['id']);
            $stmt->bindValue(3, $user['display_name']);
            $stmt->bindValue(4, $body);
            $stmt->execute();
            $flashMsg = 'Commento pubblicato.';
        }
    } elseif (isset($_POST['delete_comment'])) {
        require_role('collaboratore');
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $user = current_user();
        $stmt = $db->prepare("SELECT user_id FROM comments WHERE id = ? AND deleted_at IS NULL");
        $stmt->bindValue(1, $commentId);
        $comment = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$comment) {
            $flashMsg = 'Commento non trovato.';
            $flashType = 'error';
        } elseif ($user['role'] !== 'admin' && (int)$comment['user_id'] !== (int)$user['id']) {
            $flashMsg = 'Puoi eliminare solo i tuoi commenti.';
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("UPDATE comments SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE id = ?");
            $stmt->bindValue(1, $user['id']);
            $stmt->bindValue(2, $commentId);
            $stmt->execute();
            $flashMsg = 'Commento eliminato.';
        }
    }
}

$stmt = $db->prepare("SELECT a.*, f.name AS feed_name, f.url AS feed_url FROM articles a JOIN feed_sources f ON a.feed_id = f.id WHERE a.id = ?");
$stmt->bindValue(1, $id);
$article = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$article) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Articolo non trovato – MILAIR ITA</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <?php render_nav(); ?>
        <h2>Articolo non trovato</h2>
        <p><a href="news.php">← Torna alle notizie</a></p>
    </body>
    </html>
    <?php
    exit;
}

$keywords = json_decode($article['keywords_json'] ?? '[]', true) ?: [];
$topKeyword = $keywords[0]['word'] ?? null;
$otherKeywords = $topKeyword !== null ? array_slice($keywords, 1) : $keywords;

$comments = [];
$stmt = $db->prepare("SELECT * FROM comments WHERE article_id = ? AND deleted_at IS NULL ORDER BY created_at ASC");
$stmt->bindValue(1, $id);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $comments[] = $row;
}

$me = current_user();
$canComment = $me && (ROLE_RANK[$me['role']] ?? 0) >= ROLE_RANK['collaboratore'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($article['title']) ?> – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .article-body { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px 24px; margin: 20px 0; white-space: pre-wrap; line-height: 1.7; }
        .info-box { border: 1px solid #dee2e6; border-radius: 8px; background: #fff; padding: 16px 20px; margin-bottom: 16px; }
        .info-box h4 { margin: 0 0 10px; color: #343a40; }
        .info-box .info-row { font-size: 0.92em; margin-bottom: 4px; }
        .info-box .info-row strong { color: #495057; }
        .info-box a { color: #007bff; text-decoration: none; word-break: break-all; }
        .info-box a:hover { text-decoration: underline; }
        .correlazione-principale { background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 6px; padding: 10px 15px; margin-bottom: 12px; font-weight: 500; }
        .correlazione-principale a { color: #856404; font-weight: 700; }
        .keyword-chips-wrap { display: flex; flex-wrap: wrap; gap: 4px 6px; }
        .keyword-chip { display: inline-block; border: 1px solid #007bff; color: #007bff; background: #eaf4ff; border-radius: 6px; padding: 5px 12px; font-size: 0.85rem; font-weight: 500; text-decoration: none; }
        .keyword-chip:hover { background: #007bff; color: #fff; }
        .comments-section { margin-top: 30px; }
        .comment-item { border-bottom: 1px solid #f1f3f5; padding: 12px 0; }
        .comment-item .comment-meta { font-size: 0.8em; color: #6c757d; margin-bottom: 4px; }
        .comment-item .comment-body { white-space: pre-wrap; }
        .comment-item .comment-delete { font-size: 0.78em; color: #dc3545; background: none; border: none; cursor: pointer; padding: 0; margin-top: 4px; }
        .comment-form textarea { width: 100%; box-sizing: border-box; min-height: 80px; font-family: inherit; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; }
        .msg-banner { padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .msg-banner.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-banner.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <?php render_nav('news.php'); ?>

    <p><a href="news.php">← Torna alle notizie</a></p>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <h2><?= htmlspecialchars($article['title']) ?></h2>

    <div class="info-box">
        <h4>Dettagli</h4>
        <div class="info-row"><strong>Pubblicato:</strong> <?= $article['published_at'] ? htmlspecialchars(format_date_it($article['published_at'])) : 'sconosciuto' ?></div>
        <div class="info-row"><strong>Fetch:</strong> <?= htmlspecialchars(format_date_it($article['fetched_at'])) ?></div>
        <?php if ($article['author']): ?><div class="info-row"><strong>Autore:</strong> <?= htmlspecialchars($article['author']) ?></div><?php endif; ?>
        <div class="info-row"><strong>Fonte:</strong> <a href="<?= htmlspecialchars($article['link']) ?>" target="_blank"><?= htmlspecialchars($article['link']) ?></a></div>
        <div class="info-row"><strong>Feed URL:</strong> <a href="<?= htmlspecialchars($article['feed_url']) ?>" target="_blank"><?= htmlspecialchars($article['feed_url']) ?></a></div>
    </div>

    <?php if (!empty($keywords)): ?>
    <div class="info-box">
        <h4>Correlazioni rapide</h4>
        <p style="font-size:0.85em;color:#6c757d;margin-top:0;">Parole più frequenti nel testo:</p>
        <?php if ($topKeyword !== null): ?>
            <div class="correlazione-principale">
                Correlazione principale: <a href="news.php?keyword=<?= urlencode($topKeyword) ?>">cerca «<?= htmlspecialchars($topKeyword) ?>»</a>
            </div>
        <?php endif; ?>
        <?php if (!empty($otherKeywords)): ?>
            <div class="keyword-chips-wrap">
                <?php foreach ($otherKeywords as $kw): ?>
                    <a class="keyword-chip" href="news.php?keyword=<?= urlencode($kw['word']) ?>"><?= htmlspecialchars($kw['word']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="article-body"><?= htmlspecialchars($article['body_text']) ?></div>

    <div class="comments-section">
        <h3>💬 Commenti (<?= count($comments) ?>)</h3>

        <?php foreach ($comments as $c): ?>
            <div class="comment-item">
                <div class="comment-meta">
                    <strong><?= htmlspecialchars($c['username_snapshot']) ?></strong> · <?= htmlspecialchars(format_date_it($c['created_at'])) ?>
                </div>
                <div class="comment-body"><?= htmlspecialchars($c['body']) ?></div>
                <?php if ($me && ($me['role'] === 'admin' || (int)$c['user_id'] === (int)$me['id'])): ?>
                    <form method="post" onsubmit="return confirm('Eliminare questo commento?');" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_comment" value="1">
                        <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="comment-delete">🗑️ Elimina</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (empty($comments)): ?>
            <p style="color:#6c757d;">Nessun commento.</p>
        <?php endif; ?>

        <?php if ($canComment): ?>
            <form method="post" class="comment-form" style="margin-top:15px;">
                <?= csrf_field() ?>
                <input type="hidden" name="post_comment" value="1">
                <textarea name="body" required maxlength="2000" placeholder="Scrivi un commento..."></textarea>
                <button type="submit" style="margin-top:8px;">Pubblica commento</button>
            </form>
        <?php elseif (!$me): ?>
            <p style="color:#6c757d;"><a href="login.php?next=<?= urlencode('news_article.php?id=' . $id) ?>">Accedi</a> come collaboratore per poter commentare.</p>
        <?php endif; ?>
    </div>
</body>
</html>
