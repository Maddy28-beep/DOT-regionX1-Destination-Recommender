<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * listings:apply-source-updates writes a fixed set of columns from the reviewed workbook, only to
 * rows whose id AND name both match, never from a blank cell, and only when --apply is given.
 */
class ApplyListingSourceUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'lsu');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function write(array $entries): void
    {
        file_put_contents($this->file, json_encode(['entries' => $entries]));
    }

    private function region(): Region
    {
        return Region::firstOrCreate(['name' => 'Davao City']);
    }

    private function destination(string $name = 'Eden Nature Park', array $extra = []): Destination
    {
        return Destination::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region()->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            'latitude' => 7.03, 'longitude' => 125.37,
        ]);
    }

    private function hotel(string $name = 'Acacia Hotel Davao', array $extra = []): Accommodation
    {
        return Accommodation::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region()->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $eden = $this->destination();
        $this->write([['table' => 'destinations', 'id' => $eden->id, 'name' => 'Eden Nature Park', 'fields' => ['hours' => '9:00 AM–5:00 PM', 'visit_duration' => '240 minutes']]]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Values to change: 2')
            ->assertSuccessful();

        $this->assertNull($eden->fresh()->hours);
        $this->assertNull($eden->fresh()->visit_duration);
    }

    public function test_apply_writes_the_requested_fields_and_nothing_else(): void
    {
        $eden = $this->destination();
        $acacia = $this->hotel();
        $this->write([
            ['table' => 'destinations', 'id' => $eden->id, 'name' => 'Eden Nature Park', 'fields' => [
                'hours' => '9:00 AM–5:00 PM', 'entry_fee_min' => 100, 'entry_fee_max' => 300, 'visit_duration' => '240 minutes',
                'best_time' => 'Morning', 'price_tier' => 'Budget-Friendly',
                'latitude' => 1.0, 'name' => 'Hacked', // not in the allowed set: must be ignored
            ]],
            ['table' => 'accommodations', 'id' => $acacia->id, 'name' => 'Acacia Hotel Davao', 'fields' => [
                'check_in' => '15:00:00', 'check_out' => '11:00:00', 'price_per_night' => 2900, 'price_tier' => 'Premium',
            ]],
        ]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $eden = $eden->fresh();
        $this->assertSame('9:00 AM–5:00 PM', $eden->hours);
        $this->assertEquals(100, $eden->entry_fee_min);
        $this->assertEquals(300, $eden->entry_fee_max);
        $this->assertSame('240 minutes', $eden->visit_duration);
        $this->assertSame('Morning', $eden->best_time);
        $this->assertSame('Budget-Friendly', $eden->price_tier);
        $this->assertEquals(7.03, $eden->latitude, 'Coordinates are never touched.');
        $this->assertSame('Eden Nature Park', $eden->name, 'The name is never touched.');

        $acacia = $acacia->fresh();
        $this->assertSame('15:00', substr((string) $acacia->check_in, 0, 5));
        $this->assertSame('11:00', substr((string) $acacia->check_out, 0, 5));
        $this->assertEquals(2900, $acacia->price_per_night);
        $this->assertSame('Premium', $acacia->price_tier);
    }

    public function test_restaurants_take_opening_hours_and_price_tier(): void
    {
        $restaurant = Restaurant::create([
            'slug' => 'arvies-cafe', 'name' => "Arvie's Cafe", 'location' => 'Nabunturan', 'region_id' => $this->region()->id,
            'cuisine_type' => 'Cafe', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
        $this->write([['table' => 'restaurants', 'id' => $restaurant->id, 'name' => "Arvie's Cafe", 'fields' => ['opening_hours' => '8:00 AM–8:00 PM', 'price_tier' => 'Budget-Friendly', 'contact_number' => '000']]]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $restaurant = $restaurant->fresh();
        $this->assertSame('8:00 AM–8:00 PM', $restaurant->opening_hours);
        $this->assertSame('Budget-Friendly', $restaurant->price_tier);
        $this->assertNull($restaurant->contact_number, 'Contact numbers are not in scope.');
    }

    public function test_a_row_whose_name_differs_or_whose_id_is_missing_is_skipped(): void
    {
        $eden = $this->destination();
        $this->write([
            ['table' => 'destinations', 'id' => $eden->id, 'name' => 'Belviz Farm', 'fields' => ['hours' => '8:00 AM–5:00 PM']],
            ['table' => 'destinations', 'id' => 99999, 'name' => 'Nowhere', 'fields' => ['hours' => '8:00 AM–5:00 PM']],
        ]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('skipped, no such id: 1 | skipped, name differs: 1')
            ->assertSuccessful();

        $this->assertNull($eden->fresh()->hours);
    }

    public function test_a_blank_value_never_overwrites_and_a_bad_price_tier_is_rejected(): void
    {
        $eden = $this->destination('Eden Nature Park', ['hours' => 'Existing hours']);
        $this->write([['table' => 'destinations', 'id' => $eden->id, 'name' => 'Eden Nature Park', 'fields' => ['hours' => '', 'best_time' => null, 'price_tier' => 'Luxury']]]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $eden = $eden->fresh();
        $this->assertSame('Existing hours', $eden->hours);
        $this->assertNull($eden->best_time);
        $this->assertSame('Mid-range', $eden->price_tier);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $acacia = $this->hotel();
        $this->write([['table' => 'accommodations', 'id' => $acacia->id, 'name' => 'Acacia Hotel Davao', 'fields' => ['check_in' => '15:00:00', 'price_per_night' => 2900]]]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('Values to change: 0')
            ->expectsOutputToContain('Nothing needed changing')
            ->assertSuccessful();
    }

    public function test_a_table_outside_the_allowed_three_is_refused(): void
    {
        $this->write([['table' => 'users', 'id' => 1, 'name' => 'x', 'fields' => ['name' => 'y']]]);

        $this->artisan('listings:apply-source-updates', ['--file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('table not allowed')
            ->assertSuccessful();
    }

    public function test_the_shipped_data_file_only_contains_the_requested_fields(): void
    {
        $data = json_decode(file_get_contents(base_path('database/data/listing-source-updates.json')), true);
        $allowed = [
            'destinations' => ['hours', 'entry_fee_min', 'entry_fee_max', 'visit_duration', 'best_time', 'price_tier'],
            'accommodations' => ['check_in', 'check_out', 'price_per_night', 'price_tier'],
            'restaurants' => ['opening_hours', 'price_tier'],
        ];

        $this->assertNotEmpty($data['entries']);
        foreach ($data['entries'] as $entry) {
            $this->assertArrayHasKey($entry['table'], $allowed);
            $this->assertSame([], array_diff(array_keys($entry['fields']), $allowed[$entry['table']]), $entry['name']);
        }
    }
}
