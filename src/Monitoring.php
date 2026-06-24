<?php

declare(strict_types=1);

namespace zeixcom\craftmonitoring;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Monitoring plugin.
 *
 * Exposes a token-protected JSON endpoint with information about the Craft
 * installation (versions, plugins, environment, health, available updates) so
 * an external dashboard can keep an overview of all your sites.
 *
 * The endpoint is mounted on the CP URL manager, so it sits behind any IP
 * allowlist, basic-auth, or WAF rule scoped to the CP host instead of being
 * exposed on the public site.
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

    /**
     * Returns the configured client secret, or null if it is missing or shorter
     * than the minimum length. The controller treats null as "endpoint not
     * configured" and responds 404.
     */
    public function getClientSecret(): ?string
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-monitoring');
        $secret = is_array($config) ? ($config['clientSecret'] ?? null) : null;

        if (!is_string($secret) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            return null;
        }

        return $secret;
    }
}
