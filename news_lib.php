<?php
/**
 * Libreria condivisa per il sottosistema Notizie/RSS — seconda eccezione
 * deliberata alla convenzione "nessun include condiviso" del progetto (la
 * prima è auth.php), per lo stesso motivo: schema DB, parser feed e algoritmo
 * di estrazione parole chiave sono usati da più file e non vanno duplicati.
 *
 * Non tocca sessioni/autenticazione: le pagine includono comunque auth.php a
 * parte per require_role()/require_csrf()/csrf_field()/create_alert()/format_date_it().
 */

define('NEWS_DB_PATH', __DIR__ . '/news.db');

// ---------------------------------------------------------------------------
// Connessione DB
// ---------------------------------------------------------------------------

function get_news_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(NEWS_DB_PATH);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        // Deliberatamente NON in modalità WAL — stessa disciplina di auth.php:
        // i file .shm/.wal persistenti hanno causato un problema reale di
        // proprietà mista tra utente di sistema e www-data su questo host.

        $db->exec("CREATE TABLE IF NOT EXISTS feed_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            is_active INTEGER NOT NULL DEFAULT 1,
            default_author TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_fetched_at TEXT,
            last_fetch_status TEXT,
            last_fetch_error TEXT,
            last_fetch_item_count INTEGER
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER NOT NULL REFERENCES feed_sources(id),
            guid TEXT NOT NULL,
            link TEXT NOT NULL,
            title TEXT NOT NULL,
            body_text TEXT NOT NULL,
            author TEXT,
            published_at TEXT,
            fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            keywords_json TEXT,
            top_keyword TEXT
        )");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_articles_dedup ON articles(feed_id, guid)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_articles_published ON articles(published_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_articles_fetched ON articles(fetched_at)");

        $db->exec("CREATE TABLE IF NOT EXISTS article_keywords (
            article_id INTEGER NOT NULL REFERENCES articles(id),
            keyword TEXT NOT NULL,
            weight INTEGER NOT NULL,
            PRIMARY KEY (article_id, keyword)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_article_keywords_kw ON article_keywords(keyword)");

        $db->exec("CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            article_id INTEGER NOT NULL REFERENCES articles(id),
            user_id INTEGER NOT NULL,
            username_snapshot TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT,
            deleted_by INTEGER
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_comments_article ON comments(article_id, created_at)");
    }
    return $db;
}

// ---------------------------------------------------------------------------
// Parsing RSS/Atom (SimpleXML)
// ---------------------------------------------------------------------------

function fetch_feed_xml(string $url): ?SimpleXMLElement {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: Mozilla/5.0 (compatible; MilAirItaBot/1.0; RSS reader, uso non commerciale)\r\n",
        'timeout' => 20,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_clear_errors();
    return $xml ?: null;
}

