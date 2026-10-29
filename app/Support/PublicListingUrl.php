<?php

namespace App\Support;

/** Where a listing's public page lives, from its kind (as the partner portal and the morph map name it). */
final class PublicListingUrl
{
    private const ROUTES = [
        'destination' => 'destinations.show',
        'accommodation' => 'accommodations.show',
        'restaurant' => 'restaurants.show',
        'package' => 'packages.show',
        'souvenir_center' => 'souvenir-centers.show',
        'tour_operator' => 'tour-operators.show',
    ];

    public static function for(string $kind, mixed $listing): ?string
    {
        $route = self::ROUTES[$kind] ?? null;

        return $route && $listing && filled($listing->slug) ? route($route, $listing->slug) : null;
    }
}
