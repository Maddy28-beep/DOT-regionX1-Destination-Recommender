<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Support\SampleManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The rollback manifest: durable file, off-database copy, hash-verified, database-verified. */
class SampleManifestTest extends TestCase
{
    use RefreshDatabase;

    private function importTwoDestinations(): void
    {
        Region::firstOrCreate(['name' => 'Davao City']);
        $path = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($path, "name,type,location,region,latitude,longitude\nFirst Falls,Nature,X,Davao City,7.1,125.4\nSecond Falls,Nature,X,Davao City,7.2,125.5\n");
        $this->artisan('sample:import-destinations', ['file' => $path, '--apply' => true])->assertSuccessful();
    }

    public function test_capture_write_copy_and_verify_round_trip(): void
    {
        $this->importTwoDestinations();
        $path = storage_path('framework/sample-manifest-'.uniqid().'.json');
        $copyDir = storage_path('framework/manifest-copy-'.uniqid());

        $this->artisan('sample:manifest', ['--from-number' => 'SAMPLE-0001', '--to-number' => 'SAMPLE-0002', '--write' => $path, '--copy-to' => $copyDir])->assertSuccessful();

        $manifest = app(SampleManifest::class);
        $check = $manifest->verifyAgainstDatabase($path);
        $this->assertTrue($check['ok'], implode('; ', $check['errors']));
        $this->assertSame(2, $check['count']);
        $copy = $copyDir.'/'.basename($path);
        $this->assertFileExists($copy);
        $this->assertSame($check['sha256'], $manifest->verifyFile($copy)['sha256']);

        $entries = $manifest->entriesForRange('SAMPLE-0001', 'SAMPLE-0002');
        $this->assertSame(['destination', 'destination'], array_column($entries, 'kind'));
        $this->assertSame(Destination::orderBy('id')->pluck('id')->all(), array_column($entries, 'id'));
        $this->assertSame(['SAMPLE-0001', 'SAMPLE-0002'], array_column($entries, 'number'));
    }

    public function test_a_tampered_manifest_fails_hash_verification(): void
    {
        $this->importTwoDestinations();
        $path = storage_path('framework/sample-manifest-'.uniqid().'.json');
        $this->artisan('sample:manifest', ['--from-number' => 'SAMPLE-0001', '--to-number' => 'SAMPLE-0002', '--write' => $path])->assertSuccessful();

        $tampered = str_replace('First Falls', 'Forged Falls', file_get_contents($path));
        file_put_contents($path, $tampered);

        $this->assertFalse(app(SampleManifest::class)->verifyFile($path)['ok']);
        $this->assertSame(1, Artisan::call('sample:manifest', ['--verify' => $path]));
    }

    /** Capture a manifest for the given range and return its path. */
    private function capture(string $from, string $to): string
    {
        $path = storage_path('framework/sample-manifest-'.uniqid().'.json');
        $this->artisan('sample:manifest', ['--from-number' => $from, '--to-number' => $to, '--write' => $path])->assertSuccessful();

        return $path;
    }

    public function test_manifest_enforced_removal_succeeds_on_an_exact_match(): void
    {
        $this->importTwoDestinations();
        $path = $this->capture('SAMPLE-0001', 'SAMPLE-0002');

        // Dry run with the manifest deletes nothing; the apply removes exactly the manifested set.
        $this->artisan('sample:import-destinations', ['--remove' => true, '--manifest' => $path])->assertSuccessful();
        $this->assertSame(2, Destination::count());
        $this->artisan('sample:import-destinations', ['--remove' => true, '--manifest' => $path, '--apply' => true])->assertSuccessful();
        $this->assertSame(0, Destination::count());
    }

