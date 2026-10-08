<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDotDatasetTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $accno, string $expiry, ?float $lat = null, ?float $lng = null): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $d = Destination::create([
            'slug' => strtolower($accno), 'name' => $accno, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            'latitude' => $lat, 'longitude' => $lng,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => $accno,
            'status' => 'Active', 'issue_date' => '2025-01-01', 'expiration_date' => $expiry,
        ]);

        return $d;
    }

    private function dataset(array $rows): string
    {
        $rel = 'storage/framework/testing/dot-dataset-test.json';
        @mkdir(dirname(base_path($rel)), 0777, true);
        file_put_contents(base_path($rel), json_encode(['rows' => $rows]));

        return $rel;
    }

    private function row(string $accno, string $expiry, float $lat, float $lng, string $check = 'Verified', string $prec = 'Establishment (by name)'): array
    {
        return ['accno' => $accno, 'name' => $accno, 'expiry' => $expiry, 'lat' => $lat, 'lng' => $lng, 'check' => $check, 'precision' => $prec];
    }

    public function test_dry_run_writes_nothing_and_apply_updates_only_what_is_provable(): void
    {
        $pin = $this->listing('A-PIN', '2027-06-01');
        $renew = $this->listing('A-RENEW', '2026-10-31', 7.0, 125.0);
        $swap = $this->listing('A-SWAP', '2027-06-01');
        $weak = $this->listing('A-WEAK', '2027-06-01');
        $shorter = $this->listing('A-SHORT', '2028-06-30');

        $file = $this->dataset([
            $this->row('A-PIN', '2027-06-01', 7.5, 125.5),
            $this->row('A-RENEW', '2028-10-31', 7.0, 125.0),
            $this->row('A-SWAP', '2027-01-06', 7.6, 125.6),
            $this->row('A-WEAK', '2027-06-01', 7.7, 125.7, 'Approximate', 'Street'),
            $this->row('A-SHORT', '2027-06-30', 7.8, 125.8),
        ]);

        $this->artisan('dot:sync-dataset', ['--file' => $file])->assertExitCode(0);
        $this->assertNull($pin->fresh()->latitude);
        $this->assertSame('2026-10-31', AccreditationRecord::where('accreditation_number', 'A-RENEW')->first()->expiration_date->toDateString());

        $this->artisan('dot:sync-dataset', ['--file' => $file, '--apply' => true])->assertExitCode(0);

        $this->assertEqualsWithDelta(7.5, (float) $pin->fresh()->latitude, 0.0001);
        $this->assertSame('2028-10-31', AccreditationRecord::where('accreditation_number', 'A-RENEW')->first()->expiration_date->toDateString());
        $this->assertSame('2027-06-01', AccreditationRecord::where('accreditation_number', 'A-SWAP')->first()->expiration_date->toDateString(), 'a day/month swap is not a renewal');
        $this->assertEqualsWithDelta(7.7, (float) $weak->fresh()->latitude, 0.0001, 'an empty listing takes even an approximate point');
        $kept = $this->listing('A-KEEP', '2027-06-01', 7.1, 125.1);
        $this->dataset([$this->row('A-KEEP', '2027-06-01', 7.9, 125.9, 'Approximate', 'Street')]);
        $this->artisan('dot:sync-dataset', ['--file' => 'storage/framework/testing/dot-dataset-test.json', '--apply' => true])->assertExitCode(0);
        $this->assertEqualsWithDelta(7.1, (float) $kept->fresh()->latitude, 0.0001, 'an approximate point never replaces a stored pin');
        $this->assertSame('2028-06-30', AccreditationRecord::where('accreditation_number', 'A-SHORT')->first()->expiration_date->toDateString(), 'dates are never shortened');
    }

    public function test_numbers_that_appear_twice_in_the_dataset_are_skipped(): void
    {
        $d = $this->listing('A-DUP', '2026-10-31');
        $file = $this->dataset([
            $this->row('A-DUP', '2028-10-31', 7.5, 125.5),
            $this->row('A-DUP', '2027-10-31', 7.9, 125.9),
        ]);

        $this->artisan('dot:sync-dataset', ['--file' => $file, '--apply' => true])->assertExitCode(0);

        $this->assertNull($d->fresh()->latitude);
        $this->assertSame('2026-10-31', AccreditationRecord::where('accreditation_number', 'A-DUP')->first()->expiration_date->toDateString());
    }

    public function test_add_missing_creates_the_new_establishments_once(): void
    {
        foreach (['Davao City', 'Island Garden City of Samal', 'Davao del Norte', 'Davao del Sur', 'Davao Oriental', 'Davao de Oro', 'Davao Occidental'] as $name) {
            Region::create(['name' => $name]);
        }

        $this->artisan('dot:sync-dataset', ['--add-missing' => true, '--apply' => true, '--file' => $this->dataset([])])->assertExitCode(0);

        $this->assertSame(25, AccreditationRecord::count());
        $this->assertSame(8, \App\Models\Accommodation::count());
        $this->assertSame(15, \App\Models\TourOperator::count());
        $this->assertSame(0, (int) \App\Models\TourOperator::sum('rating'), 'new businesses start unrated');

        $this->artisan('dot:sync-dataset', ['--add-missing' => true, '--apply' => true, '--file' => $this->dataset([])])->assertExitCode(0);
        $this->assertSame(25, AccreditationRecord::count(), 'running it twice adds nothing');
    }
}
