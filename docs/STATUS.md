# Status (handover between sessions)

As of 2026-10-25. Binding rules: `docs/START.md`. Decisions D-1…D-6 and open points OP-1…OP-11:
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

Acceptance tests already covered by integration/E2E tests: 1, 1a, 2–12, 14.
Still open: 13 (filesystem diff before/after a test run), 15 (release zip with built assets and `de_DE`).

## Next steps

1. `de_DE` translation (Du-Form): `languages/` (`.po`/`.mo` and JSON for the media tab script).
2. README (English): configuration via constants, shares with download permission, Memories/Photos
   note, WP-CLI commands, uninstall behaviour.
3. Acceptance test 13 (filesystem diff before/after a test run) and release zip (acceptance 15:
   built assets and `de_DE`, without `dev/`, tests, `node_modules`).

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

- `dev/bin/setup.sh` starts and seeds everything (MariaDB, Nextcloud 34.0.4 with TLS via dev CA,
  WordPress 7.1.3 / PHP 8.4); `dev/bin/test-integration.sh [--filter X]`, `dev/bin/test-e2e.sh`.
- Composer: GitHub zipballs return 403 through the proxy; a local, git-ignored `composer.local.json`
  with a path repository for phpstan was used as a workaround.
- If the Docker daemon is not running, start it detached (`setsid nohup dockerd … & disown`); background
  tasks are killed after 2 h.
- The merge tool needs the full 40-character head SHA.
