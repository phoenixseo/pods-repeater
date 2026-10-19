#!/usr/bin/env bash
#
# verify-pr-package.sh — Reproduzierbarkeitstest fuer das PR-Paket.
#
# Baut dist/pods-pr zweimal unabhaengig (zwei getrennte Ziele unter einem
# mktemp -d), vergleicht die sha256summen aller Dateien und meldet jede
# Abweichung namentlich. Die echte Ausgabe dist/ wird NICHT angefasst.
#
#   bash scripts/verify-pr-package.sh      # Exit 0 = identisch, 1 = Abweichung
#
# Geprueft wird:
#   1. beide Laeufe erfolgreich
#   2. gleiche Dateiliste (relativ, sortiert)
#   3. gleiche Inhalte je Datei
#   4. gleiche Dateirechte je Datei (Executable-Bit ist Teil der Ausgabe)
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
BUILD="${SCRIPT_DIR}/build-pr-package.sh"

DIE() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

[[ -f "${BUILD}" ]] || DIE "build-pr-package.sh fehlt: ${BUILD}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/verify-pr-package.XXXXXX")" || DIE "mktemp -d fehlgeschlagen"
cleanup() { rm -rf "${WORK}"; }
trap cleanup EXIT

RUN1="${WORK}/lauf1"
RUN2="${WORK}/lauf2"

# Manifest: "<sha256>  <pfad>" + Rechte, sortiert nach Pfad.
manifest() {
  local dir="$1"
  (
    cd "${dir}" || exit 1
    find . -type f -print0 \
      | sort -z \
      | while IFS= read -r -d '' f; do
          printf '%s %s %s\n' \
            "$(sha256sum "$f" | cut -d' ' -f1)" "$(stat -c '%a' "$f")" "${f#./}"
        done
  )
}

for run in 1 2; do
  target="${WORK}/lauf${run}"
  mkdir -p "${target}"
  printf 'Lauf %s: baue nach %s\n' "${run}" "${target}"
  if ! PR_OUT_DIR="${target}" bash "${BUILD}" >"${WORK}/build${run}.log" 2>&1; then
    sed 's/^/  /' "${WORK}/build${run}.log" >&2
    DIE "Build in Lauf ${run} fehlgeschlagen (Log oben)"
  fi
done

# Leerer Build waere ein trivialer "Erfolg" — das ist kein Nachweis.
for run in 1 2; do
  count="$(find "${WORK}/lauf${run}" -type f | wc -l | tr -d ' ')"
  [[ "${count}" -gt 0 ]] || DIE "Lauf ${run} hat keine Dateien erzeugt"
done

manifest "${RUN1}" >"${WORK}/manifest1"
manifest "${RUN2}" >"${WORK}/manifest2"

if diff -u "${WORK}/manifest1" "${WORK}/manifest2" >"${WORK}/diff.txt"; then
  files="$(wc -l <"${WORK}/manifest1" | tr -d ' ')"
  printf 'OK: PR-Paket reproduzierbar — %s Dateien, zwei Laeufe identisch\n' "${files}"
  while IFS= read -r line; do
    printf '  %s  %s\n' "$(printf '%s' "${line}" | cut -d' ' -f1)" \
      "$(printf '%s' "${line}" | cut -d' ' -f3)"
  done <"${WORK}/manifest1"
  exit 0
fi

# Abweichungen namentlich benennen: Pfade, die nur in einem Lauf vorkommen,
# plus Pfade mit abweichender Pruefsumme oder abweichenden Rechten.
declare -A H1=() H2=()
while read -r sum mode path; do H1["${path}"]="${sum} ${mode}"; done <"${WORK}/manifest1"
while read -r sum mode path; do H2["${path}"]="${sum} ${mode}"; done <"${WORK}/manifest2"

echo "FEHLER: PR-Paket nicht reproduzierbar" >&2
only1=0
only2=0
differ=0
while IFS= read -r path; do
  [[ -n "${path}" ]] || continue
  in1=0; in2=0
  [[ -n "${H1[${path}]:-}" ]] && in1=1
  [[ -n "${H2[${path}]:-}" ]] && in2=1
  if [[ ${in1} -eq 1 && ${in2} -eq 0 ]]; then
    echo "  nur in Lauf 1: ${path}" >&2; only1=$((only1 + 1))
  elif [[ ${in1} -eq 0 && ${in2} -eq 1 ]]; then
    echo "  nur in Lauf 2: ${path}" >&2; only2=$((only2 + 1))
  elif [[ ${H1[${path}]} != "${H2[${path}]}" ]]; then
    echo "  abweichend:    ${path}" >&2
    echo "    Lauf 1: ${H1[${path}]}" >&2
    echo "    Lauf 2: ${H2[${path}]}" >&2
    differ=$((differ + 1))
  fi
done < <(printf '%s\n' "${!H1[@]}" "${!H2[@]}" | sort -u)

printf '  Summe: %s nur Lauf 1, %s nur Lauf 2, %s abweichend\n' \
  "${only1}" "${only2}" "${differ}" >&2
printf '  Diff (Manifest):\n' >&2
sed 's/^/    /' "${WORK}/diff.txt" >&2
exit 1
