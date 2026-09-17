<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Support\Toast;

/**
 * Manual advisories a DOT Admin posts against a specific listing ("Mt. Apo is
 * closed this season") or the whole platform (a general notice) -- a
 * temporary notice about conditions, deliberately separate from
 * is_accredited/archived_at, which govern whether a listing is DOT-accredited
 * or shown at all rather than what's currently happening there.
 */
class AdminAdvisoryController extends Controller
{
    /** listing_kind values an advisory can target, mapped to their model class -- same set the morph map registers. */
    private const LISTING_KINDS = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'restaurant' => Restaurant::class,
        'package' => Package::class,
        'souvenir_center' => SouvenirCenter::class,
        'tour_operator' => TourOperator::class,
    ];

    private const SEVERITIES = ['info', 'warning', 'danger'];

    public function index(): View
    {
        $advisories = Advisory::with('listing', 'admin')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.advisories.index', compact('advisories'));
    }

    public function create(): View
    {
        return view('admin.advisories.form', [
            'advisory' => new Advisory(),
            'listingKinds' => self::LISTING_KINDS,
            'severities' => self::SEVERITIES,
            'listingOptions' => $this->listingOptionsFor(old('listing_kind')),
            'listingOptionsByKind' => $this->allListingOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['admin_id'] = Auth::guard('admin')->id();

        $advisory = Advisory::create($data);

        return redirect()->route('admin.advisories.index')
            ->with(Toast::success('Advisory posted', "\"{$advisory->title}\" is now visible to travelers."));
    }

    public function edit(Advisory $advisory): View
    {
        return view('admin.advisories.form', [
            'advisory' => $advisory,
            'listingKinds' => self::LISTING_KINDS,
            'severities' => self::SEVERITIES,
            'listingOptions' => $this->listingOptionsFor(old('listing_kind', $advisory->listing_kind)),
            'listingOptionsByKind' => $this->allListingOptions(),
        ]);
    }

    public function update(Request $request, Advisory $advisory): RedirectResponse
    {
        $advisory->update($this->validated($request));

        return redirect()->route('admin.advisories.index')
            ->with(Toast::success('Advisory updated', "Changes to \"{$advisory->title}\" have been saved."));
    }

    public function destroy(Advisory $advisory): RedirectResponse
    {
        $title = $advisory->title;
        $advisory->delete();

        return back()->with(Toast::success('Advisory removed', "\"{$title}\" is no longer shown to travelers."));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:1000'],
            'severity' => ['required', Rule::in(self::SEVERITIES)],
            'listing_kind' => ['nullable', Rule::in(array_keys(self::LISTING_KINDS))],
            'listing_id' => ['nullable', 'integer', 'required_with:listing_kind'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        // A listing picked without a kind (or vice versa) is a general
        // advisory, not a half-specified one -- both columns must agree.
        if (blank($data['listing_kind'] ?? null) || blank($data['listing_id'] ?? null)) {
            $data['listing_kind'] = null;
            $data['listing_id'] = null;
        } else {
            $modelClass = self::LISTING_KINDS[$data['listing_kind']];
            abort_unless($modelClass::whereKey($data['listing_id'])->exists(), 422, 'Selected listing was not found.');
        }

        return $data;
    }

    /** @return \Illuminate\Support\Collection<int, array{id: int, name: string}> */
    private function listingOptionsFor(?string $listingKind)
    {
        if (! $listingKind || ! isset(self::LISTING_KINDS[$listingKind])) {
            return collect();
        }

        return self::LISTING_KINDS[$listingKind]::orderBy('name')->get(['id', 'name']);
    }

    /**
     * All listings for every kind, small enough (tourism listings, not
     * bookings) to ship whole so the "Which One" dropdown can repopulate on
     * the client the instant "Applies To" changes, without a round trip.
     *
     * @return array<string, array<int, array{id: int, name: string}>>
     */
    private function allListingOptions(): array
    {
        return collect(self::LISTING_KINDS)
            ->map(fn (string $modelClass) => $modelClass::orderBy('name')->get(['id', 'name'])->toArray())
            ->all();
    }
}
