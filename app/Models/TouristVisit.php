<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded visit to an establishment.
 *
 * ---------------------------------------------------------------------------
 * HOW AN ESTABLISHMENT'S VISIT COUNT WORKS
 * ---------------------------------------------------------------------------
 *
 * A visit count is simply the number of rows in this table for that listing.
 * There is no counter column anywhere to increment, drift, or get out of sync:
 * the count is derived by querying these rows, always.
 *
 * The full path, end to end:
 *
 *  1. EACH LISTING GETS ITS OWN QR CODE.
 *     QrCodeController renders an SVG per listing. The code does NOT encode
 *     the listing's detail page -- it encodes that listing's check-in URL,
 *     /check-in/{type}/{id} (QrCodeController::targetUrlFor). Two listings
 *     therefore never produce the same code. The establishment prints theirs
 *     from the partner portal (Listing -> QR code); DOT staff can pull the
 *     same code for any listing from the admin console.
 *
 *  2. THE TRAVELER SCANS IT AT THE VENUE.
 *     Scanning opens that check-in URL. No login, no app, no account -- the
 *     route is public (routes/web.php), because traveler accounts were removed
 *     for Data Privacy Act compliance. A traveler without a working camera can
 *     press "Check In Here" on the listing page instead; it is the same URL
 *     either way (partials/check-in-button).
 *
 *  3. CheckInController RECORDS THE VISIT.
 *     It refuses listings that are archived or no longer accredited (a stale
 *     printed poster stops counting), then writes ONE row here with:
 *         visitor_token  -- who scanned, as an opaque random browser id
 *         listing_kind   -- 'destination', 'accommodation', 'restaurant', ...
 *         listing_id     -- which listing of that kind
 *         visit_date     -- the day of the scan
 *         source         -- 'qr_scan'
 *     Then it forwards the traveler to the listing's detail page, so the scan
 *     also does the obvious useful thing.
 *
 *  4. THE SAME PHONE CANNOT COUNT TWICE IN A DAY.
 *     Before inserting, the controller looks for an existing row on
 *     (visitor_token, listing_kind, listing_id, today). If one exists the
 *     visitor is told "you already checked in today" and nothing is written.
 *     A unique index (visits_unique_daily_per_browser) enforces the same rule
 *     in the database, so two simultaneous scans cannot both slip past that
 *     check. This is the "Alex visits Eden Park twice in one day"
 *     double-counting concern DOT raised.
 *
 *     The visitor_token is a random UUID in a cookie (EnsureVisitorToken). It
 *     is not derived from an IP, a device fingerprint, or anything personal,
 *     and there is no account to link it to. It exists ONLY to make this
 *     one-per-day rule possible. Consequences worth knowing: a visitor who
 *     clears cookies, or scans from a second phone, counts again; two people
 *     sharing one phone count once. The count is therefore "unique devices per
 *     day", which is the closest honest proxy for footfall available without
 *     identifying anybody.
 *
 *  5. WHERE THE COUNT IS READ BACK.
 *     - Per listing: $listing->visits() (the MorphMany on Destination,
 *       Accommodation, Restaurant, SouvenirCenter, Package, TourOperator).
 *     - DOT admin overview: today's total, plus the latest check-ins.
 *     - DOT admin "Verified Visits (QR Check-ins)" report: distinct
 *       visitor_token per listing over a date range.
 *
 *     This is deliberately separate from the Exit Survey's self-reported
 *     "places I visited" ticks, which are anonymous, retrospective, and
 *     counted per submission. These rows are the verified, at-the-venue
 *     number.
 *
 * CARE WITH visit_date: it is cast to a date, so Eloquent stores it as
 * "2026-08-30 00:00:00". A plain where('visit_date', '2026-08-30') matches
 * NOTHING, and a whereBetween silently drops the last day of the range. Query
 * it with whereDate(). Both the dedupe lookup and the admin console have been
 * bitten by this.
 */
class TouristVisit extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['visitor_token', 'listing_kind', 'listing_id', 'visit_date', 'source'];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
        ];
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }
}
