<?php

namespace App\Http\Controllers\Establishment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Establishment\Concerns\ReturnsToPage;
use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\TourOperator;
use App\Models\ListingPhoto;
use App\Services\Amenities\AmenityStore;
use App\Services\Packages\PackageChecks;
use App\Support\Amenities;
use App\Support\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * My packages: a tour operator's own packages. An operator adds one, saves it as a draft, sends it to DOT, and DOT
 * approves it (Admin\AdminPackageApprovalController) before travellers can see it. Once live, the operator's edits
 * go live straight away; only new packages are reviewed.
 *
 * Only a tour operator account has this. A package is the operator's when its tour_operator_id is theirs.
 */
class EstablishmentPackageController extends Controller
{
    use ReturnsToPage;

    private function operator(Request $request): TourOperator
    {
        $establishment = $request->user('establishment');
        $operator = $establishment->listing_kind === 'tour_operator' ? $establishment->matchedListing : null;

        abort_unless($operator instanceof TourOperator, 404);

        return $operator;
    }

    private function own(Request $request, Package $package): TourOperator
    {
        $operator = $this->operator($request);
        abort_unless($package->tour_operator_id === $operator->id, 403);

        return $operator;
    }

    public function index(Request $request): View
    {
        $operator = $this->operator($request);

        $all = $operator->packages()->with('itineraryDays')->latest('id')->get();
        $counts = $all->countBy('status');
        $status = (string) $request->query('status', 'all');
        $term = trim((string) $request->query('q', ''));

        $packages = $all
            ->when(isset(Package::STATUSES[$status]), fn ($c) => $c->where('status', $status))
            ->when($term !== '', fn ($c) => $c->filter(fn (Package $p) => Str::contains(Str::lower($p->name), Str::lower($term))))
            ->values();

        return view('establishment.packages.index', [
            'establishment' => $request->user('establishment'), 'operator' => $operator,
            'packages' => $packages, 'counts' => $counts, 'total' => $all->count(), 'status' => $status, 'term' => $term,
            'checks' => app(PackageChecks::class),
        ]);
    }

    public function show(Request $request, Package $package): View
    {
        $operator = $this->own($request, $package);
        $package->load(['itineraryDays', 'inclusions', 'events', 'photos']);

        return view('establishment.packages.show', [
            'featureKeys' => app(AmenityStore::class)->keys('package', $package),
            'establishment' => $request->user('establishment'), 'operator' => $operator, 'package' => $package,
            'missing' => app(PackageChecks::class)->missingForSubmit($package),
        ]);
    }

    public function create(Request $request): View|Response
    {
        $operator = $this->operator($request);

        return $this->formView($request, $operator, new Package(['status' => Package::STATUS_DRAFT, 'price_tier' => 'Mid-range']), 'Add a package', 'Save a draft any time. DOT reviews it before travellers can see it.');
    }

    public function edit(Request $request, Package $package): View|Response
    {
        $operator = $this->own($request, $package);

        return $this->formView($request, $operator, $package->load(['itineraryDays', 'inclusions']), 'Edit package', $package->name);
    }

    private function formView(Request $request, TourOperator $operator, Package $package, string $title, string $sub): View|Response
    {
        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.package', [
            'establishment' => $request->user('establishment'), 'listing' => $operator, 'package' => $package,
            'section' => 'package', 'title' => $title, 'sub' => $sub,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $operator = $this->operator($request);
        $submit = $request->input('intent') === 'submit';

        $package = new Package([
            'tour_operator_id' => $operator->id, 'provider_name' => $operator->name, 'region_id' => $operator->region_id,
            'location' => $operator->location, 'status' => Package::STATUS_DRAFT, 'is_accredited' => false,
            'slug' => $this->uniqueSlug((string) $request->input('name')),
        ]);

        $this->fill($request, $package, completeRequired: $submit);
        $package->save();
        $this->saveChildren($request, $package);

        if ($submit) {
            return $this->sendIn($request, $package, route('establishment.packages.show', $package));
        }

        return $this->savedTo($request, route('establishment.packages.show', $package), Toast::success('Draft saved', 'Send it to DOT when it is ready.'));
    }

