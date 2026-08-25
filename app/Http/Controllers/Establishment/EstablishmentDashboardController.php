<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EstablishmentDashboardController extends Controller
{
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

        return view('establishment.overview', compact(
            'establishment', 'listing', 'accreditation', 'photoCount', 'recentReviews', 'unrepliedCount'
        ));
    }

    public function editListing(Request $request): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with('status', 'Your establishment is not yet linked to a catalog listing. A DOT Admin will link it once your accreditation is verified.');
        }

        return view('establishment.edit-listing', compact('establishment', 'listing'));
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
        ]);

        $listing->description = $data['description'] ?? null;
        $listing->price_tier = $data['price_tier'] ?? null;

        match ($establishment->listing_kind) {
            'accommodation' => $listing->price_per_night = $data['price_amount'] ?? null,
            'package' => $listing->price_per_pax = $data['price_amount'] ?? null,
            default => null,
        };

        $listing->save();

        return redirect()->route('establishment.overview')->with('status', 'Your listing has been updated.');
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

        return back()->with('status', 'Your reply has been posted.');
    }

    public function notifications(Request $request): View
    {
        $establishment = $request->user('establishment');

        $establishment->notifications()->where('is_read', false)->update(['is_read' => true]);

        $notifications = $establishment->notifications()->latest()->paginate(15);

        return view('establishment.notifications', compact('establishment', 'notifications'));
    }
}
