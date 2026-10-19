<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** When nothing is open for a trip, the tourist gets a clear message, not an error page. */
class NoDestinationsAvailableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_trip_with_no_open_place_gets_a_message_not_an_error(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        Destination::create(['slug' => 'closed-park', 'name' => 'Closed Park', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'operating_status' => 'closed']);

        $response = $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City',
            'activities' => ['Wildlife'], 'amenities' => ['Parking Area'],
        ]);

        $response->assertRedirect(route('plan.edit'));
        $response->assertSessionHas('error', 'No open places for this trip');
    }
}
