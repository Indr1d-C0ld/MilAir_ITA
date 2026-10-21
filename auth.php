<?php
require_once __DIR__ . '/percorsi.php';
/**
 * Nucleo condiviso di autenticazione, autorizzazione e CSRF
 * per l'intera piattaforma MILAIR ITA.
 *
 * Incluso da (quasi) ogni pagina con `require_once __DIR__ . '/auth.php';` —
 * unica eccezione deliberata alla convenzione "nessun include condiviso" già
 * usata nel resto del progetto, giustificata perché la sicurezza è per
 * natura una responsabilità trasversale che non va duplicata file per file.
 *
 * Ruoli: 'pubblico' (nessun account, sola visione) < 'collaboratore'
 * (editing completo) < 'admin' (editing + pannello di amministrazione).
 */


const ROLE_RANK = ['pubblico' => 0, 'collaboratore' => 1, 'admin' => 2];

// Lunghezza minima delle password, applicata in setup.php e admin_users.php
// (validazione lato server e attributo minlength nei form).
// Portata da 10 a 9 su richiesta esplicita dell'amministratore del deployment.
// Alzarla è sempre consigliabile: il login è già protetto da un limite di
// tentativi per utente e per IP, quindi il rischio non è l'attacco online ma
// un eventuale attacco offline se auth.db finisse in mani altrui.
const PASSWORD_MIN_LENGTH = 9;

// Conservazione dei dati di servizio (vedi prune_old_logs()). Valori generosi:
// la potatura serve solo a evitare crescita illimitata.
// Gli alert non letti sono esclusi dalla potatura a prescindere dall'età.
// Il registro di ogni richiesta (access_log) non c'e' piu': scriveva una riga
// nel database a ogni pagina vista, anche dai crawler (552.000 righe in due
// mesi e mezzo, il 99% anonime). Le visite si leggono dai log di Apache.
const LOGIN_ATTEMPTS_RETENTION_DAYS  = 90;
const ALERTS_RETENTION_DAYS          = 365;

// ---------------------------------------------------------------------------
// Connessione DB (auth.db, separato da events.db per non contendere le
// scritture di logging con gli import cron sui dati di volo)
// ---------------------------------------------------------------------------

function get_auth_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(AUTH_DB_PATH);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        // Nota: deliberatamente NON in modalità WAL. WAL crea file .shm/.wal
        // persistenti accanto al database, la cui proprietà resta di chiunque
        // li abbia toccati per ultimi (anche solo aprendo il file in lettura
        // da riga di comando) — su questo host, dove sia www-data (Apache)
        // sia l'utente di sistema devono poter scrivere, questo ha bloccato
        // ripetutamente le scritture reali. Il journal classico crea un file
        // temporaneo solo durante la transazione e lo rimuove subito dopo.

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('collaboratore','admin')),
            display_name TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER REFERENCES users(id),
            last_login_at TEXT,
            last_login_ip TEXT
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip TEXT NOT NULL,
            success INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(username, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, created_at)");

        // Dalle installazioni precedenti: il registro accessi e la cache della
        // geolocalizzazione IP non esistono piu'.
        $db->exec("DROP TABLE IF EXISTS access_log");
        $db->exec("DROP TABLE IF EXISTS ip_geo_cache");

        $db->exec("CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            request_type TEXT NOT NULL DEFAULT 'contact' CHECK (request_type IN ('contact','collab_access')),
            message TEXT NOT NULL,
            ip TEXT,
            status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','reviewed','approved','rejected')),
            admin_note TEXT,
            reviewed_by INTEGER REFERENCES users(id),
            reviewed_at TEXT
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status, created_at)");

        $db->exec("CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            alert_type TEXT NOT NULL,
            hex TEXT,
            request_id INTEGER REFERENCES requests(id),
            article_id INTEGER,          -- della rassegna RSS, non piu' usata: resta per i database esistenti
            title TEXT NOT NULL,
            detail TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            note TEXT,
            note_updated_at TEXT,
            note_updated_by INTEGER REFERENCES users(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_created ON alerts(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_read ON alerts(is_read, created_at)");

        // Checkpoint dello scanner cron (alert_scan.php): ultimo rowid di events.db già processato.
        $db->exec("CREATE TABLE IF NOT EXISTS alert_scan_checkpoint (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            last_events_rowid INTEGER NOT NULL DEFAULT 0
        )");

        // Cooldown per evitare un alert ad ogni scansione per watchlist/emergency_squawk
        // (rare_contact non ne ha bisogno: una prima apparizione è per natura irripetibile).
        $db->exec("CREATE TABLE IF NOT EXISTS alert_cooldown (
            hex TEXT NOT NULL,
            alert_type TEXT NOT NULL,
            last_alerted_at TEXT NOT NULL,
            PRIMARY KEY (hex, alert_type)
        )");

        // Prime apparizioni in attesa che update_rarity.php (rebuild orario) le classifichi.
        $db->exec("CREATE TABLE IF NOT EXISTS alert_rarity_pending (
            hex TEXT PRIMARY KEY,
            events_rowid INTEGER NOT NULL,
            first_seen_utc TEXT NOT NULL,
            discovered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }
    return $db;
}

// ---------------------------------------------------------------------------
// Bootstrap sessione
// ---------------------------------------------------------------------------

/**
 * Prepara autenticazione e sessione per la richiesta.
 *
 * La sessione e' pigra: nasce solo se il browser ne ha gia' una (cookie), su
 * un POST, o sulle pagine con un modulo aperto anche a chi non ha fatto
 * l'accesso (PAGINE_CON_MODULO: il login, le richieste, il primo avvio). Una
 * visita anonima alla tabella o alla mappa non apre nessuna sessione e non
 * scrive nulla: prima ogni richiesta, crawler compresi, lasciava un file di
 * sessione.
 *
 * La sessione va aperta QUI, prima di qualunque output: a pagina iniziata le
 * intestazioni sono gia' partite e il cookie non si puo' piu' mandare.
 */
const PAGINE_CON_MODULO = ['login.php', 'richieste.php', 'setup.php'];

function auth_bootstrap(): void {
    if (session_status() !== PHP_SESSION_ACTIVE
        && (isset($_COOKIE[MILAIR_SESSIONE])
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            || in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), PAGINE_CON_MODULO, true))) {
        avvia_sessione();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        get_auth_db(); // assicura che le tabelle esistano
        revalidate_session_user();
    }
}

