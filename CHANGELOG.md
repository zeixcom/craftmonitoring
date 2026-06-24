# Release Notes for Craft Monitoring

All notable changes to this project are documented in this file, following
[Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).

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
