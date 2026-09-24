<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use Database\Seeders\RealAccreditedEstablishmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeder used to set coordinates only when it created a listing, so a
 * database seeded before the DOT coordinate sheet arrived never received
 * them. Re-running it now fills a missing position, but must never move a pin
 * that is already there, since that may be a partner's own portal map pin.
 */
class RealAccreditedEstablishmentSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> the data file's row for this accreditation number */
    private function dataRow(string $accno): array
    {
        $data = json_decode(file_get_contents(database_path('data/dot-accredited-establishments.json')), true);

        foreach ($data['listings'] as $rows) {
            foreach ($rows as $row) {
                if ($row['accno'] === $accno) {
                    return $row;
                }
            }
        }

        $this->fail("No data row for {$accno}");
    }

    private function accommodation(array $row, ?float $lat, ?float $lng): Accommodation
    {
        $region = Region::firstOrCreate(['name' => $row['region']]);

        return Accommodation::create([
            'slug' => $row['slug'], 'name' => $row['name'], 'location' => $row['location'],
            'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'latitude' => $lat, 'longitude' => $lng,
        ]);
    }

    public function test_an_existing_listing_without_a_position_receives_the_data_files_coordinates(): void
    {
        $row = $this->dataRow('DOT-R11-HTL-00112-2021'); // Acacia Hotel Davao
        $this->assertNotNull($row['latitude']);
        $listing = $this->accommodation($row, null, null);

        $this->seed(RealAccreditedEstablishmentSeeder::class);

        $listing->refresh();
        $this->assertEqualsWithDelta($row['latitude'], (float) $listing->latitude, 0.0000001);
        $this->assertEqualsWithDelta($row['longitude'], (float) $listing->longitude, 0.0000001);
    }

    public function test_an_existing_pin_is_never_overwritten(): void
    {
        $row = $this->dataRow('DOT-R11-HTL-01326-2024'); // Apo View Hotel
        $this->assertNotNull($row['latitude']);
        $listing = $this->accommodation($row, 7.05, 125.55);

        $this->seed(RealAccreditedEstablishmentSeeder::class);

        $listing->refresh();
        $this->assertEqualsWithDelta(7.05, (float) $listing->latitude, 0.0000001);
        $this->assertEqualsWithDelta(125.55, (float) $listing->longitude, 0.0000001);
    }

    public function test_every_imported_coordinate_lies_within_the_davao_region(): void
    {
        $this->seed(RealAccreditedEstablishmentSeeder::class);

        foreach ([Accommodation::class, Destination::class] as $model) {
            foreach ($model::whereNotNull('latitude')->get() as $listing) {
                $this->assertTrue(
                    $listing->latitude >= 5.3 && $listing->latitude <= 8.1
                        && $listing->longitude >= 125.0 && $listing->longitude <= 126.7,
                    "{$listing->name} is pinned outside the Davao Region ({$listing->latitude}, {$listing->longitude})"
                );
            }
        }
    }
}
