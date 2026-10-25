# Nextcloud interfaces – verification against source

Verified on 2026-10-25 against these tags (shallow clones, read-only):

| Repository | Tag | Commit | Note |
|---|---|---|---|
| `nextcloud/server` | `v34.0.4` | `77c7284f` | `version.php`: 34.0.4 |
| `nextcloud/photos` | `v34.0.4` | `b953b0b5` | `appinfo/info.xml`: version **7.0.0**, requires NC 34 |
| `pulsejet/memories` | `v10.2.0` | `d59c33d9` | requires NC 34–35, PHP ≥ 8.3 |

**Tag naming:** the Photos app has **no tag `v7.0.0`**. Its tags follow the server
version; app version 7.0.0 is shipped in tag `v34.0.4` (and the other `v34.0.x` tags).
All tests and references use `photos@v34.0.4`.

---

## 1. Authentication (HTTP Basic + app password)

Confirmed.

- `lib/base.php` `OC::handleLogin()` order: Apache auth → AppAPI → `tryTokenLogin`
  → remember-me cookies → `Session::tryBasicAuthLogin()`.
- `tryBasicAuthLogin()` (`lib/private/User/Session.php`) uses `PHP_AUTH_USER`/`PHP_AUTH_PW`
  (`handleAuthHeaders()` also extracts them from `HTTP_AUTHORIZATION`).
  `logClientIn()` accepts an app password as the password.
- **Wrong credentials throw `LoginException`** (no fallback to anonymous) and are counted by
  the brute-force throttler. Consequence for the plugin: no automatic retries after a 401;
  diagnostics must report 401 clearly and stop.
- Nextcloud sets session cookies on each Basic-authenticated request. The plugin ignores and
  never stores them.
- WebDAV (`remote.php/dav`) uses its own Sabre auth backend, also accepting Basic + app password.

## 2. Preview route (Photos)

`GET {base}/index.php/apps/photos/api/v1/preview/{fileId}?x={w}&y={h}`
(`appinfo/routes.php` → `PreviewController::index(int $fileId = -1, int $x = 32, int $y = 32)`,
`@NoAdminRequired`, `@NoCSRFRequired`). Confirmed.

Lookup order (stops at the first hit):
1. `userFolder->getById()` – own file tree incl. folders shared to the account;
   nodes on a shared storage whose share has `permissions/download = false` are dropped.
2. Own albums containing the file (`getForUserAndFile`).
3. Albums shared directly with the account (`TYPE_USER`).
4. Albums shared with one of the account's groups (`TYPE_GROUP`).

For album hits the file is resolved in the **owner's** file tree; smart albums (filters) are
included via `FiltersManager`. → **The file ID is sufficient as reference.** Confirmed.

Responses:
- `400` if `fileId = -1`, `x = 0` or `y = 0`, or the preview is invalid (`InvalidArgumentException`).
- `403` if not logged in or node not readable.
- `404` if not found, not a file, no preview provider, or no preview.
- `200` `FileDisplayResponse`: `Content-Type` of the generated preview (not necessarily the original's type), `Content-Length`, `ETag` (of the preview file),
  `Last-Modified`, `Content-Disposition: inline; filename="…"`,
  `Cache-Control` from `cacheFor(86400, false, true)` = private, immutable.
  `304` on matching `If-None-Match` is answered by `NotModifiedMiddleware`.

**New details that affect the design:**
- **No crop, no aspect parameter.** The controller calls `getPreview($node, $x, $y)` →
  `crop = false`, `mode = fill`. The result always keeps the aspect ratio and fits into `x × y`.
  Hard-cropped WordPress sizes (e.g. `thumbnail` 150×150) cannot be produced by this route.
  The core route `/index.php/core/preview?fileId=…&a=0` can crop, but only finds files in the
  account's own file tree – **not** files in shared albums. → Decision needed (see plan, D-1).
- **Sizes are rounded.** `Preview\Generator::calculateSize()` scales every request that is not
  the maximum preview to the next power of 4 (min. 64): effective long edges are
  **64, 256, 1024, 4096** (capped by `preview_max_x/y`, default 4096, and by the original).
  Example: request 768×768 → 1024 px preview. The plugin must compute the real delivered
  width/height per size for `srcset`/metadata, not the requested ones.
- Upstream bug (harmless for us): in the group loop `$checkedAlbums` is merged with
  `$receivedAlbums` instead of `$albumsForGroup`.

## 3. Download restriction

Confirmed for the preview route (see 2.1). **Additional finding – WebDAV `GET` behaves
differently:** `apps/dav/lib/DAV/ViewOnlyPlugin.php` allows `GET` on a share with
`download = false` if the server setting `shareapi_allow_view_without_download` is on
(`Share::canSeeContent()`). So, depending on server config, the original can be downloadable
via WebDAV while the preview returns 404.

→ The plugin uses one rule for both paths: a file whose share attributes contain
`{"scope":"permissions","key":"download","value":false}` is **not selectable** and is
reported in diagnostics ("share must allow download, read-only").
The attributes are available per node via PROPFIND `nc:share-attributes` (JSON list of
`{scope,key,value}`, empty `[]` outside shares). `nc:hide-download` is the separate
public-link flag and is not relevant here.

## 4. Folders (WebDAV files)

`PROPFIND {base}/remote.php/dav/files/{user}/{path}` with `Depth: 1`, `GET` for originals.
Property names (from `apps/dav/lib/Connector/Sabre/FilesPlugin.php`; `d:` = `DAV:`,
`oc:` = `http://owncloud.org/ns`, `nc:` = `http://nextcloud.org/ns`):

| Purpose | Property |
|---|---|
| File ID (integer) | `oc:fileid` (not `oc:id`, which contains the instance ID) |
| MIME type | `d:getcontenttype` (Sabre core) |
| Size | `d:getcontentlength` (files), `oc:size` (files and folders) |
| Modified | `d:getlastmodified` |
| ETag | `d:getetag` |
| Type | `d:resourcetype` (`<d:collection/>` for folders) |
| Preview available | `nc:has-preview` (`true`/`false`) |
| Permissions | `oc:permissions` (e.g. `RGDNVW`; `G` = readable) |
| Share attributes | `nc:share-attributes` |
| Image size | `nc:metadata-photos-size` → `{"width":…,"height":…}` (Photos `SizeMetadataProvider`, already corrected for EXIF rotation) |
| Hidden live-photo video | `nc:hidden` |

`GET` streams the original. `Range` support comes from sabre/dav (3rdparty, not checked
out) – to be confirmed in the test environment; the plugin does not depend on it in v1.

## 5. Albums (WebDAV photos)

`{base}/remote.php/dav/photos/{user}/` → `PhotosHome` with children `albums`, `sharedalbums`,
`places` (not used). Access only for the logged-in user's own principal (`RootCollection`
throws `Forbidden` otherwise).

