#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

/**
 * Scarica periodicamente gli articoli dalle fonti RSS/Atom configurate in
 * admin_feeds.php, deduplica per (feed_id, guid), estrae le parole chiave e
 * genera un alert nel sistema di notifiche per ogni articolo nuovo.
 *
 * Da eseguire via cron (vedi crontab.txt) — non installa da sé la voce di
 * crontab, va aggiunta manualmente al crontab reale del sistema.
 */

require_once __DIR__ . '/news_lib.php';
require_once __DIR__ . '/auth.php'; // solo per create_alert(): NON chiama auth_bootstrap() (nessuna sessione in CLI)

const NEWS_ARTICLE_EXCERPT_LEN = 200;

$db = get_news_db();
$logPrefix = '[' . date('Y-m-d H:i:s') . ']';

$feeds = [];
$res = $db->query("SELECT * FROM feed_sources WHERE is_active = 1");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $feeds[] = $row;
}

echo "$logPrefix Fonti attive da processare: " . count($feeds) . "\n";

foreach ($feeds as $feed) {
    echo "$logPrefix Fonte: {$feed['name']} ({$feed['url']})\n";

    $xml = fetch_feed_xml($feed['url']);
    if ($xml === null) {
        echo "$logPrefix  Errore: impossibile scaricare/interpretare il feed.\n";
        $stmt = $db->prepare("UPDATE feed_sources SET last_fetched_at = CURRENT_TIMESTAMP, last_fetch_status = 'error', last_fetch_error = ? WHERE id = ?");
        $stmt->bindValue(1, 'Download o parsing XML fallito');
        $stmt->bindValue(2, $feed['id']);
        $stmt->execute();
        continue;
    }

    $items = normalize_feed_items($xml);
    $newCount = 0;

    foreach ($items as $item) {
        if ($item['guid'] === '' || $item['title'] === '') {
            continue;
        }

        $stmtCheck = $db->prepare("SELECT id FROM articles WHERE feed_id = ? AND guid = ?");
        $stmtCheck->bindValue(1, $feed['id']);
        $stmtCheck->bindValue(2, $item['guid']);
        if ($stmtCheck->execute()->fetchArray(SQLITE3_ASSOC)) {
            continue; // già visto
        }

        $bodyText = clean_html_to_text($item['raw_body']);
        if ($bodyText === '') {
            $bodyText = $item['title'];
        }
        $author = $item['author'] !== '' ? $item['author'] : ($feed['default_author'] ?: $feed['name']);
        $keywords = extract_keywords($bodyText);
        $topKeyword = $keywords[0]['word'] ?? null;

        $stmt = $db->prepare("INSERT INTO articles (feed_id, guid, link, title, body_text, author, published_at, keywords_json, top_keyword)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $feed['id']);
        $stmt->bindValue(2, $item['guid']);
        $stmt->bindValue(3, $item['link']);
        $stmt->bindValue(4, $item['title']);
        $stmt->bindValue(5, $bodyText);
        $stmt->bindValue(6, $author);
        $stmt->bindValue(7, $item['published_at']);
        $stmt->bindValue(8, json_encode($keywords, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(9, $topKeyword);
        $stmt->execute();
        $articleId = $db->lastInsertRowID();

        foreach ($keywords as $kw) {
            $stmtKw = $db->prepare("INSERT OR IGNORE INTO article_keywords (article_id, keyword, weight) VALUES (?, ?, ?)");
            $stmtKw->bindValue(1, $articleId);
            $stmtKw->bindValue(2, $kw['word']);
            $stmtKw->bindValue(3, $kw['count']);
            $stmtKw->execute();
        }

        $excerpt = mb_substr($bodyText, 0, NEWS_ARTICLE_EXCERPT_LEN);
        create_alert('new_article', null, null, $item['title'], $excerpt, $articleId);

        $newCount++;
    }

    echo "$logPrefix  {$newCount} nuovi articoli su " . count($items) . " nel feed.\n";

    $stmt = $db->prepare("UPDATE feed_sources SET last_fetched_at = CURRENT_TIMESTAMP, last_fetch_status = 'ok', last_fetch_error = NULL, last_fetch_item_count = ? WHERE id = ?");
    $stmt->bindValue(1, $newCount);
    $stmt->bindValue(2, $feed['id']);
    $stmt->execute();
}

echo "$logPrefix Completato.\n";
