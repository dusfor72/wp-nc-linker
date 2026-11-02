# Decisions and open points

## Decisions

| Code | Topic | Decision |
|---|---|---|
| D-1 | Cropping | Image sizes are delivered **without hard crop**; every size keeps the aspect ratio (Photos preview route cannot crop, see `nextcloud-interfaces.md` §2). Metadata reports the real dimensions. |
| D-2 | Size mapping | Each registered WordPress size maps to the next larger Nextcloud preview step (long edge 256, 1024, max ≤ 4096, capped by the original). `full` = original via WebDAV. `srcset` uses the delivered dimensions, duplicates removed. |
| D-3 | Download restriction | Files whose share has `permissions/download = false` are **not selectable** and are reported by diagnostics/check, regardless of `shareapi_allow_view_without_download`. |
| D-4 | Streaming | The proxy requires `ext-curl` and streams chunks directly to the client (WordPress HTTP API buffers or writes files). TLS verification always on; CA bundle defaults to the WordPress bundle, changeable via filter. PROPFIND/diagnostics use the WordPress HTTP API. |
| D-5 | Proxy URL | `/{prefix}/{id}/{variant}/{filename}`, variant = `full` or the delivered dimensions `{w}x{h}` (sizes with equal dimensions share one URL). Without pretty permalinks `/index.php/{prefix}/…`. *Amended in F4:* no query-string fallback, because WordPress matches srcset candidates by the last path segment, which must be the file name. Requests are recognised by the plugin before WordPress parses the query, so no rewrite rule (and no flush on prefix change) is needed. |
| D-6 | Dialog thumbnails | Authenticated REST route (selection capability + nonce, private cache) that only serves file IDs from the current user's recent listing (short-lived transient). |

## Open points

Each open point says what it is about, how the plugin behaves today, and what a solution could
look like. Points marked *Done* are kept for reference.

- **OP-1 – Videos.** Memories also manages videos, but version 1 links only images and documents
  (the allowed MIME types contain no video types). Streaming videos through the proxy would need
  `Range` support (see OP-7), because browsers seek in videos with range requests; previews/posters
  would come from Nextcloud's preview route. Planned as an option for version 2.

- **OP-2 – Publication on wordpress.org.** The plugin is distributed as a zip from GitHub releases.
  Listing it in the WordPress plugin directory would need a review against the directory
  guidelines, a `readme.txt` in the directory format, a final plugin name/slug (trademark rules for
  "Nextcloud" in plugin names must be checked) and banner/icon assets. Decision pending; nothing
  in the code depends on it.

- **OP-3 – Update path without wordpress.org.** WordPress shows no update notice for this plugin,
  because it is not in the directory, and the target system forbids updates from the admin area
  (`DISALLOW_FILE_MODS`). Today an update means: download the release zip and run
  `wp plugin install <zip> --force` (settings and links are kept). Possible improvement: a WP-CLI
  command such as `wp nc-linker update-check` that compares the installed version with the latest
  GitHub release, or a hint in the dashboard.

- **OP-4 – Repository visibility.** The GitHub repository was set up as private for development
  (`docs/START.md`). Making it public would allow downloading release zips without a GitHub login and is a prerequisite for
  OP-2. Before that, check that no target-system data is in the history (the rules forbid it, but
  a review is still advisable).

- **OP-5 – External configuration file.** Configuration comes from the settings page or from
  constants in `wp-config.php`. A separate configuration file outside the web root (path set by a
  constant) was considered for setups that keep secrets out of `wp-config.php`. Only to be built if
  a real installation needs it.

- **OP-6 – Upstream bug in Photos.** In Photos' `PreviewController`, the loop over albums shared with
  the account's groups merges the wrong variable (`$receivedAlbums` instead of `$albumsForGroup`).
  It has no effect on the plugin (previews of group-shared albums still work in tests). Could be
  reported to the Photos project.

