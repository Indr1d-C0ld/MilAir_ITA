<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/news_lib.php';
auth_bootstrap();
log_access();
require_role('admin');

$db = get_news_db();
$flashMsg = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['create_feed'])) {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $defaultAuthor = trim($_POST['default_author'] ?? '');

        if ($name === '' || mb_strlen($name) > 150) {
            $flashMsg = 'Nome non valido.';
            $flashType = 'error';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $flashMsg = 'URL del feed non valido.';
            $flashType = 'error';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO feed_sources (name, url, default_author) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $url);
                $stmt->bindValue(3, $defaultAuthor !== '' ? $defaultAuthor : null);
                $stmt->execute();
                $flashMsg = "Fonte \"" . $name . "\" aggiunta.";
            } catch (Exception $e) {
                $flashMsg = 'Errore: URL già presente tra le fonti.';
                $flashType = 'error';
            }
        }
    } elseif (isset($_POST['update_feed'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $defaultAuthor = trim($_POST['default_author'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $flashMsg = 'Nome o URL non validi.';
            $flashType = 'error';
        } else {
            try {
                $stmt = $db->prepare("UPDATE feed_sources SET name = ?, url = ?, default_author = ?, is_active = ? WHERE id = ?");
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $url);
                $stmt->bindValue(3, $defaultAuthor !== '' ? $defaultAuthor : null);
                $stmt->bindValue(4, $isActive);
                $stmt->bindValue(5, $id);
                $stmt->execute();
                $flashMsg = 'Fonte aggiornata.';
            } catch (Exception $e) {
                $flashMsg = 'Errore: URL già usato da un\'altra fonte.';
                $flashType = 'error';
            }
        }
    } elseif (isset($_POST['delete_feed'])) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM articles WHERE feed_id = ?");
        $stmt->bindValue(1, $id);
        $articleCount = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
        if ($articleCount > 0) {
            $flashMsg = "Impossibile eliminare: questa fonte ha già {$articleCount} articoli associati. Disattivala invece, per non orfanizzare articoli e commenti già pubblicati.";
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("DELETE FROM feed_sources WHERE id = ?");
            $stmt->bindValue(1, $id);
            $stmt->execute();
            $flashMsg = 'Fonte eliminata.';
        }
    }
}

$feeds = [];
$res = $db->query("SELECT f.*, (SELECT COUNT(*) FROM articles a WHERE a.feed_id = f.id) AS article_count
    FROM feed_sources f ORDER BY f.created_at DESC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $feeds[] = $row;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestione Feed RSS – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .msg-banner { padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .msg-banner.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-banner.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .rules-table-container { max-height: 500px; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 30px; border: 1px solid #dee2e6; border-radius: 6px; }
        .edit-row { display: none; background: #f8f9fa; }
        .edit-row form { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 8px 0; }
        .edit-row label { display: flex; flex-direction: column; font-size: 0.85em; gap: 2px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: bold; }
        .badge.active { background: #28a745; color: #fff; }
        .badge.inactive { background: #6c757d; color: #fff; }
        .badge.status-ok { background: #28a745; color: #fff; }
        .badge.status-error { background: #dc3545; color: #fff; }
        .url-cell { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85em; }
    </style>
</head>
<body>
    <?php render_nav('admin_feeds.php'); ?>

    <h2>🗞️ Gestione Feed RSS</h2>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <h3>Nuova fonte</h3>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="create_feed" value="1">
        <label>Nome: <input type="text" name="name" required maxlength="150" placeholder="es. ItaMilRadar"></label>
        <label>URL del feed RSS/Atom: <input type="text" name="url" required placeholder="https://esempio.com/feed/"></label>
        <label>Autore predefinito (se il feed non lo indica): <input type="text" name="default_author"></label>
        <button type="submit">Aggiungi fonte</button>
    </form>

    <h3>Fonti esistenti</h3>
    <div class="rules-table-container">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>URL</th>
                    <th>Stato</th>
                    <th>Ultimo fetch</th>
                    <th>Esito</th>
                    <th>Articoli</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feeds as $f): $rowId = 'feed-' . $f['id']; ?>
                    <tr id="view-<?= $rowId ?>">
                        <td><?= htmlspecialchars($f['name']) ?></td>
                        <td class="url-cell" title="<?= htmlspecialchars($f['url']) ?>"><a href="<?= htmlspecialchars($f['url']) ?>" target="_blank"><?= htmlspecialchars($f['url']) ?></a></td>
                        <td><?php if ((int)$f['is_active']): ?><span class="badge active">attiva</span><?php else: ?><span class="badge inactive">disattiva</span><?php endif; ?></td>
                        <td><?= $f['last_fetched_at'] ? htmlspecialchars(format_date_it($f['last_fetched_at'])) : '-' ?></td>
                        <td><?php if ($f['last_fetch_status'] === 'ok'): ?><span class="badge status-ok">ok (<?= (int)$f['last_fetch_item_count'] ?>)</span><?php elseif ($f['last_fetch_status'] === 'error'): ?><span class="badge status-error" title="<?= htmlspecialchars($f['last_fetch_error'] ?? '') ?>">errore</span><?php else: ?>-<?php endif; ?></td>
                        <td><?= (int)$f['article_count'] ?></td>
                        <td>
                            <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                            <?php if ((int)$f['article_count'] === 0): ?>
                                <form method="post" class="inline-form" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente questa fonte?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_feed" value="1">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="edit-<?= $rowId ?>" class="edit-row">
                        <td colspan="7">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="update_feed" value="1">
                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                <label>Nome
                                    <input type="text" name="name" value="<?= htmlspecialchars($f['name']) ?>" required>
                                </label>
                                <label>URL
                                    <input type="text" name="url" value="<?= htmlspecialchars($f['url']) ?>" required style="min-width:260px;">
                                </label>
                                <label>Autore predefinito
                                    <input type="text" name="default_author" value="<?= htmlspecialchars($f['default_author'] ?? '') ?>">
                                </label>
                                <label>Attiva
                                    <input type="checkbox" name="is_active" value="1" <?= (int)$f['is_active'] ? 'checked' : '' ?>>
                                </label>
                                <button type="submit">💾 Salva</button>
                                <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($feeds)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#6c757d;">Nessuna fonte configurata.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
