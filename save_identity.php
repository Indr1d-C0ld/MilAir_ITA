<?php
// Salva correzioni manuali dell'analista (reg/callsign/model_t) e/o carica manualmente
// una foto per un contatto identificato solo parzialmente, senza attendere il ciclo
// di scraping automatico. Risponde in JSON (chiamato via fetch da index.php).
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();

$dbPath = __DIR__ . '/events.db';

header('Content-Type: application/json; charset=utf-8');

function respond($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}

function sanitizeCode($value) {
    return preg_replace('/[^A-Za-z0-9_\-]/', '', strtoupper(trim($value)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, ['error' => 'metodo non consentito']);
}

// require_role() risponderebbe con una pagina HTML in caso di rifiuto: per un
// endpoint JSON è più corretto un errore JSON esplicito.
if ((ROLE_RANK[current_role()] ?? 0) < ROLE_RANK['collaboratore']) {
    http_response_code(is_logged_in() ? 403 : 401);
    respond(false, ['error' => 'accesso non autorizzato: effettua il login come collaboratore o admin']);
}
require_csrf();

$hex = sanitizeCode($_POST['hex'] ?? '');
if (!preg_match('/^[0-9A-F]{6}$/', $hex)) {
    http_response_code(400);
    respond(false, ['error' => 'hex non valido']);
}
$hexLower = strtolower($hex);

$reg = trim($_POST['reg'] ?? '');
$callsign = trim($_POST['callsign'] ?? '');
$modelT = strtoupper(trim($_POST['model_t'] ?? ''));
$clearOverride = isset($_POST['clear_override']);

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec("CREATE TABLE IF NOT EXISTS manual_overrides (
        hex TEXT PRIMARY KEY,
        reg TEXT,
        callsign TEXT,
        model_t TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    if ($clearOverride) {
        $stmt = $db->prepare("DELETE FROM manual_overrides WHERE hex = ?");
        $stmt->bindValue(1, $hexLower);
        $stmt->execute();
    } else {
        $stmt = $db->prepare("INSERT INTO manual_overrides (hex, reg, callsign, model_t, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(hex) DO UPDATE SET reg = excluded.reg, callsign = excluded.callsign,
                model_t = excluded.model_t, updated_at = CURRENT_TIMESTAMP");
        $stmt->bindValue(1, $hexLower);
        $stmt->bindValue(2, $reg !== '' ? $reg : null);
        $stmt->bindValue(3, $callsign !== '' ? $callsign : null);
        $stmt->bindValue(4, $modelT !== '' ? $modelT : null);
        $stmt->execute();
    }
} catch (Exception $e) {
    http_response_code(500);
    respond(false, ['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------------------
// Upload foto manuale (opzionale, indipendente dal salvataggio dell'identità)

function handleImageUpload($fieldName, $destDir, $destBasename, &$errors) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "$fieldName: errore di upload (codice " . $file['error'] . ')';
        return false;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        $errors[] = "$fieldName: file troppo grande (max 8MB)";
        return false;
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        $errors[] = "$fieldName: il file non è un'immagine valida";
        return false;
    }

    $image = null;
    switch ($info['mime']) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $image = @imagecreatefrompng($file['tmp_name']); break;
        case 'image/gif':  $image = @imagecreatefromgif($file['tmp_name']); break;
        case 'image/webp': $image = @imagecreatefromwebp($file['tmp_name']); break;
        default:
            $errors[] = "$fieldName: formato non supportato (usa JPG, PNG, GIF o WEBP)";
            return false;
    }
    if (!$image) {
        $errors[] = "$fieldName: impossibile decodificare l'immagine";
        return false;
    }

    // Appiattisce eventuale trasparenza su sfondo bianco prima di salvare come JPEG
    $width = imagesx($image);
    $height = imagesy($image);
    $flat = imagecreatetruecolor($width, $height);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
    imagedestroy($image);

    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    $destPath = $destDir . '/' . $destBasename . '.jpg';
    $ok = imagejpeg($flat, $destPath, 90);
    imagedestroy($flat);

    if (!$ok) {
        $errors[] = "$fieldName: impossibile salvare l'immagine sul server";
        return false;
    }
    return true;
}

$uploadErrors = [];
// fdbphotos/ usa il nome file in MAIUSCOLO (convenzione di download_fdb_photos.php e
// getFdbPhotoPath() in index.php) — a differenza della tabella manual_overrides, dove
// l'hex resta minuscolo come nelle altre tabelle del database.
$photoRealSaved = handleImageUpload('photo_real', __DIR__ . '/fdbphotos', $hex, $uploadErrors);
$modelForPhoto = $modelT !== '' ? $modelT : sanitizeCode($_POST['current_model_t'] ?? '');
$photoModelSaved = false;
if ($modelForPhoto !== '') {
    $photoModelSaved = handleImageUpload('photo_model', __DIR__ . '/photos', $modelForPhoto, $uploadErrors);
}

respond(true, [
    'hex' => $hexLower,
    'cleared' => $clearOverride,
    'photo_real_saved' => $photoRealSaved,
    'photo_model_saved' => $photoModelSaved,
    'upload_errors' => $uploadErrors,
]);
