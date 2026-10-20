# Pods Repeater Field — 0.3.0-alpha

Ein Alpha-Paket zum Ausprobieren. **Auf einer Testseite installieren, nicht auf
einer Seite, die du brauchst.** Zwischen Alpha-Ständen gibt es keinen
garantierten Upgrade-Pfad, und das Datenformat kann sich noch bewegen.

Paket: `pods-repeater-0.3.0-alpha.zip`

## Voraussetzungen

| Was | Wert |
| --- | --- |
| WordPress | 6.8 oder neuer (getestet gegen 6.8.2 und 7.1.3) |
| PHP | 7.4 oder neuer (getestet auf 7.4.33 und 8.3.33) |
| Pods | 3.x, **muss aktiv sein** (getestet mit 3.3.9.1) |

Ohne aktives Pods erscheint der Feldtyp nicht im Feld-Editor. Das Plugin bricht
in dem Fall nicht ab, es tut nur nichts.

Diese Angaben stehen auch in `readme.txt` und im Plugin-Header. Ein Test hält
alle drei Stellen zusammen.

## Installation

Das Zip ist ein normales WordPress-Plugin-Paket. Es enthält einen Ordner
`pods-repeater/`; WordPress entpackt ihn nach `wp-content/plugins/`.

**Variante A — über die Oberfläche (empfohlen):**

1. Als Administrator anmelden. Bei einer Multisite im **Netzwerk-Admin**
   (`/wp-admin/network/`), nicht in der Verwaltung einer einzelnen Seite — nur
   dort ist „Hochladen" für Plugins verfügbar.
2. **Plugins → Installieren → Plugin hochladen**.
3. `pods-repeater-0.3.0-alpha.zip` wählen und **Jetzt installieren** klicken.
4. **Plugin aktivieren**.
5. Prüfen: Unter **Plugins** steht „Pods Repeater Field" mit der Version
   `0.3.0-alpha`. Steht dort eine andere Version, wurde ein älteres Zip
   installiert — das Paket noch einmal hochladen.

**Variante B — per Dateisystem:**

1. Zip entpacken.
2. Den Ordner `pods-repeater/` nach `wp-content/plugins/` kopieren.
3. Im Plugin-Screen aktivieren.

Bei einer Multisite: nach der Aktivierung im Netzwerk-Admin das Plugin für die
Seiten freigeben, auf denen du testen willst (**Einstellungen → Plugins** in der
Seitenverwaltung, oder netzwerkweit aktivieren).

## Erster Test in fünf Schritten

Ziel: ein Repeater mit zwei Unterfeldern, ein Wert gespeichert, ein Wert im
Template gelesen. Wenn das läuft, ist die Installation in Ordnung.

**1. Pod anlegen**

**Pods Admin → Add New** → Pod-Name z. B. `buecher`, Typ „Custom Post Type".
Speichern.

**2. Feld vom Typ Repeater hinzufügen**

Im Feld-Editor des Pods **Add Field**:

- Label: `Kapitel`
- Name: `kapitel` (wird vorgeschlagen)
- Feld-Typ: **Repeater (Repeatable Group)**

Speichern. Der Typ erscheint erst, wenn dieses Plugin aktiv ist.

**3. Unterfelder definieren**

Im Feld `kapitel` die Option **Sub-Fields (JSON)** ausfüllen:

```
[
  { "name": "titel", "label": "Titel", "type": "text", "required": true },
  { "name": "seiten", "label": "Seiten", "type": "number" }
]
```

Speichern. Erlaubt sind nur die Typen aus der Whitelist — `text`, `textarea`,
`paragraph`, `number`, `website`, `email`, `password`, `wysiwyg`, `code`,
`date`, `time`, `datetime`, `color`, `boolean`, `pick`. Ein `file`-Unterfeld
wird mit einer Fehlermeldung abgelehnt, nicht still ignoriert; siehe
*Known Limitations* unten.

**4. Beitrag speichern**

