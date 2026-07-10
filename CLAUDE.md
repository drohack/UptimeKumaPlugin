# Uptime Kuma Plugin for Unraid — project conventions

Unraid plugin that shows Uptime Kuma monitor statuses on the Unraid dashboard.
Single `.plg` manifest downloads files from this repo's `main` branch at
install time — there is no build step; pushing to `main` IS deploying.

## Layout

- `uptime-kuma.plg` — plugin manifest: version entity, `<CHANGES>` changelog,
  install/remove scripts. The version entity (`ENTITY version`) is the single
  source of truth for the released version.
- `src/uptime-kuma/usr/local/emhttp/plugins/uptime-kuma/` — the files the .plg
  downloads onto the Unraid server (PHP backend, .page files, JS, CSS, icons).
- `CHANGELOG.md` — user-facing changelog, mirrors the .plg `<CHANGES>` block.
- `.github/workflows/release.yml` — auto-creates the git tag + GitHub Release
  when the version in `uptime-kuma.plg` changes on `main`.

## Release checklist

Every release, in one task:

1. Bump `ENTITY version` in `uptime-kuma.plg` (format `YYYY.MM.DD`, letter
   suffix `a`, `b`, … for same-day follow-ups).
2. Add a `###<version>` entry at the TOP of the `<CHANGES>` block. This exact
   text becomes the GitHub Release notes — write it for end users.
3. Mirror the entry in `CHANGELOG.md` (Keep-a-Changelog style:
   `Added`/`Fixed`/`Changed`/`Security` headings).
4. Update README.md if features, settings, or defaults changed.
5. Commit and push to `main` (only with explicit user instruction).

The tag and GitHub Release are created automatically by the workflow — do NOT
run `git tag` or `gh release create` manually. Tag name = plain version string,
no `v` prefix (e.g. `2026.07.09b`). The workflow skips silently if a release
for the current version already exists, so content-only .plg edits are safe.

## Deployment gotchas

- raw.githubusercontent.com serves a stale `.plg` for ~5+ minutes after a
  push — wait before testing an update on a live server, or pull via a
  commit-pinned URL.
- Users only see changes after bumping the version — Unraid's update check
  compares the version entity.

## Testing

- Local harness (no Unraid needed): see the `local-test-harness` project
  memory — tests the PHP backend on Windows against a real Kuma v2 embedded
  MariaDB container.
- End-to-end verification happens on the live Unraid server ("Tower") via
  Playwright against the real WebGUI.
