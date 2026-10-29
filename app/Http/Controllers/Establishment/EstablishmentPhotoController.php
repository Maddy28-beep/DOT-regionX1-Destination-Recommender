<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Establishment\Concerns\ReturnsToPage;
use App\Models\EstablishmentAccount;
use App\Models\ListingPhoto;
use App\Services\Establishment\ListingReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Support\Toast;

class EstablishmentPhotoController extends Controller
{
    use ReturnsToPage;

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

    public function create(Request $request): View|Response|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        if (! $listing) {
            return redirect()->route('establishment.overview')
                ->with(Toast::success('No listing linked yet', 'A DOT Admin will link one once your accreditation is verified.'));
        }

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.photos-upload', [
            'establishment' => $establishment, 'listing' => $listing, 'categories' => self::CATEGORIES,
            'section' => 'photos-upload', 'title' => 'Upload photos', 'sub' => 'JPG, PNG or WebP, up to 2 MB each',
        ]);
    }

    /** The credit form: for one photo, or (no photo) for every photo that still has none. */
    public function creditForm(Request $request, ?ListingPhoto $photo = null): View|Response|RedirectResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        if ($photo) {
            $this->authorizePhoto($establishment, $photo);
            $photos = collect([$photo]);
        } else {
            $readiness = app(ListingReadiness::class);
            $photos = $readiness->realPhotos($listing)->reject(fn (ListingPhoto $p) => $readiness->credited($p))->values();
        }

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.photo-credit', [
            'establishment' => $establishment, 'listing' => $listing, 'photos' => $photos, 'single' => (bool) $photo,
            'action' => $photo ? route('establishment.photos.credit', $photo) : route('establishment.photos.credit.bulk'),
            'section' => 'photo-credit', 'title' => 'Photo credit', 'sub' => 'Travellers see who took each photo',
        ]);
    }

    public function saveCredit(Request $request, ?ListingPhoto $photo = null): RedirectResponse|JsonResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        $data = $request->validate([
            'credit_text' => ['nullable', 'required_without:own', 'string', 'max:120'],
            'own' => ['nullable', 'boolean'],
        ], ['credit_text.required_without' => 'Say who took the photo, or tick that it is your own.']);

        $credit = $request->boolean('own') ? $establishment->business_name : trim((string) $data['credit_text']);

        if ($photo) {
            $this->authorizePhoto($establishment, $photo);
            $targets = collect([$photo]);
        } else {
            $readiness = app(ListingReadiness::class);
            $targets = $readiness->realPhotos($listing)->reject(fn (ListingPhoto $p) => $readiness->credited($p));
        }

        // credit_text is what travellers read under the photo; source_name is what DOT's completeness check reads.
        $targets->each(fn (ListingPhoto $p) => $p->update(['credit_text' => $credit, 'source_name' => $credit]));

        return $this->savedTo($request, route('establishment.photos'), Toast::success('Credit saved', 'It shows under the photo on your public page.'));
    }

    /** Drag-and-drop order: the ids of the listing's photos, first to last. */
    public function reorderAll(Request $request): JsonResponse
    {
        $establishment = $request->user('establishment');
        abort_if(! $establishment->matchedListing, 404);

        $ids = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];

        $own = ListingPhoto::where('listing_kind', $establishment->listing_kind)
            ->where('listing_id', $establishment->matched_listing_id)->pluck('id')->all();

        foreach (array_values(array_intersect($ids, $own)) as $position => $id) {
            ListingPhoto::whereKey($id)->update(['sort_order' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $establishment = $request->user('establishment');
        $listing = $establishment->matchedListing;

        abort_if(! $listing, 404);

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:10'],
            'photos.*' => ['image', 'max:2048'],
            'category' => ['required', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'credit_text' => ['nullable', 'required_without:own', 'string', 'max:120'],
            'own' => ['nullable', 'boolean'],
        ], ['credit_text.required_without' => 'Say who took the photos, or tick that they are your own.']);

        $credit = $request->boolean('own') ? $establishment->business_name : trim((string) ($data['credit_text'] ?? ''));

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
                'credit_text' => $credit,
                'source_name' => $credit,
            ]);
        }

        $n = count($data['photos']);

        return $this->savedTo($request, route('establishment.photos'), Toast::success('Photos uploaded', "{$n} photo".($n === 1 ? '' : 's').' added to your listing.'));
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
