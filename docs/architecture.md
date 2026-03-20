# Architecture: Manifest Caching

## Overview

PHP fetches a versioned `manifest.json` from Qomon's CDN per form, resolves the asset URLs, and writes `<script type="module">` and `data-style-link` directly into the page HTML. The manifest is cached so the CDN is not hit on every page render.

## Manifest resolution

When a page containing a Qomon form is rendered, `wpqomon_get_manifest()` is called:

```
1. Transient hit?
   └─ YES → return immediately (fast path, ~0ms)
   └─ NO  ↓

2. Live CDN fetch (wp_remote_get)
   └─ SUCCESS → write to transient (2h TTL) + wp_options → return
   └─ FAIL    ↓

3. wp_options stale copy
   └─ EXISTS → return last known-good manifest
   └─ MISSING → return false → render HTML comment placeholder
```

## Two cache stores

| Store | TTL | Survives object cache flush? | Purpose |
|---|---|---|---|
| Transient | 2 hours | No (if using Redis/Memcached) | Fast reads |
| `wp_options` | Permanent | Yes | Fallback if transient is missing and CDN is unreachable |

Every successful CDN fetch writes to both. If the object cache is flushed, the next render attempts a live CDN fetch and falls back to the `wp_options` copy if the CDN is unavailable.

## Cache warming

The transient TTL (2 hours) is intentionally longer than the cron interval (1 hour):

- **Normal operation:** cron refreshes the transient before it expires → renders always hit the transient fast path
- **Cron misses one run:** transient still valid for another hour → no impact
- **Cron misses two runs:** transient expires → live CDN fetch on next render → re-warms both stores
- **CDN down at expiry:** `wp_options` stale copy serves the form

## Form registry

The cron only refreshes forms it knows about. Forms are registered in `qomon_registered_forms` (see `docs/database.md`) via two paths:

1. **`save_post` hook** — when a post is saved, the plugin parses block attributes and shortcode attributes from `post_content`, registers any Qomon form IDs found, and immediately calls `wpqomon_refresh_manifests()`. The cache is warm before any visitor loads the page.

2. **`wpqomon_render_form()`** — every render call also registers the form as a fallback, catching forms inserted via REST API or raw SQL.

## Block rendering

The Gutenberg block uses a PHP `render_callback`. `save()` in `src/qomon-form-block/save.js` returns `null`, marking the block as dynamically rendered — Gutenberg stores attributes in the block comment delimiter but does not validate HTML output. On the frontend, WordPress calls `render_callback` with the block attributes, which calls `wpqomon_render_form()`.

## Script enqueuing

Qomon's form components are ES modules. WordPress's `wp_enqueue_script()` does not support `type="module"` natively, so a `script_loader_tag` filter adds it to any handle prefixed `qomon-form-`. Handles are keyed by `base_id`, so placing the same form twice on a page produces only one `<script>` tag.
