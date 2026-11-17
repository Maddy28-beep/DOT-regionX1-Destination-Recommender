<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\ListingPhoto;
use App\Support\ListingPhotoManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StayPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    private function entry(int $id): array
    {
        return collect((new ListingPhotoManifest(database_path('data/listing-photos/stays-research-2026-11-17')))->entries())->firstWhere('id', $id);
    }

    private function stay(int $id, array $override = []): Accommodation
    {
        $entry = $this->entry($id);
        return Accommodation::create(array_merge(['name' => $entry['name'], 'slug' => $entry['slug'], 'location' => $entry['location'],
            'type' => 'Hotel', 'is_accredited' => true, 'price_per_night' => 2300, 'price_per_day' => 700, 'latitude' => 7.1, 'longitude' => 125.6], $override));
    }

    public function test_import_is_read_only_by_default_preserves_existing_photos_and_is_repeat_safe(): void
    {
        Storage::fake('public');
        $new = $this->stay(14);
        $placeholder = $new->photos()->create(['path' => 'old.svg', 'category' => 'General', 'is_primary' => true]);
        $existing = $this->stay(20);
        $real = $existing->photos()->create(['path' => 'owner.jpg', 'category' => 'General', 'is_primary' => true]);
        $complex = $this->stay(211);
        $wrong = $this->stay(22, ['location' => 'Different branch']);
        $before = $new->fresh()->getRawOriginal();
        $realBefore = $real->fresh()->getRawOriginal();
        $placeholderBefore = $placeholder->fresh()->getRawOriginal();

        $this->artisan('stays:apply-photos')->expectsOutputToContain('2 photos ready')->assertSuccessful();
        $this->assertSame(2, ListingPhoto::count());
        $this->artisan('stays:apply-photos', ['--apply' => true])->expectsOutputToContain('2 photos added')->assertSuccessful();
        $this->artisan('stays:apply-photos', ['--apply' => true])->expectsOutputToContain('0 photos added')->assertSuccessful();

        $this->assertSame(4, ListingPhoto::count());
        $this->assertSame($before, $new->fresh()->getRawOriginal(), 'Only listing_photos may change.');
        $this->assertSame($realBefore, $real->fresh()->getRawOriginal());
        $this->assertSame($placeholderBefore, $placeholder->fresh()->getRawOriginal());
        $this->assertSame(1, $existing->photos()->count());
        $this->assertSame(0, $wrong->photos()->count());
        $photo = $new->fresh()->coverPhoto();
        $this->assertFalse($photo->isIllustration());
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame('team_testing_decision', $photo->permission_basis);
        $this->assertSame('Complex exterior · Unit not shown', $complex->fresh()->coverPhoto()->scopeLabel());
        $this->assertStringContainsString('Complex exterior', $complex->fresh()->coverPhoto()->creditLine());
    }

    public function test_complex_labels_are_visible_on_cards_and_both_gallery_layouts(): void
    {
        $stay = $this->stay(211);
        $stay->photos()->create(['path' => 'complex.jpg', 'category' => 'Complex exterior · Unit not shown', 'is_primary' => true]);
        $card = view('partials.listing-card', ['listing' => $stay])->render();
        $this->assertStringContainsString('photo-scope-tag', $card);
        $this->assertStringContainsString('Complex exterior · Unit not shown', $card);
        $this->get(route('accommodations.show', $stay->slug))->assertOk()->assertSee('Complex exterior · Unit not shown');
    }
}
