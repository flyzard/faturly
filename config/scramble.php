<?php

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [

    /*
     * Must track `apiPrefix` in bootstrap/app.php: a mismatch documents nothing, silently.
     */
    'api_path' => 'v1',

    'ui' => [
        'title' => 'Faturly API',
    ],

    'security_strategy' => MiddlewareAuthSecurityStrategy::class,

];
