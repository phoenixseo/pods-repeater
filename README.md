# Pods Repeater Field

Repeatable field groups (ACF-Repeater equivalent) for [Pods](https://pods.io) — as a **generic, community-first** field type. SDC Page Builder is one consumer among many.

**Status:** Skeleton (Phase A Spike pending). Not production-ready yet.

## Why

Pods supports simple repeatable fields (single values) but **not repeatable field groups** — a configurable set of sub-fields that repeats. This has been requested for years (Discussion [#7238](https://github.com/pods-framework/pods/discussions/7238), Issue [#109](https://github.com/pods-framework/pods/issues/109)).

This plugin adds a `repeater` field type: a group of scalar sub-fields (text, textarea, select, number, url) stored as JSON, with a server-side repeatable admin UI (add/remove/move rows).

## Strategy

1. **Generic plugin first** — standalone, wp.org-ready, own release cycle.
2. **PR to pods-framework/pods** (Phase 1: PHP field type) so the maintainer can adopt it and the whole community benefits. The PR is an option, not a requirement — the plugin lives independently either way.
3. SDC Page Builder builds on top of this field type afterwards.

See the [master plan](https://github.com/phoenixseo/sdc-page-builder/blob/main/plan/realistischer-masterplan.md) (Vault) for the full strategy.

## Install (dev)

```bash
# wp-env (Docker) — WP + Pods 3.3.9.1 + this plugin
npx wp-env start
npx wp-env run cli wp plugin list
```

## Field type

| Property | Value |
|---|---|
| Type | `repeater` |
| Class | `Pods_Repeater_Field` (plugin prefix; port to core = `PodsField_Repeater`) |
| Storage | JSON string in `wp_postmeta` / `longtext` (table-based) |
| UI | Server-side (`input()` + vanilla JS, no DFV-React, no build) |
| Sub-fields | JSON config in field options (`repeater_sub_fields`) |

## Roadmap

- [ ] Phase A — Spike: JSON roundtrip (T3/T5) in wp-env
- [ ] Phase B — Plugin MVP: production-ready for foreign users
- [ ] Phase C — PR Phase 1 to pods-framework/pods
- [ ] Phase D — Feedback window; builder on the plugin

## License

GPL-2.0-or-later
