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

    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $role = $_POST['role'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '') ?: $username;

        if ($username === '' || strlen($username) < 3 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $username)) {
            $flashMsg = 'Username non valido (minimo 3 caratteri; solo lettere, numeri, punto, trattino, underscore).';
            $flashType = 'error';
        } elseif (!in_array($role, ['collaboratore', 'admin'], true)) {
            $flashMsg = 'Ruolo non valido.';
            $flashType = 'error';
        } elseif (strlen($password) < 10) {
            $flashMsg = 'La password deve avere almeno 10 caratteri.';
            $flashType = 'error';
        } elseif ($password !== $passwordConfirm) {
            $flashMsg = 'Le due password non coincidono.';
            $flashType = 'error';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, display_name, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $username);
                $stmt->bindValue(2, hash_password($password));
                $stmt->bindValue(3, $role);
                $stmt->bindValue(4, $displayName);
                $stmt->bindValue(5, $me['id']);
                $stmt->execute();
                $flashMsg = "Utente \"" . $username . "\" creato con successo.";
            } catch (Exception $e) {
                $flashMsg = 'Errore: username già esistente.';
                $flashType = 'error';
            }
        }
    } elseif (isset($_POST['update_user'])) {
        $id = (int)($_POST['id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        $role = $_POST['role'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        $stmtCheck = $db->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $stmtCheck->bindValue(1, $id);
        $target = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$target) {
            $flashMsg = 'Utente non trovato.';
            $flashType = 'error';
        } elseif (!in_array($role, ['collaboratore', 'admin'], true)) {
            $flashMsg = 'Ruolo non valido.';
            $flashType = 'error';
        } else {
            // Impedisce di disattivare o retrocedere l'ultimo amministratore attivo
            $becomesInactiveOrDemoted = ($isActive === 0) || ($role !== 'admin');
            $wasActiveAdmin = ($target['role'] === 'admin' && (int)$target['is_active'] === 1);
            if ($wasActiveAdmin && $becomesInactiveOrDemoted) {
                $stmt = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?");
                $stmt->bindValue(1, $id);
                $otherAdmins = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
                if ($otherAdmins === 0) {
                    $flashMsg = 'Operazione rifiutata: non puoi disattivare o retrocedere l\'unico amministratore attivo.';
                    $flashType = 'error';
                }
            }

            if ($flashMsg === '') {
                $stmt = $db->prepare("UPDATE users SET display_name = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->bindValue(1, $displayName);
                $stmt->bindValue(2, $role);
                $stmt->bindValue(3, $isActive);
                $stmt->bindValue(4, $id);
                $stmt->execute();

                if ($newPassword !== '') {
                    if (strlen($newPassword) < 10) {
                        $flashMsg = 'Dati aggiornati. La nuova password NON è stata impostata (minimo 10 caratteri).';
                        $flashType = 'error';
                    } else {
                        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $stmt->bindValue(1, hash_password($newPassword));
                        $stmt->bindValue(2, $id);
                        $stmt->execute();
                        $flashMsg = 'Utente aggiornato e password reimpostata.';
                    }
                } else {
                    $flashMsg = 'Utente aggiornato.';
                }
            }
        }
    } elseif (isset($_POST['change_own_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $newConfirm = $_POST['new_password_confirm'] ?? '';

        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->bindValue(1, $me['id']);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$row || !verify_password($current, $row['password_hash'])) {
            $flashMsg = 'Password attuale non corretta.';
            $flashType = 'error';
        } elseif (strlen($new) < 10) {
            $flashMsg = 'La nuova password deve avere almeno 10 caratteri.';
            $flashType = 'error';
        } elseif ($new !== $newConfirm) {
            $flashMsg = 'Le due nuove password non coincidono.';
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bindValue(1, hash_password($new));
            $stmt->bindValue(2, $me['id']);
            $stmt->execute();
            $flashMsg = 'La tua password è stata aggiornata.';
        }
    }
}

$users = [];
$res = $db->query("SELECT u.*, creator.username AS created_by_name
    FROM users u LEFT JOIN users creator ON u.created_by = creator.id
    ORDER BY u.created_at DESC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $users[] = $row;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestione Utenti – MILAIR ITA</title>
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
        .badge.admin { background: #dc3545; color: #fff; }
        .badge.collaboratore { background: #007bff; color: #fff; }
        .badge.inactive { background: #6c757d; color: #fff; }
        .you-badge { background: #28a745; color: #fff; padding: 1px 6px; border-radius: 8px; font-size: 0.75em; margin-left: 4px; }
    </style>
</head>
<body>
    <?php render_nav('admin_users.php'); ?>

    <h2>👤 Gestione Utenti</h2>

    <?php if ($flashMsg !== ''): ?>
        <div class="msg-banner <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <h3>Nuovo account</h3>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="create_user" value="1">
        <label>Username: <input type="text" name="username" required></label>
        <label>Nome visualizzato: <input type="text" name="display_name"></label>
        <label>Ruolo:
            <select name="role">
                <option value="collaboratore">Collaboratore</option>
                <option value="admin">Admin</option>
            </select>
        </label>
        <label>Password (min. 10 caratteri): <input type="password" name="password" required minlength="10"></label>
        <label>Conferma password: <input type="password" name="password_confirm" required minlength="10"></label>
        <button type="submit">Crea account</button>
    </form>

    <h3>Account esistenti</h3>
    <div class="rules-table-container">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nome</th>
                    <th>Ruolo</th>
                    <th>Stato</th>
                    <th>Creato</th>
                    <th>Ultimo accesso</th>
                    <th>Ultimo IP</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): $rowId = 'user-' . $u['id']; $isMe = ((int)$u['id'] === (int)$me['id']); ?>
                    <tr id="view-<?= $rowId ?>">
                        <td><?= htmlspecialchars($u['username']) ?><?php if ($isMe): ?><span class="you-badge">tu</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                        <td><span class="badge <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                        <td><?php if (!(int)$u['is_active']): ?><span class="badge inactive">disattivo</span><?php else: ?>attivo<?php endif; ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?><?= $u['created_by_name'] ? ' (da ' . htmlspecialchars($u['created_by_name']) . ')' : '' ?></td>
                        <td><?= htmlspecialchars($u['last_login_at'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($u['last_login_ip'] ?? '-') ?></td>
                        <td>
                            <button type="button" class="btn" onclick="toggleEditRow('<?= $rowId ?>')">✏️</button>
                        </td>
                    </tr>
                    <tr id="edit-<?= $rowId ?>" class="edit-row">
                        <td colspan="8">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="update_user" value="1">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <label>Nome visualizzato
                                    <input type="text" name="display_name" value="<?= htmlspecialchars($u['display_name'] ?? '') ?>">
                                </label>
                                <label>Ruolo
                                    <select name="role">
                                        <option value="collaboratore" <?= $u['role'] === 'collaboratore' ? 'selected' : '' ?>>Collaboratore</option>
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </label>
                                <label>Attivo
                                    <input type="checkbox" name="is_active" value="1" <?= (int)$u['is_active'] ? 'checked' : '' ?>>
                                </label>
                                <label>Nuova password (lascia vuoto per non cambiarla)
                                    <input type="password" name="new_password" minlength="10">
                                </label>
                                <button type="submit">💾 Salva</button>
                                <button type="button" onclick="toggleEditRow('<?= $rowId ?>')">Annulla</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3>🔑 Cambia la tua password</h3>
    <form method="post" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="change_own_password" value="1">
        <label>Password attuale: <input type="password" name="current_password" required></label>
        <label>Nuova password: <input type="password" name="new_password" required minlength="10"></label>
        <label>Conferma nuova password: <input type="password" name="new_password_confirm" required minlength="10"></label>
        <button type="submit">Aggiorna password</button>
    </form>

    <script>
        function toggleEditRow(rowId) {
            var view = document.getElementById('view-' + rowId);
            var edit = document.getElementById('edit-' + rowId);
            if (!view || !edit) return;
            var editing = edit.style.display === 'table-row';
            edit.style.display = editing ? 'none' : 'table-row';
        }
    </script>
</body>
</html>
