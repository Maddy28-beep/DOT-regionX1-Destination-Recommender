<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sample destinations arrive from a CSV, clearly labelled, all or nothing, and can be taken out again. */
class ImportSampleDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'name,type,location,region,latitude,longitude,description,price_tier,hours';

    private function csv(array $lines, string $header = self::HEADER): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sample').'.csv';
        file_put_contents($path, $header."\n".implode("\n", $lines)."\n");

        return $path;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Region::firstOrCreate(['name' => 'Davao City']);
        Region::firstOrCreate(['name' => 'Davao Oriental']);
    }

    public function test_a_dry_run_reports_every_row_and_writes_nothing(): void
    {
        $file = $this->csv(['Mango Falls,Nature,Toril,Davao City,7.0000,125.4000,A waterfall,Budget-Friendly,8AM-5PM']);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('sample:import-destinations', ['file' => $file]));
        $output = \Illuminate\Support\Facades\Artisan::output();

        foreach (['Mango Falls [SAMPLE]', 'SAMPLE-0001', 'new', 'Dry run'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertSame(0, Destination::count());
        $this->assertSame(0, AccreditationRecord::count());
    }

    public function test_apply_creates_labelled_destinations_with_sample_numbers_and_flagged_records(): void
    {
        $file = $this->csv([
            'Mango Falls,Nature,Toril,Davao City,7.0000,125.4000,,,',
            'Coral Cove,Beach,Mati,Davao Oriental,6.9500,126.2300,,Mid-range,',
        ]);

        $this->artisan('sample:import-destinations', ['file' => $file, '--apply' => true])->assertSuccessful();

        $falls = Destination::where('name', 'Mango Falls [SAMPLE]')->firstOrFail();
        $this->assertTrue($falls->is_accredited);
        $this->assertSame(0, $falls->review_count);
        $this->assertSame(['SAMPLE-0001', 'SAMPLE-0002'], AccreditationRecord::orderBy('id')->pluck('accreditation_number')->all());
        $this->assertTrue(AccreditationRecord::first()->is_placeholder);
        $this->assertTrue(app(SampleData::class)->isSample($falls));
        $this->get(route('destinations.show', $falls))->assertOk()->assertSee('Sample data, not DOT-verified');
    }

    public function test_numbers_carry_on_from_the_highest_existing_sample_number(): void
    {
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['A Place,Nature,X,Davao City,7.1,125.4,,,']), '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['B Place,Nature,X,Davao City,7.1,125.5,,,']), '--apply' => true])->assertSuccessful();

        $this->assertSame(['SAMPLE-0001', 'SAMPLE-0002'], AccreditationRecord::orderBy('id')->pluck('accreditation_number')->all());
    }

    public function test_one_bad_row_stops_the_whole_import(): void
    {
        $file = $this->csv([
            'Good Place,Nature,X,Davao City,7.1,125.4,,,',
            'Missing Pin,Nature,X,Davao City,,,,,',
            'Swapped,Nature,X,Davao City,125.4,7.1,,,',
            'Wrong Region,Nature,X,Atlantis,7.1,125.4,,,',
        ]);

        $this->artisan('sample:import-destinations', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('outside the Davao Region')->expectsOutputToContain("region 'Atlantis'")->assertFailed();

        $this->assertSame(0, Destination::count(), 'The good row is not loaded either.');
    }

    public function test_a_name_that_already_exists_is_an_error_and_so_is_a_missing_column(): void
    {
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['Dup,Nature,X,Davao City,7.1,125.4,,,']), '--apply' => true])->assertSuccessful();
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['Dup,Nature,X,Davao City,7.1,125.4,,,'])])->expectsOutputToContain('already exists')->assertFailed();
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['Z,Nature,X'], 'name,type,location')])->expectsOutputToContain('Missing column(s): region, latitude, longitude')->assertFailed();
    }

    public function test_remove_deletes_only_the_samples(): void
    {
        $region = Region::first();
        $real = Destination::create(['slug' => 'real', 'name' => 'Real Place', 'location' => 'X', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        $this->artisan('sample:import-destinations', ['file' => $this->csv(['A Place,Nature,X,Davao City,7.1,125.4,,,']), '--apply' => true])->assertSuccessful();

        $this->artisan('sample:import-destinations', ['--remove' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(2, Destination::count());

        $this->artisan('sample:import-destinations', ['--remove' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame([$real->id], Destination::pluck('id')->all());
        $this->assertSame(0, AccreditationRecord::count());
    }
}
