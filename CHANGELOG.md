# Changelog

All notable changes to the Uptime Kuma plugin for Unraid are documented here.
Each version is also published on the [GitHub Releases](https://github.com/drohack/UptimeKumaPlugin/releases) page.

Versions follow Unraid plugin convention: `YYYY.MM.DD` with a letter suffix for
same-day follow-up releases.

## 2026.07.09b

### Fixed
- The Database Path folder picker really opens below the input now. 2026.07.09a
  only cancelled the panel's left offset, but the stock webGui also renders the
  panel `position: absolute`, which still anchored it to the page's left edge.
  The panel is now forced back into normal flow — verified live on Unraid 7.x.

## 2026.07.09a

### Fixed
- The Database Path folder picker opened shifted to the far left, over the
  setting label, instead of below the input. (The stock file tree positions
  itself for Unraid's dl/dd settings markup; inside this page's table layout
  that lands on the document edge — now pinned below the input.)

## 2026.07.09

### Added
- Uptime Kuma v2 instances using MariaDB are now supported (embedded MariaDB —
  the option the v2 setup wizard recommends — and external MariaDB). Data is
  read through the Uptime Kuma container's own database client; the container
  must be running. Note: Uptime Kuma itself does not support PostgreSQL.
- The Database Path setting has a folder/file picker. Select your Uptime Kuma
  data folder (recommended) and the database type is detected automatically, or
  keep pointing at `kuma.db` directly — existing configs work unchanged.
- Optional Container Name setting for MariaDB mode, only needed if the
  Uptime Kuma container can't be auto-detected.

### Fixed
- v2 long time periods (7d–180d) compared the stat tables' integer unix
  timestamps against a datetime string, piling all history into the first bar
  and skewing the uptime %. The cutoff is now an epoch value.
- The Support link now points to the [Unraid forum support thread](https://forums.unraid.net/topic/198044-plugin-uptime-kuma-monitor-widget-for-unraid-dashboard/).

## 2026.06.29a

### Fixed
- The dashboard widget can now be dragged/reordered like every other tile (#2).
  It now registers as a native Unraid dashboard tile (via `$mytiles`) instead of
  injecting its table into an existing tile, which had locked it to the
  bottom-right.

### Changed
- Requires Unraid 6.12+ for native dashboard tile support (min bumped from
  6.11.0).

## 2026.06.29

### Fixed
- 1 Hour time period showed no data on servers in a timezone ahead of UTC (#1).
  Heartbeat cutoff is now computed in UTC to match how Uptime Kuma stores
  timestamps.

## 2026.04.03

### Security
- Removed filesystem paths from error messages (prevents path enumeration).
- WebUI URL is validated to allow only `http`/`https` schemes (prevents
  tabnabbing).
- Added `rel="noopener noreferrer"` to the external WebUI link.

## 2026.03.30n

### Changed
- Code cleanup: remove dead code, sanitize inputs, optimize queries.

### Added
- Supports Uptime Kuma v1.x and v2.x (auto-detected).
- v2 uses `stat_hourly`/`stat_daily` for longer time periods.

## 2026.03.30k

### Added
- Heartbeat bars with hover tooltips matching Uptime Kuma style.
- Native Unraid dashboard tile (1/3 width, border, background).
- WebUI link with auto-detect from Docker template.
- Monitor checklist to select which monitors to display.
- Configurable refresh interval (10s to 10 min, default 1 min).
- Configurable time periods (1h to 180d).
- Settings page accessible via plugin icon.

## 2026.03.30

- Initial release.