const MILAIR_SESSIONE = 'MILAIRSESSID';

function avvia_sessione(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent($file, $riga)) {
        // Troppo tardi per il cookie: la pagina va aggiunta a PAGINE_CON_MODULO
        error_log("MilAir: sessione chiesta dopo l'inizio della pagina ($file:$riga)");
        return;
    }
    // Directory di sessione dedicata al progetto, in RAM (MILAIR_RAM): isola le
    // sessioni di questa app da quelle di altri siti sullo stesso host e non
    // scrive sulla SSD. Dopo un riavvio si rifa' il login, e basta.
    $sessionPath = MILAIR_RAM . '/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0770, true);
    }
    session_save_path($sessionPath);

    // Garbage collection delle sessioni: da attivare ESPLICITAMENTE qui.
    // Su Debian session.gc_probability vale 0, perché la pulizia è delegata
    // allo script di sistema /usr/lib/php/sessionclean — che però legge la
    // save_path dai file php.ini e quindi NON vede questa directory, scelta
    // a runtime. Senza queste righe i file di sessione non venivano mai
    // rimossi, e una sessione rubata restava valida per sempre.
    // 1 richiesta su 100 esegue la pulizia: costo trascurabile, nessun cron.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '43200'); // 12 ore di inattività
    // Nome di sessione dedicato: con il PHPSESSID di default e path '/', le
    // app che convivono su questo host condividono un unico cookie. Poiché
    // ognuna rigenera l'id al login, accedere a una disconnetteva le altre.
    session_name(MILAIR_SESSIONE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,   // il vhost forza HTTPS su tutto il sito
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Riallinea la sessione allo stato attuale dell'utente nel database.
 *
 * Ruolo e stato attivo venivano copiati in sessione al momento del login e non
 * più riletti: disattivare un account o retrocederne il ruolo da admin_users.php
 * non aveva quindi alcun effetto finché quella persona restava collegata — e una
 * sessione, prima dell'introduzione della garbage collection, non scadeva mai.
 * Per un pannello che permette di revocare un accesso è il comportamento
 * sbagliato: la revoca deve avere effetto alla richiesta successiva.
 *
 * Costo: una lettura per chiave primaria su una tabella di pochi record, del
 * tutto trascurabile rispetto al resto della pagina.
 */
/** Impronta dell'hash della password salvata in sessione (mai l'hash stesso). */
function password_fingerprint(string $passwordHash): string {
    return substr(hash('sha256', $passwordHash), 0, 16);
}

function revalidate_session_user(): void {
    if (empty($_SESSION['user']['id'])) {
        return;
    }
    try {
        $stmt = get_auth_db()->prepare("SELECT username, role, display_name, is_active, password_hash FROM users WHERE id = ?");
        $stmt->bindValue(1, (int)$_SESSION['user']['id'], SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    } catch (Exception $e) {
        return; // problema temporaneo sul database: non buttiamo fuori nessuno
    }

    // Utente eliminato o disattivato: la sessione decade subito.
    if (!$row || !(int)$row['is_active']) {
        $_SESSION['user'] = null;
        unset($_SESSION['user']);
        return;
    }

    // Password cambiata dopo il login (reset da admin, o cambio fatto da un'altra
    // sessione): le sessioni aperte con la vecchia password decadono. Prima un
    // reset per account compromesso lasciava attiva la sessione dell'intruso.
    // Le sessioni nate prima di questo controllo non hanno l'impronta: la si
    // registra ora, senza buttarle fuori.
    $fp = password_fingerprint($row['password_hash']);
    if (!isset($_SESSION['user']['pwfp'])) {
        $_SESSION['user']['pwfp'] = $fp;
    } elseif (!hash_equals($_SESSION['user']['pwfp'], $fp)) {
        $_SESSION['user'] = null;
        unset($_SESSION['user']);
        return;
    }

    // Ruolo o nome cambiati nel frattempo: si adegua la sessione in corso.
    $_SESSION['user']['role'] = $row['role'];
    $_SESSION['user']['username'] = $row['username'];
    $_SESSION['user']['display_name'] = $row['display_name'] ?: $row['username'];
}

// ---------------------------------------------------------------------------
// Identità
// ---------------------------------------------------------------------------

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function current_role(): string {
    return current_user()['role'] ?? 'pubblico';
}

// ---------------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------------

function log_login_attempt(string $username, string $ip, bool $success): void {
    $stmt = get_auth_db()->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $username);
    $stmt->bindValue(2, $ip);
    $stmt->bindValue(3, $success ? 1 : 0);
    $stmt->execute();
}

/**
 * Verifica le credenziali e, se valide, avvia la sessione autenticata.
 * Ritorna ['ok' => bool, 'error' => ?string]. Il messaggio d'errore è
 * sempre generico (utente inesistente, password errata, account disattivo
 * o rate-limit superato producono la stessa risposta) per non rivelare
 * quali username esistono.
 */
function attempt_login(string $username, string $password): array {
    $db = get_auth_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $username = trim($username);
    $genericError = 'Credenziali non valide.';

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE username = ? AND success = 0 AND created_at > datetime('now','-15 minutes')");
    $stmt->bindValue(1, $username);
    $attemptsByUser = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > datetime('now','-15 minutes')");
    $stmt->bindValue(1, $ip);
    $attemptsByIp = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];

    if ($attemptsByUser >= 5 || $attemptsByIp >= 15) {
        log_login_attempt($username, $ip, false);
        return ['ok' => false, 'error' => $genericError];
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bindValue(1, $username);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    // Hash fittizio per mantenere un tempo di risposta costante anche
    // quando lo username non esiste (evita di rivelarne l'esistenza).
    static $dummyHash = null;
    if ($dummyHash === null) {
        $dummyHash = password_hash('dummy-password-per-tempo-costante', PASSWORD_DEFAULT);
    }
    $passwordOk = password_verify($password, $row['password_hash'] ?? $dummyHash);

    if (!$row || !$passwordOk || !(int)$row['is_active']) {
        log_login_attempt($username, $ip, false);
        return ['ok' => false, 'error' => $genericError];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'role' => $row['role'],
        'display_name' => $row['display_name'] ?: $row['username'],
        'pwfp' => password_fingerprint($row['password_hash']),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $stmt = $db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_login_ip = ? WHERE id = ?");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $row['id']);
    $stmt->execute();

    log_login_attempt($username, $ip, true);
    return ['ok' => true];
}

function logout(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Autorizzazione
// ---------------------------------------------------------------------------

/**
 * Impone il livello minimo di accesso. Se l'utente non è loggato viene
 * rediretto al login (preservando la pagina di destinazione); se è loggato
 * ma con ruolo insufficiente riceve un 403.
 */
/**
 * Riduce un URL di ritorno (next/return) a una pagina locale dell'applicazione,
 * altrimenti restituisce $default. Accetta "pagina.php", "pagina.php?query" e il
 * percorso assoluto che require_role() ricava da REQUEST_URI ("/milair_ita/
 * pagina.php?..."), di cui si tiene solo la parte dopo la cartella dell'app.
 * Rifiuta tutto il resto: schemi (https:, javascript:), host ("//evil"),
 * backslash e spazi/a capo, cioè open redirect e link "Annulla" eseguibili.
 */
function safe_local_url(?string $url, string $default = 'index.php'): string {
    $url = trim((string)$url);
    $appBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    if (str_starts_with($url, $appBase)) {
        $url = substr($url, strlen($appBase));
    }
    if (preg_match('#^[A-Za-z0-9_\-]+\.php(\?[^\s\\\\]*)?$#', $url)) {
        return $url;
    }
    return $default;
}

/**
 * Variante di require_role() per gli endpoint chiamati via fetch(): invece del
 * redirect alla pagina di login (inutile per una risposta JSON) restituisce
 * 401 (non autenticato) o 403 (ruolo insufficiente) con un corpo JSON.
 */
function require_role_json(string $minRole): void {
    if ((ROLE_RANK[current_role()] ?? 0) >= (ROLE_RANK[$minRole] ?? 99)) {
        return;
    }
    http_response_code(is_logged_in() ? 403 : 401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => is_logged_in() ? 'Permessi insufficienti.' : 'Sessione scaduta: effettua di nuovo l\'accesso.']);
    exit;
}

function require_role(string $minRole): void {
    $requiredRank = ROLE_RANK[$minRole] ?? 99;
    $currentRank = ROLE_RANK[current_role()] ?? 0;
    if ($currentRank >= $requiredRank) {
        return;
    }
    if (!is_logged_in()) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?next=' . $next);
        exit;
    }
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Accesso negato</title>'
        . '<link rel="stylesheet" href="style.css"></head><body style="padding:40px;text-align:center;">'
        . '<h2>🚫 Accesso negato</h2><p>Non hai i permessi necessari per accedere a questa pagina.</p>'
        . '<p><a href="index.php">Torna alla tabella</a></p></body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string {
    avvia_sessione();   // di solito gia' aperta da auth_bootstrap(), vedi PAGINE_CON_MODULO
    return $_SESSION['csrf_token'] ?? '';
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Da chiamare esplicitamente solo nei rami che scrivono (POST), non su ogni GET. */
function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (hash_equals($_SESSION['csrf_token'] ?? '', (string)$token)) {
        return;
    }
    http_response_code(403);
    $wantsJson = isset($_POST['ajax']) || isset($_GET['ajax'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Token di sicurezza mancante o scaduto. Ricarica la pagina.']);
    } else {
        echo 'Token di sicurezza mancante o scaduto. Torna indietro e ricarica la pagina prima di riprovare.';
    }
    exit;
}

// ---------------------------------------------------------------------------
// Password
// ---------------------------------------------------------------------------

function hash_password(string $plain): string {
    return password_hash($plain, PASSWORD_DEFAULT);
}

function verify_password(string $plain, string $hash): bool {
    return password_verify($plain, $hash);
}

/**
 * Elimina le righe di servizio più vecchie delle soglie di conservazione.
 * Soglie volutamente generose: sono dati di audit, meglio abbondare.
 * La chiama alert_scan.php una volta al giorno.
 * Gli alert NON letti non vengono mai rimossi, indipendentemente dall'età:
 * sono segnalazioni che l'operatore non ha ancora visionato.
 */
function prune_old_logs(): void {
    $db = get_auth_db();
    $db->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-" . LOGIN_ATTEMPTS_RETENTION_DAYS . " days')");
    $db->exec("DELETE FROM alerts         WHERE is_read = 1 AND created_at < datetime('now', '-" . ALERTS_RETENTION_DAYS . " days')");
}

/** Le icone del menu: tratto lineare, stesse proporzioni, colore del testo. */
const ICONE_MENU = [
    'tabella'    => '<rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 9h18M3 14.5h18M9 9v11"/>',
    'mappa'      => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"/>',
    'heatmap'    => '<path d="M12 3c1 3 5 5.2 5 10a5 5 0 0 1-10 0c0-2.5 1.4-4.1 2.5-5 .3 1.6 1.1 2.6 2.4 3 .6-2.6-.4-5.4.1-8z"/>',
    'statistiche'=> '<path d="M3 21h18M6.5 17v-5M11 17V7M15.5 17v-8M20 17V4"/>',
    'diario'     => '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4z"/><path d="M5 17a3 3 0 0 1 3-3h11M9 8h6"/>',
    'favoriti'   => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.9z"/>',
    'richieste'  => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m3.5 6.5 8.5 6 8.5-6"/>',
    'regole'     => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    'geofiltro'  => '<path d="M5 8.5 11 4l8 3-1 9.5-7 3.5-6-5z"/><circle cx="11.5" cy="11.5" r="1.6"/>',
    'utenti'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'posta'      => '<path d="M3 13.5 6 5h12l3 8.5V19H3z"/><path d="M3 13.5h5l1 3h6l1-3h5"/>',
    'accedi'     => '<path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
    'esci'       => '<path d="M14 17l5-5-5-5M19 12H8M10 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
    'campanella' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
];

function icona(string $nome): string {
    return '<svg class="icona" viewBox="0 0 24 24" aria-hidden="true">' . (ICONE_MENU[$nome] ?? '') . '</svg>';
}

/**
 * Lo stato della raccolta per il cruscotto della testata: l'ultimo contatto
 * registrato e quanti velivoli distinti oggi (giorno italiano). Una lettura
 * per pagina, sull'indice della chiave primaria di events.
 */
function stato_raccolta(): array {
    static $stato = null;
    if ($stato !== null) {
        return $stato;
    }
    $stato = ['ultimo' => null, 'oggi' => null];
    try {
        $db = new SQLite3(EVENTS_DB_PATH, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        $stato['ultimo'] = $db->querySingle("SELECT MAX(first_seen_utc) FROM events") ?: null;
        $inizio = rome_day_start_utc((new DateTime('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d'));
        if ($inizio !== null) {
            $st = $db->prepare("SELECT COUNT(DISTINCT hex) FROM events WHERE first_seen_utc >= ?");
            $st->bindValue(1, $inizio);
            $stato['oggi'] = (int)$st->execute()->fetchArray(SQLITE3_NUM)[0];
        }
        $db->close();
    } catch (Throwable $e) {
        // senza database la testata resta muta, la pagina dira' il resto
    }
    return $stato;
}

// ---------------------------------------------------------------------------
// Testata e navigazione condivise: la fascia di classifica, il marchio, il
// cruscotto della raccolta e il menu, con i link condizionati al ruolo.
// Carica anche tema.css: arriva dopo gli stili interni delle pagine, e li
// ridefinisce senza doverli riscrivere uno per uno.
// ---------------------------------------------------------------------------

function render_nav(string $current = ''): void {
    $user = current_user();
    $links = [
        'index.php'     => ['tabella', 'Tabella'],
        'map.php'       => ['mappa', 'Mappa'],
        'heatmap.php'   => ['heatmap', 'Heatmap'],
        'stats.php'     => ['statistiche', 'Statistiche'],
        'diary.php'     => ['diario', 'Diario'],
        'favorites.php' => ['favoriti', 'Favoriti'],
    ];
    if ($user) {
        $links['rules.php']     = ['regole', 'Regole'];
        $links['geofilter.php'] = ['geofiltro', 'Filtro geografico'];
    } else {
        $links['richieste.php'] = ['richieste', 'Richieste'];
    }
    if ($user && $user['role'] === 'admin') {
        $links['admin_users.php']     = ['utenti', 'Utenti'];
        $links['admin_richieste.php'] = ['posta', 'Richieste'];
    }

    $v = @filemtime(__DIR__ . '/tema.css') ?: 1;
    echo '<link rel="stylesheet" href="tema.css?v=' . $v . '">';

    // Cruscotto: da quanto tempo non arriva un contatto (verde entro 15 minuti,
    // ambra entro 3 ore: di notte e' normale non vederne)
    $stato = stato_raccolta();
    $spia = 'grigia';
    $ultimo = '—';
    $fa = '';
    if ($stato['ultimo']) {
        $t = strtotime(str_replace(' UTC', '', $stato['ultimo']) . ' UTC');
        $min = max(0, (int)floor((time() - $t) / 60));
        $spia = $min <= 15 ? 'verde' : ($min <= 180 ? 'ambra' : 'grigia');
        $ultimo = gmdate('H:i', $t) . 'Z';
        $fa = $min < 60 ? "$min min fa" : ($min < 2880 ? floor($min / 60) . ' h fa' : floor($min / 1440) . ' g fa');
    }

    echo '<div class="classifica">Non classificato // Fonti aperte · dati ADS-B pubblici</div>';
    echo '<header class="milair-testata">';
    echo '<a class="marchio" href="index.php" title="MilAir ITA — tabella dei contatti">'
        . '<svg class="radar" viewBox="0 0 40 40" aria-hidden="true">'
        . '<defs><linearGradient id="radarSpazzata" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#3ddc97" stop-opacity="0"/><stop offset="1" stop-color="#3ddc97" stop-opacity=".55"/></linearGradient></defs>'
        . '<circle cx="20" cy="20" r="18.5" fill="#0b1712" stroke="#2c4255"/>'
        . '<circle cx="20" cy="20" r="12.5" fill="none" stroke="#1d3a2b"/><circle cx="20" cy="20" r="6.5" fill="none" stroke="#1d3a2b"/>'
        . '<path d="M20 1.5v37M1.5 20h37" stroke="#1d3a2b"/>'
        . '<path d="M20 20 20 1.5A18.5 18.5 0 0 1 36 10.8Z" fill="url(#radarSpazzata)"/>'
        . '<circle cx="27.5" cy="11" r="1.9" fill="#f2a33a"/><circle cx="12" cy="26" r="1.4" fill="#3ddc97"/>'
        . '</svg>'
        . '<span><span class="marchio-nome">MILAIR<b>ITA</b></span>'
        . '<span class="marchio-sotto">Traffico aereo militare sull\'Italia · sorveglianza ADS-B</span></span></a>';
    echo '<div class="cruscotto">';
    echo '<span title="Ultimo contatto registrato (ora Zulu)"><i class="spia ' . $spia . '"></i>ULTIMO <b>' . htmlspecialchars($ultimo) . '</b>'
        . ($fa !== '' ? ' ' . htmlspecialchars($fa) : '') . '</span>';
    if ($stato['oggi'] !== null) {
        echo '<span title="Velivoli distinti visti oggi (giorno italiano)">OGGI <b>' . (int)$stato['oggi'] . '</b> velivoli</span>';
    }
    echo '<span class="orologio-zulu" title="Ora Zulu (UTC)"><b data-orologio="Z">' . gmdate('H:i:s') . '</b>Z</span>';
    echo '<span class="orologio-locale" title="Ora italiana"><b data-orologio="L">' . date('H:i') . '</b>LT</span>';
    echo '</div></header>';

    echo '<nav class="nav-links" aria-label="Sezioni">';
    echo '<div class="nav-links-primary">';
    foreach ($links as $href => [$icona, $etichetta]) {
        $attivo = ($href === $current) ? ' class="attivo" aria-current="page"' : '';
        echo '<a href="' . htmlspecialchars($href) . '"' . $attivo . '>' . icona($icona) . '<span class="etichetta">' . $etichetta . '</span></a>';
    }
    echo '</div>';
    echo '<div class="nav-links-secondary">';
    if ($user && (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['collaboratore']) {
        render_alert_bell();
    }
    if ($user) {
        echo '<a href="logout.php">' . icona('esci') . '<span class="etichetta">Esci · ' . htmlspecialchars($user['display_name']) . '</span></a>';
    } else {
        echo '<a href="login.php">' . icona('accedi') . '<span class="etichetta">Accedi</span></a>';
    }
    echo '</div>'; // .nav-links-secondary
    echo '</nav>'; // .nav-links

    // Orologi della testata; sulla mappa, l'altezza della testata per dare alla
    // cartina tutto il resto dello schermo.
    echo '<script>(function(){'
        . 'var z=document.querySelector("[data-orologio=Z]"),l=document.querySelector("[data-orologio=L]");'
        . 'function p(n){return(n<10?"0":"")+n}'
        . 'function t(){var d=new Date();if(z)z.textContent=p(d.getUTCHours())+":"+p(d.getUTCMinutes())+":"+p(d.getUTCSeconds());'
        . 'if(l)l.textContent=p(d.getHours())+":"+p(d.getMinutes())}setInterval(t,1000);'
        . (in_array($current, ['map.php', 'heatmap.php'], true)
            ? 'document.body.classList.add("pagina-mappa");function h(){var n=document.querySelector(".nav-links"),c=document.querySelector(".classifica");'
              . 'document.body.style.setProperty("--altezza-testata",(n.getBoundingClientRect().bottom)+"px");if(window.map&&map.invalidateSize)map.invalidateSize()}'
              . 'h();window.addEventListener("resize",h);window.addEventListener("load",h);'
            : '')
        . '})();</script>';
}

/**
 * Campanella notifiche: badge non lette + cassetto a tendina con le ultime 8.
 * Il token CSRF necessario alle azioni mark-read viene passato via attributo
 * data-csrf sul bottone stesso, per non dover aggiungere un <meta
 * name="csrf-token"> a ogni singola pagina che include render_nav().
 */
function render_alert_bell(): void {
    $unread = get_unread_alert_count();
    $recent = get_recent_alerts(8);
    $csrf = csrf_token();

    $typeIcons = [
        'watchlist'         => '❓',
        'new_request'       => '📬',
        'emergency_squawk'  => '🚨',
        'rare_contact'      => '✨',
        'custom_rule'       => '🎯',
    ];

    echo '<div class="alert-bell-wrap">';
    echo '<button type="button" id="alertBell" class="alert-bell" title="Notifiche" data-csrf="' . htmlspecialchars($csrf) . '" onclick="toggleAlertDropdown(event)">' . icona('campanella');
    echo '<span class="alert-badge" id="alertBadge" data-count="' . (int)$unread . '"' . ($unread > 0 ? '' : ' style="display:none;"') . '>' . ($unread > 99 ? '99+' : $unread) . '</span>';
    echo '</button>';

    echo '<div class="alert-dropdown" id="alertDropdown">';
    echo '<div class="alert-dropdown-header"><span>🔔 Notifiche</span><button type="button" title="Segna tutte come lette" onclick="markAllAlertsRead()">✅</button></div>';
    echo '<div id="alertList">';
    if (empty($recent)) {
        echo '<div class="alert-empty">Nessuna notifica.</div>';
    } else {
        foreach ($recent as $a) {
            $icon = $typeIcons[$a['alert_type']] ?? '🔔';
            $isUnread = (int)$a['is_read'] === 0;
            echo '<div class="alert-item' . ($isUnread ? ' unread' : '') . '" id="alertItem' . (int)$a['id'] . '">';
            $titleHtml = $icon . ' ' . htmlspecialchars($a['title']);
            if (!empty($a['hex'])) {
                $titleHtml = '<a href="index.php?hex=' . urlencode($a['hex']) . '&sort=ident_last_seen&order=desc" target="_blank">' . $titleHtml . '</a>';
            }
            echo '<div class="alert-title">' . $titleHtml . '</div>';
            echo '<div class="alert-meta">' . htmlspecialchars(format_date_it($a['created_at'])) . '</div>';
            if ($isUnread) {
                echo '<div class="alert-actions"><button type="button" onclick="markAlertRead(' . (int)$a['id'] . ', this)">Segna come letta</button></div>';
            }
            echo '</div>';
        }
    }
    echo '</div>';
    echo '<div class="alert-dropdown-footer"><a href="alerts.php">Vedi tutte le notifiche →</a></div>';
    echo '</div>'; // .alert-dropdown
    echo '</div>'; // .alert-bell-wrap

    echo <<<'JS'
<script>
(function() {
    var bell = document.getElementById('alertBell');
    var dropdown = document.getElementById('alertDropdown');
    var badge = document.getElementById('alertBadge');
    var csrf = bell.getAttribute('data-csrf');

    window.toggleAlertDropdown = function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    };
    document.addEventListener('click', function(e) {
        if (dropdown.classList.contains('open') && !dropdown.contains(e.target) && e.target !== bell) {
            dropdown.classList.remove('open');
        }
    });

    // Conteggio reale tenuto a parte: il badge mostra "99+" e parseInt("99+")
    // dava 99, quindi segnare letto un avviso con 250 non letti mostrava 98.
    var unread = parseInt(badge.getAttribute('data-count') || badge.textContent, 10) || 0;
    function updateBadge(count) {
        unread = count;
        if (count > 0) {
            badge.style.display = '';
            badge.textContent = count > 99 ? '99+' : count;
        } else {
            badge.style.display = 'none';
            badge.textContent = '0';
        }
    }

    window.markAlertRead = function(id, btn) {
        fetch('toggle_alert_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ajax: '1', id: id, is_read: '1', csrf_token: csrf})
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.ok) {
                var item = document.getElementById('alertItem' + id);
                if (item) { item.classList.remove('unread'); }
                if (btn) { btn.remove(); }
                updateBadge(Math.max(0, unread - 1));
            }
        }).catch(function() {});
    };

    window.markAllAlertsRead = function() {
        fetch('toggle_alert_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ajax: '1', mark_all: '1', csrf_token: csrf})
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.ok) {
                document.querySelectorAll('.alert-item.unread').forEach(function(el) { el.classList.remove('unread'); });
                document.querySelectorAll('.alert-actions').forEach(function(el) { el.remove(); });
                updateBadge(0);
            }
        }).catch(function() {});
    };

    // Sessione scaduta o ruolo revocato: alerts_count.php risponde 401/403 e il
    // polling si ferma (prima seguiva un redirect a login.php ogni minuto, per
    // ore, da ogni scheda dimenticata aperta: migliaia di righe nel log accessi).
    var poll = setInterval(function() {
        fetch('alerts_count.php', {redirect: 'error'})
            .then(function(r) {
                if (r.status === 401 || r.status === 403) { clearInterval(poll); return null; }
                return r.ok ? r.json() : null;
            })
            .then(function(data) { if (data && typeof data.count === 'number') { updateBadge(data.count); } })
            .catch(function() {});
    }, 60000);
})();
</script>
JS;
}

