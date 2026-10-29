<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Establishment\Concerns\ReturnsToPage;
use App\Http\Controllers\QrCodeController;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Review;
use App\Services\Amenities\AmenityStore;
use App\Support\Amenities;
use App\Services\Establishment\ListingInsights;
use App\Services\Establishment\ListingReadiness;
use App\Support\OpeningHoursText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Support\Toast;

class EstablishmentDashboardController extends Controller
{
    use ReturnsToPage;

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
        $readiness = null;
        $visits = null;
        $tripPlans = null;

        if ($listing) {
            $accreditation = $listing->accreditationRecords()->latest('expiration_date')->first();
            $photoCount = $listing->photos()->count();
            $recentReviews = $listing->reviews()->latest()->limit(3)->get();
            $unrepliedCount = $listing->reviews()->whereNull('owner_reply')->count();
            $readiness = app(ListingReadiness::class)->for($establishment->listing_kind, $listing);

            $insights = app(ListingInsights::class);
            $visits = $insights->checkIns($establishment->listing_kind, $listing->id, 30);
            $tripPlans = $insights->tripPlans($establishment->listing_kind, $listing->id, 30);
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
            'recentReviews', 'unrepliedCount', 'isPubliclyVisible', 'readiness', 'visits', 'tripPlans'
        ));
    }

    /** My Listing: what travellers see, what is missing, the status and the QR code. */
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
        $readiness = app(ListingReadiness::class)->for($establishment->listing_kind, $listing);
        $hoursColumn = ListingReadiness::hoursColumn($establishment->listing_kind);
        $hours = $hoursColumn ? trim((string) $listing->{$hoursColumn}) : null;
        $amenityKeys = $this->hasAmenityList($establishment->listing_kind) ? app(AmenityStore::class)->keys($establishment->listing_kind, $listing) : null;
        $amenityPhotos = $amenityKeys === null ? [] : $listing->photos->pluck('amenity')->filter()->unique()->all();

        return view('establishment.edit-listing', compact('establishment', 'listing', 'qrTargetUrl', 'readiness', 'hoursColumn', 'hours', 'amenityKeys', 'amenityPhotos'));
    }

    /** The sections of My Listing that are edited in their own form (a modal where JavaScript is on). */
    public const SECTIONS = [
        'details' => ['Edit details', 'What travellers read about you and where they can find you online'],
        'status' => ['Operating status', 'Tell travellers whether you are open'],
        'hours' => ['Opening hours', 'Shown to travellers and used when they plan a day'],
        'amenities' => ['Amenities', 'What is really there. Travellers and the trip planner use this.'],
        'position' => ['Pin your place on the map', 'Travellers use this to find you and to plan the route between places'],
    ];

    public function form(Request $request, string $section): View|Response|RedirectResponse
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);

        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        $hoursColumn = ListingReadiness::hoursColumn($establishment->listing_kind);
        abort_if($section === 'status' && ! method_exists($listing, 'operatingNotice'), 404);
        abort_if($section === 'hours' && ! $hoursColumn, 404);
        abort_if($section === 'amenities' && ! $this->hasAmenityList($establishment->listing_kind), 404);

        $data = [
            'establishment' => $establishment,
            'listing' => $listing,
            'section' => $section,
            'title' => self::SECTIONS[$section][0],
            'sub' => self::SECTIONS[$section][1],
        ];

        if ($section === 'amenities') {
            $data['ticked'] = app(AmenityStore::class)->keys($establishment->listing_kind, $listing);
        }

        if ($section === 'hours') {
            $text = trim((string) $listing->{$hoursColumn});
            $week = OpeningHoursText::week($text);
            $data['week'] = $week ?? array_fill(0, 7, ['on' => true, 'from' => '08:00', 'to' => '17:00']);
            $data['unreadable'] = $text !== '' && $week === null;
            $data['currentText'] = $text;
        }

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.'.$section, $data);
    }

    public function updateListing(Request $request): RedirectResponse|JsonResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        // Stays, restaurants, souvenir centers and destinations can say they are closed.
        $tracksStatus = method_exists($listing, 'operatingNotice');

        // Each form of My Listing sends the part it edits; a request without a section is the whole page at once.
        $section = $request->string('section')->toString() ?: 'all';
        $part = fn (string $name) => $section === 'all' || $section === $name;
        $hoursColumn = ListingReadiness::hoursColumn($establishment->listing_kind);

        if ($section === 'amenities') {
            abort_if(! $this->hasAmenityList($establishment->listing_kind), 404);
            $request->validate(['amenities' => ['nullable', 'array'], 'amenities.*' => ['string']]);
            app(AmenityStore::class)->sync($establishment->listing_kind, $listing, (array) $request->input('amenities', []));

            return $this->savedTo($request, route('establishment.listing.edit'), Toast::success('Amenities saved', 'Travellers see them on your page and the trip planner uses them.'));
        }

        if ($section === 'hours') {
            abort_if(! $hoursColumn, 404);

            $listing->{$hoursColumn} = $this->hoursFromRequest($request);
            $listing->save();

            return $this->savedTo($request, route('establishment.listing.edit'), Toast::success('Opening hours saved', 'Travellers planning a day now see when you are open.'));
        }

        $data = $request->validate([
            ...($part('details') ? [
            'description' => ['nullable', 'string', 'max:1000'],
            'price_tier' => ['nullable', 'string', 'max:20'],
            'price_amount' => ['nullable', 'numeric', 'min:0'],
            ] : []),
            ...($tracksStatus && $part('status') ? [
                'operating_status' => ['nullable', Rule::in(array_keys(Destination::OPERATING_STATUSES))],
                'closure_reason' => ['nullable', 'string', 'max:255'],
                'reopens_on' => ['nullable', 'date'],
            ] : []),
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
            ...($part('position') ? [
                'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.self::REGION_BOUNDS['lat'][0].','.self::REGION_BOUNDS['lat'][1]],
                'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.self::REGION_BOUNDS['lng'][0].','.self::REGION_BOUNDS['lng'][1]],
            ] : []),
            ...($part('details') ? [
                'itinerary' => ['nullable', 'string', 'max:5000'],
                'website_url' => ['nullable', 'url:http,https', 'max:255'],
                'facebook_url' => ['nullable', 'url:http,https', 'max:255'],
                'instagram_url' => ['nullable', 'url:http,https', 'max:255'],
                'tiktok_url' => ['nullable', 'url:http,https', 'max:255'],
            ] : []),
        ], [
            'latitude.between' => 'That point is outside the Davao Region. Drag the marker to your establishment.',
            'longitude.between' => 'That point is outside the Davao Region. Drag the marker to your establishment.',
            'website_url.url' => 'Enter a full web address, starting with http:// or https://.',
            'facebook_url.url' => 'Enter a full web address, starting with http:// or https://.',
            'instagram_url.url' => 'Enter a full web address, starting with http:// or https://.',
            'tiktok_url.url' => 'Enter a full web address, starting with http:// or https://.',
        ]);

        if ($part('details')) {
            $listing->description = $data['description'] ?? null;
            $listing->price_tier = $data['price_tier'] ?? null;
            $listing->website_url = $data['website_url'] ?? null;
            $listing->facebook_url = $data['facebook_url'] ?? null;
            $listing->instagram_url = $data['instagram_url'] ?? null;
            $listing->tiktok_url = $data['tiktok_url'] ?? null;

            match ($establishment->listing_kind) {
                'accommodation' => $listing->price_per_night = $data['price_amount'] ?? null,
                'package' => $listing->price_per_pax = $data['price_amount'] ?? null,
                default => null,
            };
        }

        if ($part('position')) {
            // Blank clears it: "we do not know" is a better answer than a wrong
            // pin, because the recommender trusts a stored coordinate completely.
            $listing->latitude = $data['latitude'] ?? null;
            $listing->longitude = $data['longitude'] ?? null;
        }

        if ($tracksStatus && $part('status')) {
            $status = $data['operating_status'] ?? Destination::STATUS_OPEN;
            $listing->operating_status = $status;
            $listing->closure_reason = $status === Destination::STATUS_OPEN ? null : ($data['closure_reason'] ?? null);
            $listing->reopens_on = $status === Destination::STATUS_TEMPORARILY_CLOSED ? ($data['reopens_on'] ?? null) : null;
        }

        $listing->save();

        // Self-service, same as everything else on this page: no DOT
        // approval step before a package's day-by-day breakdown goes live.
        if ($part('details') && $establishment->listing_kind === 'package') {
            $this->syncItineraryDays($listing, $data['itinerary'] ?? '');
        }

        $toast = match ($section) {
            'status' => Toast::success('Operating status saved', 'Travellers see the change straight away.'),
            'position' => Toast::success('Map position saved', 'Travel times to you will use this spot.'),
            default => Toast::success('Listing updated', 'Your changes are now live on the public catalog.'),
        };

        return $this->savedTo($request, route('establishment.listing.edit'), $toast);
    }

    /** Destinations, stays, restaurants and souvenir centers tick amenities; packages and tour operators do not (a package has its own accessibility section). */
    private function hasAmenityList(string $kind): bool
    {
        return $kind !== 'package' && Amenities::hasList($kind);
    }

    /**
     * The seven rows of the hours form as the one line of text listings keep. At least one day must be open, every
     * open day needs a closing time after its opening time, and the line has to fit the column.
     */
    private function hoursFromRequest(Request $request): string
    {
        $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.on' => ['nullable'],
            'hours.*.from' => ['nullable', 'date_format:H:i'],
            'hours.*.to' => ['nullable', 'date_format:H:i'],
        ]);

        $week = [];
        foreach (OpeningHoursText::DAYS as $i => $short) {
            $row = (array) $request->input("hours.{$i}", []);
            $on = ! empty($row['on']);
            $from = (string) ($row['from'] ?? '');
            $to = (string) ($row['to'] ?? '');

            if ($on && ($from === '' || $to === '')) {
                throw ValidationException::withMessages(["hours.{$i}.to" => OpeningHoursText::FULL_DAYS[$i].' needs an opening and a closing time.']);
            }
            if ($on && $to <= $from) {
                throw ValidationException::withMessages(["hours.{$i}.to" => OpeningHoursText::FULL_DAYS[$i].' closes before it opens.']);
            }

            $week[$i] = ['on' => $on, 'from' => $from, 'to' => $to];
        }

        if (! collect($week)->contains('on', true)) {
            throw ValidationException::withMessages(['hours.0.on' => 'Tick at least one day you are open, or close the place from Operating status.']);
        }

        $text = OpeningHoursText::compose($week);
        if (mb_strlen($text) > 100) {
            throw ValidationException::withMessages(['hours.0.on' => 'That is too many different schedules to show. Give days the same hours where you can.']);
        }

        return $text;
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

    /** The reply form: a modal where JavaScript is on, a page otherwise. */
    public function replyForm(Request $request, Review $review): View|Response
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing || $review->listing_kind !== $establishment->listing_kind || $review->listing_id !== $listing->id, 403);

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.reply', [
            'establishment' => $establishment, 'listing' => $listing, 'review' => $review,
            'section' => 'reply', 'title' => 'Reply to review', 'sub' => 'Your reply shows under the review on your public page',
        ]);
    }

    public function replyToReview(Request $request, Review $review): RedirectResponse|JsonResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing || $review->listing_kind !== $establishment->listing_kind || $review->listing_id !== $listing->id, 403);

        $data = $request->validate([
            'owner_reply' => ['required', 'string', 'max:500'],
        ]);

        $review->update(['owner_reply' => $data['owner_reply'], 'owner_replied_at' => now()]);

        return $this->savedTo($request, route('establishment.reviews'), Toast::success('Reply posted', 'Travelers can now see your response on this review.'));
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
