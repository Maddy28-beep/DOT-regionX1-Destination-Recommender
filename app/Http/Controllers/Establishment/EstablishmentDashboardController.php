<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\QrCodeController;
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
                ->with('status', 'Your establishment is not yet linked to a catalog listing. A DOT Admin will link it once your accreditation is verified.');
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

        return back()->with('status', 'All notifications marked as read.');
    }
}
