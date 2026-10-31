<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotently applies the verified planner data manifests in database/data/
 * (matched by slug/name/accreditation number rather than hard-coded primary keys)
 * so any fresh `php artisan migrate --seed` or `php artisan db:seed --class=PlannerVerifiedDataSeeder`
 * has the exact same verified coordinates, hours, tour durations, and meal venues.
 */
class PlannerVerifiedDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasColumn('destinations', 'planner_verified_at')
            || ! Schema::hasColumn('restaurants', 'planner_verified_at')) {
            return;
        }

        DB::transaction(function () {
            $this->applyGeographicAreas();
            $this->applyIntegrationVerifiedCsv();
            $this->applyHotelCoordinateCorrections();
            $this->applyPlannerCompletion();
            $this->applyPublicPlaces();
            $this->applyVerifiedExperiences();
        });
    }

    private function applyGeographicAreas(): void
    {
        $samal = Destination::where('name', 'Samal Island')->first();
        if ($samal && Schema::hasColumn('destinations', 'itinerary_role')) {
            $samal->update(['itinerary_role' => 'excluded']);
        }
    }

    private function applyIntegrationVerifiedCsv(): void
    {
        $path = database_path('data/planner-integration-verified-2026-10-31.csv');
        if (! is_file($path)) {
            return;
        }

        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_shift($rows);
        foreach ($rows as $row) {
            if (count($row) < 5) {
                continue;
            }
            [, $name, $field, $value] = $row;
            $names = $name === 'Davao Crocodile Park Inc' ? ['Davao Crocodile Park Inc', 'Davao Crocodile Park'] : [$name];
            foreach (Destination::whereIn('name', $names)->get() as $dest) {
                if ($field === 'category_tag') {
                    $dest->tags()->firstOrCreate(['kind' => 'category', 'value' => $value]);
                } elseif (in_array($field, ['hours', 'latitude', 'longitude', 'entry_fee_min', 'entry_fee_max', 'visit_duration'], true)) {
                    $dest->update([$field => $value]);
                }
            }
        }
    }

    private function applyHotelCoordinateCorrections(): void
    {
        $path = database_path('data/planner-hotel-coordinate-corrections-2026-10-31.json');
        if (! is_file($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['hotels'] ?? [] as $row) {
            $hotel = Accommodation::where('name', $row['name'])->first();
            if ($hotel) {
                $hotel->update([
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                ]);
            }
        }
    }

    private function applyPlannerCompletion(): void
    {
        $path = database_path('data/planner-completion-2026-10-31.json');
        if (! is_file($path)) {
            return;
        }

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($manifest['renewals'] ?? [] as $row) {
            AccreditationRecord::where('accreditation_number', $row['accno'])
                ->update(['expiration_date' => $row['expiry']]);
            if (isset($row['coordinates'])) {
                Accommodation::where('name', $row['name'])->update([
                    'latitude' => $row['coordinates'][0],
                    'longitude' => $row['coordinates'][1],
                ]);
            }
        }

        foreach ($manifest['destinations'] ?? [] as $row) {
            $dest = (! empty($row['slug']) ? Destination::where('slug', $row['slug'])->first() : null)
                ?? ($row['name'] === 'Elysia Wellness Spa'
                    ? Destination::where('name', $row['name'])->where('location', 'like', '%Obrero%')->first()
                    : null)
                ?? Destination::where('name', $row['name'])->first();
            if ($dest && isset($row['coordinates'])) {
                $dest->update([
                    'latitude' => $row['coordinates'][0],
                    'longitude' => $row['coordinates'][1],
                ]);
            }
        }

        $additionsPath = database_path('data/dot-accredited-additions-2026-09-23.json');
        if (is_file($additionsPath)) {
            $additions = json_decode(file_get_contents($additionsPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['add_hotels'] ?? [] as $row) {
                $source = collect($additions['listings']['accommodation'] ?? [])
                    ->first(fn ($r) => ($r['accno'] ?? null) === $row['accno'] && ($r['slug'] ?? null) === $row['slug']);
                if (! $source) {
                    continue;
                }
                $region = Region::firstOrCreate(['name' => $source['region'] ?? 'Davao City']);
                $hotel = Accommodation::firstOrNew(['slug' => $row['slug']]);
                $hotel->fill([
                    'name' => $source['name'],
                    'location' => $source['location'],
                    'region_id' => $region->id,
                    'type' => $source['type'] ?? 'Hotel',
                    'dot_classification' => $source['dot_classification'] ?? 'Hotel',
                    'is_accredited' => true,
                    'latitude' => $row['coordinates'][0],
                    'longitude' => $row['coordinates'][1],
                ])->save();

                AccreditationRecord::updateOrCreate(
                    [
                        'listing_kind' => 'accommodation',
                        'listing_id' => $hotel->id,
                        'accreditation_number' => $row['accno'],
                    ],
                    [
                        'status' => 'Active',
                        'expiration_date' => $source['expiry'] ?? '2028-10-31',
                        'is_placeholder' => false,
                    ]
                );
            }
        }
    }

    private function applyPublicPlaces(): void
    {
        $path = database_path('data/planner-public-places-2026-10-31.json');
        if (! is_file($path)) {
            return;
        }

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $checkedAt = ($manifest['checked_on'] ?? '2026-10-31').' 12:00:00';

        foreach ($manifest['places'] ?? [] as $place) {
            $region = Region::firstOrCreate(['name' => $place['region']]);
            $dest = Destination::where('slug', $place['slug'])->first()
                ?? Destination::where('name', $place['name'])->first()
                ?? new Destination(['slug' => $place['slug'], 'is_accredited' => false]);

            $attributes = [
                'name' => $place['name'],
                'region_id' => $region->id,
                'location' => $dest->location ?: $place['region'],
                'type' => $place['type'],
                'latitude' => $place['coordinates'][0],
                'longitude' => $place['coordinates'][1],
                'description' => $place['description'],
                'planner_verified_at' => $checkedAt,
                'planner_verification_source' => $place['source'],
            ];

            foreach (['hours', 'price_tier', 'entry_fee_min', 'entry_fee_max'] as $optional) {
                if (array_key_exists($optional, $place)) {
                    $attributes[$optional] = $place[$optional];
                }
            }

            $dest->fill($attributes)->save();

            foreach ($place['tags'] ?? [] as $tag) {
                $dest->tags()->firstOrCreate(['kind' => 'category', 'value' => $tag]);
            }
        }
    }

    private function applyVerifiedExperiences(): void
    {
        $path = database_path('data/planner-verified-experiences-2026-10-31.json');
        if (! is_file($path)) {
            return;
        }

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $checkedAt = ($manifest['checked_on'] ?? '2026-10-31').' 12:00:00';

        foreach ($manifest['destinations'] ?? [] as $row) {
            $dest = (! empty($row['slug']) ? Destination::where('slug', $row['slug'])->first() : null)
                ?? ($row['name'] === 'Elysia Wellness Spa'
                    ? Destination::where('name', $row['name'])->whereNotNull('latitude')->first()
                    : null)
                ?? Destination::where('name', $row['name'])->first();
            if (! $dest) {
                continue;
            }
            $dest->fill($row['fields'] ?? [])->save();
            foreach ($row['tags'] ?? [] as $tag) {
                $dest->tags()->firstOrCreate(['kind' => 'category', 'value' => $tag]);
            }
        }

        foreach ($manifest['restaurant_updates'] ?? [] as $row) {
            $rest = Restaurant::where('name', $row['name'])->first();
            if ($rest) {
                $updates = $row['fields'] ?? [];
                if (! empty($row['source'])) {
                    $updates['planner_verified_at'] = $checkedAt;
                    $updates['planner_verification_source'] = $row['source'];
                }
                $rest->fill($updates)->save();
            }
        }

        $hostAccommodationNames = [
            'vanda-park-inn-davao' => 'Park Inn by Radisson Davao',
            'marinatuna-rogen-inn' => 'Rogen Inn',
            'rhapsody-aria-davao' => 'Aria Hotel & Residences',
        ];
        $hostDestinationNames = [
            'vista-restaurant-eden' => 'Eden Nature Park',
            'resto-beach-paradise' => 'Paradise Island Park & Beach Resort',
        ];

        $defaultRegion = Region::firstOrCreate(['name' => 'Davao City']);

        foreach ($manifest['restaurants'] ?? [] as $row) {
            $slug = $row['slug'];
            $hostAcc = isset($hostAccommodationNames[$slug])
                ? Accommodation::where('name', $hostAccommodationNames[$slug])->first()
                : (isset($row['host_accommodation_id']) ? Accommodation::find($row['host_accommodation_id']) : null);
            $hostDest = isset($hostDestinationNames[$slug])
                ? Destination::where('name', $hostDestinationNames[$slug])->first()
                : (isset($row['host_destination_id']) ? Destination::find($row['host_destination_id']) : null);
            $host = $hostAcc ?? $hostDest;

            $rest = Restaurant::firstOrNew(['slug' => $slug]);
            $attributes = [
                'name' => $row['name'],
                'location' => $host?->location ?? ($row['location'] ?? 'Davao City'),
                'region_id' => $host?->region_id ?? $defaultRegion->id,
                'latitude' => $host?->latitude ?? ($row['coordinates'][0] ?? null),
                'longitude' => $host?->longitude ?? ($row['coordinates'][1] ?? null),
                'opening_hours' => $row['opening_hours'],
                'host_accommodation_id' => $hostAcc?->id,
                'host_destination_id' => $hostDest?->id,
                'planner_verified_at' => $checkedAt,
                'planner_verification_source' => $row['source'],
                'description' => 'Named dining venue checked against the recorded sources. Confirm service, availability and meal charges; this itinerary is not a booking.',
            ];
            if (! $rest->exists) {
                $attributes['is_accredited'] = false;
            }
            $rest->fill($attributes)->save();
        }
    }
}
