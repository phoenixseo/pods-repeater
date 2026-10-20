#!/usr/bin/env python3
"""Zaehlt Textdomain-Literale in der portierten Feldklasse.

Beleg fuer die Zahl in CHECKLISTE.md ("35 Ersetzungen") — die Zahl soll
nachpruefbar sein, nicht behauptet.

Aufruf:
    python3 scripts/s20-i18n-count.py

Zaehlt drei Dinge getrennt, weil sie im Doku-Kontext unterschiedlich
gemeint sind:
  * i18n  — String-Literal 'pods' als Argument einer Uebersetzungsfunktion
  * kopf  — 'pods' in Kommentaren (Datei-Kopf nennt die Textdomain)
  * rest  — alle uebrigen 'pods'-Literale
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PKG = ROOT / "dist" / "pods-pr" / "classes" / "fields" / "repeater.php"
SRC = ROOT / "classes" / "fields" / "repeater.php"

I18N = re.compile(
    r"""(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)"""
    r"""\(\s*(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")"""
    r"""(?:\s*,\s*(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|\$[A-Za-z_]\w*|\d+))*"""
    r"""\s*,\s*'(?P<dom>[a-z0-9-]+)'\s*\)"""
)


def scan(path: Path) -> dict:
    if not path.exists():
        return {"error": f"{path} fehlt"}
    text = path.read_text(encoding="utf-8")
    lines = text.splitlines()

    doms = [m.group("dom") for m in I18N.finditer(text)]
    # Doppelzaehlung vermeiden: _e/esc_html_e haben nur ein Argument.
    # Der Ausdruck oben verlangt zwei Argumente, also greifen nur die
    # Funktionen mit Domain. _e('x','pods') wird korrekt erfasst.
    all_literals = sum(line.count("'pods'") for line in lines)
    all_wrong = sum(line.count("'pods-repeater'") for line in lines)
    in_comments = sum(
        line.count("'pods'")
        for line in lines
        if line.strip().startswith(("*", "//", "/*"))
    )
    return {
        "path": str(path.relative_to(ROOT)),
        "bytes": len(text.encode("utf-8")),
        "lines": len(lines),
        "i18n_domain_pods": len(doms),
        "literal_pods_gesamt": all_literals,
        "literal_pods_in_kommentaren": in_comments,
        "literal_pods_im_code": all_literals - in_comments,
        "i18n_aufrufe_gesamt": len(I18N.findall(text)),
        "literal_pods_repeater": all_wrong,
        "css_klassen_pods_repeater": text.count("pods-repeater-"),
    }


def main() -> int:
    print(f"{'Datei':52} {'i18n':>5} {'Lit':>5} {'Komm':>5} {'Code':>5} {'falsch':>7} {'CSS':>5}")
    for path in (SRC, PKG):
        r = scan(path)
        if "error" in r:
            print(f"{path}: {r['error']}", file=sys.stderr)
            return 1
        print(
            f"{r['path']:52} {r['i18n_domain_pods']:5} {r['literal_pods_gesamt']:5} "
            f"{r['literal_pods_in_kommentaren']:5} {r['literal_pods_im_code']:5} "
            f"{r['literal_pods_repeater']:7} {r['css_klassen_pods_repeater']:5}"
        )
        print(
            f"{'':52} i18n-Aufrufe gesamt: {r['i18n_aufrufe_gesamt']} "
            f"(davon Textdomain 'pods': {r['i18n_domain_pods']})"
        )
    print()
    print("Spalte 'falsch' = Literale 'pods-repeater' (im Paket muss das 0 sein).")
    print("Spalte 'CSS'    = Klassen mit Praefix 'pods-repeater-' (im Paket muss das 0 sein).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
