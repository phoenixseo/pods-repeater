# Checkliste vor dem Einreichen

Der Sprint stoppt hier bewusst: kein `git push`, kein `gh pr create`, keine
Nachricht an Dritte. Alles darunter ist eine Entscheidung des Auftraggebers.

## 0. Stand: 0.3.0-alpha — ausprobierbar, nicht einreichungsreif

**Zum Weitergeben gibt es jetzt ein fertiges Paket:**

- `dist/release/pods-repeater-0.3.0-alpha.zip` — installierbares
  WordPress-Plugin-Paket, baut sich per `bash scripts/build-release-package.sh`
  reproduzierbar neu (zwei Laeufe, identischer SHA-256).
- `dist/release/INSTALL.md` — Installation, Voraussetzungen, Erster Test in
  fuenf Schritten, bekannte Grenzen. Das ist die Anleitung, die mitgegeben
  wird; `readme.txt` im Zip bleibt die Kurzfassung fuer den Plugin-Screen.
- `dist/release/pods-repeater-0.3.0-alpha.manifest.txt` — die Dateiliste des
  Zips, **vom Build erzeugt**, nicht von Hand gepflegt. Die Release-Tests in
  `tests/PrPackageTest.php` pruefen dagegen.

Damit laesst sich die Frage „kann ich das jemandem geben" mit Ja beantworten,
ohne ein Repo anzulegen. Was diesem Paket **fehlt**, um als Alpha-Paket
durchzugehen, steht weiter unten — der Abschnitt 1 ist unveraendert offen.

Was gegenueber 0.2.1 im Paket steckt, steht in `readme.txt` unter
`= 0.3.0-alpha =`: verschachtelte Repeater werden serverseitig abgelehnt,
Kompatibilitaetsangaben korrigiert, Liste der unterstuetzten Unterfeld-Typen
korrigiert, bekannte Grenzen dokumentiert.

**Alpha heisst:** Testseite. Kein Upgrade-Pfad zwischen Alpha-Staenden, das
Datenformat kann sich noch bewegen.

## 1. Muss entschieden werden

- [ ] **Ueberhaupt einreichen?** Das Paket ist jetzt verteilbar; ob daraus
      ein PR an `pods-framework/pods` wird, ist eine eigene Entscheidung und
      nicht Voraussetzung fuer das Weitergeben.
- [ ] **Repo-Name und Link.** Der Platzhalter `<REPO-URL>` im
      Discussion-Kommentar muss durch das oeffentliche Repo ersetzt werden.
      Gibt es keins, faellt der Verweis weg — dann traegt der Kommentar nur
      die Beschreibung, und der PR braucht einen Fork.
- [ ] **Fork oder Branch.** Ziel ist `pods-framework/pods`. Der PR kommt aus
      einem Fork, weil das Arbeitsrepo kein Remote hat.
- [ ] **Reihenfolge: erst Kommentar, dann PR?** Empfehlung: erst der
      Kommentar in #7238, zwei bis drei Tage warten, dann der PR mit dem
      Verweis auf die Antwort. Ein PR ohne Vorlauf in einem alten Thread
      wird eher geschlossen als gelesen.
- [ ] **Screenshots beilegen?** Die Admin-UI ist visuell; ein Bild des
      Repeaters mit zwei Zeilen im Editor hilft mehr als drei Absaetze. Es
      liegen zwei passende Dateien aus dem E2E-Lauf vor, beide gitignored und
      nicht im Paket:
      `dist/e2e/gruppe-nach-neuladen.png` (Feldgruppen-Pfad, aus
      `node scripts/e2e-group-path.mjs`) und `dist/e2e/nach-neuladen.png`
      (Inline-JSON-Pfad, aus `node scripts/e2e-admin-ui.mjs`). Bei Bedarf vor
      dem PR neu erzeugen — der Lauf schreibt sie ohnehin neu.
- [ ] **Lizenzheader.** Feldklasse und Skript tragen den GPL-2.0-or-later-
      Header des Plugins. Vor dem PR gegen die Kopfzeilen bestehender
      Kern-Felddateien abgleichen (Jahr, Copyright-Inhaber).
- [ ] **Versionsnummer im Kern.** Dieses Paket traegt `0.3.0-alpha` als
      Plugin-Version und im Changelog-Eintrag `= 3.3.10 =` fuer Pods. Vor dem PR
      pruefen, welche Nummer der Kern als naechste fuehrt — die Zahl im
      Changelog-Block ist eine Annahme, nicht gemessen.
