<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$db = get_auth_db();
$flashMsg = '';
$flashType = 'success';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Honeypot: campo nascosto via CSS, invisibile e non compilabile da un utente reale.
    // Se arriva valorizzato la richiesta è quasi certamente di un bot — la scartiamo
    // silenziosamente mostrando comunque un messaggio di successo, per non rivelare
    // al bot che è stato individuato.
    $honeypot = trim($_POST['website'] ?? '');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $requestType = $_POST['request_type'] ?? 'contact';
    $message = trim($_POST['message'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($honeypot !== '') {
        $sent = true;
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $flashMsg = 'Inserisci un nome valido.';
        $flashType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashMsg = 'Inserisci un indirizzo email valido.';
        $flashType = 'error';
    } elseif (!in_array($requestType, ['contact', 'collab_access'], true)) {
        $flashMsg = 'Tipo di richiesta non valido.';
        $flashType = 'error';
    } elseif ($message === '' || mb_strlen($message) > 3000) {
        $flashMsg = 'Inserisci un messaggio (max 3000 caratteri).';
        $flashType = 'error';
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM requests WHERE ip = ? AND created_at > datetime('now','-1 hour')");
        $stmt->bindValue(1, $ip);
        $recentCount = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];

        if ($recentCount >= 5) {
            $flashMsg = 'Hai inviato troppe richieste di recente. Riprova più tardi.';
            $flashType = 'error';
        } else {
            $stmt = $db->prepare("INSERT INTO requests (name, email, request_type, message, ip) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $name);
            $stmt->bindValue(2, $email);
            $stmt->bindValue(3, $requestType);
            $stmt->bindValue(4, $message);
            $stmt->bindValue(5, $ip);
            $stmt->execute();
            $requestId = $db->lastInsertRowID();
            $typeLabel = $requestType === 'collab_access' ? 'richiesta accesso collaboratore' : 'contatto';
            create_alert('new_request', null, $requestId,
                "Nuova richiesta ({$typeLabel}) da " . $name,
                mb_substr($message, 0, 200));
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Richieste – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .req-box { max-width: 560px; margin: 30px auto; background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .req-box h2 { margin-top: 0; }
        .req-box label { display: block; margin-bottom: 14px; }
        .req-box input[type="text"], .req-box input[type="email"], .req-box select, .req-box textarea {
            display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px;
            border: 1px solid #ced4da; border-radius: 4px; font-family: inherit;
        }
        .req-box textarea { min-height: 120px; resize: vertical; }
        .req-box button { padding: 10px 20px; margin-top: 6px; }
        .req-box .website-field { position: absolute; left: -9999px; top: -9999px; }
        .msg-banner { padding: 10px 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; }
        .msg-banner.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-banner.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .req-hint { font-size: 0.85em; color: #6c757d; margin-top: -8px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <?php render_nav('richieste.php'); ?>

    <div class="req-box">
        <h2>✉️ Richieste</h2>
        <p>Usa questo modulo per contattare l'amministratore del portale, oppure per richiedere l'accesso come collaboratore (funzioni di editing: contrassegni, note, correzioni identità, regole).</p>

        <?php if ($sent): ?>
            <div class="msg-banner success">Richiesta inviata. Verrà esaminata dall'amministratore al più presto.</div>
        <?php elseif ($flashMsg !== ''): ?>
            <div class="msg-banner error"><?= htmlspecialchars($flashMsg) ?></div>
        <?php endif; ?>

        <?php if (!$sent): ?>
        <form method="post">
            <?= csrf_field() ?>
            <label class="website-field" aria-hidden="true">
                Sito web
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </label>
            <label>Nome
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required maxlength="100">
            </label>
            <label>Email
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </label>
            <label>Tipo di richiesta
                <select name="request_type">
                    <option value="contact" <?= ($_POST['request_type'] ?? '') === 'contact' ? 'selected' : '' ?>>Contatto generico</option>
                    <option value="collab_access" <?= ($_POST['request_type'] ?? '') === 'collab_access' ? 'selected' : '' ?>>Richiesta accesso collaboratore</option>
                </select>
            </label>
            <label>Messaggio
                <textarea name="message" required maxlength="3000"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </label>
            <div class="req-hint">Max 3000 caratteri.</div>
            <button type="submit">Invia richiesta</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
