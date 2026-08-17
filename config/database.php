<?php

$pgsql = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
];

return [

    'connections' => [

        'pgsql' => $pgsql,

        /*
         * Migrations run as the table owner; the application never does. Keeping the runtime
         * role free of ownership is what stops it disabling its own row-level security.
         */
        'pgsql_migrator' => [
            ...$pgsql,
            'username' => env('DB_MIGRATE_USERNAME', 'joaofelizardo3'),
            'password' => env('DB_MIGRATE_PASSWORD', ''),
        ],

    ],

];
