#!/bin/bash
# MilAir ITA — proprietari e permessi. Modifica le variabili prima di eseguire.
#
#   DIR   il codice: non scrivibile da Apache, tranne le cartelle degli asset
#         (il pulsante 🔄 della tabella li scarica come utente di Apache)
#   DATI  i database (events.db, auth.db): li scrivono Apache e il logger
#   RAM   sessioni e cache: in tmpfs (vedi milair-tmpfiles.conf), si ricreano
#         a ogni avvio
set -e
DIR="/var/www/html/milair_ita"
DATI="/var/lib/milair"
USER="CHANGEME"
GROUP="www-data"

echo "Codice: $USER:$GROUP, cartelle 755, file 644 ..."
chown -R "$USER":"$GROUP" "$DIR"
find "$DIR" -type d -exec chmod 755 {} +
find "$DIR" -type f -exec chmod 644 {} +
chmod 640 "$DIR"/*_secrets.php 2>/dev/null || true
chmod +x "$DIR/flight_mil_ita.py"

echo "Asset scaricati (scrivibili anche da Apache) ..."
for d in photos fdbphotos silhouettes drawings opflags; do
    mkdir -p "$DIR/$d"
    chmod 2775 "$DIR/$d"
done

echo "Database in $DATI ..."
# 2770: il gruppo eredita le cartelle e i file del journal di SQLite (creato
# accanto al database durante ogni transazione) restano scrivibili da entrambi.
mkdir -p "$DATI"
chown "$USER":"$GROUP" "$DATI"
chmod 2770 "$DATI"
for f in "$DATI"/*.db; do
    [ -f "$f" ] && chown "$USER":"$GROUP" "$f" && chmod 660 "$f"
done

echo "Permessi impostati."
