<?php

declare(strict_types=1);

namespace zeixcom\craftmonitoring\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\FileHelper;
use DateTimeImmutable;
use JsonException;
use Throwable;

/**
 * Tracks the last Craft CMS core or plugin update, independent of project
 * config applies that also bump craft_info.dateUpdated.
 */
class SystemUpdateTracker
{
    private const STATE_FILENAME = 'last-system-update.json';

    public function getLastSystemOrPluginUpdateAt(): ?string
    {
        $migrationAt = $this->lastUpdateFromMigrations();
        $fingerprint = $this->buildVersionFingerprint();
        $state = $this->readState();

        if ($state === null) {
            $this->writeState($fingerprint, $migrationAt);

            return $migrationAt;
        }

        $storedFingerprint = $state['fingerprint'] ?? null;
        $storedAt = is_string($state['lastUpdateAt'] ?? null) ? $state['lastUpdateAt'] : null;

        if ($storedFingerprint === $fingerprint) {
            return $this->laterOf($storedAt, $migrationAt);
        }

        $newAt = ($migrationAt !== null && ($storedAt === null || strtotime($migrationAt) > strtotime($storedAt)))
            ? $migrationAt
            : (new DateTimeImmutable())->format(DATE_ATOM);

        $this->writeState($fingerprint, $newAt);

        return $newAt;
    }

    private function lastUpdateFromMigrations(): ?string
    {
        try {
            $applyTime = (new Query())
                ->from([Table::MIGRATIONS])
                ->where([
                    'or',
                    [
                        'track' => 'craft',
                    ],
                    ['like', 'track', 'plugin:', false],
                ])
                ->max('applyTime');
        } catch (Throwable) {
            return null;
        }

        if (!is_string($applyTime) || $applyTime === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($applyTime))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{craftVersion: string, plugins: array<string, string|null>}
     */
    private function buildVersionFingerprint(): array
    {
        $plugins = [];

        try {
            foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $info) {
                if (!($info['isInstalled'] ?? false)) {
                    continue;
                }

                $plugins[$handle] = $info['version'] ?? null;
            }
        } catch (Throwable) {
            foreach (Craft::$app->getPlugins()->getAllPlugins() as $allPlugin) {
                $plugins[$allPlugin->id] = $allPlugin->getVersion();
            }
        }

        ksort($plugins);

        return [
            'craftVersion' => Craft::$app->getVersion(),
            'plugins' => $plugins,
        ];
    }

    /**
     * @return array{fingerprint?: array, lastUpdateAt?: string}|null
     */
    private function readState(): ?array
    {
        $path = $this->stateFilePath();

        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{craftVersion: string, plugins: array<string, string|null>} $fingerprint
     */
    private function writeState(array $fingerprint, ?string $lastUpdateAt): void
    {
        $path = $this->stateFilePath();
        FileHelper::createDirectory(dirname($path));

        file_put_contents(
            $path,
            json_encode([
                'fingerprint' => $fingerprint,
                'lastUpdateAt' => $lastUpdateAt,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function stateFilePath(): string
    {
        return Craft::getAlias('@storage/runtime/monitoring/' . self::STATE_FILENAME);
    }

    private function laterOf(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return strtotime($a) >= strtotime($b) ? $a : $b;
    }
}
