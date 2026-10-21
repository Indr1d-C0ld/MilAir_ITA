# MilAir ITA

Piattaforma web self-hosted per il monitoraggio in tempo reale del traffico aereo **militare** sopra l'Italia, basata su dati ADS-B pubblici. Raccoglie, archivia e visualizza su una mappa interattiva i contatti radar dei velivoli militari, con un sistema di regole/alert personalizzabili, statistiche, un diario giornaliero e gestione utenti multi-ruolo.

Il progetto è nato come strumento "per aeroappassionati" (spotting/monitoraggio open-source di traffico aereo militare, pratica comune e basata su dati già pubblici) e viene condiviso qui affinché altri possano installarlo, studiarlo e migliorarlo.

> ⚠️ Questo repository contiene **solo il codice sorgente**. Database, log, sessioni, cache e immagini scaricate automaticamente (foto/silhouette/disegni) **non sono inclusi**: vengono creati e popolati automaticamente al primo avvio e durante l'uso normale (vedi [Installazione](#installazione)).
>
> Il progetto è pensato per stare leggero su un piccolo server con SSD: i database si scrivono solo quando cambia davvero qualcosa, sessioni e cache stanno in RAM, le visite anonime non scrivono nulla.

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

Il sistema si basa su una pipeline semplice, pensata per essere robusta anche su un piccolo VPS e per non consumare il disco:

