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

- **OP-1:** Videos as an option (version 2).
- **OP-2:** Publication in the WordPress plugin directory (review guidelines, name, assets).
- **OP-3:** Update path for installations without wordpress.org (e.g. release check via WP-CLI).
- **OP-4:** Visibility of the GitHub repository (private during development, public later?).
- **OP-5:** External configuration file outside the web root (path via constant) – only if needed.
- **OP-6:** Upstream bug in Photos `PreviewController` (group loop merges the wrong variable). No effect on the plugin; report upstream?
- **OP-7:** `Range` requests in the proxy (resumable downloads, PDF viewers). Nextcloud supports them (`206`); the proxy answers `Accept-Ranges: none` in v1.
- **OP-8:** Nextcloud generates no image previews for originals larger than `preview_max_filesize_image` (default 50 MB). Such files have no thumbnail and no resized sizes; behaviour (placeholder vs. original) to be decided in F4/F5. Nextcloud still reports `has-preview: true` for them; the media dialog falls back to a file icon when the thumbnail fails. *F5:* when the preview route answers 404, image sizes are served from the original if it is at most 20 MB (filter `wpncl_preview_fallback_max_bytes`), otherwise the placeholder is shown; a missing preview is not reported as a missing file.
- **OP-9:** `nc:metadata-photos-size` can be missing (files added by scan or on external storage until the background job ran). *F4:* the dimensions are then read from the first 256 KB of the original (Range request); EXIF orientation is not applied in this fallback.
- **OP-10:** Image editing (crop/rotate) is not offered for linked attachments (the edit action is removed in the media library); out of scope for v1.
- **OP-11:** Uninstall on multisite cleans up the current (main) site only; options, cron events and linked media of other sites remain. Transients in an external object cache are not enumerable and expire on their own (at most one hour).
- **OP-12:** A file replaced in Nextcloud by a new upload (new file ID, often a slightly different name) is reported as missing; the plugin follows file IDs only, so the old attachment is not switched to the new file. Possible: a hint in the dashboard/`check` output with candidates of the same folder, or a "replace link" action.
- **OP-13:** Shares with "Hide download": `check` marks their files missing (download not allowed), but Nextcloud 34 still delivers the original over WebDAV, so the proxy serves it and resets the status to ok. Decide which behaviour is wanted (proxy refuses such files vs. check accepts originals) and make both consistent.
- **OP-14:** `check` and `list` do not say *why* a file is missing (HTTP status at the source, not found by file ID, download not allowed); a reason column would make server-side diagnosis possible without ad-hoc scripts.
- **OP-15:** Content updates keep the file ID (verified on Nextcloud 34: PUT overwrite and chunked upload keep it; only delete + new upload creates a new ID), so updated files stay linked and the proxy delivers the new content. But the metadata stored at linking (ETag, size, width/height) is not refreshed by `check`; an image with new dimensions gets wrong `srcset` sizes, and browsers may show the old version for up to the cache lifetime (`Cache-Control` max-age, default 1 day).
