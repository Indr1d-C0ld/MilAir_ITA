#!/usr/bin/env php
<?php
require_once __DIR__ . '/percorsi.php';
// diary_build.php - calcola (e, con --publish, pubblica) il digest del Diario
// per uno o più giorni. Pensato per il cron: una riga al giorno costruisce e
// pubblica la voce di IERI. Non tocca la sintesi IA (resta manuale).
//
//   php diary_build.php --publish                 # digest di IERI + pubblicazione
//   php diary_build.php --day=2026-09-01          # un giorno preciso (senza pubblicare)
//   php diary_build.php --publish --backfill=14   # ultimi 14 giorni (fino a ieri)
//   php diary_build.php --publish --quiet         # solo errori sullo stderr
//
// --publish marca la voce come pubblica SOLO se non lo è mai stata prima:
// un eventuale "Ritira dal diario" fatto a mano dall'admin resta rispettato.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando.\n");
}

require_once __DIR__ . '/diary_lib.php';

$opts = getopt('', ['publish', 'quiet', 'day:', 'backfill:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php diary_build.php [--publish] [--day=YYYY-MM-DD] [--backfill=N] [--quiet]\n");
    exit(0);
}

$do_publish = isset($opts['publish']);
$quiet      = isset($opts['quiet']);
$log = function (string $s) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $s . "\n");
    }
};

// --- Giorni da elaborare -----------------------------------------------------
$rome = new DateTimeZone('Europe/Rome');
$yesterday = (new DateTime('yesterday', $rome))->format('Y-m-d');

$days = [];
if (isset($opts['day'])) {
    if (!diary_valid_day($opts['day'])) {
        fwrite(STDERR, "Data non valida: {$opts['day']} (attesa YYYY-MM-DD).\n");
        exit(2);
    }
    $days = [$opts['day']];
} elseif (isset($opts['backfill'])) {
    $n = (int) $opts['backfill'];
    if ($n < 1 || $n > 366) {
        fwrite(STDERR, "--backfill fuori range (1..366).\n");
        exit(2);
    }
    $end = new DateTime($yesterday, $rome);
    for ($i = $n - 1; $i >= 0; $i--) {
        $days[] = (clone $end)->modify("-{$i} day")->format('Y-m-d');
    }
} else {
    $days = [$yesterday];
}

// --- Elaborazione ------------------------------------------------------------
$dbPath = EVENTS_DB_PATH;
try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    diary_ensure_schema($db);
} catch (Throwable $e) {
    fwrite(STDERR, 'diary_build: impossibile aprire il database: ' . $e->getMessage() . "\n");
    exit(1);
}

$errors = 0;
foreach ($days as $day) {
    try {
        $digest = diary_build_digest($db, $day);
        diary_store_digest($db, $day, $digest);
        $n = (int) ($digest['totals']['events'] ?? 0);

        $note = 'non pubblicato (usa --publish)';
        if ($do_publish) {
            $row = diary_get($db, $day);
            if ((int) $row['published'] === 1) {
                $note = 'già pubblicato';
            } elseif (!empty($row['published_at'])) {
                $note = 'ritirato a mano: lasciato non pubblico';
            } else {
                $now = gmdate('Y-m-d H:i:s') . ' UTC';
                $s = $db->prepare("UPDATE diary_days SET published = 1, published_at = ?, updated_at = ? WHERE day = ?");
                $s->bindValue(1, $now, SQLITE3_TEXT);
                $s->bindValue(2, $now, SQLITE3_TEXT);
                $s->bindValue(3, $day, SQLITE3_TEXT);
                $s->execute();
                $note = 'pubblicato';
            }
        }
        $log(sprintf('%s: %d contatti, digest aggiornato — %s', $day, $n, $note));
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "diary_build: errore su {$day}: " . $e->getMessage() . "\n");
    }
}

// La potatura dei dati di servizio di auth.db (tentativi di login, alert gia'
// letti oltre la soglia): una volta per notte basta. Prima la faceva una
// richiesta web su 500, ora che il registro di ogni richiesta non c'e' piu'.
try {
    require_once __DIR__ . '/auth.php';
    prune_old_logs();
} catch (Throwable $e) {
    fwrite(STDERR, 'diary_build: potatura di auth.db non riuscita: ' . $e->getMessage() . "\n");
}

exit($errors ? 1 : 0);
