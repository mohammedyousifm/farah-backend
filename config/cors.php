<?php

/*
 * Which websites may call the API from a browser. The mobile app is not a browser and is not affected.
 * Local set-ups allow any origin (Flutter web runs on a random port); everywhere else only the origins
 * listed in CORS_ALLOWED_ORIGINS (comma separated), or the site's own address when that is empty.
 */
$listed = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $listed ?: (env('APP_ENV') === 'local' ? ['*'] : [rtrim((string) env('APP_URL', ''), '/')]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
