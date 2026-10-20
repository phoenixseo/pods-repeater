# Pods Repeater Field

Repeatable field groups for [Pods](https://pods.io) — the feature ACF calls a "Repeater", as a standalone field type.

Pods can repeat a *single* value. It cannot repeat a *group* of fields. This plugin adds a `repeater` field type: you define a set of sub-fields once, and the editor can add, remove and reorder rows of them.

**Status: `0.3.0-alpha`.** It works and it is tested, but it has not seen much use outside this repository. Read [Known limitations](#known-limitations) before you build on it.

## Why this exists

Repeatable field groups have been requested in Pods for years ([Issue #109](https://github.com/pods-framework/pods/issues/109), [Discussion #7238](https://github.com/pods-framework/pods/discussions/7238)) and are still not in core. This plugin implements the feature outside of core: usable now, adoptable into core later if the maintainers want it.

## Requirements

| | |
|---|---|
| WordPress | 6.8 or newer |
| PHP | 7.4 or newer |
| Pods | 3.3.9.1 or newer, active |
| License | GPL-2.0-or-later |

The test suite runs against the WordPress 6.8.2 test library on PHP 7.4 and PHP 8.3. The plugin itself is exercised on WordPress 7.1 with Pods 3.3.9.1.

## Install

Download the release zip and upload it under *Plugins → Add New → Upload Plugin*, or clone this repository into `wp-content/plugins/pods-repeater`.

## Quick start

1. **Define the sub-fields.** *Pods → Field Groups* (`wp-admin/admin.php?page=pods-repeater-groups`) — add a group, for example `track` with `nr` (number), `titel` (text), `laenge` (text), `stil` (text).
2. **Add the field.** Edit your Pod, add a field of type **Repeater**, and pick the field group you just created.
3. **Edit content.** The post editor renders a table: one row per entry, with *Add row*, *Remove* and up/down buttons. It saves as a normal form POST — no React, no build step, works with the classic editor and inside Pods' own forms.
4. **Read it in a template.**

```php
// Plain Pods API: an array of rows.
$tracks = get_post_meta( get_the_ID(), 'tracks', true ); // JSON string
$rows   = pods_repeater_get_rows( 'tracks', get_the_ID(), 'platte' );

// ACF-compatible loop (aliases can be disabled via the
// pods_repeater_register_acf_aliases filter):
if ( have_rows( 'tracks', get_the_ID() ) ) {
	while ( have_rows( 'tracks', get_the_ID() ) ) {
		the_row();
		echo esc_html( get_sub_field( 'nr' ) . ' — ' . get_sub_field( 'titel' ) );
	}
}
```

Sub-fields can also be defined inline as JSON in the field options (`repeater_sub_fields`) if you would rather not use the field group screen. The screen is the recommended path — it validates types and labels for you.

## Storage

Rows are stored as one JSON string in `wp_postmeta`, or in the Pods table column for table-based Pods. No extra tables, no custom schema, no serialized PHP.

## Which sub-field types work

```text
text, textarea, paragraph, wysiwyg, code,
number, website, email, password,
date, time, datetime, color, boolean, pick
```

`pick` covers select, radio and checkbox as long as the options are defined on the sub-field. The list is filterable via `pods_repeater_supported_sub_types`.

`file`, `avatar` and `oembed` are **not** supported inside a row — see below.

## Known limitations

- **No `file` / `avatar` / `oembed` sub-fields.** Those types render through Pods' React field editor, which does not initialise inside a cloned row: from row two on the picker would be dead. Put such a field on the Pod itself instead of inside the row.
- **No nested repeaters.** A repeater cannot contain another repeater. The server rejects the configuration and strips such values on write.
- **Field groups are managed in the admin only.** No WP-CLI, REST or import/export route for the field-group registry yet.
- **No ACF data migration.** Existing ACF Repeater data is not imported; this field stores its own JSON.
- **No block-editor UI.** The field renders server-side; Gutenberg and the REST API see the stored value.

## Development

```bash
npx wp-env start
npx wp-env run cli --env-cwd=wp-content/plugins/pods-repeater vendor/bin/phpunit --testsuite PodsRepeater
```

127 tests, 2455 assertions, green on PHP 7.4 and 8.3. The suite uses the WordPress PHPUnit test library (6.8.2) with Pods loaded from the running instance.

`scripts/build-release-package.sh` builds the release zip from an allowlist. `scripts/verify-pr-package.sh` checks the portable copy of the field class against the source, so the file meant for a core PR cannot drift from the plugin.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
