<?php

declare(strict_types=1);

namespace zeixcom\craftmonitoring;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use zeixcom\craftmonitoring\models\Settings;

/**
 * Token-protected JSON system report for an external monitoring dashboard.
 *
 * Mounted on the CP URL manager so the endpoint inherits any IP allowlist,
 * basic-auth, or WAF rule scoped to the CP host.
 *
 * @method static Monitoring getInstance()
 */
class Monitoring extends Plugin
{
    public string $schemaVersion = '1.0.0';

    private const MIN_SECRET_LENGTH = 32;

    public function init(): void
    {
        parent::init();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['POST monitoring/api/system-report'] = 'craft-monitoring/api/system-report';
            }
        );
    }

    public function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getClientSecret(): ?string
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-monitoring');
        $secret = is_array($config) ? ($config['clientSecret'] ?? null) : null;

        // null (missing or too short) is treated as "not configured" → 404.
        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            return null;
        }

        return $secret;
    }
}
