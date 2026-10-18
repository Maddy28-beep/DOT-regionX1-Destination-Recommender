<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The homepage hero's search card.
 *
 * It used to GET straight to the trip planner, which read none of its four
 * inputs -- so every selection was silently dropped and the button did nothing
 * but navigate. This turns it into what it looks like: a search that lands the
 * visitor on real, filtered results.
 *
 * The tab ("purpose") chooses WHICH catalogue, the way a travel site's Hotels /
 * Things-to-do tabs do; a typed name and the other answers become filters on
 * it. Each catalogue is only sent the filters its own listing page can apply
 * (FILTERS below), so nothing is passed along that the page would have to
 * quietly ignore -- the card shows a tab only the boxes that tab can use, and
 * this is the server-side half of the same rule.
 */
class SearchController extends Controller
{
    /** The tab -> the catalogue that answers it. */
    private const PURPOSE_ROUTES = [
        'destinations' => 'destinations.index',
        'accommodations' => 'accommodations.index',
        'packages' => 'packages.index',
        'restaurants' => 'restaurants.index',
        'souvenir_centers' => 'souvenir-centers.index',
    ];

    /**
     * The filters each catalogue's listing page reads, besides a name (`q`) which they all take.
     * region_id is the province or city, which every catalogue records.
     */
    private const FILTERS = [
        'destinations' => ['region_id', 'interest'],
        'accommodations' => ['region_id', 'type'],
        'restaurants' => ['region_id', 'cuisine_type'],
        'packages' => ['region_id', 'interest', 'duration'],
        'souvenir_centers' => ['region_id'],
    ];

    /**
     * The catalogues that record a price tier, which the budget choice filters on. Souvenir shops record
     * none, so a budget sent to them could only be ignored.
     */
    private const HAS_PRICE_TIER = ['destinations', 'accommodations', 'restaurants', 'packages'];

    public function __invoke(Request $request): RedirectResponse
    {
        $purpose = (string) $request->string('purpose');
        $purpose = isset(self::PURPOSE_ROUTES[$purpose]) ? $purpose : 'destinations';

        $filters = [];

        // A name typed into the search box: every catalogue searches its listings by name (`q`), so it is
        // carried to whichever one was chosen. Kept short, as the field itself is.
        $name = mb_substr(trim((string) $request->string('q')), 0, 100);
        if ($name !== '') {
            $filters['q'] = $name;
        }

        if ($request->filled('budget') && in_array($purpose, self::HAS_PRICE_TIER, true)) {
            $filters['price_tier'] = (string) $request->string('budget');
        }

        foreach (self::FILTERS[$purpose] as $key) {
            if (! $request->filled($key)) {
                continue;
            }

            // The province or city is an id; anything else is a value from a list on the page, so kept short.
            if ($key === 'region_id') {
                // Only a whole number counts: "1; DROP TABLE" must not be quietly read as 1.
                $id = filter_var($request->input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id !== false) {
                    $filters[$key] = $id;
                }

                continue;
            }

            $value = mb_substr(trim((string) $request->string($key)), 0, 80);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return redirect()->route(self::PURPOSE_ROUTES[$purpose], $filters);
    }
}
