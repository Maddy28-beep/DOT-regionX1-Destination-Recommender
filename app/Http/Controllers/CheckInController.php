<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Http\Middleware\EnsureVisitorToken;
use App\Models\TouristVisit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * QR-code check-in: scanning the code displayed at an establishment (see
 * QrCodeController) lands here, records the visit, and forwards to that
 * listing's public detail page.
 *
 * No login, and nothing personal is stored. The visit is attributed to an
 * opaque random browser token (EnsureVisitorToken), which exists only so the
 * same phone cannot count the same establishment twice in one day — the
 * double-counting case DOT raised. Counts stay accurate; the visitor stays
 * anonymous.
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

        $today = now()->toDateString();

        $attributes = [
            'visitor_token' => EnsureVisitorToken::get($request),
            'listing_kind' => $config['kind'],
            'listing_id' => $listing->id,
        ];

        /*
         * One row per (browser, listing, day), so re-scanning the same code
         * later the same day never counts a second visit — this is the "Alex
         * visits Eden Park twice in one day" double-counting concern DOT
         * raised, still handled now that there is no account to key it on.
         *
         * whereDate rather than a plain equality on visit_date: the column is
         * cast to a date, so Eloquent stores it as "2026-08-30 00:00:00" while
         * toDateString() produces "2026-08-30". Comparing those directly never
         * matched, so every rescan fell through to an INSERT and died on the
         * unique index instead of saying "you already checked in today".
         */
        $visit = TouristVisit::where($attributes)->whereDate('visit_date', $today)->first();

        if (! $visit) {
            /*
             * A DB-level unique constraint backs the check above
             * (visits_unique_daily_per_browser), so two near-simultaneous
             * requests — a fast double-tap, a retried scan — can't both slip
             * past the SELECT and insert duplicate rows. The loser of that
             * race lands in the catch and reads back the winner's row.
             */
            try {
                $visit = TouristVisit::create($attributes + ['visit_date' => $today, 'source' => 'qr_scan']);
            } catch (UniqueConstraintViolationException) {
                $visit = TouristVisit::where($attributes)->whereDate('visit_date', $today)->firstOrFail();
            }
        }

        $status = $visit->wasRecentlyCreated
            ? "You're checked in at {$listing->name}! Thanks for visiting."
            : "You already checked in at {$listing->name} today.";

        return redirect()->route($config['route'], $listing)->with('status', $status);
    }
}
