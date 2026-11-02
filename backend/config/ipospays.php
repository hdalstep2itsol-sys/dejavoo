<?php

return [
    'feed' => [
        'enabled' => env('IPOSPAYS_FEED_ENABLED', false),
        'auth_mode' => env('IPOSPAYS_FEED_AUTH_MODE', 'hmac'),
        'basic_username' => env('IPOSPAYS_FEED_BASIC_USERNAME'),
        'basic_password' => env('IPOSPAYS_FEED_BASIC_PASSWORD'),
        'diagnostic_mode' => env('IPOSPAYS_FEED_DIAGNOSTIC_MODE', false),
        'auth_diagnostics' => env('IPOSPAYS_FEED_AUTH_DIAGNOSTICS', false),
        'hmac_secret' => env('IPOSPAYS_FEED_HMAC_SECRET'),
        'hmac_profile' => env('IPOSPAYS_FEED_HMAC_PROFILE', 'unfinalized'),
        'mapping_profile' => env('IPOSPAYS_FEED_MAPPING_PROFILE', 'unfinalized'),
        'timezone' => env('IPOSPAYS_FEED_TIMEZONE', 'America/New_York'),
        'max_payload_bytes' => (int) env('IPOSPAYS_FEED_MAX_PAYLOAD_BYTES', 262144),
    ],
];
