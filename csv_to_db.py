#!/usr/bin/env python3
import sqlite3, csv, os, datetime, re

DB_FILE = os.path.join(os.path.dirname(__file__), 'events.db')
CSV_FILE = os.path.join(os.path.dirname(__file__), 'mil.csv')

def get_dates_for_hex(cur, hex_val):
    cur.execute("SELECT date FROM daily_hex WHERE hex = ? ORDER BY date ASC", (hex_val,))
    return [row[0] for row in cur.fetchall()]

def calc_max_streak(dates):
    if not dates:
        return 0
    max_streak = 1
    curr = 1
    for i in range(1, len(dates)):
        prev = datetime.date.fromisoformat(dates[i-1])
        now = datetime.date.fromisoformat(dates[i])
        if (now - prev).days == 1:
            curr += 1
            max_streak = max(max_streak, curr)
        else:
            curr = 1
    return max_streak

HEX_RE = re.compile(r'^~?[0-9a-f]{6}$')
TS_RE = re.compile(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$')

def field(r, key):
    """Valore testuale ripulito. Una riga troncata (il logger stava ancora
    scrivendo) ha i campi mancanti a None: prima None.strip() sollevava
    un'eccezione fuori da ogni try e interrompeva l'intero import."""
    v = r.get(key)
    return v.strip() if isinstance(v, str) else ''

def num(v, conv):
    """Numero o None: una stringa vuota finiva nel database come testo '',
    che SQLite ordina sopra ogni numero (quote "vuote" nella fascia 30k+)."""
    if v == '':
        return None
    try:
        return conv(v)
    except ValueError:
        return None

def main():
    if not os.path.isfile(CSV_FILE):
        print("CSV non trovato.")
        return

    # timeout=30: di default sqlite3 attende solo 5 secondi un lock occupato.
    # update_rarity.php (ogni ora, alle :00 come questo import) ne attende 30:
    # con attese asimmetriche, in caso di contesa a cedere era sempre l'import,
    # che falliva al commit con "database is locked".
    conn = sqlite3.connect(DB_FILE, timeout=30)
    conn.execute("PRAGMA journal_mode=DELETE")

    # Creazione tabelle (se non esistono)
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS events (
            first_seen_utc TEXT, hex TEXT, callsign TEXT, reg TEXT, model_t TEXT,
            lat REAL, lon REAL, alt_ft INTEGER, gs_kt REAL, squawk TEXT, ground TEXT,
            PRIMARY KEY (first_seen_utc, hex)
        );
        CREATE TABLE IF NOT EXISTS aircraft (
            hex TEXT PRIMARY KEY,
            first_seen_utc TEXT NOT NULL,
            last_seen_utc TEXT NOT NULL,
            seen_count INTEGER DEFAULT 0,
            max_consecutive_days INTEGER DEFAULT 0,
            callsign TEXT, reg TEXT, model_t TEXT,
            lat REAL, lon REAL, alt_ft INTEGER, gs_kt REAL, squawk TEXT, ground TEXT
        );
        CREATE TABLE IF NOT EXISTS daily_hex (
            hex TEXT, date TEXT, PRIMARY KEY (hex, date)
        );
        CREATE TABLE IF NOT EXISTS aircraft_identity (
            hex TEXT, callsign TEXT, reg TEXT, model_t TEXT,
            first_seen_utc TEXT, last_seen_utc TEXT,
            PRIMARY KEY (hex, callsign, reg)
        );
        CREATE TABLE IF NOT EXISTS notes (
            hex TEXT PRIMARY KEY, note TEXT
        );

        -- La chiave primaria di events e' (first_seen_utc, hex): copre le query
        -- per intervallo di date, ma NON quelle per solo hex, che facevano una
        -- scansione completa della tabella. Le usano track.php (traccia storica),
        -- heatmap.php?hex=... e il Diario.
        CREATE INDEX IF NOT EXISTS idx_events_hex ON events(hex);
    """)

    # Migrazione: aggiunge la colonna 'category' (categoria emettitore ADS-B, es. A7
    # = elicottero, B6 = UAV/drone) se assente, su database creati prima di questa modifica.
    for table in ('events', 'aircraft'):
        cur_cols = [row[1] for row in conn.execute(f"PRAGMA table_info({table})")]
        if 'category' not in cur_cols:
            conn.execute(f"ALTER TABLE {table} ADD COLUMN category TEXT")

    with open(CSV_FILE, newline='', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter=',', skipinitialspace=True)
        rows = list(reader)
        fieldnames = reader.fieldnames or []

    cur = conn.cursor()
    events_inserted = 0
    aircraft_updated = 0
    identity_updated = 0

    skipped = 0
    for r in rows:
        hex_val = field(r, 'hex').lower()
        ts = field(r, 'first_seen_utc')
        # Righe malformate (troncate, spostate di colonna): scartate invece di
        # inserire date o hex non validi che poi rompono ordinamenti e confronti.
        # Una riga con meno colonne dell'intestazione è quasi sempre l'ultima, che il
        # logger sta ancora scrivendo: la si salta e verrà importata intera al giro
        # successivo (se entrasse ora, INSERT OR IGNORE terrebbe la versione monca).
        truncated = any(r.get(k) is None for k in fieldnames)
        if truncated or not HEX_RE.match(hex_val) or not TS_RE.match(ts):
            skipped += 1
            continue

        date_str = ts[:10]
        callsign = field(r, 'callsign') or None
        reg = field(r, 'reg') or None
        model = field(r, 'model_t') or None
        lat = num(field(r, 'lat'), float)
        lon = num(field(r, 'lon'), float)
        alt = num(field(r, 'alt_ft'), lambda x: int(float(x)))
        gs = num(field(r, 'gs_kt'), float)
        squawk = field(r, 'squawk') or None
        ground = field(r, 'ground') or None
        category = field(r, 'category') or None

        # 1. Evento storico
        try:
            cur.execute("INSERT OR IGNORE INTO events VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                        (ts, hex_val, callsign, reg, model, lat, lon, alt, gs, squawk, ground, category))
            if cur.rowcount > 0:
                events_inserted += 1
        except Exception as e:
            print(f"Errore events: {e}")

        # 2. Giorno distinto
        is_new_day = False
        try:
            cur.execute("INSERT OR IGNORE INTO daily_hex VALUES (?,?)", (hex_val, date_str))
            if cur.rowcount > 0:
                is_new_day = True
        except Exception as e:
            print(f"Errore daily_hex: {e}")

        # 3. Aircraft (riepilogo per hex)
        try:
            cur.execute("SELECT hex FROM aircraft WHERE hex = ?", (hex_val,))
            if cur.fetchone():
                if is_new_day:
                    cur.execute("""UPDATE aircraft SET last_seen_utc=?, seen_count=seen_count+1,
                                    callsign=?, reg=?, model_t=?, lat=?, lon=?, alt_ft=?, gs_kt=?, squawk=?, ground=?,
                                    category=COALESCE(?, category)
                                    WHERE hex=?""",
                                (ts, callsign, reg, model, lat, lon, alt, gs, squawk, ground, category, hex_val))
                else:
                    cur.execute("""UPDATE aircraft SET last_seen_utc=?,
                                    callsign=?, reg=?, model_t=?, lat=?, lon=?, alt_ft=?, gs_kt=?, squawk=?, ground=?,
                                    category=COALESCE(?, category)
                                    WHERE hex=?""",
                                (ts, callsign, reg, model, lat, lon, alt, gs, squawk, ground, category, hex_val))
            else:
                cur.execute("""INSERT INTO aircraft (hex, first_seen_utc, last_seen_utc, seen_count, max_consecutive_days,
                                    callsign, reg, model_t, lat, lon, alt_ft, gs_kt, squawk, ground, category)
                                VALUES (?,?,?,1,0,?,?,?,?,?,?,?,?,?,?)""",
                            (hex_val, ts, ts, callsign, reg, model, lat, lon, alt, gs, squawk, ground, category))
            aircraft_updated += 1

            if is_new_day:
                dates = get_dates_for_hex(cur, hex_val)
                streak = calc_max_streak(dates)
                cur.execute("UPDATE aircraft SET max_consecutive_days=? WHERE hex=?", (streak, hex_val))
        except Exception as e:
            print(f"Errore aircraft: {e}")

        # 4. Identità (hex + callsign + reg) – deduplica manuale
        try:
            ident_callsign = callsign if callsign else None
            ident_reg = reg if reg else None
            ident_model = model if model else None

            # Cerca se esiste già
            cur.execute("""
                SELECT hex FROM aircraft_identity
                WHERE hex = ? AND callsign IS ? AND reg IS ?
            """, (hex_val, ident_callsign, ident_reg))

            if cur.fetchone():
                # Aggiorna solo ultimo avvistamento e modello
                cur.execute("""
                    UPDATE aircraft_identity
                    SET last_seen_utc = ?, model_t = ?
                    WHERE hex = ? AND callsign IS ? AND reg IS ?
                """, (ts, ident_model, hex_val, ident_callsign, ident_reg))
            else:
                cur.execute("""
                    INSERT INTO aircraft_identity (hex, callsign, reg, model_t, first_seen_utc, last_seen_utc)
                    VALUES (?, ?, ?, ?, ?, ?)
                """, (hex_val, ident_callsign, ident_reg, ident_model, ts, ts))

            identity_updated += 1
        except Exception as e:
            print(f"Errore identity: {e}")

    conn.commit()
    conn.close()
    print(f"Import: {events_inserted} eventi, {aircraft_updated} aerei, {identity_updated} identità"
          + (f", {skipped} righe malformate scartate." if skipped else "."))

if __name__ == "__main__":
    main()