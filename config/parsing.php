<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Yandex.Maps
    |--------------------------------------------------------------------------
    */

    'yandex' => [

        /*
         * Yandex serves 50 reviews per page and stops after twelve of them,
         * whatever the card's real review count. Raising this will not produce
         * more data; lowering it is a way to be gentler on the source.
         */
        'max_pages' => (int) env('YANDEX_MAX_PAGES', 12),

        'timeout' => (int) env('YANDEX_TIMEOUT', 30),

        /* Retries inside one HTTP call, before the queue's own retries. */
        'max_attempts' => (int) env('YANDEX_MAX_ATTEMPTS', 3),

        /*
         * Pause between page requests, randomised in this range. A fixed
         * interval is itself a bot signature, so the jitter is deliberate.
         */
        'throttle' => [
            'min_ms' => (int) env('YANDEX_THROTTLE_MIN_MS', 400),
            'max_ms' => (int) env('YANDEX_THROTTLE_MAX_MS', 900),
        ],

        /*
         * Comma-separated proxy URLs, e.g.
         * YANDEX_PROXIES="http://user:pass@1.2.3.4:8080,http://5.6.7.8:3128"
         * Empty means "go direct", which is fine at this volume.
         */
        'proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('YANDEX_PROXIES', '')),
        ))),

        /* How long a proxy stays out of rotation after it fails. */
        'proxy_park_seconds' => (int) env('YANDEX_PROXY_PARK_SECONDS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled refresh
    |--------------------------------------------------------------------------
    |
    | Connected cards are re-parsed on this cadence to keep the aggregates and
    | the change history current. Set to 0 to disable the schedule.
    |
    */

    'refresh' => [
        'enabled' => (bool) env('PARSING_REFRESH_ENABLED', true),
        'interval_hours' => (int) env('PARSING_REFRESH_INTERVAL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | API pagination
    |--------------------------------------------------------------------------
    */

    'reviews_per_page' => 50,

];
