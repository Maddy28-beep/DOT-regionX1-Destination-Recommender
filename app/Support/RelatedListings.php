<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The few places shown under a listing's page ("More in Davao City"). A place with a real photograph is
 * a better invitation than one showing the stock illustration, so those come first; among each kind the
 * order the caller gave (best rated first) is kept. When too few have a real photograph, the rest fill in.
 */
class RelatedListings
{
    public static function pick(Collection $candidates, int $take = 3): Collection
    {
        $hasRealPhoto = function ($listing): bool {
            $cover = method_exists($listing, 'coverPhoto') ? $listing->coverPhoto() : null;

            return $cover !== null && ! $cover->isIllustration();
        };

        // sortBy is stable, so each group keeps the caller's order.
        return $candidates->sortBy(fn ($listing) => $hasRealPhoto($listing) ? 0 : 1)->take($take)->values();
    }
}
