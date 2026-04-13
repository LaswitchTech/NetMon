<?php

return [
    'driver' => 'sqlite',

    'sqlite' => [
        'path' => __DIR__ . '/../data/app.db',
    ],

    'mysql' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'netmon',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];
