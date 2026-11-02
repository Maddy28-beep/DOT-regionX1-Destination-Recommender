<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoomType;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\ListingAmenity;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Sample accommodations, restaurants and souvenir centres arrive labelled, all or nothing, and can be taken out again. */
class ImportSampleListingsTest extends TestCase
{
    use RefreshDatabase;

    private const ACC_HEADER = 'name,location,region,latitude,longitude,description,amenities,type,price_tier,price_per_night,check_in,check_out,room_types,resort_settings';

    private const REST_HEADER = 'name,location,region,latitude,longitude,description,amenities,cuisine_type,price_tier,opening_hours';

    private const SOUV_HEADER = 'name,location,region,latitude,longitude,description,amenities';

    private function csv(array $lines, string $header): string
    {
        $path = storage_path('framework/sample-listings-'.uniqid().'.csv');
        file_put_contents($path, $header."\n".implode("\n", $lines)."\n");

        return $path;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Davao City', 'Island Garden City of Samal', 'Davao del Norte', 'Davao de Oro', 'Davao Occidental', 'Davao Oriental', 'Davao del Sur'] as $name) {
            Region::firstOrCreate(['name' => $name]);
        }
    }

    public function test_accommodation_dry_run_writes_nothing_and_apply_creates_rooms_amenities_and_provenance(): void
    {
        $file = $this->csv(['Test Hotel,Sample site,Davao City,7.07,125.61,Synthetic stay,wifi|ac|parking|restaurant,Hotel,Mid-range,1500,14:00,12:00,Standard Room:1200-1500;Deluxe Room:1800-2200,'], self::ACC_HEADER);

        $this->assertSame(0, Artisan::call('sample:import-listings', ['file' => $file, '--kind' => 'accommodation']));
        $this->assertStringContainsString('Dry run', Artisan::output());
        $this->assertSame(0, Accommodation::count());

        $this->artisan('sample:import-listings', ['file' => $file, '--kind' => 'accommodation', '--apply' => true])->assertSuccessful();

        $hotel = Accommodation::where('name', 'Test Hotel [SAMPLE]')->firstOrFail();
        $this->assertTrue($hotel->is_accredited);
        $this->assertSame(0, $hotel->review_count);
        $this->assertTrue(app(SampleData::class)->isSample($hotel));
        $this->assertSame(['ac', 'parking', 'restaurant', 'wifi'], ListingAmenity::where('listing_kind', 'accommodation')->where('listing_id', $hotel->id)->pluck('amenity')->sort()->values()->all());
        $this->assertSame(2, AccommodationRoomType::where('accommodation_id', $hotel->id)->count());
        $this->assertSame('SAMPLE-0001', AccreditationRecord::first()->accreditation_number);
        $this->assertTrue(AccreditationRecord::first()->is_placeholder);
    }

    public function test_restaurant_and_souvenir_apply_with_provenance(): void
    {
        $rest = $this->csv(['Test Grill,Sample site,Davao City,7.07,125.61,Synthetic food,parking|restroom|ac|wifi,Filipino,Budget-Friendly,10:00 AM-9:00 PM'], self::REST_HEADER);
        $this->artisan('sample:import-listings', ['file' => $rest, '--kind' => 'restaurant', '--apply' => true])->assertSuccessful();
        $this->assertTrue(app(SampleData::class)->isSample(Restaurant::firstOrFail()));
        $this->assertSame(4, ListingAmenity::where('listing_kind', 'restaurant')->count());

        $souv = $this->csv(['Test Craft,Sample site,Davao City,7.07,125.61,Synthetic products,parking|restroom|ac|gcash'], self::SOUV_HEADER);
        $this->artisan('sample:import-listings', ['file' => $souv, '--kind' => 'souvenir_center', '--apply' => true])->assertSuccessful();
        $this->assertTrue(app(SampleData::class)->isSample(SouvenirCenter::firstOrFail()));
        // Numbers carry on across kinds: one shared SAMPLE sequence.
        $this->assertSame(['SAMPLE-0001', 'SAMPLE-0002'], AccreditationRecord::orderBy('id')->pluck('accreditation_number')->all());
    }

    public function test_duplicate_and_illegal_rows_stop_the_whole_import(): void
    {
        $file = $this->csv(['Dup Hotel,Sample site,Davao City,7.07,125.61,Synthetic,wifi|ac|parking|restaurant,Hotel,Mid-range,1500,14:00,12:00,Standard Room:1200-1500,'], self::ACC_HEADER);
        $this->artisan('sample:import-listings', ['file' => $file, '--kind' => 'accommodation', '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-listings', ['file' => $file, '--kind' => 'accommodation'])->assertFailed();
        $this->assertSame(1, Accommodation::count());

        $bad = $this->csv(['Few Hotel,Sample site,Davao City,7.07,125.61,Synthetic,wifi|ac,Hotel,Mid-range,1500,14:00,12:00,Standard Room:1200-1500,'], self::ACC_HEADER);
        $this->artisan('sample:import-listings', ['file' => $bad, '--kind' => 'accommodation', '--apply' => true])->assertFailed();
        $this->assertSame(1, Accommodation::count(), 'Too-few-amenities row must not be created.');
    }

    public function test_remove_deletes_only_the_samples_of_that_kind(): void
    {
        $region = Region::where('name', 'Davao City')->firstOrFail();
        $real = Accommodation::create(['slug' => 'real-hotel', 'name' => 'Real Hotel', 'location' => 'X', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        $file = $this->csv(['Gone Hotel,Sample site,Davao City,7.07,125.61,Synthetic,wifi|ac|parking|restaurant,Hotel,Mid-range,1500,14:00,12:00,Standard Room:1200-1500,'], self::ACC_HEADER);
        $this->artisan('sample:import-listings', ['file' => $file, '--kind' => 'accommodation', '--apply' => true])->assertSuccessful();

        $this->artisan('sample:import-listings', ['--kind' => 'accommodation', '--remove' => true])->assertSuccessful();
        $this->assertSame(2, Accommodation::count(), 'Remove dry run deletes nothing.');

        $this->artisan('sample:import-listings', ['--kind' => 'accommodation', '--remove' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame([$real->id], Accommodation::pluck('id')->all());
        $this->assertSame(0, AccreditationRecord::count());
        $this->assertSame(0, ListingAmenity::count());
        $this->assertSame(0, AccommodationRoomType::count());
    }

    public function test_the_approved_130_record_data_files_dry_run_cleanly(): void
    {
        $files = [
            ['sample:import-destinations', base_path('database/data/sample-catalogue-130-destinations.csv'), '54'],
            ['sample:import-listings', base_path('database/data/sample-catalogue-130-accommodations.csv'), '27'],
            ['sample:import-listings', base_path('database/data/sample-catalogue-130-restaurants.csv'), '27'],
            ['sample:import-listings', base_path('database/data/sample-catalogue-130-souvenir-centers.csv'), '22'],
        ];
        $kinds = ['accommodation', 'restaurant', 'souvenir_center'];
        foreach ($files as $i => [$command, $path, $rows]) {
            $args = ['file' => $path] + ($i === 0 ? [] : ['--kind' => $kinds[$i - 1]]);
            $this->assertSame(0, Artisan::call($command, $args), "$path must dry-run cleanly");
        }
        $this->assertSame(0, Destination::count() + Accommodation::count() + Restaurant::count() + SouvenirCenter::count(), 'Dry runs write nothing.');
    }

    public function test_the_approved_130_records_apply_with_sample_provenance_on_a_test_database(): void
    {
        $this->artisan('sample:import-destinations', ['file' => base_path('database/data/sample-catalogue-130-destinations.csv'), '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-listings', ['file' => base_path('database/data/sample-catalogue-130-accommodations.csv'), '--kind' => 'accommodation', '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-listings', ['file' => base_path('database/data/sample-catalogue-130-restaurants.csv'), '--kind' => 'restaurant', '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-listings', ['file' => base_path('database/data/sample-catalogue-130-souvenir-centers.csv'), '--kind' => 'souvenir_center', '--apply' => true])->assertSuccessful();

        $sample = app(SampleData::class);
        $this->assertSame(54, Destination::count());
        $this->assertSame(27, Accommodation::count());
        $this->assertSame(27, Restaurant::count());
        $this->assertSame(22, SouvenirCenter::count());
        $this->assertSame(130, AccreditationRecord::where('is_placeholder', true)->count());
        foreach ([Destination::class, Accommodation::class, Restaurant::class, SouvenirCenter::class] as $model) {
            $this->assertSame(0, $model::all()->filter(fn ($l) => ! $sample->isSample($l))->count(), "$model: every imported record must be sample.");
        }
        $this->assertSame(54, Destination::whereHas('tags', fn ($q) => $q->where('kind', 'category'))->count(), 'Every sample destination carries category tags.');
    }
}
