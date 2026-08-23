#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);

$db->exec("CREATE TABLE IF NOT EXISTS rarity_cache (hex TEXT PRIMARY KEY, seen_count INTEGER, rarity TEXT)");
$db->exec("BEGIN TRANSACTION");
$db->exec("DELETE FROM rarity_cache");

$sql = "
    INSERT INTO rarity_cache (hex, seen_count, rarity)
    WITH ranked AS (
        SELECT hex, seen_count,
               PERCENT_RANK() OVER (ORDER BY seen_count ASC) AS pr
        FROM aircraft
    )
    SELECT hex, seen_count,
           CASE
               WHEN pr <= 0.001 THEN 'Mythic'
               WHEN pr <= 0.01  THEN 'Legendary'
               WHEN pr <= 0.05  THEN 'Epic'
               WHEN pr <= 0.15  THEN 'Rare'
               WHEN pr <= 0.30  THEN 'Uncommon'
               ELSE 'Common'
           END
    FROM ranked
";
$db->exec($sql);
$db->exec("COMMIT");

$count = $db->querySingle("SELECT COUNT(*) FROM rarity_cache");
echo "Cache rarità aggiornata: $count hex classificati.\n";