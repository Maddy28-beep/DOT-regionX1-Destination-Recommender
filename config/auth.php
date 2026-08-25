<?php

use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\Tourist;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | ExploreDVO has no single generic user: 2.3.2 defines three actors
    | (Tourist, Tourism Administrator, DOT-Accredited Establishment), each
    | authenticated independently. The "tourist" guard is the default since
    | it's the public-facing surface of the platform.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'tourist'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'tourists'),
    ],

    'guards' => [
        'tourist' => [
            'driver' => 'session',
            'provider' => 'tourists',
        ],

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
        'tourists' => [
            'driver' => 'eloquent',
            'model' => Tourist::class,
        ],

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
        'tourists' => [
            'provider' => 'tourists',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
