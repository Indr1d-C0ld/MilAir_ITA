#!/bin/bash
set -e
# Modifica DIR e USER secondo la tua installazione prima di eseguire.
DIR="/var/www/html/milair_ita"
USER="CHANGEME"
GROUP="www-data"

echo "Impostazione proprietario $USER:$GROUP su $DIR ..."
chown -R "$USER":"$GROUP" "$DIR"

echo "Permessi di base (directory 755, file 644) ..."
find "$DIR" -type d -exec chmod 755 {} +
find "$DIR" -type f -exec chmod 644 {} +

echo "Permessi specifici per database e CSV ..."
# events.db va aperto in scrittura anche da Apache (utente $GROUP, es. rules.php,
# geofilter.php, toggle_favorite.php, edit_note.php): serve il bit di scrittura
# di gruppo sia sul file che sulla directory che lo contiene (per i file
# temporanei di journal/WAL di SQLite).
chmod 664 "$DIR/events.db" 2>/dev/null || true
chmod g+w "$DIR" 2>/dev/null || true
chmod 640 "$DIR/mil.csv"   2>/dev/null || true

echo "Permessi di scrittura per Apache su cache/upload (satellite, meteo, foto caricate manualmente) ..."
chmod g+w "$DIR/photos" "$DIR/fdbphotos" 2>/dev/null || true
[ -d "$DIR/cache" ] && chmod -R g+w "$DIR/cache" 2>/dev/null || true

echo "Permessi per autenticazione (auth.db, cartella sessioni riservata) ..."
# auth.db va creato/scritto da Apache (utente $GROUP) al primo accesso.
chmod g+w "$DIR" 2>/dev/null || true
[ -f "$DIR/auth.db" ] && chmod 664 "$DIR/auth.db" 2>/dev/null || true
# sessions/ contiene i file di sessione PHP: NON deve essere leggibile da
# "altri" (a differenza del resto, qui il blanket 755/644 sopra va corretto
# esplicitamente per restare riservato a $USER+www-data soltanto).
if [ -d "$DIR/sessions" ]; then
    chmod 770 "$DIR/sessions"
    find "$DIR/sessions" -type f -exec chmod 660 {} + 2>/dev/null || true
fi

echo "Rendi eseguibili script Python e PHP CLI ..."
chmod +x "$DIR/flight_mil_ita.py" 2>/dev/null || true
chmod +x "$DIR/csv_to_db.py"      2>/dev/null || true
chmod +x "$DIR/update_rarity.php" 2>/dev/null || true

echo "Permessi impostati con successo."