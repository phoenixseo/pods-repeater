#!/usr/bin/env python3
"""Portiert die Testdateien des Plugins in die Pods-Kern-Konvention.

Aufruf: port_test.py <quelle> <ziel> [--class <Klassenname>] [--package <Header>]

Ziel ist immer eine Datei, die im Pods-Kern unter tests/wpunit läuft
(WP_UnitTestCase, `@package Pods` bzw. `@package Pods\\Tests`).

Drei Quellen mit drei unterschiedlichen Aufgaben:

  tests/RepeaterLimitTest.php      Standard-Portierung: nur Textdomain und
                                   Klassenname wechseln, der Rest bleibt.
  tests/RepeaterContractTest.php   Vertragstest (S12): Klassenname, Docblock,
                                   @group podscore, `PodsRepeater` ->
                                   `Repeater` in den Docblocks.
  tests/CoreIntegrationTest.php    Integrationsbeleg (S13): pfadbezogene
                                   Erwartungen werden auf die Kern-Pfade
                                   übersetzt (PODS_DIR statt
                                   PODS_REPEATER_DIR), Klassen- und
                                   Pod-Prefixe auf `repeater_`.
  tests/RepeaterNestedTest.php     Ablehnung verschachtelter Repeater (S21):
                                   Klassenname und Kopf, `@group podscore`
                                   (läuft im Kern nur mit der Feldklasse aus
                                   diesem PR), Plugin-Registry-Name und
                                   Bootstrap-Meldung auf Kern-Namen. Die
                                   Assertions bleiben unangetastet.

Exit 1 mit Meldung auf stderr, wenn eine Ersetzung nicht greift. Jede
Ersetzung wird gezählt; ein Muster, das nicht mehr greift, ist ein Fehler
und keine stille Verschiebung.

Warum Pyton und kein sed: die Ersetzungen sind zustandsabhängig (der neue
Kopfkommentar hängt vom Quellkopf ab), und ein `s/pods-repeater/pods/g` wäre
falsch — CSS-Klassen (`pods-repeater-row`) und PHP-Prefixe
(`pods_repeater_get_group_fields`) sind Verträge, keine Textdomain.
"""
import re
import sys
from pathlib import Path

DOMAIN_FROM = "'pods-repeater'"
DOMAIN_TO = "'pods'"

I18N_PREFIX = (
    r"\b(?:__|_e|_x|_n|_nx|esc_html__|esc_attr__|esc_html_e|esc_attr_e"
    r"|esc_html|esc_attr)\("
)

# Wie viele Textdomain-Ersetzungen je Quelle mindestens greifen müssen.
# Die Quellen haben gemessen gar keine i18n-Aufrufe (geprüft: grep -c
# "pods-repeater" zählt nur CSS-Klassen und Kommentare). Der Eintrag steht
# trotzdem hier, damit die Prüfung nicht still entfällt, wenn ein Test später
# eine Übersetzung bekommt: dann muss die Zahl mitwandern, und der Lauf
# meldet es, statt die Textdomain im Kern stehen zu lassen.
MIN_DOMAIN_REPLACEMENTS = {
    "RepeaterLimitTest": 0,      # keine i18n-Aufrufe, nur CSS-Klasse + Kommentar
    "RepeaterContractTest": 0,   # keine i18n-Aufrufe (gemessen)
    "CoreIntegrationTest": 0,    # keine i18n-Aufrufe (gemessen)
    # RepeaterNestedTest prueft die Meldung von validate(); die Meldung selbst
    # entsteht in der Feldklasse (dort wird die Textdomain ersetzt), der Test
    # zitiert nur das englische Literal aus validate().
    "RepeaterNestedTest": 0,
}


def fail(message: str) -> None:
    print(f"FEHLER: {message}", file=sys.stderr)
    sys.exit(1)


