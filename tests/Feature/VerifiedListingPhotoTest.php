<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Database\Seeders\SampleListingPhotoSeeder;
use Database\Seeders\VerifiedListingPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** How the importer, the sample pictures and the pages fit together. Evidence rules are in ListingPhotoManifestTest. */
class VerifiedListingPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'Illustration, not a photo of this place';

    /** @var list<string> */
    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            foreach (glob($folder.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($folder);
        }

        parent::tearDown();
    }

    /** A folder holding one approved, complete entry for Eden Nature Park (id 2). */
    private function approvedFolder(): string
    {
        $dir = sys_get_temp_dir().'/verified-photo-'.uniqid();
        mkdir($dir);
        $this->folders[] = $dir;
        file_put_contents($dir.'/eden.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        file_put_contents($dir.'/manifest.json', json_encode([[
            'kind' => 'Destination', 'id' => 2, 'name' => 'Eden Nature Park', 'file' => 'eden.png',
            'sha256' => hash_file('sha256', $dir.'/eden.png'), 'source_type' => 'establishment_supplied',
            'source_name' => 'Eden Nature Park', 'source_url' => null, 'verification_status' => 'approved',
            'permission_basis' => 'establishment_meeting', 'permission_ref' => 'Owners meeting, attendance sheet p.3',
            'permission_date' => '2026-09-01', 'credit_text' => 'Eden Nature Park',
            'verified_by' => 'Team member', 'verified_at' => '2026-10-24',
        ]]));

        return $dir;
    }

    private function import(string $dir): void
    {
        $seeder = new VerifiedListingPhotoSeeder;
        $seeder->directory = $dir;
        $seeder->run();
    }

    private function place(): Destination
    {
        $place = new Destination(['slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City', 'region_id' => Region::create(['name' => 'Davao City'])->id, 'type' => 'Nature & Leisure', 'is_accredited' => true]);
        $place->id = 2;
        $place->save();

        return $place;
    }

    public function test_import_is_idempotent_and_real_photo_wins_over_placeholder(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path' => 'demo.svg', 'is_primary' => true]);
        $dir = $this->approvedFolder();
        $this->import($dir);
        $this->import($dir);

        $this->assertSame(2, $place->photos()->count());
        $photo = $place->coverPhoto();
        $this->assertStringStartsWith('listings/verified/', $photo->path);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame('Eden Nature Park', $photo->source_name);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertSee($photo->url(), false)->assertSee('Photo: Eden Nature Park');
    }

    public function test_the_committed_manifest_publishes_nothing_until_a_record_is_approved(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $this->seed(VerifiedListingPhotoSeeder::class);

        $this->assertSame(0, $place->photos()->count());
    }

    public function test_existing_upload_is_preserved(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path' => 'owner.jpg', 'is_primary' => true]);
        $this->import($this->approvedFolder());

        $this->assertSame(1, $place->photos()->count());
        $this->assertSame('owner.jpg', $place->coverPhoto()->path);
    }

    public function test_samples_fill_gaps_once_are_labelled_and_yield_to_approved_photos(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $place->photos()->create(['path' => 'demo.svg', 'is_primary' => true]);
        $this->seed(SampleListingPhotoSeeder::class);
        $this->seed(SampleListingPhotoSeeder::class);

        $this->assertSame(2, $place->photos()->count());
        $this->assertSame('Sample image', $place->coverPhoto()->category);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertSee($place->coverPhoto()->url(), false)->assertSee(self::LABEL);

        $this->import($this->approvedFolder());
        $this->assertSame('Eden Nature Park', $place->coverPhoto()->source_name);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertDontSee(self::LABEL);
    }
}