Einen Beitrag des neuen Typs anlegen. Der Repeater erscheint als Feldgruppe mit
**+ Zeile hinzufügen**. Zwei Zeilen anlegen, ausfüllen (die Pflichtzeile
`titel` nicht leer lassen), Beitrag speichern, Seite neu laden. Die Zeilen
müssen mit ihren Werten wieder da sein. Verschwinden sie beim Neuladen, ist
etwas an der Speicherung kaputt — dann stimmt die Pods-Version nicht mit der
getesteten überein.

**5. Im Template lesen**

Im Theme (oder in einem Shortcode / Block-Template) über die Pods-API:

```php
$kapitel = pods_field( 'buecher', get_the_ID(), 'kapitel' );

if ( is_array( $kapitel ) ) {
    foreach ( $kapitel as $zeile ) {
        echo esc_html( $zeile['titel'] );
        echo ' — ';
        echo (int) $zeile['seiten'];
        echo '<br>';
    }
}
```

`pods_field()` gibt ein PHP-Array zurück, ein Element pro Zeile, die
Unterfelder als Schlüssel. Kommt `null` statt eines Arrays, ist die Feldgruppe
leer gespeichert.

Alternativ funktioniert die ACF-Lese-API: `have_rows( 'kapitel', get_the_ID() )`,
`the_row()`, `get_sub_field( 'titel' )`. Das ist Lesekompatibilität, kein
Datenimport aus ACF.

## Bekannte Grenzen

Kein Fehler, sondern bewusster Zuschnitt — der Code lehnt diese Fälle ab,
statt sie halb zu unterstützen. Die vollständige Liste mit Begründung und
Ausweichlösung steht in `readme.txt` unter **Known Limitations**:

- `file`, `avatar` und `oembed` sind keine gültigen Unterfeld-Typen.
- Repeater lassen sich nicht verschachteln (serverseitig abgelehnt).
- Feldgruppen (wiederverwendbare Unterfeld-Sets) entstehen nur im Admin; es
  gibt keinen REST-Weg, kein WP-CLI-Kommando und keinen Export/Import.
- Bestehende ACF-Repeater-Daten werden nicht importiert.
- Kein Block-Editor- und kein REST-Editor-UI: in Gutenberg erscheint der Wert
  als roher JSON-Text, bearbeitet wird in der klassischen Pods-Feld-Oberfläche.

## Deinstallation

Deaktivieren ändert nichts. Löschen entfernt standardmäßig **keine Daten**:
Beitrags-Meta bleibt unangetastet (das gehört Pods, nicht diesem Plugin), und
die Feldgruppen bleiben in ihrer Option stehen. Wer sie mitlöschen will, setzt
in `wp-config.php`:

```php
define( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL', true );
```

und löscht danach das Plugin. Details in `readme.txt` unter **Uninstall**.

## Wenn etwas nicht geht

- **Feldtyp erscheint nicht im Feld-Editor:** Pods nicht aktiv, oder das Plugin
  wurde im Netzwerk-Admin installiert, aber für diese Seite nicht freigegeben.
- **Unterfelder werden mit `unsupported type` abgelehnt:** ein Typ außerhalb
  der Whitelist, meistens `file` oder `url` statt `website`.
- **Zeilen sind nach dem Neuladen weg:** Pods-Version prüfen (dieses Paket ist
  gegen 3.3.9.1 getestet).
- **Im Beitrag erscheint roher JSON-Text:** Block-Editor statt der klassischen
  Pods-Oberfläche — siehe *Known Limitations*.

Fehlerberichte mit WordPress-Version, Pods-Version und PHP-Version sind
brauchbar; ohne diese drei Angaben lässt sich fast nichts einordnen.

## Was in diesem Paket nicht enthalten ist

`vendor/` (keine Composer-Abhängigkeiten zur Laufzeit), `tests/`, `scripts/`,
`assets/` und der Build selbst. Das Paket ist das, was WordPress braucht, und
sonst nichts.

Wer das Paket selbst baut (aus dem Arbeitsverzeichnis, nicht von hier):

```
bash scripts/build-release-package.sh
```

Das Skript prüft vorher, ob Version im Header, Konstante, `Stable tag` und
Changelog übereinstimmen, und bricht ab, wenn nicht.
