<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristVisit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\Toast;

/**
 * Lets a traveller rate a place they actually went to.
 *
 * Nothing in the application could create a review before this: establishments
 * could read and reply to them, the cards and the recommender ranked listings
 * by them, but the only rows that ever existed came from a seeder. That is why
 * 383 of 396 listings carry no rating at all.
 *
 * A review is only accepted from a browser that checked in at that listing by
 * scanning its QR code (CheckInController). It is a deliberately strict gate:
 * this is a government accreditation platform, and a rating that anyone on the
 * internet can post about a real business is worth very little and is trivial
 * to brigade. Scanning a code printed at the premises is evidence the person
 * was there.
 *
 * No name, email or account is involved, in keeping with the rest of the site
 * -- the review is attributed to the same opaque token that recorded the
 * check-in, and displayed as "Verified visitor".
 */
class ReviewController extends Controller
{
    /** Same URL segments the QR codes already use, so the two stay in step. */
    private const TYPES = [
        'destinations' => ['model' => Destination::class, 'kind' => 'destination', 'route' => 'destinations.show'],
        'accommodations' => ['model' => Accommodation::class, 'kind' => 'accommodation', 'route' => 'accommodations.show'],
        'restaurants' => ['model' => Restaurant::class, 'kind' => 'restaurant', 'route' => 'restaurants.show'],
        'packages' => ['model' => Package::class, 'kind' => 'package', 'route' => 'packages.show'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'kind' => 'souvenir_center', 'route' => 'souvenir-centers.show'],
        'tour-operators' => ['model' => TourOperator::class, 'kind' => 'tour_operator', 'route' => 'tour-operators.show'],
    ];

    public function store(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $config = self::TYPES[$type];

        $listing = $config['model']::findOrFail($id);
        abort_unless($listing->is_accredited && ! $listing->archived_at, 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $token = EnsureVisitorToken::get($request);
        $back = redirect()->route($config['route'], $listing);

        if (! self::hasCheckedIn($token, $config['kind'], $listing->id)) {
            return $back->withErrors([
                'rating' => 'Scan the QR code at '.$listing->name.' to leave a review. It is how we keep reviews to people who have actually been.',
            ]);
        }

        try {
            Review::create([
                'listing_kind' => $config['kind'],
                'listing_id' => $listing->id,
                'visitor_token' => $token,
                'author_name' => 'Verified visitor',
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The unique index caught a second review from the same browser --
            // a double submit, or a refreshed form.
            return $back->withErrors(['rating' => 'You have already reviewed '.$listing->name.'.']);
        }

        self::refreshRating($listing, $config['kind']);

        return $back->with(Toast::success('Review posted', 'Thank you — it is now live on '.$listing->name.'.'));
    }

    /** Has this browser scanned the QR code at this listing? */
    public static function hasCheckedIn(string $token, string $kind, int $listingId): bool
    {
        return $token !== '' && TouristVisit::where('visitor_token', $token)
            ->where('listing_kind', $kind)
            ->where('listing_id', $listingId)
            ->exists();
    }

    public static function hasReviewed(string $token, string $kind, int $listingId): bool
    {
        return $token !== '' && Review::where('visitor_token', $token)
            ->where('listing_kind', $kind)
            ->where('listing_id', $listingId)
            ->exists();
    }

    /**
     * Recompute the listing's own rating and review_count from its reviews.
     *
     * These two columns drive the cards, the catalogue sort and the
     * recommender's Ratings and Popularity factors, and until now nothing but
     * a seeder ever wrote them -- so they could never reflect what travellers
     * actually said.
     */
    private static function refreshRating($listing, string $kind): void
    {
        $reviews = Review::where('listing_kind', $kind)->where('listing_id', $listing->id);

        $listing->forceFill([
            'review_count' => $reviews->count(),
            'rating' => round((float) $reviews->avg('rating'), 2),
        ])->save();
    }
}
