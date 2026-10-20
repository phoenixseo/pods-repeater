=== Pods Repeater Field ===
Contributors: phoenixseo
Tags: pods, repeater, repeatable, field, custom fields
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Repeatable field groups (ACF-Repeater equivalent) for Pods — a configurable set of sub-fields that repeats.

== Description ==

Adds a **`repeater`** field type to [Pods](https://pods.io): a group of scalar sub-fields that repeats, stored as JSON. This is the repeatable-field-group feature Pods has been missing for years ([discussion #7238](https://github.com/pods-framework/pods/discussions/7238), [issue #109](https://github.com/pods-framework/pods/issues/109)).

= Features =

* **Repeatable field groups** — define a set of sub-fields (`text`, `paragraph`, `number`, `website`, `pick`, …) and repeat it any number of times.
* **Server-rendered admin UI** — add / remove / move rows, no React/DFV build pipeline, no external assets.
* **JSON storage** — clean LONGTEXT / meta value, lossless round-trip, works on meta- and table-based pods.
* **`disable_dfv` aware** — uses the PHP `input()` renderer by default (required for custom field types without a DFV component).
* **Field group registry** — reusable sub-field sets (`repeater_group`), plus a read API (`pods_repeater_get_groups()`, `pods_repeater_get_group_fields()`).
* **Server-side row limit** — `repeater_limit` is enforced in `validate()` and `pre_save()`, not only in the browser.
* **Pods display formats** — `repeater_display_format` (`default` / `br` / `ul` / `ol` / `custom`) plus separator, following the `pick` field convention.
* **Built for the community** — generic field type; the SDC Page Builder is one consumer among many.
* **i18n-ready** — all strings translatable, text domain `pods-repeater`.
* **Required sub-field validation** — per-row, empty rows never block saving.

This plugin is under active development (v0.2.1). See the [GitHub repository](https://github.com/phoenixseo/pods-repeater) for the roadmap and source.

= Requirements =

* WordPress 7.1+ (tested on 7.1.3; earlier versions untested)
* PHP 7.4+ (test suite passes on 7.4.33 and 8.3.33)
* Pods 3.x (tested with 3.3.9.1)

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
  { "name": "link", "label": "Link", "type": "website" },
  { "name": "amount", "label": "Amount", "type": "number" }
]
```

Supported types are the Pods core field types, passed straight through to
`PodsForm::field_method()`: `text`, `paragraph`, `number`, `website`, `phone`,
`email`, `password`, `wysiwyg`, `code`, `date`, `time`, `datetime`, `currency`,
`file`, `avatar`, `oembed`, `pick`, `boolean`, `color`, `slug`, plus the
display-only types `heading` and `html`.

Two names differ from what other page builders call them: Pods uses
**`website`** where ACF uses `url`, and **`paragraph`** where ACF uses
`textarea`. There is no `url` or `textarea` field type in Pods 3.x — use the
core names above.

Use the field *name* to read values: `pods_field( 'my_pod', item_id, 'repeater_field' )` returns an array of rows.

== Frequently Asked Questions ==

= Does this work with the Pods DFV (React) field UI? =

The repeater registers `disable_dfv` (default **true**), so it renders through the server-side PHP `input()` renderer. DFV has no component for custom field types — this is the documented Pods path for custom types.

= Can I use it on table-based pods? =

Yes. The field reports a LONGTEXT schema for table storage; meta-based pods store the JSON as a meta value.

= Does an empty row block saving? =

No. Completely empty rows are discarded on save (`pre_save`); only rows with at least one value count. Values like `0` are treated as filled.

= Where is the value stored? =

As a JSON string (for example `[{"subtitle":"Erste Zeile","link":"https://example.com/1"}]`) under the field's meta key, and decoded back to an array when read through Pods.

== Uninstall ==

Uninstalling (deleting, not deactivating) the plugin **deletes nothing by default** — with one exception: the plugin's own translation file is part of the plugin folder and goes with it, as with any plugin.

Two options are kept by a default uninstall:

* `pods_repeater_groups` — your reusable field groups (slug, label, ordered sub-field list).
* `pods_repeater_groups_version` — the schema version of that registry.

That is deliberate. Removing the plugin to try a rollback, or to rule it out while debugging, must not destroy the field groups you built. Reinstalling picks them up again.

= Post meta is never touched =

Repeater values are stored as post meta under field names **you** defined in your Pods. A plugin uninstall must not delete content it does not own, so `uninstall.php` does not query, and never deletes, post meta — not even of the repeater fields themselves. Deleting entries or their values stays your call, in the editor or through Pods.

= Deleting the options on purpose =

If you do want the field groups gone, add this line to `wp-config.php` **before** deleting the plugin:

```php
define( 'PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL', true );
```

With that constant set, uninstalling deletes both options above. Nothing else changes: post meta stays untouched.

There is deliberately no checkbox in the admin screen for this. An admin toggle would only be reachable while the plugin is still active — at the moment you delete it, that screen is already gone. `wp-config.php` is reachable at the time it matters.

= What a default uninstall leaves behind =

Nothing but those two options. The plugin creates no database tables, no custom post types, no user meta and no cron events, so there is nothing else to clean up. If you skipped the constant and want a clean slate, delete the two options manually (for example with `wp option delete pods_repeater_groups pods_repeater_groups_version`).

== Changelog ==

= 0.2.1 =
* `uninstall.php` added: uninstalling keeps the field groups by default; the constant `PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL` removes them on request. Post meta is never touched. See the Uninstall section.
* `languages/pods-repeater.pot` generated with `wp i18n make-pot` (69 strings), so the `Domain Path` header points at a real folder.
* Requirements corrected to what was actually measured: WordPress 7.1 (tested on 7.1.3) instead of an untested 6.4, `Tested up to: 7.1` instead of 6.8.
* PHP 7.4 support is now proven, not assumed: all 90 plugin PHP files pass `php -l` on PHP 7.4.33 and the full test suite passes under PHP 7.4.33 (104 tests, 2203 assertions). No PHP-8-only syntax or WordPress API newer than 6.4 is used.

= 0.2.0 =
* Field group registry: reusable sub-field sets (`repeater_group`), plus the read API `pods_repeater_get_groups()` and `pods_repeater_get_group_fields()`.
* Server-side row limit: `repeater_limit` is now enforced in `validate()` as well as `pre_save()`, so imports and direct API writes cannot exceed it.
* `validate()` returns an array of messages (the Pods contract) instead of `WP_Error` — saves through `PodsAPI::save_pod_item()` no longer abort.
* Corrected documentation: the supported sub-field types are the Pods core types (`website`, `paragraph`), not `url` / `textarea`, which do not exist in Pods 3.x.
* Test suite expanded: field contract (validate / save / value / display / options), core integration (registry, convention path, save contract) and the limit invariant.

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

= 0.2.0 =
Adds the field group registry and enforces the row limit server-side.

= 0.1.0 =
Initial release — development version.
