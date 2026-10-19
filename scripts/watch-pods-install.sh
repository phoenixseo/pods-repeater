#!/usr/bin/env bash
# Watchdog: Prüft, ob Pods im wp-env-Bind-Mount der Testinstanz vorhanden ist.
# Wenn der Mount-Ordner leer ist (bekannter wiederkehrender Wipe), wird Pods
# aus dem Projekt-Archiv neu entpackt und im Container aktiviert.
#
# Ablage: workspace (nicht ~/.cache, das war temporär).
# Quelle: .wp-env.json referenziert https://downloads.wordpress.org/plugin/pods.3.3.9.1.zip
#         -> Pods 3.3.9.1 ist im Projekt-Archiv unter assets/pods-3.3.9.1.zip fixiert.
set -u

MOUNT="/home/hermes/hermes_agent/workspaces/phoenixseo/sdc-page-builder/.wp-env-home/wp-env-pods-repeater-54d58dae/pods"
ZIP="/home/hermes/hermes_agent/workspaces/phoenixseo/sdc-page-builder/pods-repeater/assets/pods-3.3.9.1.zip"
CONTAINER="wp-env-pods-repeater-54d58dae-cli-1"

# Hauptdatei des Pods-Zips ist pods/init.php (nicht pods.php)
if [ -f "$MOUNT/init.php" ] && [ -f "$MOUNT/classes/PodsForm.php" ]; then
    echo "OK: Pods im Mount vorhanden"
    exit 0
fi

echo "WARN: Pods fehlt im Mount ($MOUNT) - stelle aus Projekt-Archiv wieder her"

if [ ! -f "$ZIP" ]; then
    echo "FEHLER: Projekt-Archiv $ZIP nicht gefunden" >&2
    exit 1
fi

# Entpacken (Top-Level 'pods/' strippen, da Mount direkt auf /plugins/pods zeigt)
python3 - "$ZIP" "$MOUNT" <<'PY'
import sys, zipfile, os, shutil
z = zipfile.ZipFile(sys.argv[1])
dst = sys.argv[2]
os.makedirs(dst, exist_ok=True)
count = 0
for e in z.namelist():
    if e.endswith('/'):
        continue
    rel = e.split('/', 1)[1] if '/' in e else e
    target = os.path.join(dst, rel)
    os.makedirs(os.path.dirname(target), exist_ok=True)
    with z.open(e) as f, open(target, 'wb') as out:
        shutil.copyfileobj(f, out)
    count += 1
print(f"entpackt: {count} Dateien")
PY

if [ ! -f "$MOUNT/init.php" ]; then
    echo "FEHLER: init.php nach Entpacken nicht vorhanden" >&2
    exit 1
fi

# Aktivieren im Container (nur wenn Container läuft)
if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    docker exec "$CONTAINER" wp plugin activate pods 2>&1 || echo "WARN: Aktivierung fehlgeschlagen (Container läuft?)" >&2
else
    echo "HINWEIS: Container $CONTAINER läuft nicht - Aktivierung übersprungen"
fi

echo "WIEDERHERGESTELLT: Pods aktiv"
exit 0
