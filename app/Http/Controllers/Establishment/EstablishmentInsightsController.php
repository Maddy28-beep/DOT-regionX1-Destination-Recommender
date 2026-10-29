<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Services\Establishment\ListingInsights;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EstablishmentInsightsController extends Controller
{
    public function index(Request $request, ListingInsights $insights): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        $range = (int) $request->query('range', 30);
        $range = in_array($range, ListingInsights::RANGES, true) ? $range : 30;

        $kind = $establishment->listing_kind;
        $checkIns = $insights->checkIns($kind, $listing->id, $range);
        $series = $insights->series($kind, $listing->id, $range);
        $tripPlans = $insights->tripPlans($kind, $listing->id, $range);

        return view('establishment.insights', compact('establishment', 'listing', 'range', 'checkIns', 'series', 'tripPlans'));
    }
}