def replace_domain(text: str, source_name: str) -> tuple[str, int]:
    """Ersetzt 'pods-repeater' nur in Übersetzungsaufrufen.

    Ein 'pods-repeater' außerhalb eines i18n-Aufrufs ist entweder eine
    CSS-Klasse oder ein Kommentar. Beides muss bleiben: die CSS-Klasse ist
    die Assertion über ein HTML-Element, der Kommentar dokumentiert sie.
    """
    count = len(re.findall(I18N_PREFIX + r"[^;]*?" + re.escape(DOMAIN_FROM), text))

    if count:
        text = re.sub(
            r"(" + I18N_PREFIX + r"[^;]*?)" + re.escape(DOMAIN_FROM),
            lambda m: m.group(1) + DOMAIN_TO,
            text,
        )

    remaining = [line.strip() for line in text.splitlines() if DOMAIN_FROM in line]
    for line in remaining:
        # Nach der Ersetzung darf die Textdomain nur noch in Kommentaren und
        # CSS-Klassen stehen — nie in einem Übersetzungsaufruf.
        if re.search(I18N_PREFIX, line):
            fail(f"Textdomain steht noch in einem i18n-Aufruf: {line}")

    minimum = MIN_DOMAIN_REPLACEMENTS.get(source_name, 0)
    if count < minimum:
        fail(
            f"Nur {count} Textdomain-Ersetzungen in {source_name} "
            f"(erwartet mindestens {minimum}) — Quelle wurde umgebaut"
        )

    print(f"test: {source_name}: {count} Textdomain-Ersetzungen", file=sys.stderr)
    return text, count


