#!/usr/bin/env python3
"""Prüft, ob ein Passwort zu webspace/lib/config.php passt, und zeigt die Kennung des gespeicherten Hashes.

Aufruf: python3 werkzeuge/passwort_pruefen.py
"""
import getpass
import hashlib
import hmac
import os
import re
import sys

path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'webspace', 'lib', 'config.php')
m = re.search(r"'(pbkdf2_sha256\$(\d+)\$([0-9a-f]+)\$([0-9a-f]{64}))'", open(path, encoding='utf-8').read())
if not m:
    sys.exit('Kein Passwort-Hash in lib/config.php gefunden.')
full, iterations, salt, digest = m.group(1), int(m.group(2)), m.group(3), m.group(4)
print(f'Kennung des gespeicherten Passworts: {hashlib.sha256(full.encode()).hexdigest()[:12]}')
pw = getpass.getpass('Passwort zum Prüfen: ')
ok = hmac.compare_digest(hashlib.pbkdf2_hmac('sha256', pw.encode('utf-8'), salt.encode('ascii'), iterations).hex(), digest)
print('Stimmt.' if ok else 'Stimmt NICHT – das ist nicht das Passwort, das zuletzt gesetzt wurde.')
