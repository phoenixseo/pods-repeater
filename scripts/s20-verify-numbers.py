#!/usr/bin/env python3
"""Belegt die Zahlen in dist/pods-pr/EINREICHUNG.md gegen die Quelle.

Kein Ersatz fuer die Messung — die Messungen stehen in EINREICHUNG.md
Abschnitt 2. Dieses Skript prueft die *ableitbaren* Zahlen (Dateimengen,
Testmethoden, Zeilen, Textdomain-Vorkommen, Changelog-Version) und meldet
Abweichungen. Zahlen, die nur ein Container-Lauf erzeugen kann (PHPUnit,
phpcs, Plugin Check, E2E), werden hier nicht nachgestellt, sondern mit
ihrem Kommando aufgelistet, damit klar bleibt, was live gemessen wurde.

Aufruf: python3 scripts/s20-verify-numbers.py
Exit 0 = alle pruefbaren Zahlen stimmen mit dem Dokument ueberein.
"""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PKG = ROOT / "dist" / "pods-pr"
DOC = PKG / "EINREICHUNG.md"


def pkg_tests() -> int:
    n = 0
    for f in (PKG / "tests" / "wpunit").glob("*.php"):
        n += len(re.findall(r"public function test_", f.read_text(encoding="utf-8")))
    return n


def main() -> int:
    doc = DOC.read_text(encoding="utf-8")
    field = PKG / "classes" / "fields" / "repeater.php"
    field_text = field.read_text(encoding="utf-8")

    # i18n-Aufrufe: dieselbe Zaehlung wie s20-i18n-count.py, damit beide
    # Skripte nicht auseinanderlaufen.
    sys.path.insert(0, str(ROOT / "scripts"))
    from importlib import import_module

    i18n = import_module("s20-i18n-count")
    pkg_scan = i18n.scan(field)

    measured = {
        "Testmethoden im Paket (10+19+4)": (pkg_tests(), 33),
        "Zeilen Feldklasse": (len(field_text.splitlines()), 1085),
        "i18n-Aufrufe Paket": (pkg_scan["i18n_aufrufe_gesamt"], 33),
        "i18n-Aufrufe mit Domain 'pods'": (pkg_scan["i18n_domain_pods"], 33),
        "falsche Literale 'pods-repeater'": (pkg_scan["literal_pods_repeater"], 0),
        "CSS-Klassen 'pods-repeater-'": (pkg_scan["css_klassen_pods_repeater"], 0),
    }

    print(f"{'Messung':45} {'ist':>6} {'soll':>6}  Status")
    bad = 0
    for label, (ist, soll) in measured.items():
        if soll is None:
            print(f"{label:45} {ist:6} {'—':>6}  (informativ)")
            continue
        ok = ist == soll
        bad += 0 if ok else 1
        print(f"{label:45} {ist:6} {soll:6}  {'OK' if ok else 'ABWEICHUNG'}")

    # Behauptungen, die im Dokument als Text stehen, hier direkt pruefen.
    claims = [
        ("'108 tests, 2226 assertions' im Dokument", "108 tests, 2226 assertions" in doc),
        ("'18/18 Schritte PASS' im Dokument", "18/18 Schritte PASS" in doc),
        ("Repo ohne Remote dokumentiert", "git remote -v` → leer" in doc or "kein Remote" in doc),
        ("HEAD e2128dc im Dokument", "e2128dc" in doc),
        ("Risiko als Schaetzung markiert", "Schätzung, nicht Messung" in doc),
        ("1-3 Monate mit Fundstelle", "1–3 Monate" in doc and ":93" in doc),
        ("40-60 % mit Fundstelle", "40–60 %" in doc and ":100" in doc),
        ("3.4/4.0-API-Bruch benannt", "3.4/4.0" in doc),
        ("sieben Abschnitte", len(re.findall(r"^## \d\.", doc, re.M)) == 7),
        ("kein TBD/TODO", not re.search(r"TBD|TODO", doc)),
    ]
    print("\nBehauptungen im Dokument:")
    for label, ok in claims:
        bad += 0 if ok else 1
        print(f"  {'OK   ' if ok else 'FEHLT'} {label}")

    # Panel: E2E
    log = ROOT / "dist" / "e2e" / "lauf4.log"
    if log.exists():
        tail = [ln for ln in log.read_text(encoding="utf-8").splitlines() if "PASS" in ln and "/" in ln]
        print(f"\nE2E lauf4.log letzte Zeile: {tail[-1].strip() if tail else '—'}")

    print(f"\nAbweichungen: {bad}")
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
