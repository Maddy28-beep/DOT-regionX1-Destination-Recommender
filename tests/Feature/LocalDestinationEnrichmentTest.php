<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDestinationEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        parent::tearDown();
    }

    private function destination(): Destination
    {
        return Destination::create([
            'slug' => 'reviewed-park', 'name' => 'Reviewed park', 'location' => 'Davao City',
            'region_id' => Region::create(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'itinerary_role' => 'excluded', 'description' => 'Existing description',
        ]);
    }

    private function manifest(array $fields = [], array $amenities = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'enrichment');
        $this->files[] = $path;
        file_put_contents($path, json_encode(['format' => 'destination-enrichment-v1', 'records' => [[
            'slug' => 'reviewed-park', 'name' => 'Reviewed park', 'fields' => $fields, 'amenities' => $amenities,
        ]]]));

        return $path;
    }

    private function edit(mixed $value, mixed $expected = null): array
    {
        return ['value' => $value, 'expected' => $expected, 'source' => 'https://example.com/operator'];
    }

    public function test_preview_apply_and_repeat_preserve_protected_data_and_existing_amenities(): void
    {
        $d = $this->destination();
        $d->tags()->create(['kind' => 'amenity', 'value' => 'Restroom']);
        $file = $this->manifest(['latitude' => $this->edit(7.1), 'longitude' => $this->edit(125.6)], [
            ['key' => 'pool', 'source' => 'https://example.com/operator', 'display_name' => 'Swimming Pool (reported)'],
            ['key' => 'pool', 'source' => 'https://example.com/operator', 'display_name' => 'Swimming Pool (reported)'],
        ]);
        $this->artisan('destinations:enrich-local', ['file' => $file])->expectsOutputToContain('Preview')->assertSuccessful();
        $this->assertNull($d->fresh()->latitude);
        $this->assertSame(1, $d->tags()->count());
        $this->artisan('destinations:enrich-local', ['file' => $file, '--apply' => true])->assertSuccessful();
        $fresh = $d->fresh();
        $this->assertEqualsWithDelta(7.1, $fresh->latitude, 0.000001);
        $this->assertSame('excluded', $fresh->itinerary_role);
        $this->assertTrue($fresh->is_accredited);
        $this->assertNull($fresh->planner_verified_at);
        $this->assertSame('Existing description', $fresh->description);
        $this->assertSame('Swimming Pool (reported)', DestinationTag::where('value', 'Swimming Pool')->first()->display_name);
        $this->assertSame(2, $d->tags()->count());
        $this->artisan('destinations:enrich-local', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('"values":0,"amenities":0')->assertSuccessful();
    }

    public function test_concurrent_changes_stop_the_entire_bundle(): void
    {
        $d = $this->destination();
        $file = $this->manifest(['latitude' => $this->edit(7.1), 'longitude' => $this->edit(125.6), 'description' => $this->edit('New description', 'Wrong old description')]);
        $this->artisan('destinations:enrich-local', ['file' => $file, '--apply' => true])->assertFailed();
        $this->assertNull($d->fresh()->latitude);
        $this->assertSame('Existing description', $d->fresh()->description);
    }

    public function test_protected_fields_unsourced_amenities_and_single_coordinates_are_rejected(): void
    {
        $d = $this->destination();
        foreach ([
            $this->manifest(['itinerary_role' => $this->edit('sightseeing', 'excluded')]),
            $this->manifest(['latitude' => $this->edit(7.1)]),
            $this->manifest([], [['key' => 'parking']]),
            $this->manifest(['entry_fee_min' => $this->edit(200), 'entry_fee_max' => $this->edit(100)]),
        ] as $file) {
            $this->artisan('destinations:enrich-local', ['file' => $file, '--apply' => true])->assertFailed();
        }
        $this->assertSame('excluded', $d->fresh()->itinerary_role);
        $this->assertNull($d->fresh()->latitude);
        $this->assertSame(0, $d->tags()->count());
    }

    public function test_production_is_rejected(): void
    {
        $this->destination();
        $this->app->instance('env', 'production');
        $this->artisan('destinations:enrich-local', ['file' => $this->manifest(), '--apply' => true])->expectsOutputToContain('Local/testing only')->assertFailed();
    }
}