1. **Raccolta e registrazione** — [`flight_mil_ita.py`](flight_mil_ita.py), servizio systemd, interroga ogni minuto l'endpoint pubblico `/v2/mil` di [adsb.fi](https://adsb.fi) (solo velivoli identificati come militari), filtra i contatti con il perimetro di [`polygons.json`](polygons.json) (confini Italia, da Natural Earth) e li scrive **direttamente** nel database SQLite `events.db`: evento storico, giorno distinto, riepilogo per hex con la serie di giorni consecutivi, identità. Ogni contatto si scrive una volta, quando arriva, in una sola transazione; lo stesso hex resta poi in silenzio per 30 minuti.
2. **Dopo ogni registrazione** — il logger lancia [`update_rarity.php`](update_rarity.php) (rarità composita dei contatti, ispirata ai loot table dei GdR: frequenza avvistamenti, rarità dell'operatore/forza aerea e della nazionalità, in 6 fasce a soglie fisse) e [`alert_scan.php`](alert_scan.php) (notifiche). Rarità e avvisi cambiano solo quando arrivano contatti nuovi, quindi non servono cron che girino a vuoto; la rarità scrive solo le righe cambiate.
3. **Presentazione** — l'applicazione PHP legge `events.db` e mostra i contatti su una mappa [Leaflet](https://leafletjs.com/), con arricchimento in background: foto/silhouette/disegni tecnici del modello (scaricati da fonti pubbliche una volta per notte), overlay meteo/satellite, NOTAM, alert e diario.

Tutta la logica di identificazione "è un velivolo militare" avviene lato adsb.fi; l'applicazione si occupa di storicizzazione, arricchimento, visualizzazione e notifica.

Fino all'ottobre 2026 i contatti passavano da un CSV (`mil.csv`) che `csv_to_db.py` rileggeva per intero ogni 5 minuti: per importare un solo evento nuovo riscriveva migliaia di righe già presenti. La pipeline attuale ha tolto quel passaggio.

## Funzionalità principali

- **Mappa live** ([`map.php`](map.php)) con marker per ogni contatto, traccia storica on-demand ([`track.php`](track.php)) che si aggiorna automaticamente cambiando la fascia temporale, con opzione per isolare sulla mappa il solo contatto tracciato, overlay meteo (OpenWeather) e satellitare (Sentinel Hub) opzionali, NOTAM italiani, e zone geografiche disegnabili/filtrabili.
- **Dashboard principale** ([`index.php`](index.php)) con tabella dei contatti, ricerca, filtri, preferiti ([`favorites.php`](favorites.php)), note e correzioni manuali dell'analista su registrazione/callsign/modello ([`save_identity.php`](save_identity.php)). Ogni contatto con callsign riconoscibile (es. `IAM9001`) mostra il codice operatore/forza aerea a 3 lettere derivato automaticamente, col relativo logo se disponibile ([`download_opflags.php`](download_opflags.php)) — badge cliccabile come scorciatoia diretta di ricerca filtrata (`?operator=IAM`, sempre su tutto lo storico). Pulsanti 🔄 in tabella permettono il recupero istantaneo ([`fetch_assets_now.php`](fetch_assets_now.php)) di silhouette/foto/disegni/logo mancanti per un singolo contatto, senza attendere il prossimo ciclo cron; è disponibile anche l'aggiornamento automatico della pagina con intervallo regolabile. Righe di tabella ad altezza fissa e compatta: le note lunghe vengono troncate su una riga (tooltip al passaggio del mouse per leggerle per intero) e le miniature (silhouette, foto reale, foto modello, disegno tecnico) mostrano un'anteprima ingrandita al passaggio del mouse.
- **Motore di regole personalizzate** ([`rules.php`](rules.php)) per evidenziare righe, mappare nazionalità, marcare contatti (es. ❓ = in watchlist) e generare note automatiche in base a pattern su hex/reg/callsign/modello.
- **Sistema di alert** ([`alert_scan.php`](alert_scan.php), eseguito ogni 5 minuti) che genera notifiche per: contatti in watchlist che ricompaiono, squawk di emergenza (7500/7600/7700), contatti mai visti prima con rarità *Mythic*/*Legendary* ([`update_rarity.php`](update_rarity.php)), corrispondenze con le regole personalizzate, e regole di notifica su contatti non ancora visti (hex/callsign/reg attesi).
- **Statistiche e heatmap** ([`stats.php`](stats.php), [`heatmap.php`](heatmap.php)) su nazionalità (con bandierine), modelli, frequenza dei contatti, e classifiche (forze aeree/compagnie con logo, callsign, registrazioni). Giorni e ore sono sempre quelli del calendario italiano (Europe/Rome), anche se il database memorizza gli istanti in UTC: il pulsante "Oggi" di tabella, mappa e heatmap copre il giorno di calendario, non le ultime 24 ore.
- **Diario di bordo** ([`diary.php`](diary.php)): una sintesi giornaliera deterministica dei contatti (per ora, per operatore/nazionalità/modello, mezzi ricorrenti, contatti rari, squawk di emergenza, novità rispetto ai 14 giorni precedenti). Con [`diary_build.php`](diary_build.php) (cron notturno) la voce di ieri viene calcolata e **pubblicata automaticamente**; senza cron resta calcolabile e pubblicabile a mano dall'admin. Pubblica in lettura solo per i giorni effettivamente pubblicati. Supporta opzionalmente una sintesi narrativa generata da un assistente IA in esecuzione su un server [Ollama](https://ollama.com) nella propria rete locale ([`ai_lib.php`](ai_lib.php), mai un servizio cloud, sempre un passo manuale): la bozza generata va sempre rivista da un operatore.
- **Gestione utenti multi-ruolo** ([`admin_users.php`](admin_users.php)) e un modulo di richiesta accesso pubblico con approvazione manuale da parte di un admin ([`richieste.php`](richieste.php) → [`admin_richieste.php`](admin_richieste.php)). Nessun registro di ogni richiesta nel database: le visite si leggono dai log del server web.
- **Export dati** (dopo il login): [`export.php`](export.php) esporta in CSV (o in versione stampabile/PDF dal browser) esattamente le righe che la tabella mostra con gli stessi parametri — filtri, pulsanti rapidi, correzioni manuali, note automatiche e ordinamento, senza paginazione — perché tabella ed export condividono la stessa pipeline ([`table_lib.php`](table_lib.php)); [`export_rules.php`](export_rules.php) esporta le regole in JSON (reimportabili da `rules.php`).

## Architettura

```
┌──────────────────┐   HTTPS    ┌───────────────────┐
│ opendata.adsb.fi │ ─────────▶ │ flight_mil_ita.py │  (systemd, ogni 60 s)
└──────────────────┘            └─────────┬─────────┘
                                          │ solo contatti nuovi, una transazione
                                          ▼
                                  events.db (SQLite)
                                          │ dopo ogni registrazione
                                          ├──▶ update_rarity.php (solo righe cambiate)
                                          └──▶ alert_scan.php    (→ auth.db)

          index.php / map.php / stats.php / heatmap.php / diary.php  (letture)
          auth.php → auth.db (utenti, tentativi di login, alert, richieste)
```

Due database SQLite indipendenti, ciascuno con una responsabilità precisa:

| Database | Contenuto |
|---|---|
| `events.db` | Contatti radar storicizzati, regole, preferiti, note, cache rarità, filtri geografici, NOTAM, diario |
| `auth.db` | Utenti, tentativi di login, alert, richieste di accesso |

Tutti e due vengono creati automaticamente (schema `CREATE TABLE IF NOT EXISTS`) al primo utilizzo — non serve alcuna migrazione manuale.

**Dove stanno i dati** ([`percorsi.php`](percorsi.php), unico posto in cui i percorsi sono scritti): i database in `MILAIR_DATI` (`/var/lib/milair`), sessioni e cache delle tile in `MILAIR_RAM` (`/run/milair`, tmpfs: si ricreano dopo un riavvio). Se quelle cartelle non esistono si usa la cartella del codice, come nelle prime versioni. Il logger segue le stesse regole.

**Cosa scrive, e quando**:
- una visita anonima (tabella, mappa, statistiche, diario) non scrive nulla: niente sessione, niente registro;
- il logger scrive solo quando ci sono contatti nuovi, rarità e NOTAM solo ciò che è cambiato;
- le foto reali che FlightDB non ha si ritentano dopo 30 giorni, non a ogni giro.

## Struttura del progetto

```
├── index.php, map.php, stats.php, heatmap.php             → pagine principali
├── auth.php, login.php, logout.php, setup.php              → autenticazione e primo avvio
├── admin_*.php                                              → pannelli di amministrazione
├── rules.php, geofilter.php, favorites.php, edit_*.php      → motore regole e personalizzazioni utente
├── alert_scan.php, alerts.php, alerts_count.php             → sistema di notifiche
├── track.php, save_identity.php, toggle_*.php               → API JSON usate via fetch() dal frontend
├── fetch_assets_now.php                                     → recupero istantaneo on-demand di un singolo asset (via fetch() da index.php)
├── download_*.php                                           → script CLI (cron) di arricchimento (foto/silhouette/disegni/loghi operatore)
├── fetch_notams.php                                         → NOTAM per la mappa
├── geo_lib.php                                              → nazionalità, operatore e pattern matching (definizioni canoniche, usate da tutte le pagine)
├── table_lib.php                                            → pipeline condivisa della tabella contatti (filtri, regole, ordinamento): la usano index.php ed export.php
├── diary.php, diary_lib.php, diary_build.php, ai_lib.php    → Diario di bordo (digest giornaliero, pubblicazione, sintesi IA locale opzionale)
├── satellite_tile.php, weather_tile.php                     → proxy server-side per i layer mappa opzionali (con limite per IP e cache a scadenza)
├── flight_mil_ita.py                                        → raccolta e registrazione dei contatti
├── percorsi.php                                             → dove stanno database, sessioni e cache
├── map_secrets.php.example, ai_secrets.php.example          → template chiavi API (copiare senza ".example")
├── crontab.txt, milair-logger.service, milair-tmpfiles.conf,
│   fix_permissions.sh                                       → esempi di configurazione per il deploy
├── style.css, tema.css                                       → stile applicazione; tema.css (tema scuro «sala operativa»,
│                                                              caricato da render_nav() dopo gli stili delle pagine)
├── fonts/                                                   → JetBrains Mono ridotto ai caratteri latini (woff2), per i dati
├── leaflet/                                                 → libreria Leaflet + plugin (draw, heat), self-hosted
├── flags/                                                   → set di bandiere SVG per codice ISO (nazionalità)
└── polygons.json                                            → confini Italia (Natural Earth, per il geofiltro)
```

Le seguenti cartelle **non sono nel repository** (create automaticamente, vedi `.gitignore`): `drawings/`, `fdbphotos/`, `photos/`, `silhouettes/`, `opflags/`, oltre a `*.db` e `*.log`; sessioni e cache stanno in `MILAIR_RAM`.

## Requisiti

- **PHP** ≥ 8.0 con estensione `sqlite3`
- **Python** 3.8+ con il pacchetto `requests` (`pip install requests`)
- **Apache** con `mod_rewrite` e `mod_headers` (usati dal `.htaccess`, da creare copiando [`.htaccess.example`](.htaccess.example), per header di sicurezza e per bloccare l'accesso diretto a segreti, database e cache) — su Nginx vanno tradotte le regole equivalenti
- **cron** e/o **systemd**, per l'esecuzione periodica degli script
- **SQLite3** (CLI, opzionale, utile per ispezionare i database)

## Installazione

1. **Clona il repository** nella document root (es. `/var/www/html/milair_ita`):
   ```bash
   git clone <url-del-repo> /var/www/html/milair_ita
   cd /var/www/html/milair_ita
   ```

2. **Configura Apache**: copia le regole di [`.htaccess.example`](.htaccess.example) in un `.htaccess` (se il vhost ha `AllowOverride All`) oppure, meglio, in un blocco `<Directory>` della configurazione di Apache. Negano l'accesso a database, segreti, log e librerie, e chiedono ai motori di ricerca di non indicizzare.

3. **Configura le chiavi API opzionali** (vedi [sezione dedicata](#configurazione-chiavi-api-opzionali)) — puoi anche saltare questo passo: l'app funziona comunque, con i soli layer opzionali disattivati.
   ```bash
   cp map_secrets.php.example map_secrets.php
   ```

4. **Cartelle e permessi**: adatta `USER` in [`fix_permissions.sh`](fix_permissions.sh) e in [`milair-tmpfiles.conf`](milair-tmpfiles.conf), poi (con sudo):
   ```bash
   sudo cp milair-tmpfiles.conf /etc/tmpfiles.d/milair.conf
   sudo systemd-tmpfiles --create /etc/tmpfiles.d/milair.conf
   sudo ./fix_permissions.sh
   ```
   In sintesi: i database stanno in `/var/lib/milair` (`2770`, scrivibili da Apache e dal logger), sessioni e cache in `/run/milair` (tmpfs), il codice non è scrivibile da Apache tranne le cartelle degli asset scaricati.

5. **Primo avvio**: apri `https://<tuo-dominio>/setup.php` nel browser per creare il primo account amministratore. La pagina si autodisabilita in modo permanente subito dopo la creazione del primo utente (stesso pattern di WordPress/Nextcloud) e reindirizza a `login.php`.

6. **Avvia la raccolta dati**: installa il servizio systemd e le voci di cron (vedi sotto).

## Configurazione chiavi API opzionali

Tutte le chiavi sono **facoltative**: senza di esse l'app funziona regolarmente, con le sole funzionalità collegate disabilitate nell'interfaccia.

| File | Chiave | Funzione | Dove ottenerla |
|---|---|---|---|
| `map_secrets.php` | `SENTINELHUB_CLIENT_ID` / `SENTINELHUB_CLIENT_SECRET` | Layer satellitare sulla mappa | [sentinel-hub.com](https://www.sentinel-hub.com) (piano free, OAuth client da creare sul [dashboard](https://apps.sentinel-hub.com/dashboard/)) |
| `map_secrets.php` | `OPENWEATHER_API_KEY` | Layer meteo sulla mappa | [openweathermap.org/api](https://openweathermap.org/api) (piano free) |
| `ai_secrets.php` | `AI_BASE_URL` | Sintesi narrativa IA nel Diario | Nessuna: indirizzo di un server [Ollama](https://ollama.com) nella propria rete locale, non un servizio cloud |

`map_secrets.php` e `ai_secrets.php` sono esclusi dal repository (`.gitignore`) e negati esplicitamente via `.htaccess`: non vengono mai serviti al browser, solo inclusi lato server.

## Automazioni (cron / systemd)

**Raccolta continua** — installa [`milair-logger.service`](milair-logger.service) come servizio systemd (dopo aver sostituito `User=CHANGEME` con il tuo utente):
```bash
sudo cp milair-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now milair-logger
```
Il logger registra i contatti e, dopo ogni registrazione, lancia `update_rarity.php` e `alert_scan.php`: rarità e avvisi non hanno bisogno di cron.

**Job periodici** — aggiungi le voci di [`crontab.txt`](crontab.txt) al crontab reale (`crontab -e`), adattando i percorsi se diversi da `/var/www/html/milair_ita`:

| Frequenza | Script | Scopo |
|---|---|---|
| ogni 3 ore (al minuto 25) | `fetch_notams.php` | aggiorna i NOTAM per l'overlay mappa (scrive solo quelli cambiati) |
| ogni notte alle 00:15 | `diary_build.php --publish --quiet` | calcola e pubblica la voce di Diario di ieri; pota i dati di servizio di `auth.db` |
| ogni notte alle 04:30 | `download_silhouettes.php`, `download_photos.php`, `download_drawings.php`, `download_fdb_photos.php` | scaricano gli asset visivi mancanti, uno dopo l'altro a priorità minima |
| la domenica alle 04:50 | `download_opflags.php` | aggiorna i loghi operatore/forza aerea (VRS OperatorFlags) |

## Ruoli utente e sicurezza

Tre livelli di accesso, gestiti in [`auth.php`](auth.php):

- **pubblico** — nessun account, sola visione di tabella, mappa, statistiche e diario (l'export richiede il login)
- **collaboratore** — editing completo (regole, note, preferiti, correzioni identità)
- **admin** — come collaboratore + pannello utenti, richieste di accesso

Misure di sicurezza implementate:
- Password con hash tramite `password_hash()`/`password_verify()` di PHP (bcrypt); lunghezza minima definita da `PASSWORD_MIN_LENGTH` in [`auth.php`](auth.php) e applicata sia lato server sia nei form — alzarla è consigliabile se il portale è esposto su internet
- Protezione CSRF su tutte le form (`require_csrf()`/`csrf_field()`)
- Risposta generica e a tempo costante su login falliti (utente inesistente, password errata o account disattivo producono lo stesso esito, per non facilitare l'enumerazione utenti)
- Limite sui tentativi di login per utente **e** per indirizzo IP
- Ruolo e stato dell'account riletti dal database a ogni richiesta: disattivare o retrocedere un utente ha effetto immediato anche sulle sessioni già aperte; lo stesso vale per un cambio o reset della password, che chiude tutte le altre sessioni aperte con la vecchia (utile se un account è compromesso)
- Redirect dopo il login e link di ritorno ("Annulla", `return=`) limitati alle pagine dell'applicazione (`safe_local_url()`): niente open redirect verso siti esterni né link `javascript:`
- Gli endpoint chiamati dal frontend via `fetch()` rispondono `401`/`403` in JSON invece di reindirizzare al login: una scheda rimasta aperta dopo la scadenza della sessione smette di interrogare il server
- Sessioni pigre (nascono solo al login o quando una pagina chiede un gettone CSRF: una visita anonima non apre sessioni), in una directory dedicata in RAM, con garbage collection esplicita e scadenza dopo 12 ore di inattività (su Debian la pulizia di sistema non vedrebbe una `save_path` impostata a runtime)
- Tentativi di login in `auth.db`, con conservazione limitata nel tempo (vedi le costanti `*_RETENTION_DAYS` in [`auth.php`](auth.php)); nessun registro di ogni richiesta, nessuna geolocalizzazione degli IP presso servizi esterni
- Modulo pubblico di richiesta accesso con honeypot anti-bot e limite per IP, soggetto ad approvazione manuale di un admin
- Proxy dei layer mappa con limite di chiamate per IP: gli endpoint sono pubblici (la mappa è consultabile senza login) ma non possono essere usati per prosciugare la quota delle API esterne
- Header di sicurezza HTTP (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security`, `Permissions-Policy`, `Content-Security-Policy: frame-ancestors`)
- Messaggi d'errore generici verso l'utente, dettaglio tecnico solo nel log del server
- Le regole di Apache negano l'accesso HTTP diretto a: file di segreti, database SQLite, file `.json`/`.csv`/`.bak`, log, script CLI e librerie di sole funzioni, e chiedono di non indicizzare (`X-Robots-Tag: noindex, nofollow`): le combinazioni di filtri della tabella sono infinite, e i crawler le percorrevano tutte

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
