<?php

return [
    'feed' => [
        'enabled' => env('IPOSPAYS_FEED_ENABLED', false),
        'diagnostic_mode' => env('IPOSPAYS_FEED_DIAGNOSTIC_MODE', false),
        'hmac_secret' => env('IPOSPAYS_FEED_HMAC_SECRET'),
        'hmac_profile' => env('IPOSPAYS_FEED_HMAC_PROFILE', 'unfinalized'),
        'mapping_profile' => env('IPOSPAYS_FEED_MAPPING_PROFILE', 'unfinalized'),
        'max_payload_bytes' => (int) env('IPOSPAYS_FEED_MAX_PAYLOAD_BYTES', 262144),
    ],
];
