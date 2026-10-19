#!/usr/bin/env python3
"""Bindet die wp-env-Ports an die NetBird-IP statt an 0.0.0.0.

Warum: wp-env generiert docker-compose.yml bei jedem `wp-env start` neu und
publiziert die Ports ohne Host-Adresse. Der Container ist damit auch ueber die
oeffentliche IP erreichbar — gemessen: HTTP 200 auf 37.120.166.123:8899 bei
einer Instanz mit admin/password und WP_DEBUG=true. wp-env hat keine Option
fuer die Bindungsadresse, deshalb dieses Nachziehen nach jedem Start.

    python3 scripts/apply_netbird_bindings.py            # patchen + neu starten
    python3 scripts/apply_netbird_bindings.py --check    # nur pruefen (Exit 1)

Der Port bleibt derselbe; nur die Host-Adresse wird vorangestellt. Bereits
gebundene Dateien laesst das Skript unangetastet (idempotent).
"""
from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

REPO_DIR = Path(__file__).resolve().parent.parent
WP_ENV_HOME = Path(
    __import__("os").environ.get("WP_ENV_HOME", REPO_DIR.parent / ".wp-env-home")
)
NETBIRD_IP = __import__("os").environ.get("NETBIRD_IP", "100.67.225.184")

# Dienst -> (Host-Port, Container-Port). Feste Ports statt der wp-env-Zufalls-
# ports, damit die Zuordnung stabil und dokumentierbar ist.
SERVICES = {
    "wordpress": (8899, 80),
    "mysql": (33060, 3306),
    "phpmyadmin": (8898, 80),
}

SERVICE_RE = re.compile(r"^  ([A-Za-z0-9_-]+):\s*$")


def rewrite(text: str) -> tuple[str, list[str]]:
    """Setzt ports:-Listen der bekannten Dienste auf IP:Port:Port."""
    lines = text.splitlines()
    out: list[str] = []
    current: str | None = None
    in_ports = False
    changed: list[str] = []

    for line in lines:
        service_match = SERVICE_RE.match(line)
        if service_match:
            current = service_match.group(1)
            in_ports = False
            out.append(line)
            continue

        if current in SERVICES and re.match(r"^    ports:\s*$", line):
            in_ports = True
            out.append(line)
            continue

        if in_ports:
            item = re.match(r"^      - '([^']*)'\s*$", line)
            if item and current is not None:
                host_port, container_port = SERVICES[current]
                wanted = f"      - '{NETBIRD_IP}:{host_port}:{container_port}'"
                if item.group(1) != f"{NETBIRD_IP}:{host_port}:{container_port}":
                    changed.append(f"{current}: {item.group(1)} -> {NETBIRD_IP}:{host_port}:{container_port}")
                out.append(wanted)
                continue
            if line.strip() == "" or not line.startswith("      - "):
                in_ports = False

        out.append(line)

    return "\n".join(out) + ("\n" if text.endswith("\n") else ""), changed


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="nur pruefen, nichts aendern")
    parser.add_argument("--no-restart", action="store_true", help="nur Datei patchen")
    args = parser.parse_args()

    compose_files = sorted(WP_ENV_HOME.glob("*/docker-compose.yml"))
    if not compose_files:
        print(f"Keine docker-compose.yml unter {WP_ENV_HOME}", file=sys.stderr)
        return 2

    exit_code = 0
    for compose in compose_files:
        project = compose.parent.name
        original = compose.read_text()
        patched, changed = rewrite(original)

        if not changed:
            print(f"ok      {project}: alle Ports an {NETBIRD_IP} gebunden")
            continue

        if args.check:
            print(f"offen   {project}:", file=sys.stderr)
            for entry in changed:
                print(f"          {entry}", file=sys.stderr)
            exit_code = 1
            continue

        shutil.copy2(compose, compose.with_suffix(f".yml.bak-{time.strftime('%Y%m%d-%H%M%S')}"))
        compose.write_text(patched)
        print(f"patched {project}:")
        for entry in changed:
            print(f"          {entry}")

        if not args.no_restart:
            subprocess.run(
                ["docker", "compose", "up", "-d", "--force-recreate"],
                cwd=compose.parent,
                check=True,
                stdout=subprocess.DEVNULL,
            )
            print(f"started {project}")

    return exit_code


if __name__ == "__main__":
    sys.exit(main())
