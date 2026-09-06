<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The homepage hero's search bar.
 *
 * It used to GET straight to the trip planner, which read none of its four
 * inputs -- so every selection was silently dropped and the button did nothing
 * but navigate. This turns it into what it looks like: a search that lands the
 * visitor on real, filtered results.
 *
 * "I want to..." chooses WHICH catalogue, the way a travel site's Hotels /
 * Things-to-do tabs do; the remaining answers become filters on it. A filter
 * that cannot mean anything for the chosen catalogue is dropped rather than
 * faked -- interest says nothing about a hotel, and only packages record a
 * duration -- so nothing is passed along that the destination page would have
 * to quietly ignore.
 */
class SearchController extends Controller
{
    /** "I want to..." -> the catalogue that answers it. */
    private const PURPOSE_ROUTES = [
        'destinations' => 'destinations.index',
        'accommodations' => 'accommodations.index',
        'packages' => 'packages.index',
        'restaurants' => 'restaurants.index',
    ];

    /** Which catalogues can actually act on each of the other answers. */
    private const SUPPORTS_INTEREST = ['destinations', 'packages'];

    private const SUPPORTS_DURATION = ['packages'];

    public function __invoke(Request $request): RedirectResponse
    {
        $purpose = (string) $request->string('purpose');
        $purpose = isset(self::PURPOSE_ROUTES[$purpose]) ? $purpose : 'destinations';

        $filters = [];

        // Every one of these catalogues records a price tier, and the hero's
        // budget options are already its exact vocabulary.
        if ($request->filled('budget')) {
            $filters['price_tier'] = (string) $request->string('budget');
        }

        if ($request->filled('interest') && in_array($purpose, self::SUPPORTS_INTEREST, true)) {
            $filters['interest'] = (string) $request->string('interest');
        }

        if ($request->filled('duration') && in_array($purpose, self::SUPPORTS_DURATION, true)) {
            $filters['duration'] = (string) $request->string('duration');
        }

        return redirect()->route(self::PURPOSE_ROUTES[$purpose], $filters);
    }
}
