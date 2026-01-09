<?php

return [
    'log' => [
        'channel' => null,
    ],
    'retry' => [
        'sentinel' => [
            'attempts' => 5,
            'delay' => 1000,
            'messages' => [
                'No master found for service',
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
                'socket',
                'went away',
                'loading',
                'readonly',
                "can't write against a read only replica",
            ],
        ],
    ],
];
