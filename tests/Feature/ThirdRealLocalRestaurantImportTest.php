<?php

namespace Tests\Feature;

use App\Console\Commands\ImportThirdRealLocalRestaurants;
use App\Models\AccreditationRecord;
use App\Models\Restaurant;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThirdRealLocalRestaurantImportTest extends TestCase
{
    use RefreshDatabase;

    private function prepareRegions(): void
    {
        foreach (['Davao City', 'Davao del Norte', 'Davao de Oro', 'Davao del Sur', 'Davao Oriental', 'Davao Occidental'] as $name) {
            Region::firstOrCreate(['name' => $name]);
        }
    }

    public function test_batch_adds_only_unconfirmed_real_places_with_one_photo_and_is_repeat_safe(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        $manifest = new ListingPhotoManifest(base_path(ImportThirdRealLocalRestaurants::DIRECTORY));
        $this->assertSame([], $manifest->problems());
        $this->assertCount(5, $manifest->entries());
        $this->artisan('restaurants:import-third-real-local')->expectsOutputToContain('5 restaurants and 5 photos ready')->assertSuccessful();
        $this->assertSame(0, Restaurant::count());
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])->assertSuccessful();
        $before = Restaurant::orderBy('id')->get()->toJson();
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])->expectsOutputToContain('0 restaurants and 0 photos added')->assertSuccessful();
        $this->assertSame($before, Restaurant::orderBy('id')->get()->toJson());
        $this->assertSame(5, ListingPhoto::count());
        $this->assertSame(0, AccreditationRecord::count());
        $this->assertSame(5, Restaurant::publiclyVisible()->whereNotNull('latitude')->whereNotNull('longitude')->count(), 'All newly authorized restaurant records have sourced map coordinates.');
        foreach (Restaurant::with('photos')->get() as $destination) {
            $this->assertSame(SampleData::UNVERIFIED, app(SampleData::class)->status($destination));
            $this->assertCount(1, $destination->photos);
            $photo = $destination->coverPhoto();
            $this->assertFalse($photo->isIllustration());
            Storage::disk('public')->assertExists($photo->path);
            $this->assertNotEmpty($photo->creditLine());
            $this->assertNotNull($destination->latitude);
            $this->assertNull($destination->price_tier);
            $this->assertNotNull($destination->longitude);
            $card = view('partials.listing-poster-card', ['listing' => $destination])->render();
            $this->assertStringContainsString('dcard__mark is-unverified', $card);
            $this->assertStringNotContainsString('dot-accredited-badge.webp', $card);
            $this->assertStringNotContainsString('Illustration</span>', $card);
        }
    }

    public function test_matching_alias_with_a_real_photo_is_reused_without_changing_existing_data(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        $existing = Restaurant::create(['name' => 'Bairrada', 'slug' => 'existing-hey-monday',
            'location' => 'Existing verified address', 'region_id' => Region::where('name', 'Davao City')->value('id'),
            'description' => 'Keep this description', 'price_tier' => 'Premium']);
        $photo = $existing->photos()->create(['path' => 'existing-owner.jpg', 'category' => 'General']);
        $before = $existing->fresh()->getRawOriginal();
        $photoBefore = $photo->fresh()->getRawOriginal();
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])
            ->expectsOutputToContain('4 restaurants and 4 photos added')->assertSuccessful();
        $this->assertSame(5, Restaurant::count());
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame($photoBefore, $photo->fresh()->getRawOriginal());
        $this->assertSame(1, $existing->photos()->count());
        $this->assertFalse(Restaurant::where('slug', 'bairrada-churrasqueira-tulip')->exists());
    }

    public function test_production_is_refused_before_any_records_are_created(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame(0, Restaurant::count());
        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_an_existing_stay_alias_aborts_the_entire_batch_without_adding_photos(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        \App\Models\Accommodation::create([
            'name' => 'Lara Mia Cafe and Bistro', 'slug' => 'existing-made-simple-stay', 'location' => 'Existing address',
            'region_id' => Region::where('name', 'Davao Oriental')->value('id'),
        ]);
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame(0, Restaurant::count());
        $this->assertSame(0, ListingPhoto::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_two_existing_aliases_are_reported_as_ambiguous_without_mutation(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        foreach (['Bairrada Churrasqueira', 'Bairrada'] as $index => $name) {
            Restaurant::create(['name' => $name, 'slug' => 'ambiguous-hey-monday-'.$index,
                'region_id' => Region::where('name', 'Davao City')->value('id'), 'location' => 'Existing address']);
        }
        $before = Restaurant::orderBy('id')->get()->toJson();
        $this->artisan('restaurants:import-third-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame($before, Restaurant::orderBy('id')->get()->toJson());
        $this->assertSame(0, ListingPhoto::count());
    }
}

