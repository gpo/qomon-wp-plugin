# Architecture: Server-Side Manifest Caching

## Problem

The upstream Qomon plugin loaded `setup.js` from `scripts.qomon.org` on every page view. That script fetched a versioned `manifest.json` per form, then dynamically injected `<script type="module">` and CSS link tags into the DOM. This meant 2–3 sequential external network requests before the form could start rendering.

## Solution

PHP resolves the manifest once and writes the versioned asset URLs directly into the HTML. Visitors receive a fully-formed `<script type="module">` tag and `data-style-link` attribute in the initial response — no client-side fetching or DOM manipulation needed.

## Manifest resolution chain

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

### Why two stores?

| Store | TTL | Survives object cache flush? | Purpose |
|---|---|---|---|
| Transient | 2 hours | No (if using Redis/Memcached) | Fast reads |
| `wp_options` | None (permanent) | Yes | CDN outage / cache flush fallback |

A successful CDN fetch always writes to both. A cache flush therefore only loses the fast path — the next render falls through to a live fetch, and if that fails, the `wp_options` copy serves a stale but functional manifest.

## Cache warming

The 2-hour transient TTL is intentionally longer than the 1-hour cron interval. This means:

- **Normal operation:** cron refreshes the transient before it expires → renders always hit the fast path
- **Cron misses one run:** transient is still valid for another hour → no impact
- **Cron misses two runs:** transient expires → live CDN fetch on next render → re-warms both stores
- **CDN is down at expiry:** `wp_options` stale copy serves the form

### When forms are registered

The cron only refreshes forms it knows about. Forms are registered in `qomon_registered_forms` (a `wp_options` entry):

1. **`save_post` hook** — when a post is saved in the editor, the plugin parses block attributes and shortcode attributes from `post_content` and registers any Qomon form IDs found. It then immediately calls `wpqomon_refresh_manifests()` to prime the cache. This means the cache is warm before any visitor loads the page.

2. **`wpqomon_render_form()`** — as a fallback, every render call also registers the form. This catches forms inserted via REST API or raw SQL without going through the editor.

## Block rendering

The Gutenberg block uses a PHP `render_callback` rather than the JS `save()` function. This means:

- `save()` in `src/qomon-form-block/save.js` returns `null` — the block is marked as dynamically rendered
- Gutenberg stores block attributes in the post comment delimiter but does not validate HTML output
- On the frontend, WordPress calls `render_callback` with the block attributes, which calls `wpqomon_render_form()`

## Script enqueuing

Qomon's form components are ES modules (`type="module"`). WordPress's `wp_enqueue_script()` does not support this natively. A `script_loader_tag` filter adds `type="module"` to any script handle prefixed `qomon-form-`. `wp_enqueue_script()` also deduplicates by handle, so placing the same form twice on a page produces only one `<script>` tag.
