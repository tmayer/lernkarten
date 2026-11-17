#!/usr/bin/env python3
"""Setzt das Passwort für Lernkarten.

Aufruf:  python3 werkzeuge/passwort_setzen.py
Schreibt webspace/lib/config.php mit einem PBKDF2-Hash (das Passwort selbst wird nirgends gespeichert).
Danach lib/config.php auf den Server laden.
"""
import getpass
import hashlib
import os
import secrets
import shutil
import subprocess
import sys

ITERATIONS = 600_000
TARGET = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'webspace', 'lib', 'config.php')

pw = getpass.getpass('Neues Passwort (mind. 10 Zeichen): ')
if len(pw) < 10:
    sys.exit('Zu kurz – bitte mindestens 10 Zeichen.')
if getpass.getpass('Wiederholen: ') != pw:
    sys.exit('Die Eingaben stimmen nicht überein.')

salt = secrets.token_hex(16)
digest = hashlib.pbkdf2_hmac('sha256', pw.encode('utf-8'), salt.encode('ascii'), ITERATIONS).hex()

with open(TARGET, 'w', encoding='utf-8') as f:
    f.write(f"""<?php
// Erzeugt von werkzeuge/passwort_setzen.py – nicht von Hand ändern.
return [
    'password_hash' => 'pbkdf2_sha256${ITERATIONS}${salt}${digest}',
    'timezone'      => 'Europe/Berlin',
];
""")
os.chmod(TARGET, 0o640)
print(f'Gespeichert in {os.path.normpath(TARGET)} (für die Testumgebung und manuelles Hochladen)')

# Für den automatischen Deploy: Hash als GitHub-Secret hinterlegen
pw_hash = f'pbkdf2_sha256${ITERATIONS}${salt}${digest}'
print(f'Kennung dieses Passworts: {hashlib.sha256(pw_hash.encode()).hexdigest()[:12]}  (steht nach dem Deploy auch im GitHub-Log)')
if shutil.which('gh') and input('Als GitHub-Secret LK_PASSWORD_HASH für den Deploy setzen? [j/N] ').strip().lower() in ('j', 'ja', 'y'):
    repo_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
    r = subprocess.run(['gh', 'secret', 'set', 'LK_PASSWORD_HASH'], input=pw_hash, text=True, cwd=repo_dir,
                       env={**os.environ, 'GH_HOST': 'github.com'})
    if r.returncode == 0:
        print('Secret gesetzt. Beim nächsten Deploy gilt das neue Passwort (Deploy in GitHub unter Actions neu starten oder etwas pushen).')
    else:
        print('Secret konnte nicht gesetzt werden – stattdessen lib/config.php von Hand hochladen.')
else:
    print('Ohne Secret: lib/config.php einmal von Hand auf den Server laden. Bereits angemeldete Geräte bleiben angemeldet.')
