<?php

use App\Models\AdminUser;
use App\Models\EstablishmentAccount;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | ExploreDVO has no single generic user. Of the three actors in 2.3.2 only
    | two log in: the Tourism Administrator and the DOT-Accredited
    | Establishment, each authenticated independently.
    |
    | Travelers have no accounts at all. Registration and login were removed
    | for Data Privacy Act compliance -- the public site collects no personal
    | data, visits are counted by QR scan at the establishment, and saved
    | places and trip plans are kept against an opaque browser token (see
    | EnsureVisitorToken). The default guard is therefore the admin guard,
    | which is the only one a bare auth() call could sensibly mean.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admin_users'),
    ],

    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],

        'establishment' => [
            'driver' => 'session',
            'provider' => 'establishment_accounts',
        ],
    ],

    'providers' => [
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],

        'establishment_accounts' => [
            'driver' => 'eloquent',
            'model' => EstablishmentAccount::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'admin_users' => [
            'provider' => 'admin_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
