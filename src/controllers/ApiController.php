<?php

declare(strict_types=1);

namespace zeixcom\craftmonitoring\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Throwable;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftmonitoring\Monitoring;
use zeixcom\craftmonitoring\services\SystemUpdateTracker;

/**
 * Monitoring API controller.
 *
 * Provides a token-protected JSON system report so an external dashboard can
 * keep tabs on Craft versions, installed plugins, the environment, etc.
 *
 * Endpoint: POST <cpTrigger>/monitoring/api/system-report
 *
 * Authentication accepts the secret in any of these places (first match wins):
 *   - JSON body field:           {"clientSecret": "..."}
 *   - POST parameter:            clientSecret=...
 *   - HTTP header:               X-Client-Secret: ...
 *   - Authorization bearer:      Authorization: Bearer ...
 *
 * The URL query string is intentionally NOT accepted — secrets in URLs leak
 * into webserver and CDN logs, browser history, and Referer headers.
 */
class ApiController extends Controller
{
    /**
     * @var array<string, int>|int|bool Anonymous access — auth is handled via the client secret.
     * Permitted while the system is live OR offline so monitoring still works during maintenance.
     */
    protected array|int|bool $allowAnonymous = [
        'system-report' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
    ];

    /**
     * @var bool API endpoint — disable CSRF so external dashboards can call it.
     */
    public $enableCsrfValidation = false;

