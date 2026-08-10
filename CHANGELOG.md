# Release Notes for Craft Monitoring

All notable changes to this project are documented in this file, following
[Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).

## 1.1.0 - 2026-08-10

### Fixed

- Migration `applyTime` is now parsed as UTC. It was read in the server's default timezone, so on installs where PHP isn't set to UTC (typical for DDEV) `lastSystemOrPluginUpdateAt` was offset by the difference — reporting updates hours in the future.

### Changed

- A version fingerprint change with no newer migration now falls back to `systemInfoUpdatedAt` instead of the current time, so `lastSystemOrPluginUpdateAt` no longer jumps to the moment the endpoint was polled. Installing or upgrading a plugin that ships no migrations is the common trigger.

> Delete `storage/runtime/monitoring/last-system-update.json` once after upgrading — the stored value is a high-water mark, so a wrong timestamp written by 1.0.x survives otherwise.

## 1.0.1 - 2026-06-24

### Changed

- Trimmed inline comments to terse rationale-only (no functional change).

## 1.0.0 - 2026-06-24

### Added

- Token-protected `POST <cpTrigger>/monitoring/api/system-report` endpoint returning a JSON system report.
- `reportVersion` field so dashboards can version against the payload contract.
- `updates` block (cached-only): `infoCached`, `available`, `criticalAvailable`, `migrationsPending`.
- `health` block: `queueTotalJobs`, `deprecationWarnings`, `pendingProjectConfigChanges`.
- `craft` update timestamps: `lastSystemOrPluginUpdateAt` (migration history + version fingerprint) and `systemInfoUpdatedAt`.

> Extracted from a per-project Craft module into a reusable plugin.
