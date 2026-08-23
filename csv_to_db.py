#!/usr/bin/env python3
import sqlite3, csv, os, datetime

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

def main():
    if not os.path.isfile(CSV_FILE):
        print("CSV non trovato.")
        return

    conn = sqlite3.connect(DB_FILE)
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

    cur = conn.cursor()
    events_inserted = 0
    aircraft_updated = 0
    identity_updated = 0

    for r in rows:
        hex_val = r.get('hex', '').strip()
        if not hex_val:
            continue

        ts = r.get('first_seen_utc', '').strip()
        date_str = ts[:10]
        callsign = r.get('callsign', '').strip() or None
        reg = r.get('reg', '').strip() or None
        model = r.get('model_t', '').strip() or None
        lat = r.get('lat', '').strip()
        lon = r.get('lon', '').strip()
        alt = r.get('alt_ft', '').strip()
        gs = r.get('gs_kt', '').strip()
        squawk = r.get('squawk', '').strip() or None
        ground = r.get('ground', '').strip() or None
        category = r.get('category', '').strip() or None

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
    print(f"Import: {events_inserted} eventi, {aircraft_updated} aerei, {identity_updated} identità.")

if __name__ == "__main__":
    main()