- [ ] **Ob die Fixes in der Versionshistorie oeffentlich auftauchen sollen.**
      Die Eintraege `= 0.2.1 =` und `= 0.3.0-alpha =` beschreiben unter
      anderem Fehler, die nie veroeffentlicht waren (0.2.0 war die letzte
      Fassung mit dem Defekt). Wer sie nicht als Bugfix in der Historie stehen
      haben will, streicht die betroffenen Zeilen aus `readme.txt` — die
      Versionsnummer bleibt davon unberuehrt.

## 2. Vor dem Absenden technisch pruefen

- [ ] `bash scripts/build-pr-package.sh && bash scripts/verify-pr-package.sh`
      — muss durchlaufen, Ausgabe ohne `FEHLER`.
- [ ] `bash scripts/build-release-package.sh` — baut das Alpha-Zip neu und
      schreibt das Manifest. Bricht ab, wenn Header, `PODS_REPEATER_VERSION`,
      `Stable tag` und der Changelog-Eintrag nicht dieselbe Version tragen.
      Vor dem Weitergeben **einmal bauen**, damit das Zip zum Arbeitsstand
      passt; das eingecheckte Zip ist der Stand des letzten Baus.
- [ ] Release-Tests gegen das gebaute Zip:
      `vendor/bin/phpunit --testsuite PodsRepeater --filter release`
      (im Container). Sie lesen Header und `Stable tag` aus dem Zip, nicht aus
      der Arbeitskopie — ein Zip, das nicht zum Repo passt, wird rot.
- [ ] `php -l` auf den vier portierten Testdateien und der Feldklasse
      (im Container, nicht auf dem Host).
- [ ] Testzahlen in `PR-BESCHREIBUNG.md` gegen den eigenen Suite-Lauf
      abgleichen (der Generator setzt sie beim Bauen ein).
- [ ] `grep -c 'pods-repeater' dist/pods-pr/classes/fields/repeater.php` —
      muss 0 sein (nur `pods-*`-CSS-Klassen duerfen bleiben, sie tragen
      keinen Domain-Suffix).
- [ ] Gruppenmarke pruefen: die portierten Fassungen von Vertrag,
      Kern-Integration und Nested-Ablehnung tragen `@group podscore`. Im
      Plugin-Repo laufen sie in der Standard-Suite mit; im Pods-Repo wuerde die
      Suite ohne die Marke rot, weil die Feldklasse dort erst mit dem PR
      existiert.
- [ ] Nested-Ablehnung gegenlesen: `tests/wpunit/PodsRepeaterNestedTest.php`
      haelt fest, dass `validate()` einen Sub-Typ `repeater` meldet (Meldung
      mit Sub-Feld-Label, vor Limit und `required`) und `pre_save()` die
      verschachtelten Werte verwirft. Die zwei Gegenproben (legitime Felder
      bleiben gueltig) gehoeren dazu — sie sind der Beleg, dass der Guard nicht
      zu breit greift.

## 3. Was im Paket bewusst fehlt

- React-DFV-Editor fuer die Sub-Feld-Konfiguration — Konfiguration ist
  zunaechst JSON bzw. eine Feldgruppe.
- Die Feldgruppen-Registry — Plugin-Konzept, im Kern nicht enthalten. Der
  Typ faellt per `function_exists()` sauber auf Inline-JSON zurueck (Test 15
  haelt den Zweig fest: bei fehlender Funktion kommt ein leerer Hinweis, kein
  Fatal).
- `uninstall.php` und `languages/` — Plugin-Haushalt (Opt-in-Cleanup zweier
  Optionen, POT-Datei). Im Kern deinstalliert man kein Feld, und die
  Strings laufen dort ueber die Kern-Textdomain `pods`. Aufschluesselung
  (gemessen mit `python3 scripts/s20-i18n-count.py`): 33 i18n-Aufrufe mit
  Domain `pods-repeater` in der Quelle, im Paket 33 mit Domain `pods`;
  35 Literale `pods-repeater` in der Quelle, 34 `pods`-Literale im Paket
  (eines davon im Datei-Kommentar), 0 verbleibende `pods-repeater`-Literale;
  dazu 13 CSS-Klassen `pods-repeater-*`, die als `pods-*` mitportiert werden.
  `grep -c` zaehlt hier Zeilen, nicht Aufrufe — 31 Zeilen mit 34 Literalen.

## 4. Nach dem Einreichen

- [ ] Antworten im Thread beobachten; die Bauform-Frage ist gestellt und
      braucht eine Antwort, bevor Review-Arbeit sinnvoll ist.
- [ ] Bei Aenderungswunschen: Testsuite bleibt der Massstab, keine
      Assertion abschwaechen, um Review-Kommentare zu erfuellen.
