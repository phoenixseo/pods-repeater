"""S19-Rotbeleg: weist nach, dass der Porter-Fix tatsaechlich greift.

Drei Mutationen, jede muss den Build rot machen. Danach wird die Datei aus
dem Backup byte-gleich zurueckgeschrieben und die Summe geprueft.

  python3 scripts/s19-porter-mutations.py
"""

from __future__ import annotations

import hashlib
import pathlib
import shutil
import subprocess
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TARGET = REPO / "scripts" / "port_field.py"
BACKUP = REPO / "scripts" / ".port_field.py.s19bak"


def sha(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build_red(label: str, mutation) -> bool:
    """Mutation anwenden, bauen, zuruecksetzen. True = Build war rot."""
    original = TARGET.read_text()
    mutated = mutation(original)
    if mutated == original:
        print(f"  {label}: Mutation hat nichts geaendert — Testaufbau kaputt")
        return False
    TARGET.write_text(mutated)
    try:
        proc = subprocess.run(
            ["bash", str(REPO / "scripts" / "build-pr-package.sh")],
            capture_output=True,
            text=True,
            cwd=REPO,
        )
    finally:
        TARGET.write_text(original)
    red = proc.returncode != 0
    status = "rot" if red else "GRUEN (Befund!)"
    tail = ""
    for line in (proc.stdout + proc.stderr).splitlines():
        if line.startswith("FEHLER"):
            tail = line
            break
    print(f"  {label}: {status} (exit {proc.returncode}){' — ' + tail if tail else ''}")
    return red


def m1_drop_replacement(text: str) -> str:
    """Die Praefix-Ersetzung faellt weg: Guard bleibt auf pods_repeater_*."""
    marker = "        text = text.replace(old, new)\n"
    assert text.count(marker) == 1, text.count(marker)
    return text.replace(marker, "        pass  # MUTATION\n")


def m2_unguarded_call(text: str) -> str:
    """Zusaetzlicher ungeprufter Registry-Aufruf muss auffallen."""
    marker = "    # --- 6. Invarianten fuer die Pods-Konvention ----------------------\n"
    assert marker in text
    return text.replace(
        marker,
        "$x = repeater_get_groups();\n" + marker,
        1,
    )


def m3_reintroduce_prefix(text: str) -> str:
    """Ein altes Praefix wieder einschleusen: Nachpruefung muss anschlagen."""
    marker = "    # --- 6. Invarianten fuer die Pods-Konvention ----------------------\n"
    assert marker in text
    return text.replace(
        marker,
        "$y = pods_repeater_helper();\n" + marker,
        1,
    )


def main() -> int:
    if not TARGET.is_file():
        print(f"FEHLT: {TARGET}")
        return 2

    shutil.copy2(TARGET, BACKUP)
    before = sha(TARGET)

    results = [
        ("M1 Praefix-Ersetzung ausgebaut", m1_drop_replacement),
        ("M2 ungeprueter Registry-Aufruf", m2_unguarded_call),
        ("M3 altes Praefix wieder eingeschleust", m3_reintroduce_prefix),
    ]

    ok = True
    for label, mutation in results:
        if not build_red(label, mutation):
            ok = False

    TARGET.write_text(BACKUP.read_text())
    BACKUP.unlink()
    after = sha(TARGET)
    print(f"\nSHA256 vorher:  {before}")
    print(f"SHA256 nachher: {after}")
    if before != after:
        print("FEHLER: Datei nicht byte-gleich zurueckgeschrieben")
        return 1
    print("Datei byte-gleich wiederhergestellt.")

    if not ok:
        print("FEHLER: mindestens eine Mutation blieb gruen")
        return 1
    print("OK: alle drei Mutationen rot")
    return 0


if __name__ == "__main__":
    sys.exit(main())
