<?php
/**
 * Nucleo condiviso di autenticazione, autorizzazione, CSRF e logging accessi
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

define('AUTH_DB_PATH', __DIR__ . '/auth.db');

const ROLE_RANK = ['pubblico' => 0, 'collaboratore' => 1, 'admin' => 2];

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

        $db->exec("CREATE TABLE IF NOT EXISTS access_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip TEXT NOT NULL,
            user_id INTEGER REFERENCES users(id),
            username TEXT,
            role TEXT,
            path TEXT NOT NULL,
            method TEXT NOT NULL,
            query_string TEXT,
            status_code INTEGER,
            user_agent TEXT,
            referer TEXT
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_access_log_ip ON access_log(ip)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_access_log_ts ON access_log(ts)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_access_log_path ON access_log(path)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_access_log_user ON access_log(user_id)");

        $db->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
            ip TEXT PRIMARY KEY,
            country_code TEXT,
            country_name TEXT,
            source TEXT,
            resolved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

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
            article_id INTEGER,
            title TEXT NOT NULL,
            detail TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            note TEXT,
            note_updated_at TEXT,
            note_updated_by INTEGER REFERENCES users(id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_created ON alerts(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_read ON alerts(is_read, created_at)");

        // Migrazione one-shot: le installazioni con una tabella "alerts" creata prima
        // dell'introduzione del sottosistema Notizie/RSS hanno ancora il vecchio CHECK
        // sui valori di alert_type (che rifiuterebbe 'new_article') e non hanno la
        // colonna article_id. CREATE TABLE IF NOT EXISTS non modifica una tabella già
        // esistente, quindi va ricostruita esplicitamente — idempotente, verificato su
        // una copia reale del database di produzione prima di essere eseguita qui.
        $alertsSql = $db->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='alerts'");
        if ($alertsSql !== null && !str_contains($alertsSql, 'article_id')) {
            $db->exec('BEGIN');
            $db->exec("ALTER TABLE alerts RENAME TO alerts_old_migrate");
            $db->exec("CREATE TABLE alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                alert_type TEXT NOT NULL,
                hex TEXT,
                request_id INTEGER REFERENCES requests(id),
                article_id INTEGER,
                title TEXT NOT NULL,
                detail TEXT,
                is_read INTEGER NOT NULL DEFAULT 0,
                note TEXT,
                note_updated_at TEXT,
                note_updated_by INTEGER REFERENCES users(id)
            )");
            $db->exec("INSERT INTO alerts (id, created_at, alert_type, hex, request_id, article_id, title, detail, is_read, note, note_updated_at, note_updated_by)
                SELECT id, created_at, alert_type, hex, request_id, NULL, title, detail, is_read, note, note_updated_at, note_updated_by FROM alerts_old_migrate");
            $db->exec("DROP TABLE alerts_old_migrate");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_created ON alerts(created_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_read ON alerts(is_read, created_at)");
            $db->exec('COMMIT');
        }

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

function auth_bootstrap(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Directory di sessione dedicata al progetto invece della cartella di
        // sistema condivisa /var/lib/php/sessions (733, proprietario www-data):
        // isola le sessioni di questa app da quelle di altri siti sullo stesso
        // host e non dipende dai permessi/GC di sistema.
        $sessionPath = __DIR__ . '/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0770, true);
        }
        session_save_path($sessionPath);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,   // il vhost forza HTTPS su tutto il sito
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    get_auth_db(); // assicura che le tabelle esistano
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

// ---------------------------------------------------------------------------
// Logging accessi — chiamata esplicitamente dalle pagine "vere" (non dai
// proxy tile weather_tile.php/satellite_tile.php, chiamati decine di volte
// per singolo pan/zoom mappa: includerli intaserebbe access_log di rumore
// e serializzerebbe le richieste dietro il lock del file di sessione).
// ---------------------------------------------------------------------------

function log_access(): void {
    register_shutdown_function(function () {
        try {
            $u = current_user();
            $stmt = get_auth_db()->prepare("INSERT INTO access_log
                (ip, user_id, username, role, path, method, query_string, status_code, user_agent, referer)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bindValue(1, $_SERVER['REMOTE_ADDR'] ?? '');
            $stmt->bindValue(2, $u['id'] ?? null);
            $stmt->bindValue(3, $u['username'] ?? null);
            $stmt->bindValue(4, current_role());
            $stmt->bindValue(5, basename($_SERVER['SCRIPT_NAME'] ?? ''));
            $stmt->bindValue(6, $_SERVER['REQUEST_METHOD'] ?? '');
            $stmt->bindValue(7, $_SERVER['QUERY_STRING'] ?? '');
            $stmt->bindValue(8, http_response_code());
            $stmt->bindValue(9, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
            $stmt->bindValue(10, substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500));
            $stmt->execute();
        } catch (Exception $e) {
            // Un errore di logging non deve mai rompere la risposta della pagina.
        }
    });
}

// ---------------------------------------------------------------------------
// Geolocalizzazione IP (risoluzione pigra, con cache — vedi geo_secrets.php)
// ---------------------------------------------------------------------------

function fetch_ip_geo_from_apis(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country_code' => null, 'country_name' => null, 'source' => 'local'];
    }
    if (!defined('IPDATA_API_KEY') && !defined('IPGEOLOCATION_API_KEY') && file_exists(__DIR__ . '/geo_secrets.php')) {
        require_once __DIR__ . '/geo_secrets.php';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);

    if (defined('IPDATA_API_KEY')) {
        $resp = @file_get_contents('https://api.ipdata.co/' . urlencode($ip) . '?api-key=' . IPDATA_API_KEY, false, $ctx);
        $data = $resp ? json_decode($resp, true) : null;
        if (!empty($data['country_code'])) {
            return ['country_code' => $data['country_code'], 'country_name' => $data['country_name'] ?? null, 'source' => 'ipdata'];
        }
    }
    if (defined('IPGEOLOCATION_API_KEY')) {
        $resp = @file_get_contents('https://api.ipgeolocation.io/v3/ipgeo?apiKey=' . IPGEOLOCATION_API_KEY . '&ip=' . urlencode($ip), false, $ctx);
        $data = $resp ? json_decode($resp, true) : null;
        $cc = $data['location']['country_code2'] ?? null;
        if ($cc) {
            return ['country_code' => $cc, 'country_name' => $data['location']['country_name'] ?? null, 'source' => 'ipgeolocation'];
        }
    }
    return ['country_code' => null, 'country_name' => null, 'source' => 'failed'];
}

function resolve_ip_geo(string $ip): array {
    $db = get_auth_db();
    $stmt = $db->prepare("SELECT country_code, country_name, resolved_at FROM ip_geo_cache WHERE ip = ?");
    $stmt->bindValue(1, $ip);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row && (time() - strtotime($row['resolved_at'])) < 30 * 86400) {
        return $row;
    }

    $geo = fetch_ip_geo_from_apis($ip);

    $stmt = $db->prepare("INSERT INTO ip_geo_cache (ip, country_code, country_name, source, resolved_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(ip) DO UPDATE SET country_code = excluded.country_code, country_name = excluded.country_name,
            source = excluded.source, resolved_at = CURRENT_TIMESTAMP");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, $geo['country_code']);
    $stmt->bindValue(3, $geo['country_name']);
    $stmt->bindValue(4, $geo['source']);
    $stmt->execute();

    return $geo;
}

/** Bandiera emoji da codice ISO alpha-2, via Regional Indicator Symbols (nessuna chiamata esterna). */
function ipgeo_flag_emoji(?string $countryCode): string {
    $countryCode = strtoupper(trim((string)$countryCode));
    if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
        return '';
    }
    $offset = 0x1F1E6 - 65;
    return mb_chr(ord($countryCode[0]) + $offset, 'UTF-8') . mb_chr(ord($countryCode[1]) + $offset, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Navigazione condivisa — sostituisce il blocco <div class="nav-links">
// finora duplicato in ogni pagina, con i link condizionati al ruolo.
// ---------------------------------------------------------------------------

function render_nav(string $current = ''): void {
    $user = current_user();
    $links = [
        'index.php'     => '📋 Tabella',
        'map.php'       => '🗺️ Mappa',
        'heatmap.php'   => '🔥 Heatmap',
        'stats.php'     => '📊 Statistiche',
        'favorites.php' => '⭐ Favoriti',
        'news.php'      => '📰 Notizie',
    ];
    if ($user) {
        $links['rules.php']     = '🛠️ Regole';
        $links['geofilter.php'] = '🌍 Filtro Geografico';
    } else {
        $links['richieste.php'] = '✉️ Richieste';
    }
    if ($user && $user['role'] === 'admin') {
        $links['admin_users.php']        = '👤 Utenti';
        $links['admin_access_log.php']   = '📜 Log Accessi';
        $links['admin_access_stats.php'] = '📊 Statistiche Accessi';
        $links['admin_richieste.php']    = '📬 Richieste';
        $links['admin_feeds.php']        = '🗞️ Feed RSS';
    }

    echo '<div class="nav-links">';
    echo '<div class="nav-links-primary">';
    foreach ($links as $href => $label) {
        $style = ($href === $current) ? ' style="text-decoration:underline;"' : '';
        echo '<a href="' . htmlspecialchars($href) . '"' . $style . '>' . $label . '</a>';
    }
    echo '</div>';
    echo '<div class="nav-links-secondary">';
    if ($user && (ROLE_RANK[$user['role']] ?? 0) >= ROLE_RANK['collaboratore']) {
        render_alert_bell();
    }
    if ($user) {
        echo '<a href="logout.php">🚪 Esci (' . htmlspecialchars($user['display_name']) . ')</a>';
    } else {
        echo '<a href="login.php">🔑 Accedi</a>';
    }
    echo '</div>'; // .nav-links-secondary
    echo '</div>'; // .nav-links
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
        'new_article'       => '📰',
        'custom_rule'       => '🎯',
    ];

    echo '<div class="alert-bell-wrap">';
    echo '<button type="button" id="alertBell" class="alert-bell" data-csrf="' . htmlspecialchars($csrf) . '" onclick="toggleAlertDropdown(event)">🔔';
    echo '<span class="alert-badge" id="alertBadge"' . ($unread > 0 ? '' : ' style="display:none;"') . '>' . ($unread > 99 ? '99+' : $unread) . '</span>';
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
            } elseif (!empty($a['article_id'])) {
                $titleHtml = '<a href="news_article.php?id=' . (int)$a['article_id'] . '" target="_blank">' . $titleHtml . '</a>';
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

    function updateBadge(count) {
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
                var current = parseInt(badge.textContent, 10) || 0;
                updateBadge(Math.max(0, current - 1));
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

    setInterval(function() {
        fetch('alerts_count.php')
            .then(function(r) { return r.json(); })
            .then(function(data) { if (typeof data.count === 'number') { updateBadge(data.count); } })
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
        return htmlspecialchars($utcString);
    }
}

function create_alert(string $type, ?string $hex, ?int $requestId, string $title, ?string $detail = null, ?int $articleId = null): int {
    $db = get_auth_db();
    $stmt = $db->prepare("INSERT INTO alerts (alert_type, hex, request_id, article_id, title, detail) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $type);
    $stmt->bindValue(2, $hex);
    $stmt->bindValue(3, $requestId);
    $stmt->bindValue(4, $articleId);
    $stmt->bindValue(5, $title);
    $stmt->bindValue(6, $detail);
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
