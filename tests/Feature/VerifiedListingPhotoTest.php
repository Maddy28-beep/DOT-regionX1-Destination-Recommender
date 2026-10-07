<?php
namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Database\Seeders\VerifiedListingPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifiedListingPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** These tests need the committed photo files from database/data/listing-photos. */
    private function requirePhotoFiles(): void
    {
        if (! is_file(database_path('data/listing-photos/destination-2.jpg'))) {
            $this->markTestSkipped('database/data/listing-photos image files are not present.');
        }
    }

    private function place(): Destination
    {
        $place = new Destination(['slug'=>'eden-nature-park','name'=>'Eden Nature Park','location'=>'Davao City','region_id'=>Region::create(['name'=>'Davao City'])->id,'type'=>'Nature & Leisure','is_accredited'=>true]);
        $place->id = 2;
        $place->save();
        return $place;
    }

    public function test_import_is_idempotent_and_real_photo_wins_over_placeholder(): void
    {
        $this->requirePhotoFiles();
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path'=>'demo.svg','is_primary'=>true]);
        $this->seed(VerifiedListingPhotoSeeder::class);
        $this->seed(VerifiedListingPhotoSeeder::class);
        $this->assertSame(2, $place->photos()->count());
        $photo = $place->coverPhoto();
        $this->assertStringStartsWith('listings/verified/', $photo->path);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame('Eden Nature Park', $photo->source_name);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertSee($photo->url(), false);
    }

    public function test_existing_upload_is_preserved(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path'=>'owner.jpg','is_primary'=>true]);
        $this->seed(VerifiedListingPhotoSeeder::class);
        $this->assertSame(1, $place->photos()->count());
        $this->assertSame('owner.jpg', $place->coverPhoto()->path);
    }

    public function test_samples_fill_gaps_once_and_yield_to_verified_photos(): void
    {
        $this->requirePhotoFiles();
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path' => 'demo.svg', 'is_primary' => true]);
        $this->seed(\Database\Seeders\SampleListingPhotoSeeder::class);
        $this->seed(\Database\Seeders\SampleListingPhotoSeeder::class);
        $this->assertSame(2, $place->photos()->count());
        $this->assertSame('Sample image', $place->coverPhoto()->category);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertSee($place->coverPhoto()->url(), false)->assertDontSee('Not a photo of this place');
        $this->seed(VerifiedListingPhotoSeeder::class);
        $this->assertSame('Eden Nature Park', $place->coverPhoto()->source_name);
        $this->get('/destinations/eden-nature-park')->assertDontSee('Not a photo of this place');
    }
}



