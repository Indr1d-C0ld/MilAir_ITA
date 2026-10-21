<?php
/**
 * Dove stanno i dati di MILAIR ITA: fuori dal codice.
 *
 *   MILAIR_DATI  i database (events.db, auth.db): disco, scritto solo quando
 *                cambia davvero qualcosa
 *   MILAIR_RAM   sessioni, cache delle tile meteo/satellite, contatori dei
 *                limiti per IP: memoria (tmpfs), si rifanno da soli dopo un
 *                riavvio e non consumano la SSD
 *
 * Si indicano con le variabili d'ambiente omonime; altrimenti valgono
 * /var/lib/milair e /run/milair se esistono, e in mancanza la cartella del
 * codice, come nelle prime versioni del progetto.
 *
 * Incluso da ogni script che apre un database: e' l'unico posto in cui i
 * percorsi sono scritti.
 */
if (!defined('MILAIR_DATI')) {
    define('MILAIR_DATI', rtrim(getenv('MILAIR_DATI') ?: (is_dir('/var/lib/milair') ? '/var/lib/milair' : __DIR__), '/'));
    define('MILAIR_RAM', rtrim(getenv('MILAIR_RAM') ?: (is_dir('/run/milair') ? '/run/milair' : __DIR__), '/'));
    define('EVENTS_DB_PATH', MILAIR_DATI . '/events.db');
    define('AUTH_DB_PATH', MILAIR_DATI . '/auth.db');
}