| Level | Class | Child name |
|---|---|---|
| `…/albums/` | `AlbumsHome` | `basename(album title)` |
| `…/sharedalbums/` | `SharedAlbumsHome` | `basename("{title} ({owner uid})")` – suffix added by `AlbumMapper` to avoid duplicates |
| `…/albums/{album}/` | `AlbumRoot` / `SharedAlbumRoot` | `{fileId}-{file name}` |

- `SharedAlbumsHome` merges albums shared with the user and with each of their groups
  (deduplicated by album ID). → Abnahme 2 (group share) is covered by the same collection.
- Album contents = explicitly added files **plus smart-album files** (filters; limited to
  1000 results by `FiltersManager`). Entries whose file no longer exists are filtered out of
  listings (`AlbumPhoto::exists()`).
- Album PROPFIND properties (`photos/lib/Sabre/PropFindPlugin.php`):
  - Album: `nc:original-name` (real title), `nc:nbItems`, `nc:last-photo` (cover file ID),
    `nc:dateRange`, `nc:location`, `nc:collaborators`, `nc:filters`.
  - Photo: `oc:fileid`, `d:getetag`, `nc:file-name`, `nc:has-preview`, `oc:permissions`,
    `nc:metadata-*` (incl. `photos-size`), `nc:hidden`, `nc:photos-album-file-origin`
    (`user` or `filters`); `d:getcontenttype`, `d:getcontentlength`, `d:getlastmodified`
    via the Sabre core from `CollectionPhoto`.
- `GET …/albums/{album}/{fileId}-{name}` streams the original (`AlbumPhoto::get()` opens the
  file in the **owner's** tree). `ViewOnlyPlugin` does not apply to album photos.
- Album names are user input: path segments must be URL-encoded; titles containing `/` are
  truncated by `basename()` on the server side.

## 6. Original for a file ID

Confirmed as planned. Store per attachment the **source** (`folder` + path relative to the
user root, or `album`/`sharedalbum` + album collection name); fetch the original via that
path. If the path fails (renamed/moved; file IDs are stable), re-locate:
- folders: WebDAV `SEARCH` on `remote.php/dav/` filtered by `oc:fileid` – confirmed as
  searchable in `apps/dav/lib/Files/FileSearchBackend.php`
  (`SearchPropertyDefinition(INTERNAL_FILEID_PROPERTYNAME, selectable, searchable)`);
- albums: re-list the album collection(s) and match the `{fileId}-` prefix.

## 7. Memories

Memories 10.2.0 reads and writes the same tables (`photos_albums`, `photos_albums_files`,
`lib/Db/AlbumsQuery.php`). Albums maintained in Memories appear in the Photos DAV collections
and the Photos preview route. No Memories API is needed.