// ---------------------------------------------------------------------------
// Alerts — notifiche interne per collaboratori/admin (watchlist, richieste,
// squawk di emergenza, contatti rari mai visti prima). Generati da
// alert_scan.php (cron) e da richieste.php (al volo). Stato letto/non letto
// condiviso da tutto il team, non per singolo account.
// ---------------------------------------------------------------------------

/**
 * Inizio del giorno $ymd (o del successivo, con $nextDay) in ora italiana,
 * espresso in UTC nel formato di CURRENT_TIMESTAMP ("Y-m-d H:i:s"): serve per
 * filtrare per data la tabella alerts di auth.db, le cui date sono
 * in UTC mentre i campi "Dal/Al" della pagina sono giorni italiani. null se la
 * data non è valida.
 */
function rome_day_start_utc(string $ymd, bool $nextDay = false): ?string {
    $d = DateTime::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('Europe/Rome'));
    if (!$d || $d->format('Y-m-d') !== $ymd) {
        return null;
    }
    if ($nextDay) {
        $d->modify('+1 day');
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Converte una data UTC (con o senza suffisso " UTC") in data/ora italiana leggibile — stessa logica di formatDateIt() già in uso in index.php/map.php. */
function format_date_it(?string $utcString): string {
    if (empty($utcString)) {
        return '';
    }
    $clean = str_replace(' UTC', '', trim($utcString));
    try {
        $date = new DateTime($clean, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Rome'));
        return $date->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        // Testo grezzo: ogni chiamante lo passa già a htmlspecialchars() (o lo salva
        // nel database); con l'escape anche qui "&" diventava "&amp;amp;" a video.
        return $utcString;
    }
}

function create_alert(string $type, ?string $hex, ?int $requestId, string $title, ?string $detail = null): int {
    $db = get_auth_db();
    $stmt = $db->prepare("INSERT INTO alerts (alert_type, hex, request_id, title, detail) VALUES (?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $type);
    $stmt->bindValue(2, $hex);
    $stmt->bindValue(3, $requestId);
    $stmt->bindValue(4, $title);
    $stmt->bindValue(5, $detail);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function get_unread_alert_count(): int {
    return (int)get_auth_db()->querySingle("SELECT COUNT(*) FROM alerts WHERE is_read = 0");
}

function get_recent_alerts(int $limit = 8): array {
    $stmt = get_auth_db()->prepare("SELECT * FROM alerts ORDER BY created_at DESC, id DESC LIMIT ?");
    $stmt->bindValue(1, $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function mark_alert_read(int $id, bool $read): void {
    $stmt = get_auth_db()->prepare("UPDATE alerts SET is_read = ? WHERE id = ?");
    $stmt->bindValue(1, $read ? 1 : 0);
    $stmt->bindValue(2, $id);
    $stmt->execute();
}

function mark_all_alerts_read(): void {
    get_auth_db()->exec("UPDATE alerts SET is_read = 1 WHERE is_read = 0");
}
