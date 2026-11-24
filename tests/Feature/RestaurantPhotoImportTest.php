<?php

namespace Tests\Feature;

use App\Models\ListingPhoto;
use App\Models\Restaurant;
use App\Support\ListingPhotoManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestaurantPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_photo_import_preserves_existing_data_and_is_repeat_safe(): void
    {
        Storage::fake('public');
        $manifest = new ListingPhotoManifest(database_path('data/listing-photos/restaurants-missing-2026-11-24'));
        $this->assertSame([], $manifest->problems());
        $this->assertCount(13, $manifest->approved());
        $entries = $manifest->entries();
        $create = fn ($e, $override = []) => Restaurant::create(array_merge([
            'name' => $e['name'], 'slug' => $e['slug'], 'location' => $e['location'], 'is_accredited' => true,
            'description' => 'Original description', 'price_tier' => 'mid', 'latitude' => 7.1, 'longitude' => 125.6,
        ], $override));
        $missing = $create($entries[0]);
        $placeholder = $missing->photos()->create(['path' => 'sample.svg', 'category' => 'Sample image', 'is_primary' => true]);
        $existing = $create($entries[1]);
        $real = $existing->photos()->create(['path' => 'owner.jpg', 'category' => 'General', 'is_primary' => true]);
        Storage::disk('public')->put('owner.jpg', 'Preserved original');
        $wrong = $create($entries[2], ['location' => 'Another branch']);
        $archived = $create($entries[3], ['archived_at' => now()]);
        $before = $missing->fresh()->getRawOriginal();
        $placeholderBefore = $placeholder->fresh()->getRawOriginal();
        $realBefore = $real->fresh()->getRawOriginal();

        $this->artisan('restaurants:apply-photos')->expectsOutputToContain('1 photos ready')->assertSuccessful();
        $this->assertSame(2, ListingPhoto::count());
        $this->artisan('restaurants:apply-photos', ['--apply' => true])->expectsOutputToContain('1 photos added')->assertSuccessful();
        $this->artisan('restaurants:apply-photos', ['--apply' => true])->expectsOutputToContain('0 photos added')->assertSuccessful();
        $this->assertSame(3, ListingPhoto::count());
        $this->assertSame($before, $missing->fresh()->getRawOriginal());
        $this->assertSame($placeholderBefore, $placeholder->fresh()->getRawOriginal());
        $this->assertSame($realBefore, $real->fresh()->getRawOriginal());
        $this->assertSame('Preserved original', Storage::disk('public')->get('owner.jpg'));
        $this->assertSame(0, $wrong->photos()->count());
        $this->assertSame(0, $archived->photos()->count());
        $photo = $missing->fresh()->coverPhoto();
        $this->assertFalse($photo->isIllustration());
        Storage::disk('public')->assertExists($photo->path);
        $this->get('/restaurants?q=Kublai')->assertOk()->assertSee($photo->path);
        $this->get('/restaurants/'.$missing->slug)->assertOk()->assertSee($photo->path)->assertSee('Historical 2012 photo');
    }

    public function test_this_batch_cannot_be_applied_on_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('restaurants:apply-photos', ['--apply' => true])->assertFailed();
        $this->assertSame(0, ListingPhoto::count());
    }
}
