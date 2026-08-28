<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);
$db->busyTimeout(5000);

// Assicurati che la tabella favorites esista (la crei con toggle_favorite.php o rules.php)

$search = trim($_GET['q'] ?? '');
$rarityFilter = $_GET['rarity'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$res = $db->query("
    SELECT f.hex, f.note AS fav_note, f.created_at, a.callsign, a.reg, a.model_t,
           a.first_seen_utc, a.last_seen_utc, r.rarity
    FROM favorites f
    LEFT JOIN aircraft a ON f.hex = a.hex
    LEFT JOIN rarity_cache r ON f.hex = r.hex
");
$rows = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

$rarities = array_values(array_unique(array_filter(array_column($rows, 'rarity'))));
sort($rarities);

if ($search !== '') {
    $rows = array_filter($rows, function ($r) use ($search) {
        $haystack = implode(' ', [$r['hex'], $r['callsign'], $r['reg'], $r['model_t'], $r['fav_note']]);
        return stripos($haystack, $search) !== false;
    });
}
if ($rarityFilter !== '') {
    $rows = array_filter($rows, fn($r) => ($r['rarity'] ?? '') === $rarityFilter);
}

function favRarityOrder($r) {
    static $order = ['Mythic' => 0, 'Legendary' => 1, 'Epic' => 2, 'Rare' => 3, 'Uncommon' => 4, 'Common' => 5];
    return $order[$r] ?? 99;
}

usort($rows, function ($a, $b) use ($sort, $order) {
    $fieldMap = [
        'hex' => 'hex', 'callsign' => 'callsign', 'reg' => 'reg', 'model_t' => 'model_t',
        'rarity' => 'rarity', 'seen' => 'last_seen_utc', 'fav_note' => 'fav_note', 'created_at' => 'created_at',
    ];
    $key = $fieldMap[$sort] ?? 'created_at';
    if ($sort === 'rarity') {
        $cmp = favRarityOrder($a['rarity'] ?? '') <=> favRarityOrder($b['rarity'] ?? '');
    } elseif ($sort === 'seen') {
        $cmp = strcmp((string)($a['last_seen_utc'] ?? ''), (string)($b['last_seen_utc'] ?? ''));
    } else {
        $cmp = strcasecmp((string)($a[$key] ?? ''), (string)($b[$key] ?? ''));
    }
    return $order === 'asc' ? $cmp : -$cmp;
});

$getParams = array_intersect_key($_GET, array_flip(['q', 'rarity', 'sort', 'order']));

function favSortLink($columnKey, $label, $currentSort, $currentOrder, $getParams) {
    $newOrder = ($currentSort === $columnKey && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $columnKey) {
        $arrow = ($currentOrder === 'asc') ? ' ▲' : ' ▼';
    }
    $merged = array_merge($getParams, ['sort' => $columnKey, 'order' => $newOrder]);
    return '<a href="?' . http_build_query($merged) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preferiti – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <?php if (is_logged_in()): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php endif; ?>
    <style>
        .fav-note { font-style: italic; color: #6c757d; }
    </style>
</head>
<body>
    <?php render_nav('favorites.php'); ?>

    <h2>⭐ Contatti Preferiti</h2>

    <form method="get" class="filter-bar">
        <label>Cerca: <input type="text" name="q" placeholder="hex, callsign, reg, modello, nota..." value="<?= htmlspecialchars($search) ?>"></label>
        <label>Rarità:
            <select name="rarity">
                <option value="">Tutte</option>
                <?php foreach ($rarities as $r): ?>
                    <option value="<?= htmlspecialchars($r) ?>" <?= $rarityFilter === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Filtra</button>
        <a href="favorites.php" class="btn">Reset</a>
        <span style="margin-left:auto;color:#6c757d;font-size:0.9em;"><?= count($rows) ?> risultati</span>
    </form>

    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th><?= favSortLink('hex', 'HEX', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('callsign', 'Callsign', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('reg', 'Reg', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('model_t', 'Modello', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('rarity', 'Rarità', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('seen', 'Prima/Ultima', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('fav_note', 'Nota preferito', $sort, $order, $getParams) ?></th>
                <th><?= favSortLink('created_at', 'Aggiunto il', $sort, $order, $getParams) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <a href="index.php?hex=<?= urlencode($row['hex']) ?>&sort=ident_last_seen&order=desc" target="_blank"><?= htmlspecialchars($row['hex']) ?></a>
                </td>
                <td><?= htmlspecialchars($row['callsign']) ?></td>
                <td><?= htmlspecialchars($row['reg']) ?></td>
                <td><?= htmlspecialchars($row['model_t']) ?></td>
                <td class="rarity-<?= $row['rarity'] ?>"><?= $row['rarity'] ?></td>
                <td><?= htmlspecialchars($row['first_seen_utc']) ?> → <?= htmlspecialchars($row['last_seen_utc']) ?></td>
                <td>
                    <?php if (!empty($row['fav_note'])): ?>
                        <span class="fav-note"><?= htmlspecialchars($row['fav_note']) ?></span>
                    <?php endif; ?>
                    <?php if (is_logged_in()): ?>
                        <a href="edit_favorite.php?hex=<?= urlencode($row['hex']) ?>" title="Modifica nota preferito" style="font-size:0.8em;">✏️</a>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                <td>
                    <?php if (is_logged_in()): ?>
                        <a href="#" onclick="removeFavorite('<?= htmlspecialchars($row['hex'], ENT_QUOTES) ?>', this); return false;" title="Rimuovi dai preferiti">✖</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" style="text-align:center;color:#6c757d;">Nessun risultato per il filtro corrente.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if (is_logged_in()): ?>
    <script>
        function removeFavorite(hex, link) {
            if (!confirm('Rimuovere questo contatto dai preferiti?')) return;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            fetch('toggle_favorite.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ajax: '1', hex: hex, action: 'remove', csrf_token: csrf})
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    link.closest('tr').remove();
                } else {
                    alert('Errore: ' + (data.error || 'operazione non riuscita'));
                }
            })
            .catch(() => alert('Errore di rete.'));
        }
    </script>
    <?php endif; ?>
</body>
</html>
