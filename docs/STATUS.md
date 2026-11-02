# Status (handover between sessions)

As of 2026-11-02 (release 0.1.1). Binding rules: `docs/START.md`. Decisions D-1…D-6 and open points OP-1…OP-15:
`docs/decisions.md`. Verified interfaces: `docs/nextcloud-interfaces.md`.

## Done (merged to `main`)

| Step | Content | PR |
|---|---|---|
| 2 | Scaffold, tooling, CI, dev environment (`dev/`) | #1 |
| F1 | Settings, encrypted app password, connection test (page + `wp nc-linker test`) | #2 |
| F2/F3 | Browsing folders, albums, shared albums (REST), media tab "Nextcloud" | #3 |
| F4 | Virtual attachments (no file in uploads), sizes mapped to delivered preview sizes | #4 |
| F5 | Delivery proxy `/{prefix}/{id}/{full\|WxH}/{file}`, streaming, 304, placeholder, relocation | #5 |
| F6 | Documents (PDF, Office) via file block | #6 |
| F7 | Availability check (WP-Cron + `wp nc-linker check`), dashboard widget for missing files | #7 |
| F8 | Permissions reviewed; Nextcloud source in media library data only with the select capability | #9 |
| F9/F10 | `wp nc-linker list [--status] [--format]`; `uninstall.php` (`Uninstaller`): options, cron, transients, linked media only with `delete_on_uninstall` | #10 |
| i18n/README/zip | `de_DE` (Du-Form: PHP + media tab JSON), README, `dev/bin/i18n.sh`, `dev/bin/build-zip.sh`, CI zip job, release workflow on tag `v*` | #11 |
| Release 0.1.0 | Version 0.1.0, tag `v0.1.0` (release workflow publishes the zip) | #12 |
| Fixes after 0.1.0 | Missing-file report: path encoding test; OP-13 (proxy refuses "Hide download" originals), OP-14 (reason for missing files), OP-15 (`check` takes over changed file data) | #14 |
| Release 0.1.1 | Version 0.1.1 (fixes after 0.1.0), tag `v0.1.1` set by the user (sessions cannot push tags) | #15 |

Acceptance tests already covered by integration/E2E tests: 1, 1a, 2–12, 14.
15 is covered by the CI job "Translations and plugin zip" (zip with built assets and `de_DE`, artifact
`wp-nc-linker-zip`). 13 (filesystem diff before/after a test run): done externally – checked on the
target system, not in CI.

## Next steps

The planned scope (F1–F10, acceptance tests) is complete and released as 0.1.1 (GitHub release with
zip). There is no assigned next task; candidates for the next session:

1. Open points with the most practical value (details in `docs/decisions.md`):
   - OP-12: help editors when a file was replaced by a new upload (suggest candidates or a
     "replace link" action).
   - OP-14 (rest): show the reason for missing files in the dashboard widget.
   - OP-15 (rest): version part in proxy URLs, so changed files are not served from browser caches.
   - OP-7: `Range` support in the proxy (large PDFs, resumable downloads; prerequisite for OP-1).
   - OP-3: update check for installations without wordpress.org.
2. Decisions for the user: OP-2 (wordpress.org), OP-4 (repository visibility).
3. Next release: raise the version (`wp-nc-linker.php` header + `WPNCL_VERSION`, `package.json`,
   `package-lock.json`, `tests/phpstan-bootstrap.php`), run `dev/bin/i18n.sh`, merge. The tag
   `v<version>` (or a GitHub release) is created by the user: sessions cannot push tags (HTTP 403).
   The release workflow checks tag = plugin version and attaches the zip.
4. New or changed strings: run `dev/bin/i18n.sh` (WP-CLI needed; locally the wp-cli.phar from
   `raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar` works through the proxy) and
   translate the new entries in `languages/wp-nc-linker-de_DE.po`; CI fails if the files are stale.

Lessons from the 0.1.1 bug report (for future diagnosis):
- A file "missing although it exists" was a new upload with a new file ID; `list --status=missing`
  now shows the reason. Diagnose on the server with read-only `wp eval-file` scripts using
  `Plugin::instance()->client()` (PROPFIND at the stored path, `search_file_id()`).
- WP-CLI should run as the WordPress PHP user (`sudo -u <user> -- wp --path=<core dir> …`); with
  `--allow-root`, check afterwards for root-owned files. `--path` must point to the directory with
  `wp-load.php`; `wp-config.php` may be one level above it.
- Nextcloud keeps the ETag for changes within the same second; a shared folder name that was
  revoked and deleted is mounted as "… (2)" when shared again.

F8 review result (entry points): REST `browse`/`thumb`/`attachments` → `permission_callback` with the
select capability, cookie auth requires the `wp_rest` nonce; parent post only with `edit_post`;
admin-post connection test → settings capability + `check_admin_referer`; settings → menu, page and
`option_page_capability_wpncl` (setting not in REST); dashboard widget and media tab → select
capability; meta keys are protected (`_wpncl_*`); the proxy is public by design (registered
attachments only). No AJAX handlers of their own.

## Working mode (agreed with the user, to save credits)

- New session per step; update this file at the end of each step.
- Unit tests only for core logic (normal case + 1–2 important error cases); one integration test per
  acceptance criterion. No tests for exotic special cases.
- Special cases outside the normal flow are not built, but recorded as OP-x in `docs/decisions.md`.
- Locally run only the affected tests (`--filter`); full suites and E2E run in CI. Keep command output short.
- PRs: merge yourself once all checks are green (user rule); no safety-net check-ins, CI events wake the session.

## Dev environment notes

- Docker Hub may answer 429 (rate limit); pull the images from `mirror.gcr.io/library/<image>` and
  tag them with the original name, then run `dev/bin/setup.sh`. Containers have no internet access,
  so PHPUnit cannot be installed there; full test runs happen in CI.
- `dev/bin/setup.sh` starts and seeds everything (MariaDB, Nextcloud 34.0.4 with TLS via dev CA,
  WordPress 7.1.3 / PHP 8.4); `dev/bin/test-integration.sh [--filter X]`, `dev/bin/test-e2e.sh`.
- Composer: GitHub zipballs return 403 through the proxy; a local, git-ignored `composer.local.json`
  with a path repository for phpstan was used as a workaround.
- If the Docker daemon is not running, start it detached (`setsid nohup dockerd … & disown`); background
  tasks are killed after 2 h.
- The merge tool needs the full 40-character head SHA.
