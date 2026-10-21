#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monitor ADS-B — SOLO voli militari (endpoint /v2/mil).

Interroga adsb.fi ogni --interval secondi, tiene i contatti dentro il
perimetro di polygons.json e li registra DIRETTAMENTE in events.db: evento,
giorno distinto, riepilogo per hex (con la serie di giorni consecutivi) e
identita'. Poi, se ha registrato qualcosa, lancia update_rarity.php e
alert_scan.php: la rarita' e gli avvisi cambiano solo quando arrivano
contatti nuovi, quindi non servono piu' i cron orario e ogni 5 minuti.

Prima i contatti passavano da un CSV (mil.csv) che csv_to_db.py rileggeva per
intero ogni 5 minuti, riscrivendo ogni volta migliaia di righe gia' importate
(16.556 aerei aggiornati per importare un solo evento nuovo). Ora ogni
contatto si scrive una volta, quando arriva, in una sola transazione.
"""

import argparse
import datetime as dt
import json
import os
import sys
import time
import fcntl
import sqlite3
import subprocess
from dataclasses import dataclass
from typing import Dict, List, Optional, Tuple, Iterable

import requests

API_MIL = "https://opendata.adsb.fi/api/v2/mil"

HTTP_TIMEOUT = 15
HTTP_RETRIES = 2
HTTP_BACKOFF = 2.0

@dataclass
class Aircraft:
    hex: str
    flight: str
    lat: Optional[float]
    lon: Optional[float]
    alt_baro: Optional[int]
    gs: Optional[float]
    ts: Optional[float]
    reg: Optional[str] = None
    squawk: Optional[str] = None
    ground: Optional[bool] = None
    model_desc: Optional[str] = None
    model_t: Optional[str] = None
    category: Optional[str] = None
    is_mil: bool = False

def safe_int(val):
    try:
        return int(val)
    except (TypeError, ValueError):
        return None

def safe_float(val):
    try:
        return float(val)
    except (TypeError, ValueError):
        return None

def safe_bool(val) -> Optional[bool]:
    if isinstance(val, bool):
        return val
    s = str(val).strip().lower()
    if s in ("true", "1", "yes"):
        return True
    if s in ("false", "0", "no"):
        return False
    return None

def now_utc_str() -> str:
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")

def load_polygons_from_geojson(path: str):
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
    polys = []
    if isinstance(data, dict) and data.get("type") == "FeatureCollection":
        for feat in data.get("features", []):
            geom = feat.get("geometry", {})
            gtype = geom.get("type")
            coords = geom.get("coordinates", [])
            if gtype == "Polygon":
                polys.append([[(float(pt[1]), float(pt[0])) for pt in ring] for ring in coords])
            elif gtype == "MultiPolygon":
                for polycoords in coords:
                    polys.append([[(float(pt[1]), float(pt[0])) for pt in ring] for ring in polycoords])
    elif isinstance(data, dict) and "polygons" in data:
        for poly in data["polygons"]:
            polys.append([[(float(pt[0]), float(pt[1])) for pt in ring] for ring in poly])
    return polys

def point_in_ring(point, ring):
    x, y = point[1], point[0]
    inside = False
    n = len(ring)
    for i in range(n):
        yi, xi = ring[i][0], ring[i][1]
        yj, xj = ring[(i + 1) % n][0], ring[(i + 1) % n][1]
        if ((yi > y) != (yj > y)) and (x < (xj - xi) * (y - yi) / (yj - yi + 1e-12) + xi):
            inside = not inside
    return inside

def point_in_polygon(point, polygon):
    if not polygon:
        return False
    if not point_in_ring(point, polygon[0]):
        return False
    for hole in polygon[1:]:
        if point_in_ring(point, hole):
            return False
    return True

def in_any_polygon(lat, lon, polygons):
    if lat is None or lon is None:
        return False
    pt = (lat, lon)
    return any(point_in_polygon(pt, poly) for poly in polygons)

def fetch_military() -> List[dict]:
    api_rate_guard()
    last_exc = None
    for attempt in range(HTTP_RETRIES + 1):
        try:
            r = requests.get(API_MIL, timeout=HTTP_TIMEOUT)
            r.raise_for_status()
            raw = r.json() or {}
            if isinstance(raw, dict) and "ac" in raw:
                data = raw["ac"]
            elif isinstance(raw, dict) and "aircraft" in raw:
                data = raw["aircraft"]
            elif isinstance(raw, list):
                data = raw
            else:
                return []
            for ac in data:
                if isinstance(ac, dict):
                    ac["force_mil"] = True
            return data
        except Exception as e:
            last_exc = e
            if attempt < HTTP_RETRIES:
                time.sleep(HTTP_BACKOFF * (attempt + 1))
    print(f"[WARN] Fetch militare fallito {API_MIL} — {last_exc}", file=sys.stderr)
    return []

def api_rate_guard():
    lockfile = "/tmp/adsbfi_api.lock"
    with open(lockfile, "a+") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        f.seek(0)
        try:
            last = float(f.read().strip())
        except Exception:
            last = 0.0
        now = time.time()
        delta = now - last
        if delta < 1.05:
            time.sleep(1.05 - delta)
        f.seek(0)
        f.truncate()
        f.write(str(time.time()))
        f.flush()
        fcntl.flock(f, fcntl.LOCK_UN)

def percorso_dati(nome: str) -> str:
    """Dove sta un database: MILAIR_DATI, /var/lib/milair, o la cartella dello
    script (le stesse regole di percorsi.php)."""
    base = os.environ.get("MILAIR_DATI") or ("/var/lib/milair" if os.path.isdir("/var/lib/milair")
                                              else os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, nome)


SCHEMA = """
    CREATE TABLE IF NOT EXISTS events (
        first_seen_utc TEXT, hex TEXT, callsign TEXT, reg TEXT, model_t TEXT,
        lat REAL, lon REAL, alt_ft INTEGER, gs_kt REAL, squawk TEXT, ground TEXT,
        category TEXT,
        PRIMARY KEY (first_seen_utc, hex)
    );
    CREATE TABLE IF NOT EXISTS aircraft (
        hex TEXT PRIMARY KEY,
        first_seen_utc TEXT NOT NULL,
        last_seen_utc TEXT NOT NULL,
        seen_count INTEGER DEFAULT 0,
        max_consecutive_days INTEGER DEFAULT 0,
        callsign TEXT, reg TEXT, model_t TEXT,
        lat REAL, lon REAL, alt_ft INTEGER, gs_kt REAL, squawk TEXT, ground TEXT,
        category TEXT
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
    -- per intervallo di date, ma NON quelle per solo hex (track.php,
    -- heatmap.php?hex=..., il Diario).
    CREATE INDEX IF NOT EXISTS idx_events_hex ON events(hex);
"""


def apri_db(path: str) -> sqlite3.Connection:
    # timeout=30: update_rarity.php e le pagine web possono tenere il lock per
    # qualche istante. Journal classico (non WAL): il database lo scrivono sia
    # questo processo sia Apache, e i file -wal/-shm resterebbero con il
    # proprietario sbagliato.
    conn = sqlite3.connect(path, timeout=30)
    conn.execute("PRAGMA journal_mode=DELETE")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.executescript(SCHEMA)
    for table in ("events", "aircraft"):
        cols = [r[1] for r in conn.execute(f"PRAGMA table_info({table})")]
        if "category" not in cols:
            conn.execute(f"ALTER TABLE {table} ADD COLUMN category TEXT")
    conn.commit()
    return conn


def serie_massima(date: List[str]) -> int:
    """La serie piu' lunga di giorni consecutivi, da date ISO in ordine."""
    if not date:
        return 0
    massima = attuale = 1
    for prima, dopo in zip(date, date[1:]):
        if (dt.date.fromisoformat(dopo) - dt.date.fromisoformat(prima)).days == 1:
            attuale += 1
            massima = max(massima, attuale)
        else:
            attuale = 1
    return massima


def registra(conn: sqlite3.Connection, righe: List[dict]) -> int:
    """Scrive i contatti nuovi, in una transazione. Le stesse regole del vecchio
    csv_to_db.py, applicate una volta sola per riga. Ritorna gli eventi nuovi."""
    nuovi = 0
    cur = conn.cursor()
    for r in righe:
        ts, hx = r["first_seen_utc"], r["hex"]
        valori = (r["callsign"] or None, r["reg"] or None, r["model_t"] or None, r["lat"], r["lon"],
                  r["alt_ft"], r["gs_kt"], r["squawk"] or None, r["ground"], r["category"] or None)
        callsign, reg, model, lat, lon, alt, gs, squawk, ground, category = valori

        # 1. evento storico
        cur.execute("INSERT OR IGNORE INTO events VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", (ts, hx) + valori)
        if cur.rowcount == 0:
            continue                      # gia' registrato: niente da fare
        nuovi += 1

        # 2. giorno distinto
        cur.execute("INSERT OR IGNORE INTO daily_hex VALUES (?,?)", (hx, ts[:10]))
        giorno_nuovo = cur.rowcount > 0

        # 3. riepilogo per hex
        if cur.execute("SELECT 1 FROM aircraft WHERE hex = ?", (hx,)).fetchone():
            cur.execute("""UPDATE aircraft SET last_seen_utc=?, seen_count=seen_count+?,
                               callsign=?, reg=?, model_t=?, lat=?, lon=?, alt_ft=?, gs_kt=?, squawk=?, ground=?,
                               category=COALESCE(?, category)
                           WHERE hex=?""", (ts, 1 if giorno_nuovo else 0) + valori + (hx,))
        else:
            cur.execute("""INSERT INTO aircraft (hex, first_seen_utc, last_seen_utc, seen_count, max_consecutive_days,
                               callsign, reg, model_t, lat, lon, alt_ft, gs_kt, squawk, ground, category)
                           VALUES (?,?,?,1,0,?,?,?,?,?,?,?,?,?,?)""", (hx, ts, ts) + valori)
        if giorno_nuovo:
            date = [d for (d,) in cur.execute("SELECT date FROM daily_hex WHERE hex = ? ORDER BY date", (hx,))]
            cur.execute("UPDATE aircraft SET max_consecutive_days=? WHERE hex=?", (serie_massima(date), hx))

        # 4. identita' (hex + callsign + reg)
        if cur.execute("SELECT 1 FROM aircraft_identity WHERE hex = ? AND callsign IS ? AND reg IS ?",
                       (hx, callsign, reg)).fetchone():
            cur.execute("""UPDATE aircraft_identity SET last_seen_utc = ?, model_t = ?
                           WHERE hex = ? AND callsign IS ? AND reg IS ?""", (ts, model, hx, callsign, reg))
        else:
            cur.execute("""INSERT INTO aircraft_identity (hex, callsign, reg, model_t, first_seen_utc, last_seen_utc)
                           VALUES (?, ?, ?, ?, ?, ?)""", (hx, callsign, reg, model, ts, ts))
    conn.commit()
    return nuovi


def ultimi_visti(conn: sqlite3.Connection, secondi: int) -> Dict[str, float]:
    """Gli hex registrati negli ultimi `secondi`, con l'istante: dopo un riavvio
    del servizio il periodo di silenzio per hex riparte da qui, invece che da
    zero (che registrava di nuovo tutti i contatti appena visti)."""
    limite = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(seconds=secondi)).strftime("%Y-%m-%d %H:%M:%S UTC")
    visti = {}
    for hx, ts in conn.execute("SELECT hex, MAX(first_seen_utc) FROM events WHERE first_seen_utc >= ? GROUP BY hex", (limite,)):
        visti[hx] = dt.datetime.strptime(ts, "%Y-%m-%d %H:%M:%S UTC").replace(tzinfo=dt.timezone.utc).timestamp()
    return visti


