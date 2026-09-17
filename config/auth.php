<?php

use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\TouristAccount;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | ExploreDVO has no single generic user. Of the three actors in 2.3.2 two
    | log in with real credentials: the Tourism Administrator and the
    | DOT-Accredited Establishment.
    |
    | Trip planning itself still needs no account and collects no personal
    | data -- visits are counted by QR scan at the establishment, and saved
    | places and trip plans are kept against an opaque browser token by
    | default (see EnsureVisitorToken). The `tourist` guard below is a
    | separate, entirely OPTIONAL account (alias + password, no real
    | identity) a traveler may create only if they want an itinerary to
    | survive past the browser session -- it never gates any core feature.
    | The default guard is the admin guard, which is the only one a bare
    | auth() call could sensibly mean.
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

        'tourist' => [
            'driver' => 'session',
            'provider' => 'tourist_accounts',
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

        'tourist_accounts' => [
            'driver' => 'eloquent',
            'model' => TouristAccount::class,
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
