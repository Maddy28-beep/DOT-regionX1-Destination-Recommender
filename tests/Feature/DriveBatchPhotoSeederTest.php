<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Database\Seeders\DriveBatchPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The team photo batch: found by name, never by guess, safe to re-run, and findable again for re-review. */
class DriveBatchPhotoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    private function entry(string $name, string $file): array
    {
        return [
            'lookup' => 'name', 'name' => $name, 'file' => $file, 'source_type' => 'other',
            'source_name' => 'Team-collected photo folder', 'source_url' => 'https://drive.google.com/drive/folders/x',
            'verification_status' => 'approved', 'permission_basis' => 'team_testing_decision',
            'permission_ref' => 'Owner instruction, testing only', 'permission_date' => '2026-11-05',
            'credit_text' => 'ExploreDVO team collection (testing)', 'verified_by' => 'Project owner', 'verified_at' => '2026-11-05',
        ];
    }

    private function folder(array $entries): string
    {
        $dir = sys_get_temp_dir().'/drive-batch-'.uniqid();
        mkdir($dir);
        $this->folders[] = $dir;
        foreach ($entries as &$e) {
            file_put_contents($dir.'/'.$e['file'], base64_decode(self::PNG));
            $e['sha256'] = hash_file('sha256', $dir.'/'.$e['file']);
        }
        file_put_contents($dir.'/manifest.json', json_encode($entries));

        return $dir;
    }

    private function place(string $name, string $slug): Destination
    {
        return Destination::create(['slug' => $slug, 'name' => $name, 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Leisure', 'is_accredited' => true]);
    }

    private function importFrom(string $dir): void
    {
        $seeder = new DriveBatchPhotoSeeder;
        $seeder->directory = $dir;
        $seeder->run();
    }

    public function test_pictures_are_attached_by_exact_name_and_are_findable_for_review(): void
    {
        Storage::fake('public');
        $falls = $this->place('Aliwagwag Falls [SAMPLE]', 'aliwagwag-falls-sample');
        $dir = $this->folder([$this->entry('Aliwagwag Falls [SAMPLE]', 'a-1.png'), $this->entry('Aliwagwag Falls [SAMPLE]', 'a-2.png')]);

        $this->importFrom($dir);

        $photos = $falls->photos()->orderBy('sort_order')->get();
        $this->assertCount(2, $photos);
        $this->assertSame('team_testing_decision', $photos[0]->permission_basis);
        $this->assertSame('ExploreDVO team collection (testing)', $photos[0]->credit_text);
        Storage::disk('public')->assertExists($photos[0]->path);
        $this->assertSame(2, ListingPhoto::where('permission_basis', 'team_testing_decision')->count());
    }

    public function test_an_accommodation_is_found_by_name_too(): void
    {
        Storage::fake('public');
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $resort = Accommodation::create(['slug' => 'malagos-resort', 'name' => 'Malagos Garden Resort', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Resort', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);

        $this->importFrom($this->folder([$this->entry('Malagos Garden Resort', 'm-1.png')]));

        $this->assertSame(1, $resort->photos()->count());
    }

    public function test_an_entry_naming_its_kind_picks_that_kind_when_the_name_is_shared(): void
    {
        Storage::fake('public');
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $destination = $this->place('Malagos Garden Resort', 'malagos-dest');
        $resort = Accommodation::create(['slug' => 'malagos-acc', 'name' => 'Malagos Garden Resort', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Resort', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
        $unkinded = $this->entry('Malagos Garden Resort', 'm-0.png');
        $asDestination = $this->entry('Malagos Garden Resort', 'm-1.png') + ['kind' => 'Destination'];
        $asResort = $this->entry('Malagos Garden Resort', 'm-2.png') + ['kind' => 'Accommodation'];

        $this->importFrom($this->folder([$unkinded]));
        $this->assertSame(0, ListingPhoto::count());

        $this->importFrom($this->folder([$asDestination, $asResort]));
        $this->assertSame(1, $destination->photos()->count());
        $this->assertSame(1, $resort->photos()->count());
    }

    public function test_unknown_or_ambiguous_names_are_skipped_not_guessed(): void
    {
        Storage::fake('public');
        $this->place('Twin Place', 'twin-1');
        $this->place('Twin Place', 'twin-2');
        $dir = $this->folder([$this->entry('Twin Place', 't.png'), $this->entry('Nowhere Falls', 'n.png')]);

        $this->importFrom($dir);

        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_running_twice_adds_nothing_and_a_real_existing_photo_stays_primary(): void
    {
        Storage::fake('public');
        $place = $this->place('Eden Nature Park', 'eden');
        $place->photos()->create(['path' => 'uploads/own.jpg', 'category' => 'General', 'is_primary' => true, 'sort_order' => 0]);
        $dir = $this->folder([$this->entry('Eden Nature Park', 'e-1.png')]);

        $this->importFrom($dir);
        $this->importFrom($dir);

        $this->assertSame(2, $place->photos()->count());
        $this->assertTrue($place->photos()->where('path', 'uploads/own.jpg')->first()->is_primary);
        $this->assertFalse($place->photos()->where('path', '!=', 'uploads/own.jpg')->first()->is_primary);
    }

    public function test_the_shipped_batch_manifest_is_sound(): void
    {
        $manifest = new ListingPhotoManifest(database_path('data/listing-photos/drive-2026-11'));

        $this->assertCount(50, $manifest->entries());
        $this->assertSame([], $manifest->problems());
        $this->assertCount(50, $manifest->approved());
    }
}
