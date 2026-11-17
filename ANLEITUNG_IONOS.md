# Lernkarten auf IONOS

Lernkarten aus Bildern (z. B. iPhone-Screenshots) mit Spaced Repetition nach FSRS, so wie in Anki.

## Automatischer Deploy (wie bei Vokabelkurve)

Jeder Push auf `main` prüft die App mit GitHub Actions und lädt `webspace/` per SFTP nach IONOS (`.github/workflows/deploy.yml`).
**Nie angefasst** wird `data/` auf dem Server, also Datenbank und Bilder.

### Einmalig einrichten

1. In IONOS unter *Hosting → PHP-Version* **PHP 8.1 oder neuer** einstellen. Für die Domain oder Subdomain **HTTPS** aktivieren.
2. In GitHub unter *Settings → Secrets and variables → Actions* die Secrets anlegen. Es sind dieselben Zugangsdaten wie bei Vokabelkurve, nur ein anderer Zielordner:
   ```bash
   cd ~/Documents/Lernkarten
   GH_HOST=github.com gh secret set IONOS_SFTP_HOST
   GH_HOST=github.com gh secret set IONOS_SFTP_USER
   GH_HOST=github.com gh secret set IONOS_SFTP_PASSWORD
   GH_HOST=github.com gh secret set IONOS_TARGET_DIR      # z. B. /tools/lernkarten
   ```
   Jeder Befehl fragt den Wert ab, er landet nicht in der Shell-History.
3. App-Passwort setzen und die Frage nach dem GitHub-Secret mit **j** beantworten:
   ```bash
   python3 werkzeuge/passwort_setzen.py
   ```
   Der Deploy schreibt daraus `lib/config.php` auf den Server. Das Passwort selbst wird nirgends gespeichert, nur ein PBKDF2-Hash.
4. Pushen oder in GitHub unter *Actions → Prüfen und deployen → Run workflow* starten.
5. Die Seite auf dem iPhone in Safari öffnen, anmelden, *Teilen → Zum Home-Bildschirm*.

**Prüfen:** `https://…/data/.htaccess` und `https://…/lib/config.php` dürfen **nichts** anzeigen (Fehler 403).

### Passwort ändern

`python3 werkzeuge/passwort_setzen.py`, mit **j** bestätigen und den Workflow neu starten.

### Ohne GitHub (Notfall)

Den Inhalt von `webspace/` per SFTP in den Zielordner laden, **außer `data/`**. Die versteckten `.htaccess`-Dateien müssen mit. `lib/config.php` von Hand hochladen.

## Sicherheit

- Nur wer das Passwort kennt, kommt hinein. Nach dem Login bleibt das Gerät ein Jahr angemeldet (über ein Zufallstoken, auf dem Server liegt nur dessen Hash).
- Nach 5 Fehlversuchen von einer IP-Adresse (oder 30 insgesamt) ist der Login 15 Minuten gesperrt.
- Datenbank und Bilder liegen in `data/` und sind direkt nicht abrufbar. Bilder gibt es nur über `bild.php` nach dem Login.
- Alle Änderungen brauchen zusätzlich ein CSRF-Token. Fremde Webseiten können also nicht in deinem Namen bewerten oder löschen.
- *Einstellungen → Alle Geräte abmelden* wirft sofort alle angemeldeten Geräte raus.
- Passwort ändern: siehe oben, das neue Passwort gilt nach dem nächsten Deploy.

## Upload per iOS-Kurzbefehl

Die Anleitung steht in der App unter *Einstellungen → iPhone-Kurzbefehl*, dort auch die URL und der Token. Der Token erlaubt **nur** das Hochladen, kein Lernen, Ansehen oder Löschen.

## Backup

Den Ordner `data/` per SFTP herunterladen. `lernkarten.sqlite` enthält den kompletten Lernstand, `images/` die Bilder.

## Lokal testen

```bash
./werkzeuge/testserver.sh          # Passwort test1234, Beispielkarten aus testumgebung/
```

## Wie gelernt wird (FSRS)

| Knopf | Bedeutung |
|---|---|
| Nochmal | Nicht gewusst: Die Karte kommt nach 1 bzw. 10 Minuten wieder. |
| Schwer | Mit Mühe gewusst: kürzerer Abstand. |
| Gut | Gewusst: normaler Abstand. |
| Einfach | Sofort gewusst: deutlich längerer Abstand. |

Unter jedem Knopf steht, wann die Karte wiederkommt. Neue Karten durchlaufen zuerst kurze Lernschritte (1 min, 10 min). Danach rechnet FSRS für jede Karte aus, wann du sie mit 90 % Wahrscheinlichkeit noch weißt, und zeigt sie genau dann wieder. Das Ziel (90 %) und die Anzahl neuer Karten pro Tag lassen sich in den Einstellungen ändern. Ein Lerntag beginnt um 4 Uhr morgens.
