#!/bin/sh
# Startet die App lokal mit der Testumgebung (Passwort: test1234).
# Vom iPhone im selben WLAN erreichbar unter der angezeigten Adresse.
cd "$(dirname "$0")/.." || exit 1
[ -f testumgebung/config.php ] || php werkzeuge/testumgebung_erstellen.php
IP=$(ipconfig getifaddr en0 2>/dev/null)
echo "Am Mac:     http://localhost:8771"
[ -n "$IP" ] && echo "Am iPhone:  http://$IP:8771   (gleiches WLAN)"
echo "Passwort:   test1234      Beenden mit Ctrl+C"
LK_CONFIG="$PWD/testumgebung/config.php" LK_DATA_DIR="$PWD/testumgebung/data" exec php -S 0.0.0.0:8771 -t webspace