def port_limit(text: str) -> str:
    """Standard-Portierung für RepeaterLimitTest (unverändert seit S10)."""
    header = """<?php
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
    pattern = re.compile(r"\A<\?php\n/\*\*\n \* RepeaterLimitTest .*?\n \*/\n", re.S)
    text, count = pattern.subn(header, text)
    if count != 1:
        fail(f"Test-Kopfkommentar nicht genau einmal gefunden (Treffer: {count})")

    class_from = "class RepeaterLimitTest extends WP_UnitTestCase {"
    class_to = "class PodsRepeaterTest extends WP_UnitTestCase {"
    if class_from not in text:
        fail(f"Klassendeklaration nicht gefunden: {class_from}")
    text = text.replace(class_from, class_to)
    text = text.replace(" * Class RepeaterLimitTest\n", " * Class PodsRepeaterTest\n")

    if "new PodsField_Repeater()" not in text:
        fail("Tests instanziieren die Feldklasse PodsField_Repeater nicht")
    if re.search(r"\bRepeaterLimitTest\b", text):
        fail("Alter Klassenname RepeaterLimitTest steht noch in der Portierung")
    if re.search(r"(?:assertWPError|is_wp_error|instanceof\s+WP_Error)", text):
        fail(
            "Tests pruefen noch auf WP_Error — die Implementierung liefert ein "
            "Array von Meldungen (Pods-Vertrag); Aussage korrigieren, nicht "
            "den Test an eine falsche Erwartung haengen"
        )
    if "@package Pods\n" not in text:
        fail("@package Pods fehlt im Kopfkommentar")

    return text


def port_contract(text: str) -> str:
    """Portierung des Vertragstests (S12).

    Kopf: der Quellkopf beschreibt die Messungen — der bleibt, nur die
    Package-Angabe wechselt und die Gruppenmarke kommt dazu. Die
    Gruppenmarke `podscore` ist der Grund, warum dieser Test im Kern nur
    explizit läuft (`--group podscore`): er prüft eine Klasse, die der Kern
    erst mit diesem PR bekommt.
    """
    if "@package PodsRepeater\n */" not in text:
        fail("Kopfkommentar von RepeaterContractTest endet nicht mit '@package PodsRepeater'")

    text = text.replace(
        "@package PodsRepeater\n */",
        "@package Pods\n *\n * @group podscore\n */",
        1,
    )

    class_from = "class RepeaterContractTest extends WP_UnitTestCase {"
    class_to = "class PodsRepeaterContractTest extends WP_UnitTestCase {"
    if class_from not in text:
        fail(f"Klassendeklaration nicht gefunden: {class_from}")
    # Docblock: erst die Klassenzeile (eigener Name), dann die Erwähnungen
    # des Plugin-Pakets in den Beschreibungen.
    text = text.replace(" * Class RepeaterContractTest\n", " * Class PodsRepeaterContractTest\n")
    text, doc_count = re.subn(r"( \* .*?)PodsRepeater(?!ContractTest)", r"\1Repeater", text)
    print(f"test: {doc_count} Docblock-Erwähnungen auf 'Repeater' umgestellt", file=sys.stderr)

    text = text.replace(class_from, class_to)
    # Test-Pod-Prefix: im Kern heißt der Test-Pod repeater_contract_*, damit
    # Rückstände eines abgebrochenen Laufs zuordenbar bleiben.
    text = text.replace("'s12contract_'", "'repeater_contract_'")
    # Registry-Funktion: im Kern ohne Plugin-Prefix (der PR führt sie dort ein).
    text = text.replace("pods_repeater_get_group_fields", "repeater_get_group_fields")

    if re.search(r"\bRepeaterContractTest\b", text):
        offenders = [
            f"{i}: {line.strip()}"
            for i, line in enumerate(text.splitlines(), 1)
            if re.search(r"\bRepeaterContractTest\b", line)
        ]
        fail("Alter Klassenname RepeaterContractTest steht noch in der Portierung -> " + " | ".join(offenders))
    if "new PodsField_Repeater()" not in text:
        fail("Vertragstest instanziiert die Feldklasse PodsField_Repeater nicht")
    if "Public function test_" in text:
        fail("Unbrauchbare Zeile 'Public function test_' gefunden")

    return text


def port_integration(text: str) -> str:
    """Portierung des Integrationsbelegs (S13).

    Zwei Klassen von Ersetzungen:

      Pfade:  das Plugin liegt im Kern in PODS_DIR. PODS_REPEATER_DIR zeigt
              nach der Portierung auf das Kern-Verzeichnis, PODS_REPEATER_URL
              hat im Kern keinen Ersatz. Beide werden aus dem Test entfernt;
              der Bedingungsteil der Pfad-Assertion wird auf `defined(
              'PODS_DIR' )` umgestellt, damit die Aussage erhalten bleibt.
      Namen:  Test-Pods (s12contract_/s13core_) und Registry-Funktionen
              (pods_repeater_get_group_fields) heißen im Kern allgemein
              repeater_*. Die assertions zählen weiter, sie zeigen nur auf
              keinen Plugin-Prefix mehr.
    """
    if "@package PodsRepeater\n */" not in text:
        fail("Kopfkommentar von CoreIntegrationTest endet nicht mit '@package PodsRepeater'")

    text = text.replace(
        "@package PodsRepeater\n */",
        "@package Pods\n *\n * @group podscore\n */",
        1,
    )

    class_from = "class CoreIntegrationTest extends WP_UnitTestCase {"
    class_to = "class PodsRepeaterCoreIntegrationTest extends WP_UnitTestCase {"
    if class_from not in text:
        fail(f"Klassendeklaration nicht gefunden: {class_from}")
    text = text.replace(class_from, class_to)
    text = text.replace(" * Class CoreIntegrationTest\n", " * Class PodsRepeaterCoreIntegrationTest\n")

    # Pfad-Konstanten. Zuerst die Assertion auf den Konstantennamen (der
    # Satz bleibt wahr: das Kern-Verzeichnis muss definiert sein), dann die
    # Verwendung in der Pfadbildung.
    if "defined( 'PODS_REPEATER_DIR' )" not in text:
        fail("Erwartete Assertion 'defined( PODS_REPEATER_DIR )' fehlt in der Quelle")
    text = text.replace("defined( 'PODS_REPEATER_DIR' )", "defined( 'PODS_DIR' )")

    if "PODS_REPEATER_DIR . 'classes/fields/repeater.php'" not in text:
        fail("Erwartete Pfadbildung 'PODS_REPEATER_DIR . classes/fields/repeater.php' fehlt")
    text = text.replace(
        "PODS_REPEATER_DIR . 'classes/fields/repeater.php'",
        "PODS_DIR . 'classes/fields/repeater.php'",
    )

    # Meldungstexte: 'PODS_REPEATER_DIR fehlt — pods-repeater.php wurde nicht
    # geladen' beschreibt die Plugin-Konstante. Im Kern liegt beides im selben
    # Verzeichnis, also nennen die Meldungen PODS_DIR und init.php.
    text = text.replace(
        "'PODS_REPEATER_DIR fehlt — pods-repeater.php wurde nicht geladen (siehe tests/bootstrap.php).'",
        "'PODS_DIR fehlt — der Pods-Kern wurde nicht geladen (siehe tests/bootstrap.php).'",
    )
    # Es gibt zwei Formen: 'pods-repeater.php:83-112 registriert beim
    # plugins_loaded' und 'und pods-repeater.php:99-110 fuellt ...'. Beide
    # werden auf den Kern umgeschrieben, damit der Kommentar im Kern nicht auf
    # eine Datei zeigt, die es dort nicht gibt.
    text = text.replace("pods-repeater.php:83-112 registriert", "init.php registriert")
    text = text.replace("pods-repeater.php:99-110", "init.php (Feldtyp-Registrierung)")
    text = text.replace("pods-repeater.php:", "init.php:")

    if "PODS_REPEATER_" in text:
        leftover = [line.strip() for line in text.splitlines() if "PODS_REPEATER_" in line]
        fail("Plugin-Konstante steht noch in der Portierung: " + " | ".join(leftover[:3]))

    # Plugin-Namen: Test-Pods und Registry-Funktionen.
    #
    # Die Kern-Fassung verwendet dieselben Namen wie der PR: Pods heißt
    # `repeater_...`, die Funktionen `repeater_...`. Das ist Absicht — im
    # Kern gibt es keinen Plugin-Prefix, und die Tests sollen dort genau
    # die Namen prüfen, die der PR einführt.
    text = text.replace("s12contract_", "repeater_contract_")
    text = text.replace("s13core_", "repeater_core_")
    text = text.replace("pods_repeater_get_group_fields", "repeater_get_group_fields")
    text = text.replace("pods_repeater_get_groups", "repeater_get_groups")

    if re.search(r"\bCoreIntegrationTest\b", text):
        fail("Alter Klassenname CoreIntegrationTest steht noch in der Portierung")

    # `PodsRepeater` in Docblocks: bleibt als Test-Suite-Name stehen (der
    # Kern nennt die Suite nicht so, aber der Test beschreibt, was er
    # prüft). Nur die Klassen-Docblock-Zeile bekommt den neuen Namen.
    if "class_exists( 'PodsForm' )" not in text:
        fail("Integrationsbeleg prüft den Kern (PodsForm) nicht mehr")

    # Registry-Funktion: im Kern ohne Plugin-Praefix (der PR fuehrt sie dort
    # ein). Kein Stub-Umbau noetig — die Testdatei definiert sie nicht
    # selbst; Test 15 prueft nur den Guard-Zweig bei fehlender Funktion.
    if re.search(r"\bpods_repeater_\w+", text):
        leftover = [
            line.strip()
            for line in text.splitlines()
            if re.search(r"\bpods_repeater_\w+", line)
        ]
        fail(
            "Plugin-Funktion steht noch in der Testportierung: "
            + " | ".join(leftover[:3])
        )

    return text


def port_nested(text: str) -> str:
    """Portierung der Nested-Repeater-Ablehnung (S21).

    Was sich ändert (und was nicht):

      Kopf:    `@package PodsRepeater` -> `@package Pods` plus `@group
               podscore`. Die Marke ist nötig, weil der Test eine Feldklasse
               prüft, die es im Pods-Repo erst mit diesem PR gibt; ohne die
               Marke liefe er dort gegen eine fehlende Klasse und würde rot.
      Namen:   Klassenname `RepeaterNestedTest` -> `PodsRepeaterNestedTest`,
               Plugin-Registry-Funktion `pods_repeater_supported_sub_types`
               -> `repeater_supported_sub_types` (im Kern ohne Prefix), und in
               der Bootstrap-Meldung steht der Kern statt des Plugins.
      Rest:    unangetastet. Die Assertions sind der Beleg; wer hier eine
               abschwächt, nimmt dem PR die Beweiskraft. Darum prüft der
               Porter unten namentlich die Aussagen, die die Zusage tragen —
               fehlt eine, bricht der Build statt still durchzulaufen.
    """
    if "@package PodsRepeater\n */" not in text:
        fail("Kopfkommentar von RepeaterNestedTest endet nicht mit '@package PodsRepeater'")

    text = text.replace(
        "@package PodsRepeater\n */",
        "@package Pods\n *\n * @group podscore\n */",
        1,
    )

    class_from = "class RepeaterNestedTest extends WP_UnitTestCase {"
    class_to = "class PodsRepeaterNestedTest extends WP_UnitTestCase {"
    if class_from not in text:
        fail(f"Klassendeklaration nicht gefunden: {class_from}")
    text = text.replace(class_from, class_to)
    text = text.replace(
        " * Class RepeaterNestedTest\n",
        " * Class PodsRepeaterNestedTest\n",
    )
    # Der Datei-Kopf beginnt mit dem Klassennamen ("RepeaterNestedTest —
    # serverseitige Ablehnung ..."); im Kern traegt er denselben Namen wie die
    # Klasse, sonst zeigt der Kopf auf einen Namen, den die Datei nicht hat.
    header_line = " * RepeaterNestedTest — serverseitige Ablehnung verschachtelter Repeater."
    if header_line not in text:
        fail("Kopfzeile von RepeaterNestedTest nicht gefunden")
    text = text.replace(
        header_line,
        " * PodsRepeaterNestedTest — serverseitige Ablehnung verschachtelter Repeater.",
    )

    # Plugin-Registry: im Kern heißt die Funktion ohne Prefix. Sie wird im
    # Test nur im Kopfkommentar als Fundstelle genannt (die Durchsetzung im
    # Kern prüft die Feldklasse selbst), der Name muss aber stimmen — sonst
    # verweist der Kommentar auf eine Funktion, die es im Kern nicht gibt.
    supported_from = "pods_repeater_supported_sub_types()"
    supported_to = "repeater_supported_sub_types()"
    if supported_from not in text:
        fail(f"Erwartete Fundstelle fehlt: {supported_from}")
    text = text.replace(supported_from, supported_to)

    # Bootstrap-Meldung: 'Pods/pods-repeater wurden ... aktiv' beschreibt das
    # Plugin-Setup. Im Kern wird Pods geladen; die Aussage bleibt, der Name
    # wechselt.
    bootstrap_message = (
        "'PodsField_Repeater nicht geladen — Pods/pods-repeater wurden im "
        "muplugins_loaded-Bootstrap nicht aktiv (siehe tests/bootstrap.php) "
        "oder die Feldklasse wurde umbenannt.'"
    )
    if bootstrap_message not in text:
        fail("Bootstrap-Meldung im set_up() nicht gefunden")
    text = text.replace(bootstrap_message, "'PodsField_Repeater nicht geladen — der Pods-Kern wurde nicht geladen (siehe tests/bootstrap.php) oder die Feldklasse wurde umbenannt.'")

    # Was der PR zusagt, muss im Test stehen bleiben.
    assertions = [
        "'Nested repeaters are not supported'",
        "assertStringContainsString( 'Innere Einträge', $result[0]",
        "assertStringNotContainsString( 'at most', $result[0]",
        "assertArrayNotHasKey( 'inner_items', $clean[0]",
        "assertSame( array( 'Zeile 1', 'Zeile 2' ), wp_list_pluck( $clean, 'title' ) )",
    ]
    for needle in assertions:
        if needle not in text:
            fail(f"Testaussage fehlt in der Portierung: {needle}")

    if re.search(r"\bRepeaterNestedTest\b", text):
        fail("Alter Klassenname RepeaterNestedTest steht noch in der Portierung")
    if "new PodsField_Repeater()" not in text:
        fail("Nested-Test instanziiert die Feldklasse PodsField_Repeater nicht")
    if re.search(r"\bpods_repeater_\w+", text):
        leftover = [
            line.strip()
            for line in text.splitlines()
            if re.search(r"\bpods_repeater_\w+", line)
        ]
        fail("Plugin-Funktion steht noch in der Portierung: " + " | ".join(leftover[:3]))

    return text


PORTERS = {
    "RepeaterLimitTest": port_limit,
    "RepeaterContractTest": port_contract,
    "CoreIntegrationTest": port_integration,
    "RepeaterNestedTest": port_nested,
}


def main() -> None:
    if len(sys.argv) != 3:
        fail("Aufruf: port_test.py <quelle> <ziel>")

    source_path = Path(sys.argv[1])
    target_path = Path(sys.argv[2])

    if not source_path.is_file():
        fail(f"Quelldatei fehlt: {source_path}")

    source_name = source_path.stem
    if source_name not in PORTERS:
        fail(
            f"Kein Porter für {source_name} — bekannte Quellen: "
            + ", ".join(sorted(PORTERS))
        )

    text = source_path.read_text(encoding="utf-8")
    text, _ = replace_domain(text, source_name)
    text = PORTERS[source_name](text)

    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(text, encoding="utf-8")

    test_count = len(re.findall(r"public function test_", text))
    print(f"test: {source_name} -> {target_path.name} ({test_count} Testmethoden)")


if __name__ == "__main__":
    main()
