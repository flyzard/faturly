<?php

use Laravel\Fortify\Features;

return [

    'lowercase_usernames' => true,

    'home' => '/dashboard',

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],

    /*
     * Profile and password updates are handled by this app's own settings controllers,
     * so Fortify's versions of those features stay off.
     */
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ],

];
