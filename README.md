# WP Nextcloud Linker

WordPress plugin that lets editors pick images and documents from **Nextcloud folders and albums**
(Photos/Memories) in the media library and use them in pages – **without copying the files to
WordPress**. WordPress stores only a reference (the Nextcloud file ID) and delivers images and
documents through a streaming proxy.

- Media dialog tab **"Nextcloud"** (block editor and classic media library): folders, own albums,
  shared albums (also shared via a group), thumbnails, multiple selection.
- Linked media are regular attachments without a file in `uploads`. They work in the core blocks
  (Image, Gallery, Cover, Media & Text, File) and in themes, including all registered image sizes
  and `srcset`.
- Delivery through WordPress (`/nextcloud-media/{id}/{size}/{file name}`): streaming, browser cache
  headers, `ETag`/`304`, no Nextcloud addresses or headers reach visitors. Only linked attachments
  are delivered – the plugin is not an open proxy for the Nextcloud account.
- Files that disappear in Nextcloud (deleted, share removed, download no longer allowed) are shown
  as a neutral placeholder and listed on the dashboard; renamed or moved files keep working.
- No files are written on the WordPress server, no external resources, no telemetry.

Requirements: WordPress 6.6+, PHP 8.1+ (tested with WordPress 7.1 and PHP 8.4), Nextcloud with the
**Photos** app enabled (tested with Nextcloud 34, Photos 7, Memories 10). The connection always uses
HTTPS with certificate verification.

## Installation

Download the zip from the [releases](../../releases) and install it, e.g. with WP-CLI:

```sh
wp plugin install wp-nc-linker-<version>.zip --activate
```

Updates are not distributed through wordpress.org; install a newer release zip the same way
(`--force`).

## Nextcloud preparation

1. Create a dedicated Nextcloud account for WordPress (e.g. `linker`).
2. Log in as this account and create an **app password** under *Personal settings → Security →
   Devices & sessions*.
3. Share the folders and albums WordPress may use with this account – **read-only, with download
   permission**. Files in shares with *"Hide download"* cannot be used (Nextcloud refuses previews
   and downloads for them); the connection test lists such shares.

Albums are managed in Nextcloud with **Memories** or **Photos**. Memories uses the albums of the
Photos app, so Photos must stay enabled; for the plugin both are equivalent.

## Configuration

*Settings → Nextcloud Linker*: Nextcloud URL, user name, app password, browser cache lifetime,
allowed file types, URL prefix and the uninstall behaviour. The app password is stored encrypted
(libsodium, key derived from the WordPress salts) and is never shown again.

Alternatively, the connection can be set in `wp-config.php`; constants take precedence and lock the
corresponding fields on the settings page:

```php
define( 'WPNCL_URL', 'https://cloud.example.org' );   // Including a sub-path, if any.
define( 'WPNCL_USER', 'linker' );                      // Account ID of the connection account.
define( 'WPNCL_APP_PASSWORD', 'xxxxx-xxxxx-xxxxx-xxxxx-xxxxx' );
```

If the WordPress salts change, a password stored on the settings page can no longer be decrypted
and must be entered again (constants are not affected).

Use the **connection test** on the settings page or `wp nc-linker test` after the setup.

### Permissions

| Action | Default capability | Filter |
|---|---|---|
| Browse Nextcloud, link files, dashboard notice | `upload_files` | `wpncl_select_capability` |
| Settings page, saving, connection test | `manage_options` | `wpncl_settings_capability` |

Users who may only link files see neither the settings nor the connection data.

## WP-CLI

```sh
wp nc-linker test  [--format=<table|json|csv|yaml>]   # Connection test; exit code 1 on errors.
wp nc-linker check [--format=…]                       # Checks all linked files, marks missing ones.
wp nc-linker list  [--status=<ok|missing>] [--format=…]  # Linked media with source and status.
```

The availability check also runs daily via WP-Cron (in batches). Servers that run WP-Cron from a
system timer or not at all can call `wp nc-linker check` from their own timer instead.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wpncl_select_capability` | filter | Capability for browsing and linking (default `upload_files`). |
| `wpncl_settings_capability` | filter | Capability for settings and connection test (default `manage_options`). |
| `wpncl_allowed_mime_types` | filter | MIME types that may be linked and delivered. |
| `wpncl_cache_max_age` | filter | `Cache-Control` max-age of delivered files (seconds). |
| `wpncl_proxy_prefix` | filter | First path segment of the delivery URLs (default `nextcloud-media`). |
| `wpncl_browse_cache_ttl` | filter | Cache lifetime of folder and album listings in the dialog (default 120 s). |
| `wpncl_preview_max_size` | filter | Largest preview edge of the Nextcloud server (default 4096). |
| `wpncl_preview_fallback_max_bytes` | filter | Up to which size the original is delivered when Nextcloud has no preview (default 20 MB). |
| `wpncl_check_recurrence` | filter | WP-Cron recurrence of the availability check (default `daily`). |
| `wpncl_http_timeout` | filter | Timeout of requests to Nextcloud (default 15 s). |
| `wpncl_ca_bundle` | filter | CA bundle for verifying the Nextcloud certificate. |
| `wpncl_file_unavailable` | action | A linked file was reported missing/forbidden by Nextcloud. |
| `wpncl_file_available` | action | A file marked missing was delivered again. |

## Uninstall

Uninstalling removes the settings, scheduled checks and cached data. Linked media items stay in the
media library (with their link data, so a reinstallation shows them again) unless *"Delete all
linked media when the plugin is uninstalled"* is checked on the settings page. Files in Nextcloud
are never changed – the plugin only reads (`PROPFIND`/`GET`).

On multisite, uninstalling cleans up the current site only.

## Not included in version 1

Videos, uploading or changing files in Nextcloud, public Nextcloud share links as source, image
editing (crop/rotate) of linked files.

## Development

- `dev/bin/setup.sh` starts and seeds the test environment (Docker: MariaDB, Nextcloud with
  Photos/Memories and an internal CA, WordPress); see `dev/README.md`.
- Checks: `composer lint`, `composer analyse`, `composer test`, `dev/bin/test-integration.sh`,
  `dev/bin/test-e2e.sh`, `npm run lint:js`.
- Build: `npm run build`; translations: `dev/bin/i18n.sh` (needs WP-CLI and a current build);
  plugin zip: `dev/bin/build-zip.sh` → `dist/`.
- Release: set the version in `wp-nc-linker.php` (header and `WPNCL_VERSION`), `package.json`,
  `package-lock.json` and `tests/phpstan-bootstrap.php`, run `dev/bin/i18n.sh`, then push a tag
  `v<version>`; the release workflow builds the zip and publishes the release.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
