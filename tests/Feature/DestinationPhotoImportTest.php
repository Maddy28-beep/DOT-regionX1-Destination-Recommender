<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Support\ListingPhotoManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DestinationPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    private function destination(int $id, array $override = []): Destination
    {
        $entry = collect((new ListingPhotoManifest(database_path('data/listing-photos/destinations-research-2026-11-17')))->entries())->firstWhere('id', $id);
        return Destination::create(array_merge(['name' => $entry['name'], 'slug' => $entry['slug'], 'location' => $entry['location'],
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'entry_fee_min' => 100, 'entry_fee_max' => 300,
            'latitude' => 7.1, 'longitude' => 125.6, 'itinerary_role' => 'sightseeing'], $override));
    }

    public function test_reviewed_batch_is_valid_and_contains_one_photo_per_named_destination(): void
    {
        $manifest = new ListingPhotoManifest(database_path('data/listing-photos/destinations-research-2026-11-17'));
        $this->assertSame([], $manifest->problems());
        $this->assertCount(83, $manifest->approved());
        $this->assertCount(83, array_unique(array_column($manifest->entries(), 'slug')));
        foreach ($manifest->entries() as $entry) {
            $this->assertSame('Destination', $entry['kind']);
            $this->assertFalse(str_starts_with($entry['name'], 'Sample '));
            $this->assertSame('team_testing_decision', $entry['permission_basis']);
        }
    }

    public function test_import_preserves_records_skips_real_photos_and_is_repeat_safe(): void
    {
        Storage::fake('public');
        $new = $this->destination(3);
        $placeholder = $new->photos()->create(['path' => 'old.svg', 'category' => 'General', 'is_primary' => true]);
        $existing = $this->destination(6);
        $real = $existing->photos()->create(['path' => 'owner.jpg', 'category' => 'General', 'is_primary' => true]);
        $wrong = $this->destination(7, ['location' => 'Different location']);
        $archived = $this->destination(8, ['archived_at' => now()]);
        $before = $new->fresh()->getRawOriginal();
        $realBefore = $real->fresh()->getRawOriginal();
        $placeholderBefore = $placeholder->fresh()->getRawOriginal();

        $this->artisan('destinations:apply-photos')->expectsOutputToContain('1 photos ready')->assertSuccessful();
        $this->assertSame(2, ListingPhoto::count());
        $this->artisan('destinations:apply-photos', ['--apply' => true])->expectsOutputToContain('1 photos added')->assertSuccessful();
        $this->artisan('destinations:apply-photos', ['--apply' => true])->expectsOutputToContain('0 photos added')->assertSuccessful();

        $this->assertSame(3, ListingPhoto::count());
        $this->assertSame($before, $new->fresh()->getRawOriginal());
        $this->assertSame($realBefore, $real->fresh()->getRawOriginal());
        $this->assertSame($placeholderBefore, $placeholder->fresh()->getRawOriginal());
        $this->assertSame(1, $existing->photos()->count());
        $this->assertSame(0, $wrong->photos()->count());
        $this->assertSame(0, $archived->photos()->count());
        $photo = $new->fresh()->coverPhoto();
        $this->assertFalse($photo->isIllustration());
        Storage::disk('public')->assertExists($photo->path);
        $this->get('/destinations?q=Philippine%20Eagle')->assertOk()->assertSee($photo->path);
        $this->get('/destinations/'.$new->slug)->assertOk()->assertSee($photo->path)->assertSee('Photo: www.glennong.com');
    }

    public function test_the_batch_is_not_applied_in_production_unless_it_is_asked_for(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('destinations:apply-photos', ['--apply' => true])->assertFailed();
        $this->assertSame(0, ListingPhoto::count());

        // With the explicit flag a live server may run it (a dry run here changes nothing).
        $this->artisan('destinations:apply-photos', ['--allow-production' => true])->assertSuccessful();
        $this->assertSame(0, ListingPhoto::count());
    }
}
