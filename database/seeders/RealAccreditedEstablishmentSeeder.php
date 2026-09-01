<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Seeder;

/**
 * Every establishment on the official DOT Region XI accreditation list.
 *
 * Source: "LIST OF DOT ACCREDITED TOURISM ESTABLISHMENTS, REGION XI (DAVAO
 * REGION), as of 18 AUGUST 2026", supplied by the user as a PDF. The full
 * 372-row sheet was extracted and is stored verbatim in
 * database/data/dot-accredited-establishments.json -- name, address,
 * accreditation number, contact, room count and both dates are copied from
 * that government record, not invented.
 *
 * Enterprise types are mapped to this site's listing models as DOT classifies
 * them, so an establishment appears under the category it is actually
 * accredited for:
 *
 *   Hotel, Resort, Mabuhay Accommodation,        -> accommodations
 *   Condominium Unit, Apartment Hotel
 *   Restaurant                                   -> restaurants
 *   Travel Agency, Travel and Tour Agency,       -> tour operators
 *   Tour Operator, Tourist Transport Operator,
 *   Passenger Vessel
 *   Tourist Shop, Tourist Shop (Dive Shop)       -> souvenir centers
 *   Zoo, Museum, Farm Tourism, Sports &          -> destinations
 *   Recreational Center, Spa, MICE Facility
 *
 * Two rows are deliberately not listed, because they are not tourist-facing
 * places to visit: a tourism training college and an ambulatory clinic. They
 * are recorded in the JSON under "skipped" so the omission is auditable.
 *
 * Ratings stay at 0 / review_count 0: these are real businesses with no real
 * review data behind them, so "Not yet rated" is the honest state. Fields the
 * sheet does not carry (cuisine type, price tier, coordinates) are left null
 * rather than guessed -- except for the 49 establishments that an earlier pass
 * geocoded and curated, whose values are preserved in the JSON.
 */
class RealAccreditedEstablishmentSeeder extends Seeder
{
    /** Maps the JSON's category key to its model and listing_kind. */
    private const CATEGORIES = [
        'accommodation' => [Accommodation::class, 'accommodation'],
        'restaurant' => [Restaurant::class, 'restaurant'],
        'tour_operator' => [TourOperator::class, 'tour_operator'],
        'souvenir_center' => [SouvenirCenter::class, 'souvenir_center'],
        'destination' => [Destination::class, 'destination'],
    ];

    public function run(): void
    {
        $path = database_path('data/dot-accredited-establishments.json');

        if (! is_file($path)) {
            $this->command?->warn("DOT establishment data not found at {$path}; skipping.");

            return;
        }

        $data = json_decode(file_get_contents($path), true);
        $regions = Region::pluck('id', 'name');

        foreach (self::CATEGORIES as $key => [$model, $kind]) {
            foreach ($data['listings'][$key] ?? [] as $row) {
                $this->seedOne($model, $kind, $row, $regions);
            }
        }
    }

    private function seedOne(string $model, string $kind, array $row, $regions): void
    {
        // Curated seeders (DestinationSeeder, TourOperatorSeeder) run first and
        // already own some of these slugs with richer copy and illustrations.
        // Those entries are left alone; only the accreditation record is topped
        // up, so a hand-written destination keeps its description.
        $listing = $model::where('slug', $row['slug'])->first();

        if (! $listing) {
            $listing = new $model();
            $listing->slug = $row['slug'];
            $listing->name = $row['name'];
            $listing->location = $row['location'];
            $listing->region_id = $regions[$row['region']] ?? null;
            $listing->description = $this->describe($row, $kind);
            $listing->is_accredited = true;
            $listing->rating = 0;
            $listing->review_count = 0;
            $listing->latitude = $row['latitude'];
            $listing->longitude = $row['longitude'];

            // Only set columns the model actually has; the five listing tables
            // do not share a schema.
            $optional = [
                'type' => $row['type'] ?? null,
                'price_tier' => $row['price_tier'] ?? null,
                'cuisine_type' => $row['cuisine_type'] ?? null,
                'specialization' => $row['specialization'] ?? null,
                'contact_number' => $row['contact'] ?? null,
                'distance_km' => $row['distance_km'] ?? null,
                'dot_classification' => $kind === 'accommodation' ? 'DOT-Accredited' : null,
            ];

            // Checked against the schema, not isFillable(): the seed command
            // runs inside Model::unguarded(), so isFillable() answers true for
            // every column and would set fields the table doesn't have.
            foreach ($optional as $column => $value) {
                if ($value !== null && $this->tableHas($listing->getTable(), $column)) {
                    $listing->{$column} = $value;
                }
            }

            $listing->save();
        }

        AccreditationRecord::firstOrCreate(
            ['accreditation_number' => $row['accno']],
            [
                'listing_kind' => $kind,
                'listing_id' => $listing->id,
                'status' => $this->statusFor($row['expiry']),
                'issue_date' => $row['issued'],
                'expiration_date' => $row['expiry'],
            ]
        );
    }

    /** @var array<string, array<string, true>> column names, keyed by table */
    private array $columns = [];

    private function tableHas(string $table, string $column): bool
    {
        $this->columns[$table] ??= array_flip(
            \Illuminate\Support\Facades\Schema::getColumnListing($table)
        );

        return isset($this->columns[$table][$column]);
    }

    private function describe(array $row, string $kind): string
    {
        $what = $row['type'] ?? $row['specialization'] ?? $this->nounFor($kind);

        return "{$row['name']} is a DOT Region XI-accredited {$what} in {$row['region']} "
            ."(accreditation no. {$row['accno']}).";
    }

    private function nounFor(string $kind): string
    {
        return match ($kind) {
            'restaurant' => 'restaurant',
            'souvenir_center' => 'tourist shop',
            'tour_operator' => 'travel and tour operator',
            'destination' => 'tourism establishment',
            default => 'establishment',
        };
    }

    /**
     * Derived from the sheet's own expiry date rather than stored as a literal,
     * so the status can never disagree with the date printed beside it.
     * Mirrors the 60-day window SyncAccreditationStatus uses.
     */
    private function statusFor(?string $expiry): string
    {
        if (! $expiry) {
            return 'Active';
        }

        $date = \Illuminate\Support\Carbon::parse($expiry)->startOfDay();

        if ($date->isPast()) {
            return 'Expired';
        }

        return $date->lessThanOrEqualTo(now()->startOfDay()->addDays(60))
            ? 'Expiring Soon'
            : 'Active';
    }
}
