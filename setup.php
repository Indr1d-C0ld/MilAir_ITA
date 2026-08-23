<?php
/**
 * Crea il primo account Admin. Funziona SOLO finché la tabella users è
 * vuota — si autodisabilita permanentemente subito dopo (stesso pattern di
 * WordPress/Nextcloud). Il controllo definitivo avviene dentro una
 * transazione BEGIN IMMEDIATE per evitare race condition tra due visitatori
 * che aprissero la pagina nello stesso istante.
 */
require_once __DIR__ . '/auth.php';
auth_bootstrap();

$db = get_auth_db();

$existing = (int)$db->querySingle('SELECT COUNT(*) FROM users');
if ($existing > 0) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup completato</title>'
        . '<link rel="stylesheet" href="style.css"></head><body style="padding:60px;text-align:center;">'
        . '<h2>✅ Setup già completato</h2><p>Il primo account amministratore esiste già.</p>'
        . '<p><a href="login.php">Vai al login</a></p></body></html>');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '') ?: $username;

    if ($username === '' || strlen($username) < 3) {
        $error = 'Username troppo corto (minimo 3 caratteri).';
    } elseif (!preg_match('/^[A-Za-z0-9_.\-]+$/', $username)) {
        $error = 'Username: usa solo lettere, numeri, punto, trattino, underscore.';
    } elseif (strlen($password) < 10) {
        $error = 'La password deve essere di almeno 10 caratteri.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Le due password non coincidono.';
    } else {
        try {
            $db->exec('BEGIN IMMEDIATE');
            $recheck = (int)$db->querySingle('SELECT COUNT(*) FROM users');
            if ($recheck > 0) {
                $db->exec('ROLLBACK');
                $error = 'Il setup è già stato completato (probabilmente da un\'altra richiesta contemporanea).';
            } else {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, 'admin', ?)");
                $stmt->bindValue(1, $username);
                $stmt->bindValue(2, hash_password($password));
                $stmt->bindValue(3, $displayName);
                $stmt->execute();
                $db->exec('COMMIT');
                $success = true;
            }
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            $error = 'Errore durante la creazione dell\'account: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Configurazione iniziale – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .setup-box { max-width: 420px; margin: 60px auto; background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .setup-box h2 { margin-top: 0; text-align: center; }
        .setup-box label { display: block; margin-bottom: 14px; }
        .setup-box input[type="text"], .setup-box input[type="password"] { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; }
        .setup-box button { width: 100%; padding: 10px; margin-top: 10px; }
        .setup-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px; padding: 10px; margin-bottom: 14px; font-size: 0.9em; }
        .setup-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 6px; padding: 16px; text-align: center; }
        .setup-hint { font-size: 0.85em; color: #6c757d; margin-top: -8px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="setup-box">
        <h2>🛠️ Configurazione iniziale</h2>
        <?php if ($success): ?>
            <div class="setup-success">
                ✅ Account amministratore <strong><?= htmlspecialchars($username) ?></strong> creato con successo.<br><br>
                <a href="login.php">Accedi ora →</a>
            </div>
        <?php else: ?>
            <p style="text-align:center;color:#6c757d;font-size:0.9em;">Nessun account esiste ancora. Crea il primo account amministratore — questa pagina si disattiverà subito dopo.</p>
            <?php if ($error): ?>
                <div class="setup-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <label>Username
                    <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                </label>
                <label>Nome visualizzato (opzionale)
                    <input type="text" name="display_name" value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                </label>
                <label>Password (minimo 10 caratteri)
                    <input type="password" name="password" required minlength="10">
                </label>
                <label>Conferma password
                    <input type="password" name="password_confirm" required minlength="10">
                </label>
                <button type="submit">Crea account amministratore</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
