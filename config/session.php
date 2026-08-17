<?php

use Illuminate\Support\Str;

return [

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session',
    ),

    'serialization' => 'json',

];
