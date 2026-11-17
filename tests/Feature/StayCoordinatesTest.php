<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_distance_uses_coordinates_only_when_no_recorded_distance_exists(): void
    {
        $stay = new Accommodation(['latitude' => 7.06193, 'longitude' => 125.6086]);
        $this->assertEqualsWithDelta(1.3, $stay->distanceFromCityCentreKm(), .1);
        $this->assertNull($stay->distance_km, 'A display estimate must not overwrite saved distance data.');
        $stay->distance_km = 0;
        $this->assertSame(0.0, $stay->distanceFromCityCentreKm());
        $stay->distance_km = 8;
        $this->assertSame(8.0, $stay->distanceFromCityCentreKm());
        $stay->distance_km = null;
        $stay->latitude = null;
        $this->assertNull($stay->distanceFromCityCentreKm());
        $stay->latitude = 91;
        $this->assertNull($stay->distanceFromCityCentreKm());
        $stay->latitude = \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT;
        $stay->longitude = \App\Services\Recommendation\ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG;
        $this->assertSame(0.0, $stay->distanceFromCityCentreKm());
    }

    private function stay(): Accommodation
    {
        return Accommodation::create(['name' => 'Cosy Nook Condotel', 'slug' => 'cosy-nook-condotel', 'type' => 'Condominium Unit', 'location' => 'Centro Spatial, Davao City', 'is_accredited' => true, 'price_per_night' => 2000, 'price_per_day' => 500]);
    }

    public function test_dry_run_is_read_only_and_apply_is_idempotent_with_source_and_complex_label(): void
    {
        $stay = $this->stay();
        $this->artisan('stays:apply-coordinates')->expectsOutputToContain('1 pins ready')->assertSuccessful();
        $this->assertNull($stay->fresh()->latitude);
        $this->artisan('stays:apply-coordinates', ['--apply' => true])->expectsOutputToContain('1 pins added')->assertSuccessful();
        $stay->refresh();
        $this->assertEquals(7.06193, $stay->latitude);
        $this->assertSame('complex', $stay->coordinate_provenance['precision']);
        $this->assertSame('2000.00', $stay->price_per_night);
        $this->assertSame('500.00', $stay->price_per_day);
        $this->get('/accommodations/cosy-nook-condotel')->assertOk()->assertSee('Complex-centre pin.')->assertSee('Map source')
            ->assertSee('1.3 km')->assertDontSee('≈')->assertSee('straight-line estimate from Davao City centre');
        $this->artisan('stays:apply-coordinates', ['--apply' => true])->expectsOutputToContain('0 pins added')->assertSuccessful();
    }

    public function test_existing_pins_are_preserved_and_later_owner_pin_edits_clear_old_provenance(): void
    {
        $stay = $this->stay();
        $stay->update(['latitude' => 7.1, 'longitude' => 125.6]);
        $this->artisan('stays:apply-coordinates', ['--apply' => true])->expectsOutputToContain('0 pins added')->assertSuccessful();
        $this->assertEquals(7.1, $stay->fresh()->latitude);
        $stay->forceFill(['coordinate_provenance' => ['precision' => 'complex', 'source' => 'https://www.openstreetmap.org/']])->save();
        $stay->update(['latitude' => 7.2]);
        $this->assertNull($stay->fresh()->coordinate_provenance);
    }

    public function test_manifest_names_must_match_and_every_pin_has_a_source_and_reasonable_davao_bounds(): void
    {
        $stay = $this->stay();
        $stay->update(['name' => 'Different property with reused slug']);
        $this->artisan('stays:apply-coordinates', ['--apply' => true])->expectsOutputToContain('0 pins added')->assertSuccessful();
        $this->assertNull($stay->fresh()->latitude);
        $rows = json_decode(file_get_contents(base_path('database/data/stay-coordinates-2026-11-17.json')), true)['updates'];
        $this->assertCount(count($rows), array_unique(array_column($rows, 'slug')));
        foreach ($rows as $row) {
            $this->assertGreaterThan(5.3, $row['latitude']);
            $this->assertLessThan(8.4, $row['latitude']);
            $this->assertGreaterThan(124.7, $row['longitude']);
            $this->assertLessThan(127, $row['longitude']);
            $this->assertContains($row['precision'], ['property', 'complex']);
            $this->assertStringStartsWith('https://', $row['source']);
        }
    }
}
