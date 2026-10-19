#!/usr/bin/env python3
"""Portiert ui/js/repeater.js auf die Pods-Kern-Klassen.

Das JS selektiert die CSS-Klassen, die die Feldklasse rendert. Wenn
port_field.py `pods-repeater-row` zu `pods-row` macht, muss das Skript
denselben Namen sehen — sonst findet es keine Zeilen mehr, und das
Admin-UI ist still kaputt (kein Fehler, nur ein toter Button).

Aufruf: port_js.py <quelle.js> <ziel.js>
Exit 1 bei nicht greifender Ersetzung.
"""
import re
import sys
from pathlib import Path

# Dieselbe Regel wie in port_field.py: nur das Praefix vor einem Bindestrich,
# nicht mitten in einem Wort.
CSS_PREFIX = re.compile(r"(?<![\w-])pods-repeater(?=-|[\"' ])")

# Klassen, die das Skript nach der Umbenennung zwingend selektieren muss.
REQUIRED = [
    "pods-row",
    "pods-rows",
    "pods-add",
    "pods-remove",
    "pods-move-up",
    "pods-move-down",
    "pods-subfield",
]


def fail(message: str) -> None:
    print(f"FEHLER: {message}", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    if len(sys.argv) != 3:
        fail("Aufruf: port_js.py <quelle.js> <ziel.js>")

    source = Path(sys.argv[1])
    target = Path(sys.argv[2])

    if not source.is_file():
        fail(f"Quelldatei fehlt: {source}")

    text = source.read_text(encoding="utf-8")
    if not text.strip():
        fail(f"Quelldatei ist leer: {source}")

    text, count = CSS_PREFIX.subn("pods", text)
    if count == 0:
        fail(
            "CSS-Klassen-Umbenennung griff nicht: 0 Vorkommen von "
            "pods-repeater-* — die Feldklasse wurde umbenannt, das Skript "
            "nicht"
        )

    leftovers = [
        line.strip() for line in text.splitlines() if "pods-repeater" in line
    ]
    if leftovers:
        fail(
            "pods-repeater steht noch im Skript: " + " | ".join(leftovers[:3])
        )

    missing = [cls for cls in REQUIRED if cls not in text]
    if missing:
        fail(
            "Skript selektiert vertraglich vereinbarte Klassen nicht: "
            + ", ".join(missing)
        )

    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text, encoding="utf-8")
    print(f"js: {count} Klassen-Ersetzungen", file=sys.stderr)


if __name__ == "__main__":
    main()
