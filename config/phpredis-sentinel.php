<?php

return [
    'override_laravel_redis' => env('REDIS_SENTINEL_OVERRIDE_LARAVEL_REDIS', true),

    'node_cache' => [
        // Seconds a resolved master/replica address may stay cached in this process.
        // 0 disables expiry (discouraged: stale topology delays failover detection in long-lived workers).
        'ttl' => env('REDIS_SENTINEL_NODE_CACHE_TTL', 15),
    ],

    'log' => [
        'channel' => env('REDIS_SENTINEL_LOG_CHANNEL', env('LOG_CHANNEL')),
        // Once per consuming class, error_log() a notice when a logging failure is
        // swallowed (opt-in: retry/failover telemetry is being lost otherwise).
        'notify_swallowed' => env('REDIS_SENTINEL_LOG_NOTIFY_SWALLOWED', false),
    ],
    'commands' => [
        'events' => [
            'emit_success' => env('REDIS_SENTINEL_EMIT_SUCCESS_EVENTS', false),
        ],
    ],

    // Global default for commands routed to replicas in read/write splitting mode.
    // A connection's own 'read_commands' key overrides this list.
    'read_commands' => [],

    'retry' => [
        'sentinel' => [
            'attempts' => 5,
            'delay' => 1000,
            'messages' => [
                'No master found for service',
                'No reachable Redis Sentinel host found',
            ],
        ],
        'redis' => [
            'attempts' => 5,
            'delay' => 1000,
            'messages' => [
                'broken pipe',
                'connection closed',
                'connection refused',
                'connection lost',
                'failed while reconnecting',
                'is loading the dataset in memory',
                'php_network_getaddresses',
                'read error on connection',
                'socket error',
                'went away',
                'Connection reset by peer',
                "can't write against a read only replica",
                'Temporary failure in name resolution',
            ],
        ],
    ],
];
