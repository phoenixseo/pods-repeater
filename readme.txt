=== Pods Repeater Field ===
Contributors: phoenixseo
Tags: pods, repeater, repeatable, field, custom fields
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0-alpha
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

This plugin is under active development (v0.3.0-alpha).

= Requirements =

* WordPress 6.8+ (tested against 6.8.2 in the test suite and 7.1.3 in the demo instance)
* PHP 7.4+ (test suite passes on 7.4.33 and 8.3.33)
* Pods 3.x (tested with 3.3.9.1), active

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

Supported sub-field types are the ones in
`pods_repeater_supported_sub_types()`: `text`, `textarea`, `paragraph`, `number`,
`website`, `email`, `password`, `wysiwyg`, `code`, `date`, `time`, `datetime`,
`color`, `boolean` and `pick`. The list is filterable through
`pods_repeater_supported_sub_types`, so a custom type can be added — but it has
to render without Pods' React data-flow view (DFV), or the row clone leaves it
inert. For that reason `file`, `avatar` and `oembed` are not in the list; see
*Known Limitations* below for the alternatives.

Two names differ from what other page builders call them: Pods uses
**`website`** where ACF uses `url`, and **`paragraph`** where ACF uses
`textarea`. There is no `url` or `textarea` field type in Pods 3.x — use the
core names above (`textarea` is accepted here as an alias that renders through
Pods' own textarea template).

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

= Can I use a file/image field inside a repeater? =

Not as a sub-field of the row. `file`, `avatar` and `oembed` are the Pods field types that render through the React data-flow view (DFV), and the repeater does not support DFV-backed sub-fields — saving a field group that contains one is rejected with `unsupported type "file"`. Use a regular Pod field next to the repeater, or store an attachment ID per row in a `number` / `text` sub-field. Details and the working alternative are in *Known Limitations* below.

== Known Limitations ==

These are the edges a new user usually hits first. Each one is a deliberate scope decision, not an open bug — the code refuses these cases instead of half-supporting them.

= `file`, `avatar` and `oembed` are not supported sub-field types =

The whitelist in `pods_repeater_supported_sub_types()` (`includes/field-groups.php`) has no entry for `file`, `avatar` or `oembed`. Saving a field group that declares one is rejected with a `pods_repeater_invalid_type` error: `Field "cover" uses the unsupported type "file".`

The reason is the renderer, not the storage. Those three types render through Pods' React data-flow view and put their field name only into the `pods-dfv-field-data` JSON blob, not into a `name="..."` attribute a form POST can read. The repeater builds a new row by cloning the first one (`template.cloneNode(true)`, `ui/js/repeater.js`); the clone carries the same `data-item-id` / `form-counter` as the original, and Pods' React editor does not initialise a second instance from it. From row 2 on the file picker would be dead markup. Refusing the type up front is the honest behaviour.

Two ways that work:

* Put the file / image field on the Pod as a **normal field next to the repeater**, not inside the row. Use it as a fallback or a shared value.
* Store the **attachment ID per row** in a `number` or `text` sub-field and resolve it in your template with `wp_get_attachment_image( $row['image_id'], 'large' )`.

= Repeaters do not nest =

A repeater cannot contain another repeater as a sub-field. This is enforced server-side, not only in the UI: a sub-field configuration with a `repeater` entry is rejected with an error that names the offending sub-field, and `pre_save()` drops the values of such sub-fields so a save through the API or an import cannot smuggle one in. Model deeper structures as a second repeater field on the Pod, or store a nested JSON array in a `code` / `text` sub-field and decode it yourself.

= Field groups are managed in the admin only =

Reusable field groups (the `repeater_group` registry) are created and edited under **Pods → Field Groups**. There is no REST route, no WP-CLI command and no `pods_api()` call that creates or edits a group, and there is no export/import. The saving function `pods_repeater_save_group()` is a plain PHP function — it is the code path the admin screen uses, but it is not exposed as an endpoint. On a site where the admin is not available, define the sub-fields inline as the field's *Sub-Fields (JSON)* option instead of a shared group.

= No ACF data migration =

Existing ACF repeater data is not imported. The field stores its own JSON format (`[{"subtitle":"…","link":"…"}]`) under its own meta key, so an ACF repeater and a Pods repeater field of the same name do not share values. What the plugin does provide is ACF-compatible *reading*: `have_rows()`, `the_row()` and `get_sub_field()` are available as aliases, so an ACF-style template keeps working once the data lives in a repeater field. Moving data across is a manual step.

= No block editor or REST editor UI =

The admin UI is server-rendered PHP plus plain JavaScript, deliberately, so the plugin needs no build pipeline and no external assets. There is no block registration and no custom REST field handling: in Gutenberg or through the REST API the field appears as its raw JSON value, not as the row editor. Edit repeaters in the classic Pods field UI.

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

= 0.3.0-alpha =
This is an alpha build: install it on a test site, not on a site you depend on. There is no upgrade path guarantee between alpha builds, and the data format may still move.

* Repeaters no longer nest. A sub-field of type `repeater` is now refused on save, not only hidden in the editor: the save returns an error naming the sub-field, and writes through the API, an import or a REST call drop the nested value instead of storing a structure the frontend cannot read. A field configuration without a nested repeater is unaffected.
* Compatibility statements now match what was measured. WordPress 6.8 is the stated minimum (the test suite runs against 6.8.2 and the code uses no newer API), PHP 7.4 is the minimum (the suite passes on 7.4.33 and 8.3.33), and the requirements section no longer advertises a newer WordPress than the plugin needs. The plugin header and this readme carry the same numbers, and a test keeps them in sync.
* The list of supported sub-field types was corrected. It advertised `file`, `avatar`, `oembed`, `phone`, `currency` and `slug`, none of which this plugin accepts; it now names the types that actually work, with the Pods naming (`website`, `paragraph`) rather than the ACF naming.
* Known limitations are documented. A new section names the five edges a new user hits first — no `file` / `avatar` / `oembed` sub-fields, no nested repeaters, field groups only through the admin, no migration of existing ACF repeater data, no block-editor or REST editor UI — and gives the working alternative for each. The FAQ answers the file-field question that comes up first in support.

= 0.2.1 =
* Fixed: the field groups admin form posted to `admin.php` instead of `admin-post.php`, so saving a group was silently discarded. Success notices were also invisible in the form, and an empty group slug was dropped from the redirect URL.
* `uninstall.php` added: uninstalling keeps the field groups by default; the constant `PODS_REPEATER_REMOVE_DATA_ON_UNINSTALL` removes them on request. Post meta is never touched. See the Uninstall section.
* `languages/pods-repeater.pot` generated with `wp i18n make-pot` (69 strings), so the `Domain Path` header points at a real folder.
* Requirements now carry the evidence, not an estimate: `Requires at least: 6.8` because the whole suite runs against the wp-phpunit test library for WordPress 6.8.2 and the code itself uses no API newer than 6.8. `Tested up to: 7.1` because the demo instance on WordPress 7.1.3 with Pods 3.3.9.1 has the field type registered, field groups, admin UI and frontend exercised end to end. Both `readme.txt` and the plugin header carry the same numbers, and a test now keeps them in sync.
* `Requires PHP: 7.4` is measured under both ends of the range: the suite passes on PHP 7.4.33 and on PHP 8.3.33 against the same WordPress 6.8.2 core. No PHP-8-only syntax is used.
* Added a **Known Limitations** section naming the five edges a new user hits first — no `file` / `avatar` / `oembed` sub-fields, no nested repeaters, field groups via the admin only, no ACF data migration, no block/REST editor UI — each with the working alternative. The FAQ answers the file-field question that comes up first in support.
* Corrected the list of supported sub-field types: it advertised `file`, `avatar`, `oembed`, `phone`, `currency` and `slug`, none of which are in the whitelist. It now matches `pods_repeater_supported_sub_types()`.

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
