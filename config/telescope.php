<?php

return [

    /*
     * Telescope's migration pins its own connection, so it has to name the owning role
     * rather than inherit the DML-only default.
     */
    'storage' => [
        'database' => [
            'connection' => env('TELESCOPE_DB_CONNECTION', 'pgsql_migrator'),
            'chunk' => 1000,
        ],
    ],

];
