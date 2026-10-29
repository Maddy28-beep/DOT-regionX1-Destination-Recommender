<?php

namespace App\Support;

/**
 * Links that open Google Maps with directions TO a place.
 *
 * The origin is left out on purpose: Google Maps then starts from the visitor's own location (on a phone it asks
 * for it; on a computer it asks for a starting point). A plain place search, which these links used to open,
 * only showed a pin and left the visitor to find the Directions button.
 */
class MapLinks
{
    /** Directions to a point; the coordinates when there are any, else the name and address. */
    public static function directionsTo(mixed $lat, mixed $lng, ?string $name, ?string $location = null): string
    {
        $destination = filled($lat) && filled($lng)
            ? $lat.','.$lng
            : trim((string) $name.' '.(string) $location);

        return 'https://www.google.com/maps/dir/?'.http_build_query(['api' => 1, 'destination' => $destination, 'travelmode' => 'driving']);
    }
}
