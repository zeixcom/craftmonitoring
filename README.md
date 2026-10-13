# Craft Monitoring

A token-protected JSON endpoint that exposes information about a Craft CMS
installation (versions, plugins, environment, health, available updates) so an
external dashboard can keep an overview of all your sites.

It is the **agent** side: each site runs this plugin and exposes a read-only
report; a central dashboard polls every site and aggregates the results.

- Requires Craft CMS 5.8+ and PHP 8.2+
- Env-only configuration — no control-panel settings, no secrets in the database

## Installation

```sh
composer require zeixcom/craftmonitoring
./craft plugin/install craft-monitoring
```

## Configuration

Copy `config.example.php` (shipped with the plugin) to your project's
`config/craft-monitoring.php`:

```php
<?php

use craft\helpers\App;

return [
    'clientSecret' => App::env('MONITORING_CLIENT_SECRET'),
];
```

The plugin rejects any secret shorter than 32 characters (it returns `404` as
if the endpoint did not exist). Generate a strong secret:

```sh
openssl rand -hex 32
```

That gives you 64 hex characters (~256 bits of entropy). Put it in `.env`:

```dotenv
MONITORING_CLIENT_SECRET="<paste-output-here>"
```

Treat this secret like an admin password — rotate it if it leaks, and never
commit it. The matching value lives in the monitoring dashboard's config.

## Endpoint

```
POST <cpTrigger>/monitoring/api/system-report
```

The endpoint is mounted on the **CP URL manager**, so it sits behind any IP
allowlist, basic-auth, or WAF rule scoped to the CP host. If your CP trigger is
`admin`, the URL is `https://<site>/admin/monitoring/api/system-report`. If you
ever IP-restrict the CP at the webserver layer, the dashboard's egress IP must
be on that allowlist.

## Authentication

The endpoint accepts the secret in any of these places (first match wins):

| Method               | Example                          |
| -------------------- | -------------------------------- |
| HTTP header          | `X-Client-Secret: <secret>`      |
| Authorization bearer | `Authorization: Bearer <secret>` |
| POST form parameter  | `clientSecret=<secret>`          |
| JSON body field      | `{"clientSecret": "<secret>"}`   |

The URL query string is **not** accepted — secrets in URLs leak into webserver
logs, CDN logs, and browser history.

```sh
# Header (recommended)
curl -X POST \
  -H "X-Client-Secret: $MONITORING_CLIENT_SECRET" \
  https://<site>/admin/monitoring/api/system-report
```

## Response

A successful call returns `200` with a JSON body. The top-level `reportVersion`
(currently `1`) lets the dashboard evolve independently of the agent — bump it
on any breaking change to the payload shape. The body contains `reportVersion`,
`generatedAt`, `craft`, `php`, `database`, `environment`, `system`, `updates`,
`health`, `plugins`, `modules`.

### `updates`

Reads Craft's cached update info. If the cache is empty, the request first asks
the Craft update server once (Craft caches the result for 24 hours, or for
5 minutes if the check fails). Without this, a site where no admin opens the
control panel would never report updates.

| Field               | Meaning                                                                       |
| ------------------- | ----------------------------------------------------------------------------- |
| `infoCached`        | `false` ⇒ the update check failed; the counts below are stale (0/false)       |
| `available`         | Total available updates (Craft + plugins)                                     |
| `criticalAvailable` | A critical/security update is available                                       |
| `migrationsPending` | Code was updated but migrations haven't run                                   |

### `health`

| Field                         | Meaning                                                  |
| ----------------------------- | -------------------------------------------------------- |
| `queueTotalJobs`              | Pending queue jobs (incl. failed); steady growth ⇒ stuck |
| `deprecationWarnings`         | Count of logged deprecation warnings                     |
| `pendingProjectConfigChanges` | Project config YAML is out of sync with the database     |

Any health/update field is `null` if its probe threw.

### Update timestamps in `craft`

| Field                        | Meaning                                                          |
| ---------------------------- | ---------------------------------------------------------------- |
| `lastSystemOrPluginUpdateAt` | Last Craft CMS core or plugin update (use this in the dashboard) |
| `systemInfoUpdatedAt`        | Last `craft_info` write — includes project config applies        |

`lastSystemOrPluginUpdateAt` is derived from migration history (`craft` and
`plugin:*` tracks) plus a version fingerprint stored in
`storage/runtime/monitoring/last-system-update.json`. Project config applies
that only bump `systemInfoUpdatedAt` are ignored.

When the fingerprint changes but no migration is newer — a plugin upgrade that
ships no migrations, or a deploy landing before migrations run — the value falls
back to `systemInfoUpdatedAt` instead of the time the endpoint was polled. It is
a high-water mark: repeated calls never move it, and uninstalling a plugin
(which deletes its migration rows) can't push it backwards.

Both fields are ISO 8601 with a real offset. Migration times are read as UTC
regardless of the server's PHP timezone.

> Before 1.1.0, `lastSystemOrPluginUpdateAt` was stamped with the request time on
> any fingerprint change, and migration times were parsed in the server's default
> timezone. Dashboards talking to older installs should prefer
> `systemInfoUpdatedAt`.
>
> Because the value is a high-water mark, a wrong timestamp written by an older
> version survives the upgrade. Delete
> `storage/runtime/monitoring/last-system-update.json` once on affected installs
> to re-derive it.

## Security posture

- **Timing-safe comparison** via `hash_equals()`.
- **Opaque failures**: missing secret, invalid secret, wrong method, and
  unknown path all return the same `404` to unauthenticated callers — the
  endpoint is indistinguishable from a non-existent route. Failure reasons are
  still logged server-side at `warning` level.
- **CSRF disabled** (this is a token-based API, not a session endpoint).
- **No caching**: response sets `Cache-Control: no-store`.
- **Minimum secret length** enforced at 32 chars.
- **No sensitive identifiers** in the report: internal hostname, kernel version,
  and the Craft system UID are intentionally not emitted.

> **Dev-mode note:** with `CRAFT_DEV_MODE=true`, Craft's debug error page may
> render slightly different bodies for different 404 sources. With dev mode off
> (production), all 404s render the same template and the distinction
> disappears. Monitor production, not dev.

## Migrating from an in-repo `craftmonitoring` module

If you previously ran this as a copied Yii module:

1. `composer require zeixcom/craftmonitoring` and `./craft plugin/install craft-monitoring`.
2. Remove the module from `config/app.php` (`modules` + `bootstrap` entries) and
   delete `modules/craftmonitoring/`.
3. Rename `config/monitoring.php` → `config/craft-monitoring.php` (the plugin
   reads its config by handle). The `MONITORING_CLIENT_SECRET` env var is
   unchanged.

The route, payload, and `storage/runtime/monitoring/` state file are identical,
so the dashboard needs no changes.

## License

MIT — see [LICENSE.md](LICENSE.md).
