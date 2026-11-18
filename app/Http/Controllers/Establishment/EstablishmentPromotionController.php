<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Establishment\Concerns\ReturnsToPage;
use App\Models\EstablishmentAccount;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use App\Services\Listings\ListingDraft;
use App\Support\Toast;

class EstablishmentPromotionController extends Controller
{
    use ReturnsToPage;

    public function index(Request $request): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        // Offers still waiting for DOT are listed too, marked as waiting.
        $promotions = Promotion::withoutGlobalScopes()->forListing($establishment->listing_kind, $listing->id)->latest()->get();

        return view('establishment.promotions', compact('establishment', 'listing', 'promotions'));
    }

    public function create(Request $request): View|Response|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.promo', [
            'establishment' => $establishment, 'listing' => $listing,
            'section' => 'promo', 'title' => 'Add a promo', 'sub' => 'Shown on your public page',
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
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

        // Hidden from travelers until DOT approves the draft it goes into.
        $promotion = Promotion::create($data + [
            'review_status' => 'draft',
            'listing_kind' => $establishment->listing_kind,
            'listing_id' => $listing->id,
        ]);
        app(ListingDraft::class)->stageItem($establishment, 'promotion', ['id' => $promotion->id]);

        return $this->savedTo($request, route('establishment.promotions'), Toast::success('Promo saved to your draft', 'Travelers will see it once DOT approves it. Send your draft to DOT when you are ready.'));
    }

    public function destroy(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->authorizePromotion($request->user('establishment'), $promotion);

        \App\Models\ListingChange::where('kind', 'promotion')->where('payload->id', $promotion->id)->delete();
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
