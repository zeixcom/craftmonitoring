<?php

/**
 * Craft Monitoring plugin config.
 *
 * Copy this file to your project's `config/craft-monitoring.php`.
 *
 * The secret must be at least 32 characters or the endpoint responds 404 as if
 * it did not exist. Generate one with: openssl rand -hex 32
 */

use craft\helpers\App;

return [
    'clientSecret' => App::env('MONITORING_CLIENT_SECRET'),
];
