<?php

/*
 * Feature switches that can be flipped on the server without a deploy.
 *
 * The value here is only the DEFAULT. A row in the `feature_flags` table overrides it, and the row is changed with
 *
 *     php artisan features:set trip_location_v2 on|off
 *
 * which needs no code change and no restart, so a change that misbehaves can be turned off in seconds.
 *
 * trip_location_v2 -- the 13 November 2026 changes to Plan My Trip: no past arrival date, a start point must be inside
 * Region XI, a trip end ("finish by") instead of a departure point, visitor origin as country / province / city, and the
 * admin view of visitor origin. Off = the planner behaves exactly as it did before.
 */
return [
    'trip_location_v2' => (bool) env('FEATURE_TRIP_LOCATION_V2', false),
];
