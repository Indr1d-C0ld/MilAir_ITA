#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monitor ADS-B — SOLO voli militari (endpoint /v2/mil)
"""

import argparse
import csv
import datetime as dt
import json
import os
import sys
import time
import fcntl
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

def append_seen_csv(csv_path: str, rows: List[dict]) -> None:
    fieldnames = [
        "first_seen_utc", "hex", "callsign", "reg",
        "model_t", "lat", "lon", "alt_ft", "gs_kt",
        "squawk", "ground", "category"
    ]
    must_write_header = not os.path.isfile(csv_path)
    if not must_write_header:
        # Migrazione automatica: se lo schema del CSV esistente non combacia (es. dopo
        # l'aggiunta della colonna 'category'), il file viene ruotato invece di
        # rischiare di disallineare le colonne delle righe già scritte con quelle nuove
        # (il file non viene mai troncato da csv_to_db.py, quindi resterebbe corrotto
        # fino alla prossima rotazione manuale dei log).
        try:
            with open(csv_path, "r", encoding="utf-8") as f:
                existing_header = f.readline().strip().split(",")
            if existing_header != fieldnames:
                backup_path = csv_path + ".pre-" + dt.datetime.now().strftime("%Y%m%d%H%M%S") + ".bak"
                os.rename(csv_path, backup_path)
                print(f"[INFO] Schema CSV cambiato: vecchio file archiviato in {backup_path}", file=sys.stderr)
                must_write_header = True
        except Exception as e:
            print(f"[WARN] Verifica header CSV fallita: {e}", file=sys.stderr)
    try:
        with open(csv_path, "a", newline="", encoding="utf-8") as f:
            wr = csv.DictWriter(f, fieldnames=fieldnames)
            if must_write_header:
                wr.writeheader()
            wr.writerows(rows)
    except Exception as e:
        print(f"[WARN] Scrittura CSV fallita: {e}", file=sys.stderr)

def make_links(ac: Aircraft) -> List[str]:
    links = []
    if ac.hex:
        links.append(f"[ADSB.fi](https://globe.adsb.fi/?icao={ac.hex})")
        links.append(f"[ADSB Exchange](https://globe.adsbexchange.com/?icao={ac.hex})")
        links.append(f"[Planespotters](https://www.planespotters.net/hex/{ac.hex})")
    if ac.flight:
        links.append(f"[FlightAware](https://www.flightaware.com/it-IT/flight/{ac.flight})")
    if ac.reg:
        links.append(f"[AirHistory](https://www.airhistory.net/marks-all/{ac.reg})")
        links.append(f"[JetPhotos](https://www.jetphotos.com/registration/{ac.reg})")
    return links

def main():
    ap = argparse.ArgumentParser(description="Monitor ADS-B SOLO voli militari")
    ap.add_argument("--interval", type=int, default=60)
    ap.add_argument("--csv", type=str, default="/var/www/html/milair_ita/mil.csv")
    ap.add_argument("--mil-cooldown", type=int, default=1800)
    ap.add_argument("--polygons-file", type=str, default="/var/www/html/milair_ita/polygons.json")
    args = ap.parse_args()

    polygons = load_polygons_from_geojson(args.polygons_file) if args.polygons_file else []
    last_mil_alert: Dict[str, float] = {}

    print(f"Monitor aerei (solo MIL, clean CSV) — start {now_utc_str()}")
    while True:
        t0 = time.time()
        merged = fetch_military()

        aircraft: List[Aircraft] = []
        for ac in merged:
            try:
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
                        safe_bool(ac.get("ground")),
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
        event_rows: List[dict] = []

        for ac in aircraft:
            now_ts = time.time()
            if now_ts - last_mil_alert.get(ac.hex, 0) < args.mil_cooldown:
                continue

            row = {
                "first_seen_utc": now_str, "hex": ac.hex,
                "callsign": ac.flight, "reg": ac.reg or "",
                "model_t": ac.model_t or "",
                "lat": ac.lat or "", "lon": ac.lon or "",
                "alt_ft": ac.alt_baro or "", "gs_kt": ac.gs or "",
                "squawk": ac.squawk or "", "ground": ac.ground,
                "category": ac.category or "",
            }
            event_rows.append(row)

            # (stampa a console)
            print(f"MIL HEX:{ac.hex} FLT:{ac.flight or '-'} REG:{ac.reg or '-'}")
            last_mil_alert[ac.hex] = now_ts

        if event_rows:
            append_seen_csv(args.csv, event_rows)

        time.sleep(max(1, int(round(args.interval - (time.time() - t0)))))

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("[INFO] Interrotto dall'utente.")