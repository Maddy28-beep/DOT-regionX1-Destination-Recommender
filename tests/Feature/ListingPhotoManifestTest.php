<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Support\ListingPhotoManifest;
use Database\Seeders\VerifiedListingPhotoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The photo manifest and its importer: a picture is published only when its entry says `approved` AND carries the
 * evidence that makes it so (who allowed it and on what basis, the reference to that evidence, the date, the credit
 * to show, and who checked it shows the right place). Anything less is left out, with nothing published.
 */
class ListingPhotoManifestTest extends TestCase
{
    use RefreshDatabase;

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

    /** A folder with manifest.json and a tiny real PNG, holding the given entries (file/sha256 filled in unless given). */
    private function folder(array $entries): string
    {
        $dir = sys_get_temp_dir().'/photo-manifest-'.uniqid();
        mkdir($dir);
        $this->folders[] = $dir;

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $out = [];
        foreach ($entries as $i => $entry) {
            $file = $entry['file'] ?? "photo-$i.png";
            if (($entry['file'] ?? true) !== false) {
                file_put_contents($dir.'/'.$file, $png.str_repeat("\0", $i)); // a different file per entry
            }
            $out[] = $entry + ['file' => $file, 'sha256' => hash_file('sha256', $dir.'/'.$file)];
        }
        file_put_contents($dir.'/manifest.json', json_encode($out));

        return $dir;
    }

    private function approved(array $over = []): array
    {
        return $over + [
            'kind' => 'Destination', 'id' => 7, 'name' => 'Eden Nature Park', 'source_type' => 'establishment_supplied',
            'source_name' => 'Eden Nature Park', 'source_url' => null,
            'verification_status' => 'approved',
            'permission_basis' => 'establishment_meeting', 'permission_ref' => 'Owners meeting, attendance sheet p.3',
            'permission_date' => '2026-09-01', 'licence' => null, 'credit_text' => 'Eden Nature Park',
            'verified_by' => 'Team member', 'verified_at' => '2026-10-24',
        ];
    }

