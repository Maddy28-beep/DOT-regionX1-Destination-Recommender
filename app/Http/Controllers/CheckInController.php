<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristVisit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * QR-code check-in: scanning a listing's QR code (see QrCodeController) now
 * lands here instead of going straight to the public detail page. If the
 * scanning tourist is logged in, this records a real TouristVisit row before
 * continuing on to that same detail page — the missing piece behind
 * accurate, DOT-requested "how many unique tourists visited today" reporting
 * (see AdminDashboardController::verifiedVisitsReport()).
 */
class CheckInController extends Controller
{
    /**
     * Maps the QR code's URL-segment listing type to its model, the
     * polymorphic listing_kind value used everywhere else in the system,
     * and its public detail route.
     */
    private const TYPES = [
        'destinations' => ['model' => Destination::class, 'kind' => 'destination', 'route' => 'destinations.show'],
        'accommodations' => ['model' => Accommodation::class, 'kind' => 'accommodation', 'route' => 'accommodations.show'],
        'restaurants' => ['model' => Restaurant::class, 'kind' => 'restaurant', 'route' => 'restaurants.show'],
        'packages' => ['model' => Package::class, 'kind' => 'package', 'route' => 'packages.show'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'route' => 'souvenir-centers.show'],
        'tour-operators' => ['model' => TourOperator::class, 'kind' => 'tour_operator', 'route' => 'tour-operators.show'],
    ];

    public function checkIn(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $config = self::TYPES[$type];

        $listing = $config['model']::findOrFail($id);
        abort_unless($listing->is_accredited && ! $listing->archived_at, 404, 'Check-in is only available for currently DOT-accredited, active listings.');

        $tourist = $request->user('tourist');

        $attributes = [
            'tourist_id' => $tourist->id,
            'listing_kind' => $config['kind'],
            'listing_id' => $listing->id,
            'visit_date' => now()->toDateString(),
        ];

        // firstOrCreate on (tourist, listing, today) so re-scanning the same
        // code later the same day never creates a second row for the same
        // visit — this is the actual fix for the "Alex visits Eden Park
        // twice in one day" double-counting concern DOT raised. A DB-level
        // unique constraint backs this too (tourist_visits_unique_daily), so
        // two near-simultaneous requests (a fast double-tap, a retried scan)
        // can't both slip past the SELECT and insert duplicate rows — the
        // loser of that race hits the catch below instead.
        try {
            $visit = TouristVisit::firstOrCreate($attributes, ['source' => 'qr_scan']);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $visit = TouristVisit::where($attributes)->firstOrFail();
        }

        $status = $visit->wasRecentlyCreated
            ? "You're checked in at {$listing->name}! Thanks for visiting."
            : "You already checked in at {$listing->name} today.";

        return redirect()->route($config['route'], $listing)->with('status', $status);
    }
}
