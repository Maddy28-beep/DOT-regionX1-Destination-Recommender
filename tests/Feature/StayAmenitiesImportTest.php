<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\ListingAmenity;
use App\Models\Region;
use App\Support\Amenities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Parking and views from the DOT guidebook: a stay can have them, the importer only adds, and the filter finds them. */
class StayAmenitiesImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'stay').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function stay(string $name = 'Acacia Hotel Davao', string $slug = 'acacia-hotel-davao'): Accommodation
    {
        return Accommodation::create([
            'slug' => $slug, 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
    }

    private function csv(array $rows): string
    {
        $out = fopen($this->file, 'w');
        fputcsv($out, ['slug', 'name', 'amenity', 'source']);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return $this->file;
    }

    private const SOURCE = 'DOT Region XI, Davao MICE Guidebook 2024, PDF page 68';

    public function test_views_are_amenities_a_stay_is_offered_and_an_owner_can_tick_them(): void
    {
        foreach (['view_city', 'view_island', 'view_nature', 'view_mountain'] as $key) {
            $this->assertContains($key, Amenities::FOR_KIND['accommodation']);
            $this->assertNotNull(Amenities::group($key));
            $this->assertContains($key, Amenities::EDITOR_GROUPS['Views'], 'in the owner portal, so saving the form never wipes them');
        }
        $this->assertNotContains('view_city', Amenities::FOR_KIND['restaurant']);
    }

    public function test_every_view_has_an_icon_and_a_picture(): void
    {
        $icons = file_get_contents(resource_path('views/components/amenity-icon.blade.php'));
        foreach (['city-view', 'island-view', 'nature-view', 'mountain-view'] as $slug) {
            $this->assertStringContainsString("@case('{$slug}')", $icons);
            $this->assertFileExists(public_path("img/amenities/{$slug}.svg"));
        }
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->stay();
        $file = $this->csv([['acacia-hotel-davao', 'Acacia Hotel Davao', 'parking', self::SOURCE]]);

        $this->artisan('accommodations:apply-amenities', ['file' => $file])->assertSuccessful();

        $this->assertSame(0, ListingAmenity::count());
    }

    public function test_apply_adds_the_amenities_and_a_second_run_adds_nothing(): void
    {
        $stay = $this->stay();
        $file = $this->csv([
            ['acacia-hotel-davao', 'Acacia Hotel Davao', 'parking', self::SOURCE],
            ['acacia-hotel-davao', 'Acacia Hotel Davao', 'view_city', self::SOURCE],
        ]);

        $this->artisan('accommodations:apply-amenities', ['file' => $file, '--apply' => true])->assertSuccessful();
        $this->artisan('accommodations:apply-amenities', ['file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['parking', 'view_city'], ListingAmenity::where('listing_kind', 'accommodation')->where('listing_id', $stay->id)->pluck('amenity')->all());
    }

    public function test_it_never_removes_what_the_stay_already_has(): void
    {
        $stay = $this->stay();
        ListingAmenity::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'amenity' => 'wifi']);
        $file = $this->csv([['acacia-hotel-davao', 'Acacia Hotel Davao', 'parking', self::SOURCE]]);

        $this->artisan('accommodations:apply-amenities', ['file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['wifi', 'parking'], ListingAmenity::where('listing_id', $stay->id)->pluck('amenity')->all());
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        $this->stay();
        $file = $this->csv([
            ['acacia-hotel-davao', 'Acacia Hotel Davao', 'parking', self::SOURCE],
            ['acacia-hotel-davao', 'Some Other Hotel', 'view_city', self::SOURCE],
        ]);

        $this->artisan('accommodations:apply-amenities', ['file' => $file, '--apply' => true])->assertFailed();

        $this->assertSame(0, ListingAmenity::count(), 'the good row was not written either');
    }

    public function test_a_row_needs_a_known_stay_a_stay_amenity_and_a_source(): void
    {
        $this->stay();

        foreach ([
            ['no-such-stay', 'Acacia Hotel Davao', 'parking', self::SOURCE],
            ['acacia-hotel-davao', 'Acacia Hotel Davao', 'campsites', self::SOURCE],
            ['acacia-hotel-davao', 'Acacia Hotel Davao', 'parking', ''],
        ] as $row) {
            $this->artisan('accommodations:apply-amenities', ['file' => $this->csv([$row]), '--apply' => true])->assertFailed();
        }

        $this->assertSame(0, ListingAmenity::count());
    }

    public function test_the_stays_page_filters_by_view_and_shows_the_chip_on_the_stay(): void
    {
        $city = $this->stay('Acacia Hotel Davao', 'acacia-hotel-davao');
        $other = $this->stay('Quiet Inn', 'quiet-inn');
        $this->stay('Plain Inn', 'plain-inn');
        foreach ([$city, $other] as $s) {
            ListingAmenity::create(['listing_kind' => 'accommodation', 'listing_id' => $s->id, 'amenity' => 'view_city']);
        }
        ListingAmenity::create(['listing_kind' => 'accommodation', 'listing_id' => $other->id, 'amenity' => 'view_island']);

        $page = $this->get(route('accommodations.index', ['amenity' => ['view_island']]))->assertOk();
        $this->assertSame(['Quiet Inn'], collect($page->original->getData()['accommodations']->items())->pluck('name')->all());

        $this->get(route('accommodations.index'))->assertSee('City view')->assertSee('Island view');
        $this->get(route('accommodations.show', 'acacia-hotel-davao'))->assertOk()->assertSee('City view');
    }
}
