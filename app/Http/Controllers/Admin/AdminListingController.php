<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminListingController extends Controller
{
    private const TYPES = [
        'destinations' => ['model' => Destination::class, 'label' => 'Destinations', 'singular' => 'Destination'],
        'accommodations' => ['model' => Accommodation::class, 'label' => 'Accommodations', 'singular' => 'Accommodation'],
        'restaurants' => ['model' => Restaurant::class, 'label' => 'Restaurants', 'singular' => 'Restaurant'],
        'packages' => ['model' => Package::class, 'label' => 'Packages', 'singular' => 'Package'],
        'souvenir-centers' => ['model' => SouvenirCenter::class, 'label' => 'Souvenir Centers', 'singular' => 'Souvenir Center'],
        'tour-operators' => ['model' => TourOperator::class, 'label' => 'Tour Operators', 'singular' => 'Tour Operator'],
    ];

    public function index(Request $request, string $type): View
    {
        $config = $this->config($type);
        $model = $config['model'];

        $status = $request->get('status', 'active');

        $query = $model::with('region')->withCount('reviews');

        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->string('q').'%');
        }

        $listings = $query->orderBy('name')->paginate(12)->withQueryString();

        return view('admin.listings.index', [
            'type' => $type,
            'config' => $config,
            'types' => self::TYPES,
            'listings' => $listings,
            'status' => $status,
        ]);
    }

    public function create(string $type): View
    {
        $config = $this->config($type);

        return view('admin.listings.form', [
            'type' => $type,
            'config' => $config,
            'listing' => new $config['model'],
            'regions' => Region::orderBy('name')->get(),
            'tourOperators' => $type === 'packages' ? TourOperator::orderBy('name')->get() : collect(),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $config = $this->config($type);
        $data = $request->validate($this->rules($type));

        $data['slug'] = $this->uniqueSlug($config['model'], $data['name']);

        foreach (['is_accredited', 'featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $listing = $config['model']::create($data);

        if ($type === 'packages' && $request->filled('inclusions')) {
            $this->syncInclusions($listing, $request->input('inclusions'));
        }

        if ($type === 'accommodations' && $request->filled('room_types')) {
            $this->syncRoomTypes($listing, $request->input('room_types'));
        }

        return redirect()->route('admin.listings.index', $type)
            ->with('status', "{$config['singular']} \"{$listing->name}\" created.");
    }

    public function edit(string $type, int $id): View
    {
        $config = $this->config($type);
        $listing = $config['model']::with('region')->findOrFail($id);

        if ($type === 'packages') {
            $listing->load('inclusions');
        }

        if ($type === 'accommodations') {
            $listing->load('roomTypes');
        }

        return view('admin.listings.form', [
            'type' => $type,
            'config' => $config,
            'listing' => $listing,
            'regions' => Region::orderBy('name')->get(),
            'tourOperators' => $type === 'packages' ? TourOperator::orderBy('name')->get() : collect(),
        ]);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $config = $this->config($type);
        $listing = $config['model']::findOrFail($id);

        $data = $request->validate($this->rules($type, $listing->id));

        foreach (['is_accredited', 'featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $listing->update($data);

        if ($type === 'packages') {
            $this->syncInclusions($listing, $request->input('inclusions', ''));
        }

        if ($type === 'accommodations') {
            $this->syncRoomTypes($listing, $request->input('room_types', ''));
        }

        return redirect()->route('admin.listings.index', $type)
            ->with('status', "{$config['singular']} \"{$listing->name}\" updated.");
    }

    public function archive(string $type, int $id): RedirectResponse
    {
        $config = $this->config($type);
        $listing = $config['model']::findOrFail($id);
        $listing->archive();

        return back()->with('status', "{$config['singular']} \"{$listing->name}\" archived. It's now hidden from the public catalog.");
    }

    public function unarchive(string $type, int $id): RedirectResponse
    {
        $config = $this->config($type);
        $listing = $config['model']::findOrFail($id);
        $listing->unarchive();

        return back()->with('status', "{$config['singular']} \"{$listing->name}\" restored.");
    }

    private function config(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    private function uniqueSlug(string $modelClass, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while ($modelClass::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function syncInclusions(Package $package, string $inclusions): void
    {
        $package->inclusions()->delete();

        collect(explode("\n", $inclusions))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->each(fn ($item) => $package->inclusions()->create(['item' => $item]));
    }

    /**
     * Parses one room type per line in "Name | Price Min | Price Max" format
     * (either price may be left blank, e.g. "Deluxe Suite | 3500 | 5000" or
     * "Standard Room |  | "), mirroring syncInclusions()'s one-per-line pattern.
     */
    private function syncRoomTypes(Accommodation $accommodation, string $roomTypes): void
    {
        $accommodation->roomTypes()->delete();

        collect(explode("\n", $roomTypes))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->each(function ($line) use ($accommodation) {
                $parts = array_map('trim', explode('|', $line));
                $name = $parts[0] ?? '';

                if ($name === '') {
                    return;
                }

                $accommodation->roomTypes()->create([
                    'name' => $name,
                    'price_min' => isset($parts[1]) && is_numeric($parts[1]) ? $parts[1] : null,
                    'price_max' => isset($parts[2]) && is_numeric($parts[2]) ? $parts[2] : null,
                ]);
            });
    }

    private function rules(string $type, ?int $ignoreId = null): array
    {
        $common = [
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:255'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_accredited' => ['nullable', 'boolean'],
        ];

        return match ($type) {
            'destinations' => array_merge($common, [
                'type' => ['nullable', 'string', 'max:80'],
                'price_tier' => ['nullable', 'string', 'max:20'],
                'entry_fee_min' => ['nullable', 'numeric', 'min:0'],
                'entry_fee_max' => ['nullable', 'numeric', 'min:0'],
                'distance_km' => ['nullable', 'numeric', 'min:0'],
                'visit_duration' => ['nullable', 'string', 'max:50'],
                'best_time' => ['nullable', 'string', 'max:80'],
                'hours' => ['nullable', 'string', 'max:100'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'featured' => ['nullable', 'boolean'],
            ]),
            'accommodations' => array_merge($common, [
                'type' => ['nullable', 'string', 'max:80'],
                'dot_classification' => ['nullable', 'string', 'max:50'],
                'price_tier' => ['nullable', 'string', 'max:20'],
                'price_per_night' => ['nullable', 'numeric', 'min:0'],
                'check_in' => ['nullable', 'date_format:H:i'],
                'check_out' => ['nullable', 'date_format:H:i'],
                'distance_km' => ['nullable', 'numeric', 'min:0'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'featured' => ['nullable', 'boolean'],
            ]),
            'restaurants' => array_merge($common, [
                'location' => ['required', 'string', 'max:255'],
                'cuisine_type' => ['nullable', 'string', 'max:80'],
                'price_tier' => ['nullable', 'string', 'max:20'],
                'opening_hours' => ['nullable', 'string', 'max:100'],
                'contact_number' => ['nullable', 'string', 'max:20'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]),
            'packages' => array_merge($common, [
                'duration_label' => ['nullable', 'string', 'max:50'],
                'duration_days' => ['nullable', 'integer', 'min:1'],
                'price_per_pax' => ['nullable', 'numeric', 'min:0'],
                'price_tier' => ['nullable', 'string', 'max:20'],
                'type' => ['nullable', 'string', 'max:80'],
                'provider_name' => ['nullable', 'string', 'max:150'],
                'tour_operator_id' => ['nullable', 'exists:tour_operators,id'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'featured' => ['nullable', 'boolean'],
            ]),
            'souvenir-centers' => array_merge($common, [
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]),
            'tour-operators' => array_merge($common, [
                'specialization' => ['nullable', 'string', 'max:80'],
                'price_tier' => ['nullable', 'string', 'max:20'],
                'contact_number' => ['nullable', 'string', 'max:20'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ]),
            default => $common,
        };
    }
}
