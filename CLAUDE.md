# Qomon WordPress Plugin

## What this plugin does

Embeds Qomon action forms into WordPress pages via a Gutenberg block or `[qomon-form]` shortcode. The original plugin loaded `setup.js` from Qomon's CDN on every page view, which then fetched a versioned `manifest.json` and injected `<script type="module">` tags into the DOM — 2–3 sequential network requests before the form could render.

This version moves all of that work server-side with persistent caching, so the versioned asset URLs are resolved once by PHP and baked directly into the HTML.

## Key files

```
qomon.php            Single-file plugin — all PHP logic lives here
src/
  block.json         Block metadata and attribute schema (source of truth)
  qomon-form-block/
    index.js         Registers the block type
    edit.js          Gutenberg editor UI
    save.js          Returns null — block is server-rendered
build/               Compiled output of src/ — committed so Composer install works without Node
  block.json
  qomon-form-block/
    index.js
    index.asset.php
composer.json        Composer package definition (type: wordpress-plugin)
docs/
  architecture.md    Caching strategy and data flow
  database.md        wp_options keys written by this plugin
```

## How to embed a form

**Shortcode:**
```
[qomon-form id=5652feb3-bc2a-4c0d-ba75-e5f68997f308]
[qomon-form id=5652feb3-bc2a-4c0d-ba75-e5f68997f308 type=petition]
```

**Gutenberg block:** search for "Qomon Form" in the block inserter. Set `base_id`, choose Form Type (Form or Petition), and optionally set `env` (CDN bucket override — leave blank for production).

## Caching behaviour

Manifest resolution follows this priority chain — see `docs/architecture.md` for full details:

1. **Transient** (2-hour TTL, uses object cache if available)
2. **Live CDN fetch** (writes through to both transient and `wp_options`)
3. **`wp_options` stale copy** (survives object cache flushes and CDN outages)

The hourly WP-Cron job (`qomon_refresh_manifests`) force-refreshes all registered forms before the transient expires, so page renders almost always hit the transient fast path.

## Adding new forms / cache priming

Forms are registered automatically:
- On `save_post` — the plugin scans post content for blocks and shortcodes and primes the cache immediately at publish/save time, before any visitor loads the page
- On first frontend render — fallback for forms inserted outside the editor (REST API, raw SQL)

Registered forms are stored in `qomon_registered_forms` (see `docs/database.md`).

## Development

**Build JS** (only needed when changing `src/`):
```bash
pnpm install
pnpm build
```
Commit the updated `build/` directory alongside any `src/` changes.

**PHP changes** require no build step — edit `qomon.php` directly.

## Things to know before making changes

- **Single PHP file** — all logic is in `qomon.php`. No autoloader, no classes.
- **Block registered outside `is_admin()`** — `wpqomon_create_form_block` hooks on `init` unconditionally so the `render_callback` fires on frontend renders. Do not move it inside the admin block.
- **`save()` returns null** — the block is server-rendered via `render_callback`. If you see Gutenberg block validation errors after editing `save.js`, ensure it still returns `null`.
- **`form_type`** — selects between the `qomonForm` and `qomonPetition` web components in the manifest. Added in v2.0.0; not present in the upstream Qomon plugin.
- **`type="module"`** — WordPress's `wp_enqueue_script()` does not support ES modules natively. The `script_loader_tag` filter patches this for any handle prefixed `qomon-form-`.
- **Cron schedules on activation** — if you update the plugin without deactivating it, the existing cron schedule persists unchanged. If you change the interval, deactivate/reactivate to reschedule.
- **Composer install path** — consuming projects should configure `composer/installers` to place `wordpress-plugin` packages in the correct directory (e.g. `web/plugins/{$name}` in Bedrock-style setups).
