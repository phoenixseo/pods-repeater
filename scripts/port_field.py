#!/usr/bin/env python3
"""Portiert classes/fields/repeater.php in die Pods-Kern-Konvention.

Wird von scripts/build-pr-package.sh aufgerufen, damit die Ersetzungen in
einer Sprache liegen, in der Regex-Escapes nicht durch die Shell und durch
Editor-Layer doppelt entschaerft werden.

Aufruf: port_field.py <quelle> <ziel>

Exit 1 mit Meldung auf stderr, wenn eine Ersetzung nicht greift.
"""
import re
import sys
from pathlib import Path

DOMAIN_FROM = "'pods-repeater'"
DOMAIN_TO = "'pods'"

# Die 51 Vorkommen von "pods-repeater" im Feld sind nicht alle Textdomain.
# Ersetzt wird ausschliesslich das Anfuehrungszeichen-Muster; CSS-Klassen
# (pods-repeater-row, -subfield, -add, ...) bleiben unveraendert, weil sie
# der Vertrag mit ui/js/repeater.js sind.

OLD_HEADER_BLOCK = re.compile(
    r"\n \* This is the CORE port of the standalone plugin field type\n"
    r".*?@package Pods\\Fields\n",
    re.S,
)

NEW_HEADER_TAIL = (
    "\n"
    " * This file is the upstream port: text domain 'pods', class name\n"
    " * PodsField_Repeater, no plugin-specific function prefixes.\n"
    " * The sub-field group registry is a plugin concern; the two\n"
    " * function_exists() guards below keep the type usable without one.\n"
    " *\n"
    " * @package Pods\\Fields\n"
)


def fail(message: str) -> None:
    print(f"FEHLER: {message}", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    if len(sys.argv) != 3:
        fail("Aufruf: port_field.py <quelle> <ziel>")

    source_path = Path(sys.argv[1])
    target_path = Path(sys.argv[2])
    text = source_path.read_text(encoding="utf-8")

    # --- 1. Textdomain ------------------------------------------------
    replacement_count = text.count(DOMAIN_FROM)
    if replacement_count == 0:
        fail(
            f"Textdomain-Ersetzung griff nicht: 0 Vorkommen von {DOMAIN_FROM} "
            f"in {source_path}"
        )
    text = text.replace(DOMAIN_FROM, DOMAIN_TO)

    if DOMAIN_FROM in text:
        fail(f"{DOMAIN_FROM} steht nach der Ersetzung noch in der Portierung")

    # --- 2. Kopfkommentar ---------------------------------------------
    # Ersatz als Funktion, nicht als String: re.sub parst einen String-
    # Ersatz als Template, und "@package Pods\Fields" wuerde als Escape
    # "\F" abbrechen.
    new_text, header_count = OLD_HEADER_BLOCK.subn(lambda _m: NEW_HEADER_TAIL, text)
    if header_count != 1:
        fail(
            "Kopfkommentar-Block 'This is the CORE port' bis '@package' nicht "
            f"genau einmal gefunden (Treffer: {header_count}) — Quelle wurde umgebaut"
        )
    text = new_text

    # --- 4. CSS-Klassen-Vertrag: Praefix pods-repeater -> pods ---------
    # Die Klassen sind ein Vertrag zwischen drei Stellen: der Feldklasse
    # (rendert sie), ui/js/repeater.js (selektiert sie) und den Tests
    # (asserten darauf). Im Kern heisst der Namensraum "pods": pods-row,
    # pods-rows, pods-add. Der Kern kennt keine dieser Klassen (geprueft:
    # kein pods-row/pods-add/pods-subfield in Pods 3.3.9.1), also kollidiert
    # das nicht.
    #
    # Wichtig: dieselbe Ersetzung muss im mitgelieferten JS passieren —
    # build-pr-package.sh portiert ui/js/repeater.js mit port_field.js.
    text, css_count = re.subn(
        r"(?<![\w-])pods-repeater(?=-|[\"' ])", "pods", text
    )
    if css_count == 0:
        fail("CSS-Klassen-Umbenennung griff nicht: 0 Vorkommen")

    # --- 5. Kein pods-repeater mehr --------------------------------
    leftovers = [
        line.strip() for line in text.splitlines() if "pods-repeater" in line
    ]
    if leftovers:
        fail(
            "pods-repeater steht noch in der Portierung: "
            + " | ".join(leftovers[:3])
        )

    # --- 6. Invarianten fuer die Pods-Konvention ----------------------
    if "class PodsField_Repeater extends PodsField" not in text:
        fail("Klassenname PodsField_Repeater fehlt")
    if "pods_repeater_" not in text:
        fail(
            "Alle pods_repeater_-Vorkommen verschwunden — die "
            "function_exists()-Guards sind die einzige Kern-Beruehrung und "
            "muessen bleiben"
        )

    # CSS-Klassen muessen erhalten sein, sonst bricht das Admin-UI. Geprueft
    # wird die Kern-Schreibweise (pods-*), die port_field.py herstellt und
    # port_js.py im mitgelieferten Skript genauso herstellt.
    required_css_classes = [
        'class="pods-rows"',
        'class="pods-row"',
        "pods-subfield",
        "pods-move-up",
        "pods-remove",
        "pods-add",
    ]
    missing = [c for c in required_css_classes if c not in text]
    if missing:
        fail(f"CSS-Klassen-Vertrag zerstoert, fehlt: {', '.join(missing)}")

    # --- 7. Nutzloser Konstruktor-Override ----------------------------
    # "__construct() { parent::__construct(); }" ist ein reiner Durchreicher;
    # Kern-Felddateien (text.php, pick.php) haben ihn nicht, und phpcs flaggt
    # ihn als "Possible useless method overriding". Im Kern weg damit.
    text, ctor_count = re.subn(
        r"\t/\*\*\n"
        r"\t \* Constructor: call parent init \(loads \$this->pod_types etc\.\)\.\n"
        r"\t \*/\n"
        r"\tpublic function __construct\(\) \{\n"
        r"\t\tparent::__construct\(\);\n"
        r"\t\}\n\n",
        "",
        text,
    )
    print(f"feld: {ctor_count} Konstruktor-Override(s) entfernt", file=sys.stderr)

    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(text, encoding="utf-8")
    print(f"feld: {replacement_count} Textdomain-Ersetzungen")


if __name__ == "__main__":
    main()
