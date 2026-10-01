<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_uses_all_filtered_public_results_not_only_the_current_page(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        for ($i = 1; $i <= 12; $i++) {
            Destination::create([
                'name' => 'Park '.$i, 'slug' => 'park-'.$i, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Adventure', 'is_accredited' => true,
                'latitude' => $i === 1 ? null : 7.1, 'longitude' => $i === 1 ? null : 125.6,
            ]);
        }
        Destination::create(['name' => 'Private park', 'slug' => 'private-park', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature & Adventure', 'is_accredited' => false]);
        Destination::create(['name' => 'Other type', 'slug' => 'other-type', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Wildlife', 'is_accredited' => true]);
        $this->get('/destinations?view=map&type=Nature%20%26%20Adventure')
            ->assertOk()
            ->assertViewHas('destinations', fn ($results) => $results->count() === 9)
            ->assertViewHas('mapDestinations', fn ($results) => $results->count() === 12
                && $results->every(fn ($place) => str_starts_with($place['name'], 'Park '))
                && $results->firstWhere('name', 'Park 1')['latitude'] === null);
        $this->get('/destinations?view=map&q=nonexistent')->assertOk()
            ->assertViewHas('mapDestinations', fn ($results) => $results->isEmpty());
    }
}

