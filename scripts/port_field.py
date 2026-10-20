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

    # --- 5b. Gruppen-Registry: Praefix fallen lassen ----------------------
    # Die portierten Tests rufen `repeater_get_group_fields()` auf (ohne
    # Plugin-Praefix, so wie der PR sie im Kern einfuehren wuerde). Die
    # Feldklasse rief aber weiter `pods_repeater_get_group_fields()` — der
    # function_exists()-Guard war damit immer falsch und die Feldgruppe im
    # Kern wirkungslos. Gezaehlt wird der Guard selbst plus jede
    # verdaechtige Stelle, an der ein Registry-Aufruf ohne guard steht:
    # ungepruefter Aufruf neben geprueftem Aufruf waere ein Fatal, sobald
    # der Kern die Funktion nicht hat.
    for old, new in (
        ("pods_repeater_get_group_fields", "repeater_get_group_fields"),
        ("pods_repeater_get_groups", "repeater_get_groups"),
    ):
        old_guards = text.count(f"function_exists( '{old}' )")
        old_calls = text.count(old)
        if old_calls == 0:
            fail(f"{old} nicht in der Quelle gefunden — Quelle wurde umgebaut")
        text = text.replace(old, new)
        new_guards = text.count(f"function_exists( '{new}' )")
        new_calls = text.count(new)

        if new_calls - new_guards != old_calls - old_guards:
            fail(
                f"{old}: {old_calls - old_guards} Aufruf(se) ohne Guard in der "
                f"Quelle, nach der Ersetzung {new_calls - new_guards} — die "
                f"Ersetzung traf Aufruf und Guard ungleich"
            )
        print(
            f"feld: {old_calls} Vorkommen {old} -> {new} "
            f"({new_guards} Guard(s), {new_calls - new_guards} Aufruf(se) dahinter)",
            file=sys.stderr,
        )

    # Jede Zeile, die eine Registry-Funktion AUFRUFT, muss im selben
    # Anweisungsblock hinter einem function_exists()-Guard liegen: im
    # if-Kopf davor oder (bei langen if-Koepfen) in den Zeilen oberhalb bis
    # zur letzten offenen Klammer. Ein ungepruefter Aufruf waere ein Fatal,
    # sobald der Kern die Registry nicht mitbringt.
    lines = text.splitlines()
    for call in ("repeater_get_group_fields(", "repeater_get_groups("):
        for index, line in enumerate(lines):
            if call not in line:
                continue
            end = max(0, index - 8)
            window = "\n".join(lines[end : index + 1])
            if f"function_exists( '{call[:-1]}'" not in window or not any(
                "{" in l or "if (" in l for l in lines[end : index + 1]
            ):
                fail(
                    f"Registry-Aufruf ohne function_exists()-Guard, "
                    f"Zeile {index + 1}: {line.strip()}"
                )

    # --- 6. Invarianten fuer die Pods-Konvention ----------------------
    if "class PodsField_Repeater extends PodsField" not in text:
        fail("Klassenname PodsField_Repeater fehlt")
    if "pods_repeater_" in text:
        fail(
            "pods_repeater_-Praefix steht noch in der Kern-Portierung: "
            + " | ".join(
                line.strip() for line in text.splitlines() if "pods_repeater_" in line
            )[:300]
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
