# Database Keys

All data written by this plugin lives in `wp_options`. No custom tables.

## Manifest cache (per form)

### `_transient_qomon_manifest_<md5hash>`

The decoded manifest JSON for a given form, stored as a serialized PHP array.

- **Written by:** `wpqomon_get_manifest()` on a successful CDN fetch
- **TTL:** 2 hours
- **Hash:** `md5($base_id . $bucket)`
- **Companion row:** `_transient_timeout_qomon_manifest_<md5hash>` (expiry timestamp, written automatically by `set_transient()`)

If a persistent object cache (Redis, Memcached) is active, WordPress routes transients through it instead of this table. The transient rows may not exist in `wp_options` in that case.

### `qomon_manifest_fallback_<md5hash>`

The last known-good manifest for a given form. Written alongside the transient on every successful CDN fetch. Has no expiry — persists until the plugin is uninstalled or the option is manually deleted.

- **Written by:** `wpqomon_get_manifest()` on a successful CDN fetch
- **Read by:** `wpqomon_get_manifest()` when the transient is missing and the CDN is unreachable
- **Autoload:** `false`

## Form registry

### `qomon_registered_forms`

An associative PHP array of all form IDs known to the plugin, keyed by `md5($base_id . $bucket)`.

```php
[
    '<md5hash>' => [
        'base_id'   => '5652feb3-bc2a-4c0d-ba75-e5f68997f308',
        'form_type' => 'petition',   // '' for standard forms
        'bucket'    => 'cdn-form.qomon.org',
    ],
    // ...
]
```

- **Written by:** `wpqomon_register_form()`, called from `save_post` and `wpqomon_render_form()`
- **Read by:** `wpqomon_refresh_manifests()` (cron job)
- **Autoload:** `false`

Forms are never removed from this registry automatically. If a form is removed from all posts, its entry remains and the cron will continue to refresh it. This is intentional — stale registrations are harmless and the `wp_options` fallback copy remains available.

## Cleanup on uninstall

Currently no uninstall hook is registered. To clean up manually after removing the plugin:

```sql
DELETE FROM wp_options WHERE option_name LIKE '_transient_qomon_manifest_%';
DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_qomon_manifest_%';
DELETE FROM wp_options WHERE option_name LIKE 'qomon_manifest_fallback_%';
DELETE FROM wp_options WHERE option_name = 'qomon_registered_forms';
```
