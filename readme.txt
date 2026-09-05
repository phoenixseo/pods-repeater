=== Pods Repeater Field ===
Contributors: phoenixseo
Tags: pods, repeater, repeatable, field, custom fields
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Repeatable field groups (ACF-Repeater equivalent) for Pods — a configurable set of sub-fields that repeats.

== Description ==

Adds a **`repeater`** field type to [Pods](https://pods.io): a group of scalar sub-fields that repeats, stored as JSON. This is the repeatable-field-group feature Pods has been missing for years ([discussion #7238](https://github.com/pods-framework/pods/discussions/7238), [issue #109](https://github.com/pods-framework/pods/issues/109)).

= Features =

* **Repeatable field groups** — define a set of sub-fields (text, textarea, select, number, url) and repeat it any number of times.
* **Server-rendered admin UI** — add / remove / move rows, no React/DFV build pipeline, no external assets.
* **JSON storage** — clean LONGTEXT / meta value, lossless round-trip, works on meta- and table-based pods.
* **`disable_dfv` aware** — uses the PHP `input()` renderer by default (required for custom field types without a DFV component).
* **Built for the community** — generic field type; the SDC Page Builder is one consumer among many.
* **i18n-ready** — all strings translatable, text domain `pods-repeater`.
* **Required sub-field validation** — per-row, empty rows never block saving.

This plugin is under active development (v0.1.0). See the [GitHub repository](https://github.com/phoenixseo/pods-repeater) for the roadmap and source.

= Requirements =

* WordPress 6.4+
* PHP 7.4+
* Pods 3.x (tested with 3.3.9)

== Installation ==

1. Upload the `pods-repeater` folder to `/wp-content/plugins/`, or install the plugin through the WordPress *Plugins* screen.
2. Activate the plugin through the *Plugins* screen. Pods must be active.
3. In a Pod's field editor, add a new field of type **Repeater (Repeatable Group)**.
4. Configure the sub-fields as a JSON array in the *Sub-Fields (JSON)* option (see below), and save.
5. Add or edit an item — the repeater renders with add / remove / move controls.

= Sub-field configuration =

Sub-fields are defined as a JSON array in the field's *Sub-Fields (JSON)* option:

```
[
  { "name": "subtitle", "label": "Subtitle", "type": "text", "required": true },
  { "name": "link", "label": "Link", "type": "url" },
  { "name": "amount", "label": "Amount", "type": "number" }
]
```

Supported types: `text`, `textarea`, `select`, `number`, `url`. Use the field *name* to read values: `pods_field( 'my_pod', item_id, 'repeater_field' )` returns an array of rows.

== Frequently Asked Questions ==

= Does this work with the Pods DFV (React) field UI? =

The repeater registers `disable_dfv` (default **true**), so it renders through the server-side PHP `input()` renderer. DFV has no component for custom field types — this is the documented Pods path for custom types.

= Can I use it on table-based pods? =

Yes. The field reports a LONGTEXT schema for table storage; meta-based pods store the JSON as a meta value.

= Does an empty row block saving? =

No. Completely empty rows are discarded on save (`pre_save`); only rows with at least one value count. Values like `0` are treated as filled.

= Where is the value stored? =

As a JSON string (for example `[{"subtitle":"Erste Zeile","link":"https://example.com/1"}]`) under the field's meta key, and decoded back to an array when read through Pods.

== Changelog ==

= 0.1.0 =
* Initial release (development milestone).
* Repeater field type registration (Pods API, no core fork).
* Sub-fields (JSON option): text, textarea, select, number, url.
* Server-side admin UI with add / remove / move rows (vanilla JS).
* JSON round-trip storage (LONGTEXT / meta), lossless, unicode-safe.
* Empty-row discard on save, per-row required validation.
* `disable_dfv` default on; options `repeater_limit` (max rows, 0 = unlimited).
* Text-domain loading, WP 6.7-compatible (init hook).

== Upgrade Notice ==

= 0.1.0 =
Initial release — development version.