/** Converte una data di feed (RFC 2822 tipico RSS, o ISO8601 tipico Atom) in stringa UTC 'Y-m-d H:i:s'. */
function parse_feed_date(?string $raw): ?string {
    if (empty($raw)) {
        return null;
    }
    try {
        $d = DateTime::createFromFormat(DateTime::RFC2822, trim($raw));
        if (!$d) {
            $d = new DateTime(trim($raw));
        }
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function clean_html_to_text(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function parse_rss_item(SimpleXMLElement $item): array {
    $contentNs = $item->children('http://purl.org/rss/1.0/modules/content/');
    $dcNs = $item->children('http://purl.org/dc/elements/1.1/');
    $bodyRaw = (string)($contentNs->encoded ?: $item->description ?: '');
    $guidVal = trim((string)($item->guid ?: ''));
    return [
        'guid' => $guidVal !== '' ? $guidVal : (string)$item->link,
        'link' => (string)$item->link,
        'title' => trim((string)$item->title),
        'raw_body' => $bodyRaw,
        'author' => trim((string)($dcNs->creator ?: $item->author ?: '')),
        'published_at' => parse_feed_date((string)$item->pubDate),
    ];
}

function parse_atom_entry(SimpleXMLElement $entry): array {
    $link = '';
    if (isset($entry->link)) {
        foreach ($entry->link as $l) {
            $attrs = $l->attributes();
            $rel = (string)($attrs['rel'] ?? 'alternate');
            if ($rel === 'alternate' || $link === '') {
                $link = (string)$attrs['href'];
            }
            if ($rel === 'alternate') {
                break;
            }
        }
    }
    $bodyRaw = (string)($entry->content ?: $entry->summary ?: '');
    $author = isset($entry->author->name) ? (string)$entry->author->name : '';
    return [
        'guid' => trim((string)($entry->id ?: $link)),
        'link' => $link,
        'title' => trim((string)$entry->title),
        'raw_body' => $bodyRaw,
        'author' => trim($author),
        'published_at' => parse_feed_date((string)($entry->updated ?: $entry->published ?: '')),
    ];
}

/** Normalizza un XML di feed (RSS 2.0 o Atom) in un array di item grezzi. */
function normalize_feed_items(SimpleXMLElement $xml): array {
    $items = [];
    if (isset($xml->channel)) {
        foreach ($xml->channel->item as $item) {
            $items[] = parse_rss_item($item);
        }
    } elseif ($xml->getName() === 'feed') {
        foreach ($xml->entry as $entry) {
            $items[] = parse_atom_entry($entry);
        }
    }
    return $items;
}

// ---------------------------------------------------------------------------
// Estrazione parole chiave
// ---------------------------------------------------------------------------

const NEWS_STOPWORDS = [
    // Italiano: articoli, preposizioni, congiunzioni, pronomi, verbi ausiliari comuni, termini di contorno
    'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'del', 'dello', 'della', 'dei', 'degli', 'delle',
    'a', 'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'da', 'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle',
    'in', 'nel', 'nello', 'nella', 'nei', 'negli', 'nelle', 'con', 'su', 'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle',
    'per', 'tra', 'fra', 'e', 'ed', 'o', 'od', 'ma', 'però', 'anche', 'che', 'chi', 'cui', 'non', 'si', 'come', 'dove',
    'quando', 'perché', 'se', 'più', 'meno', 'molto', 'poco', 'questo', 'questa', 'questi', 'queste',
    'quello', 'quella', 'quelli', 'quelle', 'suo', 'sua', 'suoi', 'sue', 'loro', 'mio', 'mia', 'miei', 'mie',
    'tuo', 'tua', 'tuoi', 'tue', 'nostro', 'nostra', 'essere', 'sono', 'era', 'stato', 'stata', 'hanno', 'ha',
    'avere', 'aveva', 'fare', 'fatto', 'leggi', 'tutto', 'articolo', 'fonte', 'foto', 'video',
    // Inglese: articles, prepositions, conjunctions, pronouns, common verb/aux forms, boilerplate
    'the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'as', 'is', 'are', 'was', 'were',
    'be', 'been', 'being', 'and', 'or', 'but', 'not', 'this', 'that', 'these', 'those', 'it', 'its', 'his', 'her',
    'their', 'our', 'your', 'my', 'has', 'have', 'had', 'will', 'would', 'can', 'could', 'should', 'may', 'might',
    'said', 'says', 'more', 'most', 'also', 'than', 'then', 'about', 'into', 'over', 'under', 'after', 'before',
    'between', 'read', 'source', 'photo', 'article', 'according', 'new', 'news', 'which', 'who', 'what', 'when',
    'there', 'here', 'such', 'each', 'other', 'some', 'all', 'any', 'both', 'while', 'during',
];

/**
 * Estrae le parole chiave più frequenti di un testo, escludendo le stopword
 * italiane/inglesi. Ritorna un array [['word'=>..,'count'=>..], ...] ordinato
 * per frequenza decrescente (pareggio: alfabetico) — il primo elemento è la
 * "correlazione principale".
 */
function extract_keywords(string $text, int $topN = 10, int $minLen = 4): array {
    $lower = mb_strtolower($text, 'UTF-8');
    $tokens = preg_split('/[^\p{L}\']+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $stop = array_flip(NEWS_STOPWORDS);
    $counts = [];
    foreach ($tokens as $t) {
        $t = trim($t, "'");
        if (mb_strlen($t, 'UTF-8') < $minLen) {
            continue;
        }
        if (isset($stop[$t])) {
            continue;
        }
        $counts[$t] = ($counts[$t] ?? 0) + 1;
    }
    uksort($counts, function ($a, $b) use ($counts) {
        if ($counts[$a] !== $counts[$b]) {
            return $counts[$b] <=> $counts[$a];
        }
        return $a <=> $b;
    });
    $top = array_slice($counts, 0, $topN, true);
    $result = [];
    foreach ($top as $word => $count) {
        $result[] = ['word' => $word, 'count' => $count];
    }
    return $result;
}