    private function place(): Destination
    {
        $place = new Destination(['slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City', 'region_id' => Region::create(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
        $place->id = 7;
        $place->save();

        return $place;
    }

    private function import(string $dir): void
    {
        $seeder = new VerifiedListingPhotoSeeder;
        $seeder->directory = $dir;
        $seeder->run();
    }

    // ---------------------------------------------------------------------------------------------
    // The committed manifest
    // ---------------------------------------------------------------------------------------------

    public function test_the_committed_manifest_is_sound_and_nothing_in_it_is_approved_without_evidence(): void
    {
        $manifest = ListingPhotoManifest::default();

        $this->assertSame([], $manifest->problems());
        $this->assertNotEmpty($manifest->entries());

        // The legacy import has no recorded permission, so none of it is approved until someone adds the record.
        foreach ($manifest->entries() as $entry) {
            $this->assertContains($entry['verification_status'], ['unverified', 'identity_checked', 'second_source', 'rejected', 'approved']);
            if ($entry['verification_status'] === 'approved') {
                $this->assertNotEmpty($entry['permission_ref']);
                $this->assertNotEmpty($entry['permission_date']);
                $this->assertNotEmpty($entry['credit_text']);
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // The validator
    // ---------------------------------------------------------------------------------------------

    public function test_a_complete_approved_entry_and_an_unfinished_one_are_both_sound(): void
    {
        $dir = $this->folder([$this->approved(), $this->approved(['id' => 8, 'verification_status' => 'unverified', 'permission_basis' => null, 'permission_ref' => null, 'permission_date' => null, 'credit_text' => null, 'verified_by' => null, 'verified_at' => null])]);

        $this->assertSame([], (new ListingPhotoManifest($dir))->problems());
    }

    public function test_an_approved_entry_must_carry_its_evidence(): void
    {
        $dir = $this->folder([$this->approved(['permission_basis' => null, 'permission_ref' => '', 'permission_date' => null, 'credit_text' => null, 'verified_by' => null, 'verified_at' => null])]);
        $text = implode("\n", (new ListingPhotoManifest($dir))->problems());

        foreach (['permission_basis', 'permission_ref', 'permission_date', 'credit_text', 'verified_by', 'verified_at'] as $key) {
            $this->assertStringContainsString($key, $text);
        }
    }

    public function test_meeting_consent_needs_a_reference_to_the_meeting_and_its_date(): void
    {
        $dir = $this->folder([$this->approved(['permission_ref' => null, 'permission_date' => '2026-02-30'])]);
        $text = implode("\n", (new ListingPhotoManifest($dir))->problems());

        $this->assertStringContainsString('permission_ref', $text);
        $this->assertStringContainsString('permission_date as YYYY-MM-DD', $text, 'an impossible date does not count');
    }

    public function test_a_picture_from_another_source_needs_its_source_url(): void
    {
        $dir = $this->folder([$this->approved(['source_type' => 'official_facebook', 'source_url' => null])]);

        $this->assertStringContainsString('source_url', implode("\n", (new ListingPhotoManifest($dir))->problems()));
    }

    public function test_bad_values_a_missing_file_a_changed_file_and_a_shared_file_are_all_reported(): void
    {
        $dir = $this->folder([
            $this->approved(['kind' => 'Hotel', 'verification_status' => 'maybe', 'source_type' => 'telepathy']),
            ['kind' => 'Destination', 'id' => 2, 'name' => 'A', 'source_type' => 'other', 'verification_status' => 'unverified', 'file' => 'missing.png'] + ['sha256' => null],
        ]);
        unlink($dir.'/missing.png'); // the helper writes every file, so remove the one this entry says exists
        file_put_contents($dir.'/photo-0.png', 'changed after the hash was recorded');
        $entries = (new ListingPhotoManifest($dir))->entries();
        $entries[] = $entries[0]; // the same file twice
        $text = implode("\n", (new ListingPhotoManifest($dir))->problems($entries));

        $this->assertStringContainsString('kind must be one of', $text);
        $this->assertStringContainsString('verification_status must be one of', $text);
        $this->assertStringContainsString('source_type must be one of', $text);
        $this->assertStringContainsString('has changed since its sha256', $text);
        $this->assertStringContainsString('is not in the folder', $text);
        $this->assertStringContainsString('already uses', $text);
    }

    public function test_the_artisan_command_reports_a_sound_manifest_and_fails_on_a_broken_one(): void
    {
        $good = $this->folder([$this->approved()]);
        $this->artisan('listing-photos:validate', ['--path' => $good])->expectsOutputToContain('approved 1')->expectsOutputToContain('The manifest is sound.')->assertExitCode(0);

        $bad = $this->folder([$this->approved(['credit_text' => null])]);
        $this->artisan('listing-photos:validate', ['--path' => $bad])->expectsOutputToContain('credit_text')->assertExitCode(1);
    }

    // ---------------------------------------------------------------------------------------------
    // The importer
    // ---------------------------------------------------------------------------------------------

    public function test_only_approved_complete_entries_are_published_with_their_evidence(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $dir = $this->folder([$this->approved()]);

        $this->import($dir);
        $this->import($dir); // safe to repeat

        $this->assertSame(1, $place->photos()->count());
        $photo = $place->coverPhoto();
        $this->assertSame('listings/verified/photo-0.png', $photo->path);
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame('establishment_meeting', $photo->permission_basis);
        $this->assertSame('Owners meeting, attendance sheet p.3', $photo->permission_ref);
        $this->assertSame('2026-09-01', $photo->permission_date->toDateString());
        $this->assertSame('approved', $photo->verification_status);
        $this->assertSame('Photo: Eden Nature Park', $photo->creditLine());
    }

    public function test_entries_that_are_not_approved_or_whose_record_is_incomplete_publish_nothing(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $dir = $this->folder([
            $this->approved(['verification_status' => 'unverified']),
            $this->approved(['verification_status' => 'identity_checked']),
            $this->approved(['verification_status' => 'second_source']),
            $this->approved(['verification_status' => 'rejected']),
            $this->approved(['credit_text' => null]),            // approved on paper, record incomplete
            $this->approved(['permission_ref' => null]),
        ]);

        $this->import($dir);

        $this->assertSame(0, $place->photos()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('listings/verified'));
    }

    public function test_a_listing_whose_name_does_not_match_is_left_alone_and_a_real_upload_is_kept(): void
    {
        Storage::fake('public');
        $place = $this->place();
        $dir = $this->folder([$this->approved(['name' => 'Some Other Place'])]);
        $this->import($dir);
        $this->assertSame(0, $place->photos()->count(), 'an id alone is not enough');

        $place->photos()->create(['path' => 'owner.jpg', 'is_primary' => true]);
        $this->import($this->folder([$this->approved()]));
        $this->assertSame(1, $place->photos()->count());
        $this->assertSame('owner.jpg', $place->coverPhoto()->path);
    }
}
