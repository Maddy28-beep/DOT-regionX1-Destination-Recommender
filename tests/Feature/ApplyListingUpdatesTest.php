<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * listings:apply-updates moves edits made on a copy of the data onto the live database without restoring the copy over
 * it. It is only safe if it cannot hit the wrong row, cannot overwrite text a person wrote, and cannot half-apply.
 */
class ApplyListingUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'Team-prepared edits in a dump, 2026-10-30';

    private const GENERATED = 'Eden Nature Park is a DOT Region XI-accredited Nature & Adventure in Davao City (accreditation no. DOT-R11-X-1-2026).';

    private function destination(array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'description' => self::GENERATED,
        ]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upd').'.csv';
        $lines = ['table,id,name,field,value,source'];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r));
        }
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function test_a_dry_run_writes_nothing_and_apply_writes_once(): void
    {
        $d = $this->destination();
        $file = $this->csv([['destinations', $d->id, 'Eden Nature Park', 'description', 'Eden Nature Park is a highland park in Davao City.', self::SOURCE]]);

        $this->artisan('listings:apply-updates', ['file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(self::GENERATED, $d->fresh()->description);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true])->expectsOutputToContain('Updated 1 values')->assertSuccessful();
        $this->assertSame('Eden Nature Park is a highland park in Davao City.', $d->fresh()->description);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true])->expectsOutputToContain('Updated 0 values')->assertSuccessful();
    }

    public function test_text_a_person_wrote_is_kept_unless_replacing_it_is_asked_for(): void
    {
        $d = $this->destination(['description' => 'Perched 3,000 feet above sea level, with a zipline and a butterfly garden.']);
        $file = $this->csv([['destinations', $d->id, 'Eden Nature Park', 'description', 'Eden Nature Park is a park in Davao City.', self::SOURCE]]);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('written by a person')->assertFailed();
        $this->assertStringContainsString('zipline', $d->fresh()->description);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true, '--replace-custom' => true])->assertSuccessful();
        $this->assertSame('Eden Nature Park is a park in Davao City.', $d->fresh()->description);
    }

    public function test_a_blank_description_is_filled_on_any_catalogue_table(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $stay = Accommodation::create([
            'slug' => 'plain-hotel', 'name' => 'Plain Hotel', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);

        $this->artisan('listings:apply-updates', ['file' => $this->csv([['accommodations', $stay->id, 'Plain Hotel', 'description', 'Plain Hotel is a hotel in Davao City.', self::SOURCE]]), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame('Plain Hotel is a hotel in Davao City.', $stay->fresh()->description);
    }

    public function test_a_row_whose_name_does_not_match_the_id_is_refused(): void
    {
        $d = $this->destination();
        $file = $this->csv([['destinations', $d->id, 'Malagos Garden Resort', 'description', 'Something else.', self::SOURCE]]);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true])->expectsOutputToContain("not 'Malagos Garden Resort'")->assertFailed();
        $this->assertSame(self::GENERATED, $d->fresh()->description);
    }

    public function test_fields_values_and_sources_are_checked(): void
    {
        $d = $this->destination();
        $row = fn (string $field, string $value, string $source = self::SOURCE) => ['destinations', $d->id, 'Eden Nature Park', $field, $value, $source];
        $run = fn (array $r) => $this->artisan('listings:apply-updates', ['file' => $this->csv([$r])])->assertFailed();

        $run($row('rating', '5'));                              // not a field this command may set
        $run($row('itinerary_role', 'headliner'));              // not a role
        $run($row('price_tier', 'Cheap'));
        $run($row('entry_fee_min', '-5'));
        $run($row('hours', 'Mon-Sat, call ahead'));             // the planner could not read it
        $run($row('description', 'A park.', ''));               // no source
        $run($row('description', ''));                          // unknown stays blank
        $run(['coupons', $d->id, 'Eden Nature Park', 'description', 'x', self::SOURCE]);   // not a catalogue table
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        $d = $this->destination();
        $file = $this->csv([
            ['destinations', $d->id, 'Eden Nature Park', 'itinerary_role', 'optional', self::SOURCE],
            ['destinations', $d->id, 'Eden Nature Park', 'price_tier', 'Cheap', self::SOURCE],
        ]);

        $this->artisan('listings:apply-updates', ['file' => $file, '--apply' => true])->assertFailed();

        $this->assertNotSame('optional', DB::table('destinations')->where('id', $d->id)->value('itinerary_role'), 'The good row must not be applied alone.');
    }

    public function test_the_shipped_update_file_is_well_formed(): void
    {
        $rows = array_map('str_getcsv', file(base_path('database/data/listing-updates-2026-10-30.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertSame(['table', 'id', 'name', 'field', 'value', 'source'], array_shift($rows));

        foreach ($rows as $row) {
            $this->assertCount(6, $row);
            $this->assertContains($row[0], ['destinations', 'accommodations', 'restaurants', 'souvenir_centers', 'tour_operators']);
            $this->assertGreaterThan(20, strlen($row[5]), "{$row[0]} {$row[1]} {$row[3]} needs a real source");
            $this->assertNotSame('', $row[4]);
        }
    }
}