    /**
     * Returns a JSON report describing this Craft installation.
     */
    public function actionSystemReport(): Response
    {
        $this->requirePostRequest();
        $this->ensureAuthenticated();

        $data = [
            'reportVersion' => 1,
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'craft' => $this->craftInfo(),
            'php' => $this->phpInfo(),
            'database' => $this->databaseInfo(),
            'environment' => $this->environmentInfo(),
            'system' => $this->systemInfo(),
            'updates' => $this->updatesInfo(),
            'health' => $this->healthInfo(),
            'plugins' => $this->pluginsInfo(),
            'modules' => $this->modulesInfo(),
        ];

        $response = $this->asJson($data);

        // Never let an upstream proxy/CDN serve a stale monitoring snapshot.
        $response->getHeaders()
            ->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Both "not configured" and "bad secret" return the same 404 so an
     * unauthenticated caller cannot tell the endpoint from a missing route.
     */
    private function ensureAuthenticated(): void
    {
        $expected = Monitoring::getInstance()->getClientSecret();

        if ($expected === null) {
            Yii::warning(
                'Monitoring endpoint called but client secret is missing or below minimum length.',
                __METHOD__
            );
            throw new NotFoundHttpException();
        }

        $provided = $this->extractProvidedSecret();

        if ($provided === null || !hash_equals($expected, $provided)) {
            Yii::warning('Monitoring endpoint called with missing or invalid secret.', __METHOD__);
            throw new NotFoundHttpException();
        }
    }

    /**
     * Pulls the secret from POST body or auth headers. The URL query string
     * is intentionally not accepted (see class-level note).
     */
    private function extractProvidedSecret(): ?string
    {
        $request = Craft::$app->getRequest();

        $secret = $request->getBodyParam('clientSecret')
            ?? $request->getHeaders()->get('X-Client-Secret');

        if (!$secret) {
            $auth = $request->getHeaders()->get('Authorization');
            if ($auth && stripos((string) $auth, 'Bearer ') === 0) {
                $secret = trim(substr((string) $auth, 7));
            }
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function craftInfo(): array
    {
        $app = Craft::$app;
        $info = null;

        try {
            $info = $app->getInfo();
        } catch (Throwable) {
            // Craft may not be fully installed yet — fall through with nulls.
        }

        $systemInfoUpdatedAt = $info?->dateUpdated?->format(DATE_ATOM);

        return [
            'version' => Craft::$app->getVersion(),
            'edition' => Craft::$app->getEditionHandle(),
            'schemaVersion' => $info?->schemaVersion,
            'configVersion' => $info?->configVersion,
            'fieldVersion' => $info?->fieldVersion,
            'maintenanceMode' => (bool) ($info->maintenance ?? false),
            'lastSystemOrPluginUpdateAt' => (new SystemUpdateTracker())->getLastSystemOrPluginUpdateAt(),
            'systemInfoUpdatedAt' => $systemInfoUpdatedAt,
            'dateCreated' => $info?->dateCreated?->format(DATE_ATOM),
            'devMode' => (bool) App::env('CRAFT_DEV_MODE'),
        ];
    }

    private function phpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'timezone' => date_default_timezone_get(),
            'memoryLimit' => ini_get('memory_limit'),
            'maxExecutionTime' => (int) ini_get('max_execution_time'),
            'extensions' => $this->keyExtensions(),
        ];
    }

    /**
     * Only report a curated list of common extensions to avoid noisy output.
     */
    private function keyExtensions(): array
    {
        $watch = ['gd', 'imagick', 'pdo_mysql', 'pdo_pgsql', 'intl', 'mbstring', 'curl', 'openssl', 'zip', 'redis', 'apcu', 'opcache'];
        $loaded = [];
        foreach ($watch as $name) {
            $loaded[$name] = extension_loaded($name);
        }

        return $loaded;
    }

    private function databaseInfo(): array
    {
        $db = Craft::$app->getDb();
        $version = null;

        try {
            $version = $db->getServerVersion();
        } catch (Throwable) {
            // ignore
        }

        return [
            'driver' => $db->getDriverName(),
            'version' => $version,
            'schema' => App::env('CRAFT_DB_SCHEMA') ?: null,
        ];
    }

    private function environmentInfo(): array
    {
        return [
            'name' => App::env('CRAFT_ENVIRONMENT') ?: null,
            'primarySiteUrl' => App::env('PRIMARY_SITE_URL') ?: null,
            'systemName' => Craft::$app->getSystemName(),
            'timezone' => Craft::$app->getTimeZone(),
            'language' => Craft::$app->language,
        ];
    }

    private function systemInfo(): array
    {
        return [
            'os' => PHP_OS_FAMILY,
            'yiiVersion' => \Yii::getVersion(),
        ];
    }

    /**
     * Available-update status. Reads Craft's *cached* update info only — never
     * triggers an outbound call to the update server on the monitoring request.
     * `infoCached` is false when Craft hasn't checked recently, in which case
     * `available`/`criticalAvailable` are 0/false rather than current.
     */
    private function updatesInfo(): array
    {
        $updates = Craft::$app->getUpdates();

        try {
            $cached = $updates->getIsUpdateInfoCached();

            return [
                'infoCached' => $cached,
                'available' => $updates->getTotalAvailableUpdates(),
                'criticalAvailable' => $updates->getIsCriticalUpdateAvailable(),
                'migrationsPending' => $updates->getAreMigrationsPending(),
            ];
        } catch (Throwable) {
            return [
                'infoCached' => false,
                'available' => null,
                'criticalAvailable' => null,
                'migrationsPending' => null,
            ];
        }
    }

    /**
     * Operational health signals. Each is wrapped so one failing probe never
     * takes down the whole report.
     */
    private function healthInfo(): array
    {
        return [
            'queueTotalJobs' => $this->safeInt(fn(): int => Craft::$app->getQueue()->getTotalJobs()),
            'deprecationWarnings' => $this->safeInt(fn(): int => Craft::$app->getDeprecator()->getTotalLogs()),
            'pendingProjectConfigChanges' => $this->safeBool(fn(): bool => Craft::$app->getProjectConfig()->areChangesPending()),
        ];
    }

    private function safeInt(callable $probe): ?int
    {
        try {
            return $probe();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeBool(callable $probe): ?bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns a list of installed plugins with version and edition info.
     */
    private function pluginsInfo(): array
    {
        $plugins = [];
        $pluginsService = Craft::$app->getPlugins();

        // Active / loaded plugins
        foreach ($pluginsService->getAllPlugins() as $allPlugin) {
            $handle = $allPlugin->id;
            $stored = $pluginsService->getStoredPluginInfo($handle);

            $plugins[$handle] = [
                'handle' => $handle,
                'name' => $allPlugin->name,
                'developer' => $allPlugin->developer,
                'version' => $allPlugin->getVersion(),
                'schemaVersion' => $allPlugin->schemaVersion,
                'edition' => method_exists($allPlugin, 'getEdition') ? $allPlugin->getEdition() : null,
                'isInstalled' => true,
                'isEnabled' => true,
                'documentationUrl' => $allPlugin->documentationUrl,
                'changelogUrl' => $allPlugin->changelogUrl,
                'installedVersion' => $stored['version'] ?? null,
            ];
        }

        // Also include installed-but-disabled plugins so the dashboard sees them
        try {
            $all = $pluginsService->getAllPluginInfo();
            foreach ($all as $handle => $info) {
                if (isset($plugins[$handle])) {
                    $plugins[$handle]['isEnabled'] = (bool) ($info['isEnabled'] ?? true);
                    continue;
                }

                $plugins[$handle] = [
                    'handle' => $handle,
                    'name' => $info['name'] ?? $handle,
                    'developer' => $info['developer'] ?? null,
                    'version' => $info['version'] ?? null,
                    'schemaVersion' => $info['schemaVersion'] ?? null,
                    'edition' => $info['edition'] ?? null,
                    'isInstalled' => (bool) ($info['isInstalled'] ?? false),
                    'isEnabled' => (bool) ($info['isEnabled'] ?? false),
                    'documentationUrl' => $info['documentationUrl'] ?? null,
                    'changelogUrl' => $info['changelogUrl'] ?? null,
                    'installedVersion' => $info['version'] ?? null,
                ];
            }
        } catch (Throwable) {
            // getAllPluginInfo() may throw very early; the enabled list above is enough.
        }

        ksort($plugins);

        return array_values($plugins);
    }

    /**
     * Returns the list of registered Yii modules (handles only — no secrets).
     */
    private function modulesInfo(): array
    {
        $ids = array_keys(Craft::$app->getModules());
        sort($ids);
        return $ids;
    }
}
