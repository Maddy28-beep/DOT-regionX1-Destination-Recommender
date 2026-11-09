<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Database\Seeders\CommonsBatchPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Wikimedia Commons photo batch: every picture carries an open licence and its credit, and a place is found by name
 * the way the development data and the live site both spell it, never by guess.
 */
class CommonsBatchPhotoSeederTest extends TestCase
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
            'lookup' => 'name', 'name' => $name, 'file' => $file, 'source_type' => 'wikimedia_commons', 'source_name' => 'Wikimedia Commons',
            'source_url' => 'https://commons.wikimedia.org/wiki/File:X.jpg', 'licence' => 'CC BY-SA 4.0', 'verification_status' => 'approved',
            'permission_basis' => 'open_licence', 'permission_ref' => 'Open licence on the file page', 'permission_date' => '2026-11-09',
            'credit_text' => 'Someone / Wikimedia Commons, CC BY-SA 4.0', 'verified_by' => 'Checker', 'verified_at' => '2026-11-09',
        ];
    }

    private function import(array $entries): void
    {
        $dir = sys_get_temp_dir().'/commons-batch-'.uniqid();
        mkdir($dir);
        $this->folders[] = $dir;
        foreach ($entries as &$e) {
            file_put_contents($dir.'/'.$e['file'], base64_decode(self::PNG));
            $e['sha256'] = hash_file('sha256', $dir.'/'.$e['file']);
        }
        file_put_contents($dir.'/manifest.json', json_encode($entries));

        $seeder = new CommonsBatchPhotoSeeder;
        $seeder->directory = $dir;
        $seeder->run();
    }

    private function place(string $name, string $slug): Destination
    {
        return Destination::create(['slug' => $slug, 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
    }

    public function test_the_shipped_batch_is_sound_open_licensed_and_credited(): void
    {
        $manifest = new ListingPhotoManifest(database_path('data/listing-photos/commons-2026-11'));
        $entries = $manifest->entries();

        $this->assertCount(14, $entries);
        $this->assertSame([], $manifest->problems());
        $this->assertCount(14, $manifest->approved());

        foreach ($entries as $e) {
            $this->assertSame('wikimedia_commons', $e['source_type'], $e['name']);
            $this->assertSame('open_licence', $e['permission_basis'], $e['name']);
            $this->assertMatchesRegularExpression('/^CC BY(-SA)? \d\.\d$/', $e['licence'], $e['name'].' must carry an open licence');
            $this->assertStringContainsString('Wikimedia Commons', $e['credit_text']);
            $this->assertStringContainsString($e['licence'], $e['credit_text'], $e['name'].': the credit names the licence');
            $this->assertStringStartsWith('https://commons.wikimedia.org/wiki/File:', $e['source_url']);
            $this->assertTrue($e['show_credit'], $e['name'].': attribution must stay shown');
        }
    }

    public function test_a_name_is_found_as_written_or_with_or_without_the_sample_suffix(): void
    {
        Storage::fake('public');
        $sampleOnly = $this->place('San Pedro Cathedral [SAMPLE]', 'san-pedro-cathedral-sample');
        $plainOnly = $this->place('Museo Dabawenyo', 'museo-dabawenyo');

        $this->import([$this->entry('San Pedro Cathedral', 'a.png'), $this->entry('Museo Dabawenyo [SAMPLE]', 'b.png')]);

        $this->assertSame(1, $sampleOnly->photos()->count());
        $this->assertSame(1, $plainOnly->photos()->count());
        $photo = $sampleOnly->photos()->first();
        $this->assertSame('open_licence', $photo->permission_basis);
        $this->assertSame('CC BY-SA 4.0', $photo->licence);
        $this->assertStringStartsWith('listings/commons-2026-11/', $photo->path);
        $this->assertSame('Photo: Someone / Wikimedia Commons, CC BY-SA 4.0', $photo->creditLine());
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_when_both_spellings_exist_the_exact_one_wins_and_nothing_is_guessed(): void
    {
        Storage::fake('public');
        $plain = $this->place('San Pedro Cathedral', 'spc');
        $sample = $this->place('San Pedro Cathedral [SAMPLE]', 'spc-sample');

        $this->import([$this->entry('San Pedro Cathedral', 'a.png')]);

        $this->assertSame(1, $plain->photos()->count());
        $this->assertSame(0, $sample->photos()->count());

        // Two listings with the very same name stay unmatched.
        $this->place('Twin Place', 'twin-1');
        $this->place('Twin Place', 'twin-2');
        $before = ListingPhoto::count();
        $this->import([$this->entry('Twin Place', 't.png'), $this->entry('Nowhere Falls', 'n.png')]);
        $this->assertSame($before, ListingPhoto::count());
    }

    public function test_a_stay_is_matched_by_its_kind_and_running_twice_adds_nothing(): void
    {
        Storage::fake('public');
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $inn = Accommodation::create(['slug' => 'red-planet', 'name' => 'Red Planet Davao', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        $entries = [$this->entry('Red Planet Davao', 'r.png') + ['kind' => 'Accommodation']];

        $this->import($entries);
        $this->import($entries);

        $this->assertSame(1, $inn->photos()->count());
        $this->assertTrue($inn->photos()->first()->is_primary);
    }

    public function test_an_approved_entry_with_a_text_longer_than_its_column_is_refused_before_the_database_sees_it(): void
    {
        $manifest = new ListingPhotoManifest(database_path('data/listing-photos/commons-2026-11'));
        $entry = $manifest->entries()[0];

        $this->assertSame([], $manifest->problemsWith($entry));

        $tooLong = $manifest->problemsWith(['verified_by' => str_repeat('x', 101), 'permission_ref' => str_repeat('y', 161)] + $entry);
        $this->assertCount(2, $tooLong);
        $all = implode(' | ', $tooLong);
        $this->assertStringContainsString('verified_by is 101 characters; the database takes at most 100', $all);
        $this->assertStringContainsString('permission_ref is 161 characters; the database takes at most 160', $all);

        // The shipped entries sit inside every limit, with room to spare for a longer file name.
        foreach ($manifest->entries() as $e) {
            foreach (ListingPhotoManifest::MAX_LENGTH as $key => $max) {
                $this->assertLessThanOrEqual($max, mb_strlen((string) ($e[$key] ?? '')), $e['name'].' '.$key);
            }
        }
    }
}
