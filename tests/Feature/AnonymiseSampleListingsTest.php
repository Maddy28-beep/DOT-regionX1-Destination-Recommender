<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** listings:anonymise-samples renames sample listings that carry a real business name, and only those. */
class AnonymiseSampleListingsTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, bool $sample, string $description = ''): Destination
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'description' => $description,
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Resort', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-R11-').$d->id,
            'status' => 'Active', 'issue_date' => now()->subMonth(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
        ]);

        return $d;
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'anon').'.csv';
        $out = fopen($path, 'w');
        fputcsv($out, ['table', 'id', 'name', 'new_name', 'reason']);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);

        return $path;
    }

    public function test_dry_run_writes_nothing_and_apply_renames_the_listing_its_slug_and_its_own_text(): void
    {
        $d = $this->destination('Real Brand Resort [SAMPLE]', true, 'Enjoy Real Brand Resort by the sea.');
        $file = $this->csv([['destinations', $d->id, 'Real Brand Resort [SAMPLE]', 'Sample Seaside Resort [SAMPLE]', 'real business']]);

        $this->artisan('listings:anonymise-samples', ['file' => $file])->assertSuccessful();
        $this->assertSame('Real Brand Resort [SAMPLE]', $d->fresh()->name, 'a report only');

        $this->artisan('listings:anonymise-samples', ['file' => $file, '--apply' => true])->assertSuccessful();
        $d->refresh();
        $this->assertSame('Sample Seaside Resort [SAMPLE]', $d->name);
        $this->assertSame('sample-seaside-resort', $d->slug);
        $this->assertSame('Enjoy Sample Seaside Resort by the sea.', $d->description, 'the old name inside its own text is replaced');
        $this->assertStringNotContainsString('real-brand', $d->slug);

        // Running it again is harmless.
        $this->artisan('listings:anonymise-samples', ['file' => $file, '--apply' => true])->assertSuccessful();

        // And it can be taken back.
        $this->artisan('listings:anonymise-samples', ['file' => $file, '--apply' => true, '--revert' => true])->assertSuccessful();
        $this->assertSame('Real Brand Resort [SAMPLE]', $d->fresh()->name);
    }

    public function test_it_refuses_a_listing_with_a_genuine_dot_record_and_a_name_that_does_not_match_and_writes_nothing(): void
    {
        $real = $this->destination('Genuine Resort', false);
        $sample = $this->destination('Other Brand Resort [SAMPLE]', true);

        $file = $this->csv([
            ['destinations', $sample->id, 'Other Brand Resort [SAMPLE]', 'Sample Hillside Resort [SAMPLE]', ''],
            ['destinations', $real->id, 'Genuine Resort', 'Sample Resort', ''],
        ]);
        $this->artisan('listings:anonymise-samples', ['file' => $file, '--apply' => true])->assertFailed();
        $this->assertSame('Other Brand Resort [SAMPLE]', $sample->fresh()->name, 'all or nothing: the valid row was not written either');
        $this->assertSame('Genuine Resort', $real->fresh()->name);

        $wrongName = $this->csv([['destinations', $sample->id, 'Some Other Name', 'Sample Hillside Resort [SAMPLE]', '']]);
        $this->artisan('listings:anonymise-samples', ['file' => $wrongName, '--apply' => true])->assertFailed();
        $this->assertSame('Other Brand Resort [SAMPLE]', $sample->fresh()->name);
    }

    public function test_it_renames_other_kinds_of_listing_too(): void
    {
        $r = Restaurant::create([
            'slug' => 'real-grill', 'name' => 'Real Grill', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Budget-Friendly', 'cuisine' => 'Seafood',
            'latitude' => 7.1, 'longitude' => 125.4,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'restaurant', 'listing_id' => $r->id, 'accreditation_number' => 'SAMPLE-R1', 'status' => 'Active',
            'issue_date' => now()->subMonth(), 'expiration_date' => now()->addYear(), 'is_placeholder' => true,
        ]);

        $this->artisan('listings:anonymise-samples', ['file' => $this->csv([['restaurants', $r->id, 'Real Grill', 'Sample Seafood Grill Restaurant', '']]), '--apply' => true])->assertSuccessful();
        $this->assertSame('Sample Seafood Grill Restaurant', $r->fresh()->name);
    }
}
