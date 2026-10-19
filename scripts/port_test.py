#!/usr/bin/env python3
"""Portiert tests/RepeaterLimitTest.php nach tests/wpunit/PodsRepeaterTest.php.

Aufruf: port_test.py <quelle> <ziel>

Exit 1 mit Meldung auf stderr, wenn eine Ersetzung nicht greift.
"""
import re
import sys
from pathlib import Path

DOMAIN_FROM = "'pods-repeater'"
DOMAIN_TO = "'pods'"

CLASS_FROM = "class RepeaterLimitTest extends WP_UnitTestCase"
CLASS_TO = "class PodsRepeaterTest extends WP_UnitTestCase"

NEW_HEADER = """<?php
/**
 * PodsRepeaterTest — serverseitige Durchsetzung von `repeater_limit`.
 *
 * Ziel: der Repeater-Feldtyp (`PodsField_Repeater`) muss sein Zeilenlimit
 * auf beiden Ebenen halten, nicht nur im Browser:
 *
 *   - `validate()` liefert Fehler, wenn zu viele gefuellte Zeilen kommen.
 *     Der Rueckgabewert ist ein Array von Meldungen (Pods-Vertrag), kein
 *     `WP_Error`: PodsAPI::save_pod_item() castet das Ergebnis mit (array)
 *     und reicht die Elemente an wp_kses_post() weiter — ein Objekt in
 *     diesem Array bricht den Save mit einem PHP-Fehler ab.
 *   - `pre_save()` schneidet auf das Limit zurueck. Das ist der Schutz fuer
 *     Schreibwege, die nicht validieren (Import, direkte API-Aufrufe, POST).
 *
 * Vollstaendig leere Zeilen zaehlen nicht mit: `input()` seedet eine
 * Leerzeile als Template, die darf das Limit nicht verbrauchen und nie als
 * `{}`-Zeile persistieren.
 *
 * Teststruktur: Pods-Kern (tests/wpunit, WP_UnitTestCase).
 *
 * @package Pods
 */
"""

HEADER_PATTERN = re.compile(r"\A<\?php\n/\*\*\n \* RepeaterLimitTest .*?\n \*/\n", re.S)


def fail(message: str) -> None:
    print(f"FEHLER: {message}", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    if len(sys.argv) != 3:
        fail("Aufruf: port_test.py <quelle> <ziel>")

    source_path = Path(sys.argv[1])
    target_path = Path(sys.argv[2])
    text = source_path.read_text(encoding="utf-8")

    # --- 1. Kopfkommentar ersetzen ------------------------------------
    text, header_count = HEADER_PATTERN.subn(NEW_HEADER, text)
    if header_count != 1:
        fail(f"Test-Kopfkommentar nicht genau einmal gefunden (Treffer: {header_count})")

    # --- 2. Textdomain ------------------------------------------------
    # Zaehlen, was WIRKLICH Textdomain ist: ein 'pods-repeater' in
    # Anfuehrungszeichen, das im Argument einer Uebersetzungsfunktion steht.
    # RepeaterLimitTest.php hat keine i18n-Aufrufe; seine vier
    # "pods-repeater"-Vorkommen sind eine CSS-Klasse
    # (pods-repeater-limit-notice) und ein Kommentar. Die mitzuersetzen
    # wuerde die Assertion auf ein anderes HTML-Element haengen und den Test
    # lautlos entwerten.
    i18n_calls = re.findall(
        r"\b(?:__|_e|_x|_n|_nx|esc_html__|esc_attr__|esc_html_e|esc_attr_e)"
        r"\([^;]*?" + re.escape(DOMAIN_FROM),
        text,
    )
    domain_count = len(i18n_calls)
    if domain_count:
        text = text.replace(DOMAIN_FROM, DOMAIN_TO)
    print(f"test: {domain_count} Textdomain-Ersetzungen", file=sys.stderr)

    if DOMAIN_FROM in text:
        remaining = [
            line.strip() for line in text.splitlines() if DOMAIN_FROM in line
        ]
        # Nur zulaessig, wenn es kein i18n-String ist. Diese Zeilen werden
        # gemeldet, damit die Entscheidung nachpruefbar bleibt.
        for line in remaining:
            if re.search(r"\b(?:__|_e|_x|_n|esc_html__|esc_attr__)\(", line):
                fail(f"Textdomain steht noch in einem i18n-Aufruf: {line}")

    # --- 3. Klassenname ------------------------------------------------
    if CLASS_FROM not in text:
        fail(f"Klassendeklaration nicht gefunden: {CLASS_FROM}")
    text = text.replace(CLASS_FROM, CLASS_TO)
    # Docblock-Zeile ueber der Klasse traegt denselben Namen.
    text = text.replace(" * Class RepeaterLimitTest\n", " * Class PodsRepeaterTest\n")

    # --- 4. Invarianten ------------------------------------------------
    if "new PodsField_Repeater()" not in text:
        fail("Tests instanziieren die Feldklasse PodsField_Repeater nicht")
    if "RepeaterLimitTest" in text:
        fail("Alter Klassenname RepeaterLimitTest steht noch in der Portierung")
    if re.search(r"(?:assertWPError|is_wp_error|instanceof\s+WP_Error)", text):
        fail(
            "Tests pruefen noch auf WP_Error — die Implementierung liefert ein "
            "Array von Meldungen (Pods-Vertrag); Aussage korrigieren, nicht "
            "den Test an eine falsche Erwartung haengen"
        )
    if "@package Pods\n" not in text:
        fail("@package Pods fehlt im Kopfkommentar")

    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(text, encoding="utf-8")
    print(f"test: {domain_count} Textdomain-Ersetzungen, Klasse {CLASS_TO.split()[1]}")


if __name__ == "__main__":
    main()