    public function test_manifest_enforced_removal_aborts_when_a_record_is_missing(): void
    {
        $this->importTwoDestinations();
        $path = $this->capture('SAMPLE-0001', 'SAMPLE-0002');
        Destination::where('name', 'Second Falls [SAMPLE]')->firstOrFail()->delete();

        $this->artisan('sample:import-destinations', ['--remove' => true, '--manifest' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(['First Falls [SAMPLE]'], Destination::pluck('name')->all(), 'The surviving manifested record must not be deleted.');
    }

    public function test_manifest_enforced_removal_aborts_when_a_record_is_modified(): void
    {
        $this->importTwoDestinations();
        $path = $this->capture('SAMPLE-0001', 'SAMPLE-0002');
        Destination::where('name', 'Second Falls [SAMPLE]')->firstOrFail()->update(['name' => 'Renamed Falls [SAMPLE]']);

        $this->artisan('sample:import-destinations', ['--remove' => true, '--manifest' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(2, Destination::count());
    }

    public function test_manifest_enforced_removal_aborts_on_an_extra_record_outside_the_manifest(): void
    {
        Region::firstOrCreate(['name' => 'Davao City']);
        $one = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($one, "name,type,location,region,latitude,longitude\nFirst Falls,Nature,X,Davao City,7.1,125.4\n");
        $this->artisan('sample:import-destinations', ['file' => $one, '--apply' => true])->assertSuccessful();
        $path = $this->capture('SAMPLE-0001', 'SAMPLE-0001');

        $two = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($two, "name,type,location,region,latitude,longitude\nSecond Falls,Nature,X,Davao City,7.2,125.5\n");
        $this->artisan('sample:import-destinations', ['file' => $two, '--apply' => true])->assertSuccessful();

        // SAMPLE-0002 is eligible in the requested range but is not in the manifest: abort, delete nothing.
        $this->artisan('sample:import-destinations', ['--remove' => true, '--from-number' => 'SAMPLE-0001', '--to-number' => 'SAMPLE-0002', '--manifest' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(2, Destination::count());
    }

    public function test_manifest_enforced_removal_aborts_on_a_tampered_manifest(): void
    {
        $this->importTwoDestinations();
        $path = $this->capture('SAMPLE-0001', 'SAMPLE-0002');
        file_put_contents($path, str_replace('First Falls', 'Forged Falls', file_get_contents($path)));

        $this->artisan('sample:import-destinations', ['--remove' => true, '--manifest' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(2, Destination::count());
    }

    /** Import one sample of each kind; SAMPLE numbers come out 0001 destination, 0002 accommodation, 0003 restaurant, 0004 souvenir. */
    private function importOneOfEachKind(): void
    {
        Region::firstOrCreate(['name' => 'Davao City']);
        $dest = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($dest, "name,type,location,region,latitude,longitude\nSeq Falls,Nature,X,Davao City,7.1,125.4\n");
        $this->artisan('sample:import-destinations', ['file' => $dest, '--apply' => true])->assertSuccessful();
        $acc = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($acc, "name,location,region,latitude,longitude,description,amenities,type,price_tier,price_per_night,check_in,check_out,room_types,resort_settings\nSeq Hotel,X,Davao City,7.1,125.4,Synthetic,wifi|ac|parking|restaurant,Hotel,Mid-range,1500,14:00,12:00,Standard Room:1200-1500,\n");
        $this->artisan('sample:import-listings', ['file' => $acc, '--kind' => 'accommodation', '--apply' => true])->assertSuccessful();
        $rest = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($rest, "name,location,region,latitude,longitude,description,amenities,cuisine_type,price_tier,opening_hours\nSeq Grill,X,Davao City,7.1,125.4,Synthetic,parking|restroom|ac|wifi,Filipino,Budget-Friendly,10:00 AM-9:00 PM\n");
        $this->artisan('sample:import-listings', ['file' => $rest, '--kind' => 'restaurant', '--apply' => true])->assertSuccessful();
        $souv = storage_path('framework/manifest-seed-'.uniqid().'.csv');
        file_put_contents($souv, "name,location,region,latitude,longitude,description,amenities\nSeq Craft,X,Davao City,7.1,125.4,Synthetic,parking|restroom|ac|gcash\n");
        $this->artisan('sample:import-listings', ['file' => $souv, '--kind' => 'souvenir_center', '--apply' => true])->assertSuccessful();
    }

    public function test_reverse_order_rollback_with_a_combined_manifest_stops_after_the_first_kind(): void
    {
        $this->importOneOfEachKind();
        $combined = $this->capture('SAMPLE-0001', 'SAMPLE-0004');

        // First kind in reverse order succeeds against the combined manifest.
        $this->artisan('sample:import-listings', ['--kind' => 'souvenir_center', '--remove' => true, '--from-number' => 'SAMPLE-0004', '--to-number' => 'SAMPLE-0004', '--manifest' => $combined, '--apply' => true])->assertSuccessful();
        $this->assertSame(0, SouvenirCenter::count());

        // KNOWN LIMITATION (see audit note): the combined manifest no longer verifies against the
        // database, because its souvenir entry is gone, so the next kind aborts and removes nothing.
        $this->artisan('sample:import-listings', ['--kind' => 'restaurant', '--remove' => true, '--from-number' => 'SAMPLE-0003', '--to-number' => 'SAMPLE-0003', '--manifest' => $combined, '--apply' => true])->assertFailed();
        $this->assertSame(1, Restaurant::count(), 'The aborted step must delete nothing.');
    }

    public function test_reverse_order_rollback_with_per_kind_manifests_completes_without_regenerating_anything(): void
    {
        $this->importOneOfEachKind();
        // One manifest per kind, each captured over that kind's own SAMPLE number.
        $manifests = [
            'souvenir_center' => $this->capture('SAMPLE-0004', 'SAMPLE-0004'),
            'restaurant' => $this->capture('SAMPLE-0003', 'SAMPLE-0003'),
            'accommodation' => $this->capture('SAMPLE-0002', 'SAMPLE-0002'),
            'destination' => $this->capture('SAMPLE-0001', 'SAMPLE-0001'),
        ];
        $numbers = ['souvenir_center' => 'SAMPLE-0004', 'restaurant' => 'SAMPLE-0003', 'accommodation' => 'SAMPLE-0002', 'destination' => 'SAMPLE-0001'];

        foreach (['souvenir_center', 'restaurant', 'accommodation'] as $kind) {
            $this->artisan('sample:import-listings', ['--kind' => $kind, '--remove' => true, '--from-number' => $numbers[$kind], '--to-number' => $numbers[$kind], '--manifest' => $manifests[$kind], '--apply' => true])
                ->assertSuccessful("Reverse-order removal of {$kind} with its own manifest must succeed.");
        }
        $this->artisan('sample:import-destinations', ['--remove' => true, '--from-number' => 'SAMPLE-0001', '--to-number' => 'SAMPLE-0001', '--manifest' => $manifests['destination'], '--apply' => true])->assertSuccessful();

        $this->assertSame(0, SouvenirCenter::count() + Restaurant::count() + Accommodation::count() + Destination::count());
        $this->assertSame(0, AccreditationRecord::count());
    }

    public function test_verification_fails_when_a_manifested_record_is_gone(): void
    {
        $this->importTwoDestinations();
        $path = storage_path('framework/sample-manifest-'.uniqid().'.json');
        $this->artisan('sample:manifest', ['--from-number' => 'SAMPLE-0001', '--to-number' => 'SAMPLE-0002', '--write' => $path])->assertSuccessful();

        Destination::where('name', 'Second Falls [SAMPLE]')->firstOrFail()->delete();

        $check = app(SampleManifest::class)->verifyAgainstDatabase($path);
        $this->assertFalse($check['ok']);
        $this->assertNotEmpty($check['errors']);
    }
}
