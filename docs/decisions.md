# Decisions and open points

## Decisions

| Code | Topic | Decision |
|---|---|---|
| D-1 | Cropping | Image sizes are delivered **without hard crop**; every size keeps the aspect ratio (Photos preview route cannot crop, see `nextcloud-interfaces.md` §2). Metadata reports the real dimensions. |
| D-2 | Size mapping | Each registered WordPress size maps to the next larger Nextcloud preview step (long edge 256, 1024, max ≤ 4096, capped by the original). `full` = original via WebDAV. `srcset` uses the delivered dimensions, duplicates removed. |
| D-3 | Download restriction | Files whose share has `permissions/download = false` are **not selectable** and are reported by diagnostics/check, regardless of `shareapi_allow_view_without_download`. |
| D-4 | Streaming | The proxy requires `ext-curl` and streams chunks directly to the client (WordPress HTTP API buffers or writes files). TLS verification always on; CA bundle defaults to the WordPress bundle, changeable via filter. PROPFIND/diagnostics use the WordPress HTTP API. |
| D-5 | Proxy URL | Rewrite rule `/{prefix}/{id}/{size}/{filename}` to `index.php`; fallback `?wpncl={id}&wpncl_size={size}` for plain permalinks. |
| D-6 | Dialog thumbnails | Authenticated REST route (selection capability + nonce, private cache) that only serves file IDs from the current user's recent listing (short-lived transient). |

## Open points

- **OP-1:** Videos as an option (version 2).
- **OP-2:** Publication in the WordPress plugin directory (review guidelines, name, assets).
- **OP-3:** Update path for installations without wordpress.org (e.g. release check via WP-CLI).
- **OP-4:** Visibility of the GitHub repository (private during development, public later?).
- **OP-5:** External configuration file outside the web root (path via constant) – only if needed.
- **OP-6:** Upstream bug in Photos `PreviewController` (group loop merges the wrong variable). No effect on the plugin; report upstream?
- **OP-7:** `Range` requests in the proxy (resumable downloads, PDF viewers). Nextcloud supports them (`206`); not required for v1.
- **OP-8:** Nextcloud generates no image previews for originals larger than `preview_max_filesize_image` (default 50 MB). Such files have no thumbnail and no resized sizes; behaviour (placeholder vs. original) to be decided in F4/F5.
- **OP-9:** `nc:metadata-photos-size` can be missing (files added by scan or on external storage until the background job ran). Fallback for width/height needed in F4 (e.g. from the delivered maximum preview, or a later re-check).
