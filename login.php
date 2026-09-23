<?php
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $result = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($result['ok']) {
        // Evita open-redirect: solo pagine locali. safe_local_url() accetta anche il
        // percorso assoluto che require_role() passa ("/milair_ita/map.php?..."):
        // prima la regex locale lo rifiutava e si finiva sempre su index.php.
        header('Location: ' . safe_local_url($_POST['next'] ?? ''));
        exit;
    }
    $error = $result['error'];
}

$next = safe_local_url($_GET['next'] ?? $_POST['next'] ?? '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accedi – MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .login-box { max-width: 380px; margin: 80px auto; background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .login-box h2 { margin-top: 0; text-align: center; }
        .login-box label { display: block; margin-bottom: 14px; }
        .login-box input[type="text"], .login-box input[type="password"] { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; }
        .login-box button { width: 100%; padding: 10px; margin-top: 10px; }
        .login-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px; padding: 10px; margin-bottom: 14px; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🔑 Accedi</h2>
        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <label>Username
                <input type="text" name="username" required autofocus>
            </label>
            <label>Password
                <input type="password" name="password" required>
            </label>
            <button type="submit">Accedi</button>
        </form>
        <p style="text-align:center;margin-top:16px;"><a href="index.php">← Torna alla tabella pubblica</a></p>
    </div>
</body>
</html>
