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
 * Token-protected JSON system report for an external monitoring dashboard.
 *
 * Endpoint: POST <cpTrigger>/monitoring/api/system-report
 * Auth: shared secret via JSON body, X-Client-Secret header, or Bearer token —
 * never the query string, which leaks into logs, history, and Referer headers.
 */
class ApiController extends Controller
{
    // Auth is the client secret; allow live + offline so monitoring survives maintenance mode.
    protected array|int|bool $allowAnonymous = [
        'system-report' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE,
    ];

    // Token API, not a session endpoint.
    public $enableCsrfValidation = false;

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

        // No proxy/CDN should ever serve a stale monitoring snapshot.
        $response->getHeaders()
            ->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->set('Pragma', 'no-cache');

        return $response;
    }

    private function ensureAuthenticated(): void
    {
        $expected = Monitoring::getInstance()->getClientSecret();

        // Same 404 whether unconfigured or wrong secret, so the endpoint is
        // indistinguishable from a missing route. Reasons are logged, not returned.
        if ($expected === null) {
            Yii::warning('Monitoring endpoint called but client secret is missing or below minimum length.', __METHOD__);
            throw new NotFoundHttpException();
        }

        $provided = $this->extractProvidedSecret();

        if ($provided === null || !hash_equals($expected, $provided)) {
            Yii::warning('Monitoring endpoint called with missing or invalid secret.', __METHOD__);
            throw new NotFoundHttpException();
        }
    }

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
        $info = null;

        try {
            $info = Craft::$app->getInfo();
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

    // Cached update info only — never triggers an outbound call on the request.
    // infoCached === false means the counts are stale (0/false), not current.
    private function updatesInfo(): array
    {
        $updates = Craft::$app->getUpdates();

        try {
            return [
                'infoCached' => $updates->getIsUpdateInfoCached(),
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

    // Each probe is null-guarded so one failure can't sink the whole report.
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

    private function pluginsInfo(): array
    {
        $plugins = [];
        $pluginsService = Craft::$app->getPlugins();

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

        // Installed-but-disabled plugins too, so the dashboard still sees them.
        try {
            foreach ($pluginsService->getAllPluginInfo() as $handle => $info) {
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
            // getAllPluginInfo() can throw very early; the enabled list above is enough.
        }

        ksort($plugins);

        return array_values($plugins);
    }

    private function modulesInfo(): array
    {
        $ids = array_keys(Craft::$app->getModules());
        sort($ids);
        return $ids;
    }
}
