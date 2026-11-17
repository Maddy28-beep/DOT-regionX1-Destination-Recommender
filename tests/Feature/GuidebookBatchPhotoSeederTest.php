<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Database\Seeders\GuidebookBatchPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The DOT Davao MICE Guidebook 2024 photo batch: stays only (the guidebook has no restaurant listing), each picture
 * credited to the hotel and DOT Region XI, and a stay found by exact name, never by guess.
 */
class GuidebookBatchPhotoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DIR = 'data/listing-photos/guidebook-2026-11';

    public function test_the_shipped_batch_is_sound_complete_and_credited(): void
    {
        $manifest = new ListingPhotoManifest(database_path(self::DIR));
        $entries = $manifest->entries();

        $this->assertCount(44, $entries);
        $this->assertSame([], $manifest->problems());
        $this->assertCount(44, $manifest->approved());

        foreach ($entries as $e) {
            $this->assertSame('Accommodation', $e['kind'], $e['name']);
            $this->assertStringContainsString('DOT Region XI', $e['credit_text'], $e['name']);
            $this->assertStringContainsString($e['name'], $e['credit_text'], $e['name']);
            $this->assertTrue($e['show_credit'], $e['name'].': the credit must stay shown');
            $this->assertSame('2026-11-17', $e['permission_date']);
        }
    }

    public function test_a_stay_gets_its_guidebook_picture_and_running_twice_adds_nothing(): void
    {
        Storage::fake('public');
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $stay = Accommodation::create(['slug' => 'acacia', 'name' => 'Acacia Hotel Davao', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);

        (new GuidebookBatchPhotoSeeder)->run();
        (new GuidebookBatchPhotoSeeder)->run();

        $this->assertSame(1, $stay->photos()->count());
        $photo = $stay->photos()->first();
        $this->assertStringStartsWith('listings/guidebook-2026-11/', $photo->path);
        $this->assertTrue((bool) $photo->is_primary);
        $this->assertStringContainsString('Acacia Hotel Davao', (string) $photo->creditLine());
        Storage::disk('public')->assertExists($photo->path);
    }
}
