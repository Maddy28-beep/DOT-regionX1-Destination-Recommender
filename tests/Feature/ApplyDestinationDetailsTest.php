<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * destinations:apply-details fills gaps in the catalogue from a researched CSV. It is only safe to run against live
 * data if it cannot touch the wrong place, cannot take a value without a source, and cannot half-apply a file.
 */
class ApplyDestinationDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 1,
            'latitude' => 7.0322, 'longitude' => 125.3739,
        ]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'details').'.csv';
        $lines = ["id,name,field,value,source"];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r));
        }
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    private const SOURCE = 'OpenStreetMap geocoder, accessed 2026-10-30';

    public function test_a_dry_run_writes_nothing(): void
    {
        $d = $this->destination('Eden Nature Park');
        $file = $this->csv([[$d->id, 'Eden Nature Park', 'latitude', '7.0282', self::SOURCE]]);

        $this->artisan('destinations:apply-details', ['file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertEqualsWithDelta(7.0322, (float) $d->fresh()->latitude, 0.00001);
    }

    public function test_apply_updates_the_sourced_values_and_a_second_run_changes_nothing(): void
    {
        $d = $this->destination('Eden Nature Park');
        $file = $this->csv([
            [$d->id, 'Eden Nature Park', 'latitude', '7.0282', self::SOURCE],
            [$d->id, 'Eden Nature Park', 'longitude', '125.4018', self::SOURCE],
            [$d->id, 'Eden Nature Park', 'entry_fee_min', '0', self::SOURCE],
            [$d->id, 'Eden Nature Park', 'hours', '9:00 AM–5:00 PM', self::SOURCE],
        ]);

        $this->artisan('destinations:apply-details', ['file' => $file, '--apply' => true])->expectsOutputToContain('Updated 4 values')->assertSuccessful();

        $fresh = $d->fresh();
        $this->assertEqualsWithDelta(7.0282, (float) $fresh->latitude, 0.00001);
        $this->assertEqualsWithDelta(125.4018, (float) $fresh->longitude, 0.00001);
        $this->assertSame('9:00 AM–5:00 PM', $fresh->hours);

        $this->artisan('destinations:apply-details', ['file' => $file, '--apply' => true])->expectsOutputToContain('Updated 0 values')->assertSuccessful();
    }

    public function test_a_row_for_another_place_with_the_same_id_is_refused(): void
    {
        $d = $this->destination('Davao Crocodile Park');
        // The file was prepared against a copy where this id was Malagos Garden Resort.
        $file = $this->csv([[$d->id, 'Malagos Garden Resort', 'latitude', '7.1910', self::SOURCE]]);

        $this->artisan('destinations:apply-details', ['file' => $file, '--apply' => true])->expectsOutputToContain('not \'Malagos Garden Resort\'')->assertFailed();

        $this->assertEqualsWithDelta(7.0322, (float) $d->fresh()->latitude, 0.00001);
    }

    public function test_a_value_without_a_source_or_outside_the_region_is_refused(): void
    {
        $d = $this->destination('Eden Nature Park');

        $this->artisan('destinations:apply-details', ['file' => $this->csv([[$d->id, 'Eden Nature Park', 'latitude', '7.0282', '']])])
            ->expectsOutputToContain('a source')->assertFailed();

        $this->artisan('destinations:apply-details', ['file' => $this->csv([[$d->id, 'Eden Nature Park', 'latitude', '14.6', self::SOURCE]])])
            ->expectsOutputToContain('inside the Davao Region')->assertFailed();

        $this->artisan('destinations:apply-details', ['file' => $this->csv([[$d->id, 'Eden Nature Park', 'hours', 'Mon–Sat, call ahead', self::SOURCE]])])
            ->expectsOutputToContain('plain daily schedule')->assertFailed();

        $this->artisan('destinations:apply-details', ['file' => $this->csv([[$d->id, 'Eden Nature Park', 'entry_fee_min', '', self::SOURCE]])])
            ->expectsOutputToContain('unknown stays blank')->assertFailed();
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        $d = $this->destination('Eden Nature Park');
        $file = $this->csv([
            [$d->id, 'Eden Nature Park', 'latitude', '7.0282', self::SOURCE],
            [$d->id, 'Eden Nature Park', 'price_tier', 'Cheap', self::SOURCE],
        ]);

        $this->artisan('destinations:apply-details', ['file' => $file, '--apply' => true])->assertFailed();

        $this->assertEqualsWithDelta(7.0322, (float) $d->fresh()->latitude, 0.00001, 'The good row must not be applied alone.');
    }

    public function test_a_category_tag_is_added_once(): void
    {
        $d = $this->destination('Lon Wa Buddhist Temple [SAMPLE]');
        $d->tags()->create(['kind' => 'category', 'value' => 'Nature']);
        $file = $this->csv([
            [$d->id, 'Lon Wa Buddhist Temple [SAMPLE]', 'category_tag', 'Cultural Heritage', self::SOURCE],
            [$d->id, 'Lon Wa Buddhist Temple [SAMPLE]', 'category_tag', 'nature', self::SOURCE],
        ]);

        $this->artisan('destinations:apply-details', ['file' => $file, '--apply' => true])->expectsOutputToContain('Updated 1 values')->assertSuccessful();

        $this->assertSame(['Cultural Heritage', 'Nature'], $d->tags()->where('kind', 'category')->orderBy('value')->pluck('value')->all(),
            'A tag the place already has, in any letter case, is not added twice.');

        $this->artisan('destinations:apply-details', ['file' => $this->csv([[$d->id, 'Lon Wa Buddhist Temple [SAMPLE]', 'category_tag', 'Nature; Beach', self::SOURCE]])])
            ->expectsOutputToContain('short word or phrase')->assertFailed();
    }

    public function test_the_researched_files_in_the_repository_are_well_formed(): void
    {
        // Every row of the shipped CSVs has a source and a field the command accepts, whatever database it is run on.
        foreach (['destination-details-2026-10-30.csv', 'destination-details-2026-10-30-b.csv'] as $name) {
            $rows = array_map('str_getcsv', file(base_path("database/data/{$name}"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $header = array_shift($rows);
            $this->assertSame(['id', 'name', 'field', 'value', 'source'], $header, $name);

            foreach ($rows as $row) {
                $this->assertCount(5, $row, $name);
                $this->assertGreaterThan(20, strlen($row[4]), "{$name}: row for {$row[1]} / {$row[2]} needs a real source");
                $this->assertContains($row[2], ['latitude', 'longitude', 'hours', 'visit_duration', 'best_time', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'category_tag']);
                $this->assertNotSame('', $row[3]);
            }
        }
    }
}
