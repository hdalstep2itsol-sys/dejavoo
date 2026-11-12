<?php

return [
    'feed_health' => [
        'recent_failure_seconds' => (int) env('IPOSPAYS_FEED_FAILURE_RECENT_SECONDS', 86400),
    ],

    'feed_alerts' => [
        'recipient' => env('IPOSPAYS_FEED_ALERT_EMAIL'),
        'threshold' => (int) env('IPOSPAYS_FEED_FAILURE_ALERT_THRESHOLD', 3),
        'window_seconds' => (int) env('IPOSPAYS_FEED_FAILURE_ALERT_WINDOW_SECONDS', 900),
    ],
];
