<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * listings:archive takes a listing out of the site and out of itineraries without deleting it. It is only safe on live
 * data if it cannot hit the wrong row, can be undone, and leaves nothing half done.
 */
class ArchiveListingsTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'No map location, cannot be placed on a route';

    private function shop(string $name = 'Kadayawan Souvenir Shop'): SouvenirCenter
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return SouvenirCenter::create([
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
        ]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arc').'.csv';
        $lines = ['table,id,name,reason'];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r));
        }
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function test_a_dry_run_changes_nothing_and_apply_archives_once(): void
    {
        $shop = $this->shop();
        $file = $this->csv([['souvenir_centers', $shop->id, 'Kadayawan Souvenir Shop', self::REASON]]);

        $this->artisan('listings:archive', ['file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertNull($shop->fresh()->archived_at);

        $this->artisan('listings:archive', ['file' => $file, '--apply' => true])->expectsOutputToContain('Archived 1 listings')->assertSuccessful();
        $this->assertNotNull($shop->fresh()->archived_at);
        $this->assertFalse(SouvenirCenter::publiclyVisible()->whereKey($shop->id)->exists(), 'An archived shop is no longer offered anywhere.');

        $this->artisan('listings:archive', ['file' => $file, '--apply' => true])->expectsOutputToContain('Archived 0 listings')->assertSuccessful();
    }

    public function test_restore_puts_it_back(): void
    {
        $shop = $this->shop();
        $shop->archive();
        $file = $this->csv([['souvenir_centers', $shop->id, 'Kadayawan Souvenir Shop', '']]);

        $this->artisan('listings:archive', ['file' => $file, '--restore' => true, '--apply' => true])->expectsOutputToContain('Restored 1 listings')->assertSuccessful();

        $this->assertNull($shop->fresh()->archived_at);
    }

    public function test_the_name_must_match_the_id_and_a_reason_is_required(): void
    {
        $shop = $this->shop();

        $this->artisan('listings:archive', ['file' => $this->csv([['souvenir_centers', $shop->id, 'Some Other Shop', self::REASON]]), '--apply' => true])
            ->expectsOutputToContain("not 'Some Other Shop'")->assertFailed();

        $this->artisan('listings:archive', ['file' => $this->csv([['souvenir_centers', $shop->id, 'Kadayawan Souvenir Shop', '']]), '--apply' => true])
            ->expectsOutputToContain('a reason is required')->assertFailed();

        $this->artisan('listings:archive', ['file' => $this->csv([['coupons', 1, 'x', self::REASON]]), '--apply' => true])->assertFailed();

        $this->assertNull($shop->fresh()->archived_at);
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        $shop = $this->shop();
        $file = $this->csv([
            ['souvenir_centers', $shop->id, 'Kadayawan Souvenir Shop', self::REASON],
            ['souvenir_centers', 9999, 'Missing Shop', self::REASON],
        ]);

        $this->artisan('listings:archive', ['file' => $file, '--apply' => true])->assertFailed();

        $this->assertNull($shop->fresh()->archived_at, 'The good row must not be applied alone.');
    }

    public function test_it_archives_a_restaurant_too(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $restaurant = Restaurant::create([
            'slug' => 'kublai-khan', 'name' => 'Kublai Khan Mongolian Restaurant', 'location' => 'Davao City', 'region_id' => $region->id,
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);

        $this->artisan('listings:archive', ['file' => $this->csv([['restaurants', $restaurant->id, 'Kublai Khan Mongolian Restaurant', self::REASON]]), '--apply' => true])
            ->assertSuccessful();

        $this->assertNotNull($restaurant->fresh()->archived_at);
    }

    public function test_the_shipped_file_names_the_three_listings_with_a_reason_each(): void
    {
        $rows = array_map('str_getcsv', file(base_path('database/data/listings-to-archive-2026-10-30.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertSame(['table', 'id', 'name', 'reason'], array_shift($rows));
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertGreaterThan(30, strlen($row[3]), "{$row[2]} needs a real reason");
        }
        $this->assertSame(['Davao Local Products & Souvenir Center', 'Kadayawan Souvenir Shop', 'Kublai Khan Mongolian Restaurant'], array_column($rows, 2));
    }
}
