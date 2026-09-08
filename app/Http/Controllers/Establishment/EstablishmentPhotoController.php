<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Models\EstablishmentAccount;
use App\Models\ListingPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Support\Toast;

class EstablishmentPhotoController extends Controller
{
    public const CATEGORIES = ['General', 'Exterior', 'Interior', 'Amenities', 'Food', 'Views'];

    public function index(Request $request): View|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        $photos = $listing->photos;

        return view('establishment.photos', [
            'establishment' => $establishment,
            'listing' => $listing,
            'photos' => $photos,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:10'],
            'photos.*' => ['image', 'max:2048'],
            'category' => ['required', 'string', 'in:'.implode(',', self::CATEGORIES)],
        ]);

        $nextSort = (int) $listing->photos()->max('sort_order') + 1;
        $hasPhotos = $listing->photos()->exists();

        foreach ($data['photos'] as $i => $file) {
            $path = $file->store("listings/{$establishment->listing_kind}/{$listing->id}", 'public');

            ListingPhoto::create([
                'listing_kind' => $establishment->listing_kind,
                'listing_id' => $listing->id,
                'path' => $path,
                'category' => $data['category'],
                'sort_order' => $nextSort + $i,
                'is_primary' => ! $hasPhotos && $i === 0,
            ]);
        }

        $n = count($data['photos']);

        return back()->with(Toast::success('Photos uploaded', "{$n} photo".($n === 1 ? '' : 's').' added to your listing.'));
    }

    public function setPrimary(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $this->authorizePhoto($request->user('establishment'), $photo);

        ListingPhoto::where('listing_kind', $photo->listing_kind)
            ->where('listing_id', $photo->listing_id)
            ->update(['is_primary' => false]);

        $photo->update(['is_primary' => true]);

        return back()->with(Toast::success('Cover photo updated', 'This image now leads your listing in search results.'));
    }

    public function destroy(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $establishment = $request->user('establishment');
        $this->authorizePhoto($establishment, $photo);

        $wasPrimary = $photo->is_primary;
        $listingKind = $photo->listing_kind;
        $listingId = $photo->listing_id;

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        if ($wasPrimary) {
            ListingPhoto::where('listing_kind', $listingKind)
                ->where('listing_id', $listingId)
                ->orderBy('sort_order')
                ->first()?->update(['is_primary' => true]);
        }

        return back()->with(Toast::success('Photo removed', 'It no longer appears on your listing.'));
    }

    public function moveUp(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $this->reorder($request, $photo, -1);

        return back();
    }

    public function moveDown(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $this->reorder($request, $photo, 1);

        return back();
    }

    private function reorder(Request $request, ListingPhoto $photo, int $direction): void
    {
        $this->authorizePhoto($request->user('establishment'), $photo);

        $siblings = ListingPhoto::where('listing_kind', $photo->listing_kind)
            ->where('listing_id', $photo->listing_id)
            ->orderBy('sort_order')
            ->get();

        $index = $siblings->search(fn ($p) => $p->id === $photo->id);
        $swapIndex = $index + $direction;

        if (! isset($siblings[$swapIndex])) {
            return;
        }

        $neighbor = $siblings[$swapIndex];
        [$photoSort, $neighborSort] = [$photo->sort_order, $neighbor->sort_order];

        $photo->update(['sort_order' => $neighborSort]);
        $neighbor->update(['sort_order' => $photoSort]);
    }

    private function authorizePhoto(EstablishmentAccount $establishment, ListingPhoto $photo): void
    {
        abort_unless(
            $photo->listing_kind === $establishment->listing_kind
                && $photo->listing_id === $establishment->matched_listing_id,
            403
        );
    }
}