def lancia_dopo(script: List[str]) -> None:
    """Gli script da eseguire dopo una registrazione, in ordine: la rarita'
    (update_rarity.php) e poi gli avvisi (alert_scan.php, incrementale con un
    checkpoint sul rowid), che usano la rarita' appena calcolata. Quello che
    stampano finisce nel giornale del servizio: tacciono se non c'e' nulla da dire."""
    for s in script:
        if not os.path.isfile(s):
            continue
        try:
            r = subprocess.run(["nice", "-n", "10", "php", s], capture_output=True, text=True, timeout=180)
            uscita = (r.stdout + r.stderr).strip()
            if r.returncode != 0:
                print(f"[WARN] {os.path.basename(s)}: uscita {r.returncode}: {uscita[:300]}", file=sys.stderr, flush=True)
            elif uscita:
                print(uscita[:500], flush=True)
        except Exception as e:
            print(f"[WARN] {os.path.basename(s)} non eseguito: {e}", file=sys.stderr, flush=True)


def main():
    qui = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description="Monitor ADS-B SOLO voli militari")
    ap.add_argument("--interval", type=int, default=60)
    ap.add_argument("--db", type=str, default=percorso_dati("events.db"))
    ap.add_argument("--mil-cooldown", type=int, default=1800,
                    help="secondi di silenzio per hex dopo una registrazione")
    ap.add_argument("--polygons-file", type=str, default=os.path.join(qui, "polygons.json"))
    ap.add_argument("--dopo", type=str, default="update_rarity.php,alert_scan.php",
                    help="script PHP (nella cartella del logger) da lanciare dopo ogni registrazione, separati da virgole")
    args = ap.parse_args()
    dopo = [os.path.join(qui, n.strip()) for n in args.dopo.split(",") if n.strip()]

    polygons = load_polygons_from_geojson(args.polygons_file) if args.polygons_file else []
    conn = apri_db(args.db)
    last_mil_alert: Dict[str, float] = ultimi_visti(conn, args.mil_cooldown)
    print(f"Monitor aerei militari — avvio {now_utc_str()}, database {args.db}, "
          f"{len(last_mil_alert)} hex in silenzio dagli ultimi {args.mil_cooldown // 60} minuti", flush=True)

    while True:
        t0 = time.time()
        merged = fetch_military()
        aircraft: List[Aircraft] = []
        for ac in merged:
            try:
                # readsb/tar1090 non hanno un campo "ground": un aereo a terra ha
                # alt_baro == "ground".
                alt_raw = ac.get("alt_baro")
                if alt_raw == "ground":
                    on_ground = True
                elif safe_int(alt_raw) is not None:
                    on_ground = False
                else:
                    on_ground = safe_bool(ac.get("ground"))
                aircraft.append(
                    Aircraft(
                        (ac.get("hex") or "").lower(),
                        (ac.get("flight") or "").strip(),
                        safe_float(ac.get("lat")),
                        safe_float(ac.get("lon")),
                        safe_int(ac.get("alt_baro")),
                        safe_float(ac.get("gs")),
                        safe_float(ac.get("seen_pos_timestamp") or ac.get("seen_timestamp")),
                        (ac.get("r") or ac.get("reg") or "").strip() or None,
                        str(ac.get("squawk")).strip() if ac.get("squawk") else None,
                        on_ground,
                        (ac.get("desc") or None),
                        (ac.get("t") or None),
                        (ac.get("category") or None),
                        True
                    )
                )
            except Exception:
                continue

        if polygons:
            aircraft = [ac for ac in aircraft if ac.lat and ac.lon and in_any_polygon(ac.lat, ac.lon, polygons)]

        now_str = now_utc_str()
        righe: List[dict] = []
        for ac in aircraft:
            now_ts = time.time()
            if not ac.hex or now_ts - last_mil_alert.get(ac.hex, 0) < args.mil_cooldown:
                continue
            # None solo per i valori assenti: quota 0 ft e velocita' 0 kt sono validi
            righe.append({
                "first_seen_utc": now_str, "hex": ac.hex,
                "callsign": ac.flight, "reg": ac.reg or "",
                "model_t": ac.model_t or "",
                "lat": ac.lat, "lon": ac.lon,
                "alt_ft": ac.alt_baro, "gs_kt": ac.gs,
                "squawk": ac.squawk or "",
                "ground": None if ac.ground is None else str(ac.ground),
                "category": ac.category or "",
            })
            last_mil_alert[ac.hex] = now_ts

        if righe:
            try:
                nuovi = registra(conn, righe)
                if nuovi:
                    print(f"{nuovi} contatti registrati: " + ", ".join(f"{r['hex']} {r['callsign'] or '-'}" for r in righe[:8])
                          + (" …" if len(righe) > 8 else ""), flush=True)
                    lancia_dopo(dopo)
            except sqlite3.Error as e:
                conn.rollback()
                # non persi: tolti dal silenzio, si ritentano al giro successivo
                for r in righe:
                    last_mil_alert.pop(r["hex"], None)
                print(f"[WARN] Scrittura su database fallita, riprovo al prossimo giro: {e}", file=sys.stderr, flush=True)

        # pulizia: hex in silenzio scaduti, perche' il dizionario non cresca senza fine
        soglia = time.time() - args.mil_cooldown
        for hx in [h for h, t in last_mil_alert.items() if t < soglia]:
            del last_mil_alert[hx]

        time.sleep(max(1, int(round(args.interval - (time.time() - t0)))))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