- **OP-7 – Range requests in the proxy.** The proxy always delivers the whole file and answers
  `Accept-Ranges: none`. Consequences: interrupted downloads of large files start again from the
  beginning, and some PDF viewers load the whole document before showing the first page. Nextcloud
  supports ranges (`206 Partial Content`), so the proxy could pass a `Range` header through and
  forward `206` answers. Needed anyway for videos (OP-1).

- **OP-8 – Very large originals have no previews.** Nextcloud generates no previews for images
  larger than `preview_max_filesize_image` (default 50 MB), but still reports `has-preview: true`.
  Current behaviour: the dialog shows a file icon instead of a thumbnail; on the site, resized
  sizes are served from the original if it is at most 20 MB (filter
  `wpncl_preview_fallback_max_bytes`), otherwise the placeholder is shown. Such a file is not
  reported as missing. Alternative for large photos: raise the Nextcloud limit, or link a smaller
  export of the photo.

- **OP-9 – Image dimensions not yet known to Nextcloud.** Nextcloud stores width/height in
  `nc:metadata-photos-size`, but only after its background job has analysed a file (files added by
  scanning or on external storage can lack it for a while). Current behaviour: when linking, the
  plugin reads the dimensions from the first 256 KB of the original instead; EXIF rotation is not
  applied in this fallback, so a portrait photo taken with a rotated camera can get swapped
  width/height. When `check` sees new content without known dimensions, it keeps the old data and
  tries again later.

- **OP-10 – Image editing.** WordPress' image editor (crop, rotate, scale) needs a local file, so
  the edit action is removed for linked images. Editing has to happen in Nextcloud; the changed
  file is then picked up by `check` (OP-15). Out of scope for version 1.

- **OP-11 – Uninstall on multisite.** On a multisite network, uninstalling cleans up only the
  current (main) site: options, scheduled checks and linked media of the other sites stay in the
  database. Transients in an external object cache (Redis, Memcached) cannot be listed and are not
  deleted; they expire on their own within an hour. Only relevant if the plugin is used on
  multisite.

- **OP-12 – File replaced by a new upload.** Links follow the Nextcloud file ID, which survives
  renaming, moving and content updates. If a file is deleted and a new one is uploaded instead
  (often with a slightly different name), it gets a new file ID; the old attachment is then
  reported `missing` with reason `not_found`, and the pages still show the placeholder. Today the
  editor links the new file and replaces it in the affected pages by hand. Possible improvement:
  `check` or the dashboard suggests candidates (same folder, similar name, same type), or a
  "replace link" action that points the existing attachment to another file, so the pages need no
  change.

- **OP-13 – "Hide download" shares.** *Done in 0.1.1:* originals from folder shares with "Hide
  download" are refused by the proxy too (one PROPFIND before each original; Nextcloud 34 would
  still deliver them over WebDAV), so proxy and `check` agree: placeholder/404 and
  `download_not_allowed`. The same PROPFIND keeps the proxy from delivering another file that took
  over the stored path.

- **OP-14 – Reason for missing files.** *Done in 0.1.1:* `check` and `list` show why a file is
  missing (`not_found`, `forbidden`, `download_not_allowed`, `other_file_at_path`,
  `invalid_path`), stored as `_wpncl_reason`. *Still open:* the dashboard widget lists missing
  files without the reason; editors only see it via WP-CLI. Showing it there needs translated
  texts for the reasons.

- **OP-15 – Updated content.** Content updates keep the file ID (verified on Nextcloud 34: overwrite
  and chunked upload keep it; only delete + new upload creates a new ID). *Done in 0.1.1:* `check`
  takes over a changed ETag, size or modification time together with the new image dimensions
  (outcome `updated`); Nextcloud keeps the ETag for a change within the same second, so all three
  are compared. *Still open:* the proxy URL of a file does not change when its content changes, so
  browsers (and caches in front of WordPress) may show the old version until the cache lifetime
  ends (default one day). If the ETag stayed the same, a browser's revalidation is even answered
  with 304 and keeps the old copy. Possible solution: a version part in the URL (e.g. derived from
  the ETag/modification time), which would also require updating the URLs stored in post content.
