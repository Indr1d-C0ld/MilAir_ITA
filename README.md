# MilAir ITA

Piattaforma web self-hosted per il monitoraggio in tempo reale del traffico aereo **militare** sopra l'Italia, basata su dati ADS-B pubblici. Raccoglie, archivia e visualizza su una mappa interattiva i contatti radar dei velivoli militari, con un sistema di regole/alert personalizzabili, statistiche, rassegna stampa correlata e gestione utenti multi-ruolo.

Il progetto è nato come strumento "per aeroappassionati" (spotting/monitoraggio open-source di traffico aereo militare, pratica comune e basata su dati già pubblici) e viene condiviso qui affinché altri possano installarlo, studiarlo e migliorarlo.

> ⚠️ Questo repository contiene **solo il codice sorgente**. Database, log, sessioni, cache e immagini scaricate automaticamente (foto/silhouette/disegni) **non sono inclusi**: vengono creati e popolati automaticamente al primo avvio e durante l'uso normale (vedi [Installazione](#installazione)).

---

## Indice

- [Come funziona](#come-funziona)
- [Funzionalità principali](#funzionalità-principali)
- [Architettura](#architettura)
- [Struttura del progetto](#struttura-del-progetto)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione chiavi API opzionali](#configurazione-chiavi-api-opzionali)
- [Automazioni (cron / systemd)](#automazioni-cron--systemd)
- [Ruoli utente e sicurezza](#ruoli-utente-e-sicurezza)
- [Fonti dati e crediti](#fonti-dati-e-crediti)
- [Contribuire](#contribuire)
- [Licenza](#licenza)

---

## Come funziona

Il sistema si basa su una pipeline in tre fasi, pensata per essere semplice e robusta anche su un piccolo VPS:

1. **Raccolta** — [`flight_mil_ita.py`](flight_mil_ita.py) interroga periodicamente l'endpoint pubblico `/v2/mil` di [adsb.fi](https://adsb.fi) (solo velivoli identificati come militari), filtra i contatti tramite il perimetro geografico definito in [`polygons.json`](polygons.json) (confini Italia, da Natural Earth) e appende ogni nuovo contatto a un file CSV (`mil.csv`).
2. **Importazione** — [`csv_to_db.py`](csv_to_db.py), eseguito ogni 5 minuti via cron, importa il CSV nel database SQLite `events.db`, deduplica i contatti e aggiorna le statistiche di "serie" (giorni consecutivi di avvistamento per ogni velivolo).
3. **Presentazione** — l'applicazione PHP legge `events.db` e mostra i contatti su una mappa [Leaflet](https://leafletjs.com/), con arricchimento progressivo in background: rarità composita dei contatti ([`update_rarity.php`](update_rarity.php), ispirata ai loot table dei GdR — combina frequenza avvistamenti, rarità dell'operatore/forza aerea e rarità della nazionalità in 6 fasce a soglie fisse, non ricalcolate sulla popolazione corrente), foto/silhouette/disegni tecnici del modello (scaricati da fonti pubbliche), overlay meteo/satellite, NOTAM, alert e rassegna stampa correlata.

Tutta la logica di identificazione "è un velivolo militare" avviene lato adsb.fi; l'applicazione si occupa di storicizzazione, arricchimento, visualizzazione e notifica.

## Funzionalità principali

- **Mappa live** ([`map.php`](map.php)) con marker per ogni contatto, traccia storica on-demand ([`track.php`](track.php)) che si aggiorna automaticamente cambiando la fascia temporale, con opzione per isolare sulla mappa il solo contatto tracciato, overlay meteo (OpenWeather) e satellitare (Sentinel Hub) opzionali, NOTAM italiani, e zone geografiche disegnabili/filtrabili.
- **Dashboard principale** ([`index.php`](index.php)) con tabella dei contatti, ricerca, filtri, preferiti ([`favorites.php`](favorites.php)), note e correzioni manuali dell'analista su registrazione/callsign/modello ([`save_identity.php`](save_identity.php)). Ogni contatto con callsign riconoscibile (es. `IAM9001`) mostra il codice operatore/forza aerea a 3 lettere derivato automaticamente, col relativo logo se disponibile ([`download_opflags.php`](download_opflags.php)) — badge cliccabile come scorciatoia diretta di ricerca filtrata (`?operator=IAM`, sempre su tutto lo storico). Pulsanti 🔄 in tabella permettono il recupero istantaneo ([`fetch_assets_now.php`](fetch_assets_now.php)) di silhouette/foto/disegni/logo mancanti per un singolo contatto, senza attendere il prossimo ciclo cron; è disponibile anche l'aggiornamento automatico della pagina con intervallo regolabile. Righe di tabella ad altezza fissa e compatta: le note lunghe vengono troncate su una riga (tooltip al passaggio del mouse per leggerle per intero) e le miniature (silhouette, foto reale, foto modello, disegno tecnico) mostrano un'anteprima ingrandita al passaggio del mouse.
- **Motore di regole personalizzate** ([`rules.php`](rules.php)) per evidenziare righe, mappare nazionalità, marcare contatti (es. ❓ = in watchlist) e generare note automatiche in base a pattern su hex/reg/callsign/modello.
- **Sistema di alert** ([`alert_scan.php`](alert_scan.php), eseguito ogni 5 minuti) che genera notifiche per: contatti in watchlist che ricompaiono, squawk di emergenza (7500/7600/7700), contatti mai visti prima con rarità *Mythic*/*Legendary* ([`update_rarity.php`](update_rarity.php)), corrispondenze con le regole personalizzate, e regole di notifica su contatti non ancora visti (hex/callsign/reg attesi).
- **Statistiche e heatmap** ([`stats.php`](stats.php), [`heatmap.php`](heatmap.php)) su nazionalità (con bandierine), modelli, frequenza dei contatti, e classifiche (forze aeree/compagnie con logo, callsign, registrazioni).
- **Rassegna stampa correlata** ([`news.php`](news.php)): un aggregatore RSS/Atom configurabile ([`admin_feeds.php`](admin_feeds.php)) che scarica periodicamente articoli, estrae parole chiave e genera alert per le notizie pertinenti.
- **Gestione utenti multi-ruolo** ([`admin_users.php`](admin_users.php)) con log accessi ([`admin_access_log.php`](admin_access_log.php), [`admin_access_stats.php`](admin_access_stats.php)) e un modulo di richiesta accesso pubblico con approvazione manuale da parte di un admin ([`richieste.php`](richieste.php) → [`admin_richieste.php`](admin_richieste.php)).
- **Export dati** ([`export.php`](export.php), [`export_rules.php`](export_rules.php)) in JSON/CSV.

## Architettura

```
┌─────────────────┐   HTTPS    ┌──────────────────┐
│ opendata.adsb.fi │ ─────────▶│ flight_mil_ita.py │  (systemd, polling continuo)
└─────────────────┘            └────────┬─────────┘
                                         │ append CSV
                                         ▼
                                     mil.csv
                                         │ cron */5 min
                                         ▼
                                  csv_to_db.py
                                         │
                                         ▼
                                    events.db (SQLite)
                                         │
                     ┌───────────────────┼────────────────────┐
                     ▼                   ▼                    ▼
              index.php / map.php   alert_scan.php      stats.php / heatmap.php
              (dashboard PHP +          (cron */5 min           (analisi)
               Leaflet)                  → auth.db)

              auth.php  →  auth.db  (utenti, sessioni, log accessi, alert, richieste)
              news_lib.php → news.db (rassegna stampa RSS/Atom)
```

Tre database SQLite indipendenti, ciascuno con una responsabilità precisa:

| Database | Contenuto |
|---|---|
| `events.db` | Contatti radar storicizzati, regole, preferiti, note, cache rarità, filtri geografici |
| `auth.db` | Utenti, tentativi di login, log accessi, alert, richieste di accesso |
| `news.db` | Fonti RSS/Atom configurate, articoli scaricati, parole chiave |

Tutti e tre vengono creati automaticamente (schema `CREATE TABLE IF NOT EXISTS`) al primo utilizzo — non serve alcuna migrazione manuale.

## Struttura del progetto

```
├── index.php, map.php, stats.php, heatmap.php, news.php   → pagine principali
├── auth.php, login.php, logout.php, setup.php              → autenticazione e primo avvio
├── admin_*.php                                              → pannelli di amministrazione
├── rules.php, geofilter.php, favorites.php, edit_*.php      → motore regole e personalizzazioni utente
├── alert_scan.php, alerts.php, alerts_count.php             → sistema di notifiche
├── track.php, save_identity.php, toggle_*.php               → API JSON usate via fetch() dal frontend
├── fetch_assets_now.php                                     → recupero istantaneo on-demand di un singolo asset (via fetch() da index.php)
├── download_*.php                                           → script CLI (cron) di arricchimento (foto/silhouette/disegni/loghi operatore)
├── fetch_news.php, fetch_notams.php, news_lib.php           → aggregazione RSS e NOTAM
├── satellite_tile.php, weather_tile.php                     → proxy server-side per i layer mappa opzionali
├── flight_mil_ita.py, csv_to_db.py                          → pipeline di raccolta/importazione dati
├── geo_secrets.php.example, map_secrets.php.example         → template chiavi API (copiare senza ".example")
├── crontab.txt, milair-logger.service, fix_permissions.sh   → esempi di configurazione per il deploy
├── style.css                                                → stile applicazione
├── leaflet/                                                 → libreria Leaflet + plugin (draw, heat), self-hosted
├── flags/                                                   → set di bandiere SVG per codice ISO (nazionalità)
└── polygons.json                                            → confini Italia (Natural Earth, per il geofiltro)
```

Le seguenti cartelle **non sono nel repository** (create automaticamente, vedi `.gitignore`): `cache/`, `sessions/`, `drawings/`, `fdbphotos/`, `photos/`, `silhouettes/`, `opflags/`, oltre a `*.db`, `*.log` e `mil.csv`.

## Requisiti

- **PHP** ≥ 8.0 con estensione `sqlite3`
- **Python** 3.8+ con il pacchetto `requests` (`pip install requests`)
- **Apache** con `mod_rewrite` (usato da [`.htaccess`](.htaccess) per bloccare l'accesso diretto a segreti, database e cache) — su Nginx vanno tradotte le regole equivalenti
- **cron** e/o **systemd**, per l'esecuzione periodica degli script
- **SQLite3** (CLI, opzionale, utile per ispezionare i database)

## Installazione

1. **Clona il repository** nella document root (es. `/var/www/html/milair_ita`):
   ```bash
   git clone <url-del-repo> /var/www/html/milair_ita
   cd /var/www/html/milair_ita
   ```

2. **Configura le chiavi API opzionali** (vedi [sezione dedicata](#configurazione-chiavi-api-opzionali)) — puoi anche saltare questo passo: l'app funziona comunque, con i soli layer opzionali disattivati.
   ```bash
   cp geo_secrets.php.example geo_secrets.php
   cp map_secrets.php.example map_secrets.php
   ```

3. **Permessi**: adatta `USER` in [`fix_permissions.sh`](fix_permissions.sh) al tuo utente di sistema, poi eseguilo (richiede sudo):
   ```bash
   sudo ./fix_permissions.sh
   ```
   In sintesi: Apache (`www-data`) deve poter scrivere su `events.db`, `auth.db`, `mil.csv`, `cache/`, `photos/`, `fdbphotos/`, `sessions/` (quest'ultima con permessi ristretti, `770`/`660`).

4. **Primo avvio**: apri `https://<tuo-dominio>/setup.php` nel browser per creare il primo account amministratore. La pagina si autodisabilita in modo permanente subito dopo la creazione del primo utente (stesso pattern di WordPress/Nextcloud) e reindirizza a `login.php`.

5. **Avvia la raccolta dati**: installa il servizio systemd e le voci di cron (vedi sotto).

## Configurazione chiavi API opzionali

Tutte le chiavi sono **facoltative**: senza di esse l'app funziona regolarmente, con le sole funzionalità collegate disabilitate nell'interfaccia.

| File | Chiave | Funzione | Dove ottenerla |
|---|---|---|---|
| `geo_secrets.php` | `IPGEOLOCATION_API_KEY` | Geolocalizzazione IP nel log accessi admin | [ipgeolocation.io](https://ipgeolocation.io) (piano free) |
| `geo_secrets.php` | `IPDATA_API_KEY` | Geolocalizzazione IP (alternativa/fallback) | [ipdata.co](https://ipdata.co) (piano free) |
| `map_secrets.php` | `SENTINELHUB_CLIENT_ID` / `SENTINELHUB_CLIENT_SECRET` | Layer satellitare sulla mappa | [sentinel-hub.com](https://www.sentinel-hub.com) (piano free, OAuth client da creare sul [dashboard](https://apps.sentinel-hub.com/dashboard/)) |
| `map_secrets.php` | `OPENWEATHER_API_KEY` | Layer meteo sulla mappa | [openweathermap.org/api](https://openweathermap.org/api) (piano free) |

`geo_secrets.php` e `map_secrets.php` sono esclusi dal repository (`.gitignore`) e negati esplicitamente via `.htaccess`: non vengono mai serviti al browser, solo inclusi lato server.

## Automazioni (cron / systemd)

**Raccolta continua** — installa [`milair-logger.service`](milair-logger.service) come servizio systemd (dopo aver sostituito `User=CHANGEME` con il tuo utente):
```bash
sudo cp milair-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now milair-logger
```

**Job periodici** — aggiungi le voci di [`crontab.txt`](crontab.txt) al crontab reale (`crontab -e`), adattando il percorso se diverso da `/var/www/html/milair_ita`:

| Frequenza | Script | Scopo |
|---|---|---|
| ogni 5 min | `csv_to_db.py` | importa `mil.csv` in `events.db` |
| ogni 5 min | `alert_scan.php` | scansiona nuovi eventi e genera alert |
| ogni 15 min | `fetch_news.php` | scarica nuovi articoli RSS/Atom |
| ogni ora | `update_rarity.php` | ricalcola la cache di rarità dei contatti |
| ogni 3 ore | `fetch_notams.php` | aggiorna i NOTAM per l'overlay mappa |
| ogni 6 ore | `download_silhouettes.php`, `download_photos.php`, `download_drawings.php`, `download_fdb_photos.php` | scaricano gli asset visivi mancanti per i modelli in database |
| una volta a settimana | `download_opflags.php` | aggiorna i loghi operatore/forza aerea (VRS OperatorFlags) |

## Ruoli utente e sicurezza

Tre livelli di accesso, gestiti in [`auth.php`](auth.php):

- **pubblico** — nessun account, sola visione (se abilitata dalla configurazione delle pagine)
- **collaboratore** — editing completo (regole, note, preferiti, correzioni identità)
- **admin** — come collaboratore + pannello utenti, log accessi, gestione feed RSS, richieste di accesso

Misure di sicurezza implementate:
- Password con hash tramite `password_hash()`/`password_verify()` di PHP (bcrypt), minimo 10 caratteri
- Protezione CSRF su tutte le form (`require_csrf()`/`csrf_field()`)
- Risposta generica e a tempo costante su login falliti (utente inesistente, password errata o account disattivo producono lo stesso esito, per non facilitare l'enumerazione utenti)
- Log di accessi e tentativi di login in `auth.db`
- Modulo pubblico di richiesta accesso con honeypot anti-bot, soggetto ad approvazione manuale di un admin
- `.htaccess` nega l'accesso HTTP diretto a: file di segreti, database SQLite, file `.json`/`.csv`/`.bak` e alla cartella `cache/`

## Fonti dati e crediti

- **Dati di volo**: [opendata.adsb.fi](https://adsb.fi) — rete comunitaria di ricevitori ADS-B, endpoint pubblico `/v2/mil`
- **Confini geografici**: [Natural Earth](https://www.naturalearthdata.com/) (dominio pubblico)
- **Foto e disegni tecnici velivoli**: [doc8643.com](https://doc8643.com)
- **Silhouette e foto contatti reali**: [flightdb.net](https://www.flightdb.net)
- **NOTAM Italia**: [notaminfo.com](https://notaminfo.com)
- **Bandiere nazionali**: set di icone SVG per codice ISO 3166
- **Loghi operatore/forza aerea**: [VRS OperatorFlags](https://github.com/rikgale/VRSOperatorFlags) (GPL-3.0)
- **Mappa**: [Leaflet](https://leafletjs.com/) con i plugin [Leaflet.draw](https://github.com/Leaflet/Leaflet.draw) e [Leaflet.heat](https://github.com/Leaflet/Leaflet.heat)
- **Meteo**: [OpenWeather](https://openweathermap.org/) (opzionale)
- **Satellite**: [Copernicus / Sentinel Hub](https://www.sentinel-hub.com/) (opzionale)

Rispettare i termini d'uso e i limiti di frequenza delle richieste (rate limit) di ciascun servizio esterno — gli intervalli di cron di default sono già tarati per restare entro un uso ragionevole (es. NOTAM ogni 3 ore, per rispettare il `Crawl-delay: 10` dichiarato da notaminfo.com).

## Contribuire

Pull request e segnalazioni di bug sono benvenute. Alcune aree in cui contribuire è particolarmente utile:
- supporto a Nginx (in alternativa alle regole `.htaccess` per Apache)
- containerizzazione (Docker/docker-compose)
- test automatici
- traduzione dell'interfaccia (attualmente solo in italiano)

## Licenza

Distribuito con licenza **GNU General Public License v3.0** — vedi [`LICENSE`](LICENSE).
