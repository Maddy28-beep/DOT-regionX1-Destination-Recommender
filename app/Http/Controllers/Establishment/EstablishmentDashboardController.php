<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QrCodeController;
use App\Models\Package;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Toast;

class EstablishmentDashboardController extends Controller
{
    /**
     * The bounding box of Davao Region, used to sanity-check a pin an
     * establishment drops on the map. Wide enough to hold every province in
     * the region (Davao Occidental's southern tip down to roughly 5.4, Davao
     * Oriental's east coast out to roughly 126.8) without reaching Cebu or
     * Caraga.
     */
    public const REGION_BOUNDS = ['lat' => [5.4, 8.1], 'lng' => [125.0, 126.8]];

    /** Davao City centre — where the picker opens when nothing is set yet. */
    public const MAP_DEFAULT = ['lat' => 7.0731, 'lng' => 125.6128];

    public function overview(Request $request): View
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        $accreditation = null;
        $photoCount = 0;
        $recentReviews = collect();
        $unrepliedCount = 0;

        if ($listing) {
            $accreditation = $listing->accreditationRecords()->latest('expiration_date')->first();
            $photoCount = $listing->photos()->count();
            $recentReviews = $listing->reviews()->latest()->limit(3)->get();
            $unrepliedCount = $listing->reviews()->whereNull('owner_reply')->count();
        }

        /*
         * Single source of truth for "can travelers see this listing".
         *
         * Public visibility is decided by scopePubliclyVisible() -- is_accredited
         * AND not archived -- and by nothing else. The AccreditationRecord's
         * status string is a separate, human-maintained field, so the two can
         * legitimately disagree: a record can read "Expired" while the listing
         * flag is still on (or vice versa). The dashboard used to assert
         * "your listing is hidden" purely from the record status, which was
         * wrong whenever an admin had not also flipped the flag. Every status
         * shown on this page now derives from this one value.
         */
        $isPubliclyVisible = (bool) ($listing && $listing->is_accredited && ! $listing->archived_at);

        return view('establishment.overview', compact(
            'establishment', 'listing', 'accreditation', 'photoCount',
            'recentReviews', 'unrepliedCount', 'isPubliclyVisible'
        ));
    }

    public function editListing(Request $request): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        // Same map the QR encoder uses, so the URL shown beside the code can't
        // drift from the one it actually encodes.
        $qrTargetUrl = QrCodeController::targetUrlFor($establishment->listing_kind, $listing);

        return view('establishment.edit-listing', compact('establishment', 'listing', 'qrTargetUrl'));
    }

    public function updateListing(Request $request): RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'price_tier' => ['nullable', 'string', 'max:20'],
            'price_amount' => ['nullable', 'numeric', 'min:0'],
            /*
             * Position, set by the establishment itself on the map.
             *
             * Bounded to Davao Region rather than merely to valid coordinates.
             * Bulk-geocoding these addresses was tried and abandoned: they are
             * barangay/purok level, below what a geocoder resolves, so it
             * matched stray words and returned an elementary school for one
             * listing and a street in the wrong province for another -- with
             * confidence scores that gave no way to tell good from bad. The
             * business knows where it is; the bounds just stop a mis-drag or a
             * fat-fingered paste putting it in another region, which matters
             * because a stored coordinate is treated as exact by
             * distanceKmFor() while a missing one is honestly treated as
             * unknown.
             */
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.self::REGION_BOUNDS['lat'][0].','.self::REGION_BOUNDS['lat'][1]],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.self::REGION_BOUNDS['lng'][0].','.self::REGION_BOUNDS['lng'][1]],
            'itinerary' => ['nullable', 'string', 'max:5000'],
        ], [
            'latitude.between' => 'That point is outside the Davao Region. Drag the marker to your establishment.',
            'longitude.between' => 'That point is outside the Davao Region. Drag the marker to your establishment.',
        ]);

        $listing->description = $data['description'] ?? null;
        $listing->price_tier = $data['price_tier'] ?? null;
        // Blank clears it: "we do not know" is a better answer than a wrong
        // pin, because the recommender trusts a stored coordinate completely.
        $listing->latitude = $data['latitude'] ?? null;
        $listing->longitude = $data['longitude'] ?? null;

        match ($establishment->listing_kind) {
            'accommodation' => $listing->price_per_night = $data['price_amount'] ?? null,
            'package' => $listing->price_per_pax = $data['price_amount'] ?? null,
            default => null,
        };

        $listing->save();

        // Self-service, same as everything else on this page: no DOT
        // approval step before a package's day-by-day breakdown goes live.
        if ($establishment->listing_kind === 'package') {
            $this->syncItineraryDays($listing, $data['itinerary'] ?? '');
        }

        return redirect()->route('establishment.overview')->with(Toast::success('Listing updated', 'Your changes are now live on the public catalog.'));
    }

    /**
     * One day per line, "Title | Description" -- the description half is
     * optional (a bare title line is still a valid day). Day numbers are
     * assigned by line order rather than typed by the operator, so removing
     * or reordering a line can't leave a gap or a duplicate day number.
     */
    private function syncItineraryDays(Package $package, string $itinerary): void
    {
        $package->itineraryDays()->delete();

        collect(explode("\n", $itinerary))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->each(function (string $line, int $index) use ($package) {
                [$title, $description] = array_pad(explode('|', $line, 2), 2, null);

                $package->itineraryDays()->create([
                    'day_number' => $index + 1,
                    'title' => trim($title),
                    'description' => $description !== null ? trim($description) ?: null : null,
                ]);
            });
    }

    public function reviews(Request $request): View
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        $reviews = $listing ? $listing->reviews()->latest()->paginate(10) : null;

        return view('establishment.reviews', compact('establishment', 'listing', 'reviews'));
    }

    public function replyToReview(Request $request, Review $review): RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing || $review->listing_kind !== $establishment->listing_kind || $review->listing_id !== $listing->id, 403);

        $data = $request->validate([
            'owner_reply' => ['required', 'string', 'max:500'],
        ]);

        $review->update(['owner_reply' => $data['owner_reply'], 'owner_replied_at' => now()]);

        return back()->with(Toast::success('Reply posted', 'Travelers can now see your response on this review.'));
    }

    public function notifications(Request $request): View
    {
        $establishment = $request->user('establishment');

        /*
         * Notifications used to be flagged read on page load. That made an
         * All/Unread filter impossible -- Unread was empty by the time it
         * rendered -- and left no way to keep something marked for later.
         * Reading is now an explicit action (markAllRead below).
         */
        $filter = $request->string('filter')->toString() === 'unread' ? 'unread' : 'all';

        $query = $establishment->notifications()->latest();

        if ($filter === 'unread') {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate(15)->withQueryString();
        $unreadCount = $establishment->notifications()->where('is_read', false)->count();
        $totalCount = $establishment->notifications()->count();

        return view('establishment.notifications', compact(
            'establishment', 'notifications', 'filter', 'unreadCount', 'totalCount'
        ));
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $establishment = $request->user('establishment');

        $establishment->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back()->with(Toast::success('Notifications cleared', 'All of them are now marked as read.'));
    }
}
