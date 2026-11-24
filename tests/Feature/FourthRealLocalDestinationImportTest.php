<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFourthRealLocalDestinations;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FourthRealLocalDestinationImportTest extends TestCase
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
        $manifest = new ListingPhotoManifest(base_path(ImportFourthRealLocalDestinations::DIRECTORY));
        $this->assertSame([], $manifest->problems());
        $this->assertCount(15, $manifest->entries());
        $this->artisan('destinations:import-fourth-real-local')->expectsOutputToContain('15 destinations and 15 photos ready')->assertSuccessful();
        $this->assertSame(0, Destination::count());
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])->assertSuccessful();
        $before = Destination::orderBy('id')->get()->toJson();
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])->expectsOutputToContain('0 destinations and 0 photos added')->assertSuccessful();
        $this->assertSame($before, Destination::orderBy('id')->get()->toJson());
        $this->assertSame(15, ListingPhoto::count());
        $this->assertSame(0, AccreditationRecord::count());
        foreach (Destination::with('photos')->get() as $destination) {
            $this->assertSame(SampleData::UNVERIFIED, app(SampleData::class)->status($destination));
            $this->assertCount(1, $destination->photos);
            $photo = $destination->coverPhoto();
            $this->assertFalse($photo->isIllustration());
            Storage::disk('public')->assertExists($photo->path);
            $this->assertNotEmpty($photo->creditLine());
            $this->assertNull($destination->latitude);
            $this->assertNull($destination->entry_fee_max);
            $this->assertSame('excluded', $destination->itinerary_role);
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
        $existing = Destination::create(['name' => 'Buntod Ta Laya', 'slug' => 'buntod-ta-laya',
            'location' => 'Existing verified address', 'region_id' => Region::where('name', 'Davao del Sur')->value('id'),
            'description' => 'Keep this description', 'entry_fee_min' => 80, 'entry_fee_max' => 80]);
        $photo = $existing->photos()->create(['path' => 'existing-owner.jpg', 'category' => 'General']);
        $before = $existing->fresh()->getRawOriginal();
        $photoBefore = $photo->fresh()->getRawOriginal();
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])
            ->expectsOutputToContain('14 destinations and 14 photos added')->assertSuccessful();
        $this->assertSame(15, Destination::count());
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertSame($photoBefore, $photo->fresh()->getRawOriginal());
        $this->assertSame(1, $existing->photos()->count());
        $this->assertFalse(Destination::where('slug', 'bamboo-peak')->exists());
    }

    public function test_production_is_refused_before_any_records_are_created(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame(0, Destination::count());
        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_an_existing_stay_alias_aborts_the_entire_batch_without_adding_photos(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        \App\Models\Accommodation::create([
            'name' => 'DECA Wakeboarding Park', 'slug' => 'existing-deca-stay', 'location' => 'Existing address',
            'region_id' => Region::where('name', 'Davao Oriental')->value('id'),
        ]);
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame(0, Destination::count());
        $this->assertSame(0, ListingPhoto::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_two_existing_aliases_are_reported_as_ambiguous_without_mutation(): void
    {
        Storage::fake('public');
        $this->prepareRegions();
        foreach (['Bamboo Peak', 'Buntod Ta Laya'] as $name) {
            Destination::create(['name' => $name, 'slug' => str($name)->slug()->toString(),
                'region_id' => Region::where('name', 'Davao del Sur')->value('id'), 'location' => 'Existing address']);
        }
        $before = Destination::orderBy('id')->get()->toJson();
        $this->artisan('destinations:import-fourth-real-local', ['--apply' => true])->assertFailed();
        $this->assertSame($before, Destination::orderBy('id')->get()->toJson());
        $this->assertSame(0, ListingPhoto::count());
    }
}

