#!/usr/bin/env python3
"""Prueft die drei Einreichungs-Kommandos aus EINREICHUNG.md Abschnitt 7.

Extrahiert die Bash-Bloecke aus dem Dokument, schickt jeden durch
`bash -n` (Syntaxpruefung, keine Ausfuehrung) und prueft zusaetzlich
wichtige Vorbedingungen per `gh --help` / Dateisystem.

Damit ist Akzeptanzkriterium 3 (')woertlich ausfuehrbare Befehle')
nachpruefbar statt behauptet.

Aufruf: python3 scripts/s20-verify-commands.py
Exit 0 = alle Bloecke syntaktisch ok und Vorbedingungen erfuellt.
"""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOC = ROOT / "dist" / "pods-pr" / "EINREICHUNG.md"


def section_7_blocks(text: str) -> list[str]:
    m = re.search(r"^## 7\..*?$(.*?)^## Anhang", text, re.S | re.M)
    if not m:
        return []
    body = m.group(1)
    return [b.strip() for b in re.findall(r"```(?:bash)?\n(.*?)```", body, re.S)]


def main() -> int:
    if not DOC.exists():
        print(f"FEHLT: {DOC}", file=sys.stderr)
        return 1
    blocks = section_7_blocks(DOC.read_text(encoding="utf-8"))
    print(f"Bash-Bloecke in Abschnitt 7: {len(blocks)}")

    problems = 0
    for i, block in enumerate(blocks, 1):
        # Pro Block: Syntaxpruefung ohne Ausfuehrung.
        r = subprocess.run(
            ["bash", "-n"], input=block, capture_output=True, text=True
        )
        status = "OK" if r.returncode == 0 else "SYNTAXFEHLER"
        if r.returncode != 0:
            problems += 1
        first = block.splitlines()[0]
        print(f"  [{i}] {status:12} {first[:70]}")
        if r.returncode != 0:
            print(f"      {r.stderr.strip()[:200]}")

    # Vorbedingungen, die im Dokument behauptet werden.
    print("\nVorbedingungen:")
    checks = [
        ("gh vorhanden", ["gh", "--version"]),
        ("gh angemeldet", ["gh", "auth", "status"]),
        ("Paketdatei Feldklasse", None),
        ("Paketdatei JS", None),
        ("Paket-Testverzeichnis", None),
        ("kein Remote im Repo", None),
    ]
    for label, cmd in checks:
        if cmd is not None:
            r = subprocess.run(cmd, capture_output=True, text=True)
            ok = r.returncode == 0
            print(f"  {'OK ' if ok else 'FEHLT'} {label}")
            if not ok:
                problems += 1
            continue
        path = {
            "Paketdatei Feldklasse": ROOT / "dist/pods-pr/classes/fields/repeater.php",
            "Paketdatei JS": ROOT / "dist/pods-pr/ui/js/repeater.js",
            "Paket-Testverzeichnis": ROOT / "dist/pods-pr/tests/wpunit",
            "kein Remote im Repo": None,
        }[label]
        if path is None:
            r = subprocess.run(
                ["git", "remote", "-v"], cwd=ROOT, capture_output=True, text=True
            )
            ok = r.returncode == 0 and not r.stdout.strip()
            print(f"  {'OK ' if ok else 'FEHLT'} {label} (leer = kein Remote)")
        else:
            ok = path.exists()
            print(f"  {'OK ' if ok else 'FEHLT'} {label}: {path.relative_to(ROOT)}")
        if not ok:
            problems += 1

    # Der im Dokument als Annahme markierte Testpfad im Kern.
    print("\nAls Annahme markiert (im Dokument ausdruecklich benannt):")
    print("  tests/codeception/wpunit/ — im Kern-Mount nicht verifizierbar")

    print(f"\nBefunde: {problems}")
    return 0 if problems == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
