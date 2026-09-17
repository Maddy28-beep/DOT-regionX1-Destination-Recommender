<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Models\EstablishmentAccount;
use App\Models\Promotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Toast;

class EstablishmentPromotionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        $promotions = Promotion::forListing($establishment->listing_kind, $listing->id)->latest()->get();

        return view('establishment.promotions', compact('establishment', 'listing', 'promotions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        Promotion::create($data + [
            'listing_kind' => $establishment->listing_kind,
            'listing_id' => $listing->id,
        ]);

        return back()->with(Toast::success('Promo added', 'Travelers will now see it on your listing page.'));
    }

    public function destroy(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->authorizePromotion($request->user('establishment'), $promotion);

        $promotion->delete();

        return back()->with(Toast::success('Promo removed', 'It no longer appears on your listing.'));
    }

    private function authorizePromotion(EstablishmentAccount $establishment, Promotion $promotion): void
    {
        abort_unless(
            $promotion->listing_kind === $establishment->listing_kind
                && $promotion->listing_id === $establishment->matched_listing_id,
            403
        );
    }
}