    public function update(Request $request, Package $package): RedirectResponse|JsonResponse
    {
        $this->own($request, $package);
        $submit = $request->input('intent') === 'submit' && in_array($package->status, [Package::STATUS_DRAFT, Package::STATUS_CHANGES], true);
        // A package that is live, paused or with DOT already needs to be complete: it is, or was, in front of people.
        $complete = $submit || in_array($package->status, [Package::STATUS_LIVE, Package::STATUS_PAUSED, Package::STATUS_IN_REVIEW], true);

        $this->fill($request, $package, completeRequired: $complete);
        $package->save();
        $this->saveChildren($request, $package);

        if ($submit) {
            return $this->sendIn($request, $package, route('establishment.packages.show', $package));
        }

        $toast = match ($package->status) {
            Package::STATUS_LIVE => Toast::success('Saved', 'The change is live now.'),
            Package::STATUS_IN_REVIEW => Toast::success('Saved', 'It is still with DOT for review.'),
            default => Toast::success('Draft saved', 'Send it to DOT when it is ready.'),
        };

        return $this->savedTo($request, route('establishment.packages.show', $package), $toast);
    }

    /** @throws ValidationException */
    private function fill(Request $request, Package $package, bool $completeRequired): void
    {
        $min = PackageChecks::MIN_DESCRIPTION;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => [$completeRequired ? 'required' : 'nullable', 'string', 'max:1000', $completeRequired ? "min:{$min}" : 'nullable'],
            'price_per_pax' => [$completeRequired ? 'required' : 'nullable', 'numeric', 'min:0'],
            'price_tier' => ['nullable', 'in:Budget-Friendly,Mid-range,Premium'],
            'meeting_point' => ['nullable', 'string', 'max:150'],
            'inclusions' => ['nullable', 'array'],
            'inclusions.*' => ['string', 'in:'.implode(',', Package::INCLUSION_CHOICES)],
            'itinerary' => ['nullable', 'string', 'max:5000'],
            'difficulty' => ['nullable', 'in:'.implode(',', array_keys(Amenities::DIFFICULTY))],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'in:'.implode(',', Amenities::FOR_KIND['package'])],
            'accessibility_note' => ['nullable', 'string', 'max:300'],
        ], [
            'description.min' => "Write at least a couple of sentences ({$min}+ characters) so DOT and travellers know what this is.",
            'description.required' => 'Write a description so DOT and travellers know what this is.',
            'price_per_pax.required' => 'Enter the price per person as a number, for example 2450.',
        ]);

        $days = $this->parseDays((string) ($data['itinerary'] ?? ''));
        if ($completeRequired && $days === []) {
            throw ValidationException::withMessages(['itinerary' => 'Add at least one day, each with a title.']);
        }

        $package->fill([
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'price_per_pax' => $data['price_per_pax'] ?? null,
            'price_tier' => $data['price_tier'] ?? null, 'meeting_point' => $data['meeting_point'] ?? null,
            'difficulty' => $data['difficulty'] ?? null, 'accessibility_note' => $data['accessibility_note'] ?? null,
            'duration_days' => $days === [] ? null : count($days),
            'duration_label' => $days === [] ? null : count($days).(count($days) === 1 ? ' day' : ' days'),
        ]);
    }

    /** One day per line, "Title | Description", the same wire format the package owner's listing form uses. */
    private function parseDays(string $text): array
    {
        return collect(explode("\n", $text))->map(fn ($l) => trim($l))->filter()->values()
            ->map(function (string $line) {
                [$title, $description] = array_pad(explode('|', $line, 2), 2, null);

                return ['title' => trim($title), 'description' => $description !== null ? (trim($description) ?: null) : null];
            })->filter(fn ($d) => $d['title'] !== '')->values()->all();
    }

    private function saveChildren(Request $request, Package $package): void
    {
        $package->itineraryDays()->delete();
        foreach ($this->parseDays((string) $request->input('itinerary', '')) as $i => $day) {
            $package->itineraryDays()->create($day + ['day_number' => $i + 1]);
        }

        app(AmenityStore::class)->sync('package', $package, (array) $request->input('features', []));

        $package->inclusions()->delete();
        foreach (array_unique((array) $request->input('inclusions', [])) as $item) {
            $package->inclusions()->create(['item' => $item]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'package';
        $slug = $base;

        for ($n = 2; Package::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    // ---------- photos ----------

    public const PHOTO_CATEGORIES = ['General', 'Exterior', 'Interior', 'Amenities', 'Accessibility', 'Food', 'Views'];

    public function photoForm(Request $request, Package $package): View|Response
    {
        $this->own($request, $package);

        return $this->modalView($request, 'establishment.form-page', 'establishment.forms.package-photo', [
            'establishment' => $request->user('establishment'), 'listing' => $package, 'package' => $package, 'categories' => self::PHOTO_CATEGORIES,
            'choices' => collect(Amenities::FOR_KIND['package'])->mapWithKeys(fn ($k) => [$k => Amenities::label($k)])->all(),
            'section' => 'package-photo', 'title' => 'Add package photos', 'sub' => 'JPG, PNG or WebP, up to 2 MB each',
        ]);
    }

    public function photoStore(Request $request, Package $package): RedirectResponse|JsonResponse
    {
        $this->own($request, $package);

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:10'],
            'photos.*' => ['image', 'max:2048'],
            'category' => ['required', 'in:'.implode(',', self::PHOTO_CATEGORIES)],
            'credit_text' => ['nullable', 'required_without:own', 'string', 'max:120'],
            'own' => ['nullable', 'boolean'],
            'amenity' => ['nullable', 'string'],
        ], ['credit_text.required_without' => 'Say who took the photos, or tick that they are your own.']);

        // A photo in Amenities or Accessibility says which feature of the trip it shows.
        $feature = null;
        if (in_array($data['category'], ['Amenities', 'Accessibility'], true)) {
            if (empty($data['amenity']) || ! in_array($data['amenity'], Amenities::FOR_KIND['package'], true)) {
                throw ValidationException::withMessages(['amenity' => 'Say what the photo shows, so it is not just "'.$data['category'].'".']);
            }
            $feature = $data['amenity'];
        }

        $credit = $request->boolean('own') ? $request->user('establishment')->business_name : trim((string) ($data['credit_text'] ?? ''));
        $next = (int) $package->photos()->max('sort_order') + 1;
        $first = ! $package->photos()->exists();

        foreach ($data['photos'] as $i => $file) {
            ListingPhoto::create([
                'listing_kind' => 'package', 'listing_id' => $package->id, 'path' => $file->store("listings/package/{$package->id}", 'public'),
                'category' => $data['category'], 'amenity' => $feature, 'sort_order' => $next + $i, 'is_primary' => $first && $i === 0,
                'credit_text' => $credit, 'source_name' => $credit,
            ]);
        }

        if ($feature) {
            app(AmenityStore::class)->add('package', $package, $feature);
        }

        $n = count($data['photos']);

        return $this->savedTo($request, route('establishment.packages.show', $package), Toast::success('Photos added', "{$n} ".Str::plural('photo', $n).' on this package.'));
    }

    public function photoDestroy(Request $request, Package $package, ListingPhoto $photo): RedirectResponse
    {
        $this->own($request, $package);
        abort_unless($photo->listing_kind === 'package' && $photo->listing_id === $package->id, 403);

        Storage::disk('public')->delete($photo->path);
        $wasPrimary = $photo->is_primary;
        $photo->delete();

        if ($wasPrimary) {
            ListingPhoto::where('listing_kind', 'package')->where('listing_id', $package->id)->orderBy('sort_order')->first()?->update(['is_primary' => true]);
        }

        return back()->with(Toast::success('Photo removed'));
    }

    // ---------- status changes ----------

    private function sendIn(Request $request, Package $package, string $url): RedirectResponse|JsonResponse
    {
        $package->refresh()->load('itineraryDays');
        $missing = app(PackageChecks::class)->missingForSubmit($package);

        if ($missing !== []) {
            throw ValidationException::withMessages(['name' => 'Not ready to send: '.collect($missing)->pluck('title')->map(fn ($t) => Str::lower($t))->implode(', ').'.']);
        }

        $package->transitionTo(Package::STATUS_IN_REVIEW, 'submitted', 'operator', $request->user('establishment')->business_name);

        return $this->savedTo($request, $url, Toast::success('Sent to DOT', 'It stays hidden from travellers until DOT approves it.'));
    }

    public function submit(Request $request, Package $package): RedirectResponse
    {
        $this->own($request, $package);
        abort_unless(in_array($package->status, [Package::STATUS_DRAFT, Package::STATUS_CHANGES], true), 422);

        $package->load('itineraryDays');
        $missing = app(PackageChecks::class)->missingForSubmit($package);

        if ($missing !== []) {
            return back()->with(Toast::error('Not ready to send', collect($missing)->pluck('title')->implode(', ').'.'));
        }

        $package->transitionTo(Package::STATUS_IN_REVIEW, 'submitted', 'operator', $request->user('establishment')->business_name);

        return back()->with(Toast::success('Sent to DOT', 'It stays hidden from travellers until DOT approves it.'));
    }

    public function withdraw(Request $request, Package $package): RedirectResponse
    {
        $this->own($request, $package);
        abort_unless($package->status === Package::STATUS_IN_REVIEW, 422);

        $package->transitionTo(Package::STATUS_DRAFT, 'withdrawn', 'operator', $request->user('establishment')->business_name);

        return back()->with(Toast::success('Withdrawn', 'It is a draft again.'));
    }

    public function pause(Request $request, Package $package): RedirectResponse
    {
        $this->own($request, $package);
        abort_unless($package->status === Package::STATUS_LIVE, 422);

        $package->transitionTo(Package::STATUS_PAUSED, 'paused', 'operator', $request->user('establishment')->business_name);

        return back()->with(Toast::success('Paused', 'It is hidden from travellers until you make it live again.'));
    }

    public function resume(Request $request, Package $package): RedirectResponse
    {
        $operator = $this->own($request, $package);
        abort_unless($package->status === Package::STATUS_PAUSED, 422);

        [$accredited] = app(PackageChecks::class)->operator($operator->load('accreditationRecords'));
        if (! $accredited) {
            return back()->with(Toast::error('Cannot go live', 'Your DOT accreditation has lapsed. Renew it first.'));
        }

        $package->transitionTo(Package::STATUS_LIVE, 'resumed', 'operator', $request->user('establishment')->business_name);

        return back()->with(Toast::success('Live again', 'Travellers can see it.'));
    }

    public function duplicate(Request $request, Package $package): RedirectResponse
    {
        $operator = $this->own($request, $package);
        $package->load(['itineraryDays', 'inclusions']);

        $copy = $package->replicate(['slug', 'status', 'is_accredited', 'submitted_at', 'reviewed_at', 'review_note', 'rating', 'review_count', 'featured', 'archived_at']);
        $copy->fill(['name' => Str::limit($package->name, 135, '').' (copy)', 'slug' => $this->uniqueSlug($package->name.' copy'), 'status' => Package::STATUS_DRAFT, 'is_accredited' => false, 'tour_operator_id' => $operator->id]);
        $copy->save();

        foreach ($package->itineraryDays as $day) {
            $copy->itineraryDays()->create(['day_number' => $day->day_number, 'title' => $day->title, 'description' => $day->description]);
        }
        foreach ($package->inclusions as $inclusion) {
            $copy->inclusions()->create(['item' => $inclusion->item]);
        }

        return redirect()->route('establishment.packages.show', $copy)->with(Toast::success('Copied as a draft', 'Change what you need, then send it to DOT.'));
    }

    public function destroy(Request $request, Package $package): RedirectResponse
    {
        $this->own($request, $package);

        if (! in_array($package->status, [Package::STATUS_DRAFT, Package::STATUS_CHANGES], true)) {
            return back()->with(Toast::error('Cannot delete it', 'A package travellers have seen or DOT has is paused, not deleted. Pause it instead.'));
        }

        $package->delete();

        return redirect()->route('establishment.packages')->with(Toast::success('Package deleted'));
    }
}
