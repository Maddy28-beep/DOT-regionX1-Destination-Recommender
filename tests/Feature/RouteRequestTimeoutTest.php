<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D15: a route request to the public OSRM server must not leave "Calculating route..." on screen forever.
 */
class RouteRequestTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_request_gives_up_after_a_timeout_instead_of_spinning_forever(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64], ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Beach One', 'Beach & Leisure', 7.15, 125.71]] as $i => [$name, $type, $lat, $lng]) {
            Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 5 - $i * 0.1, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'latitude' => $lat, 'longitude' => $lng,
            ]);
        }
        Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => '08:00',
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));

        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();

        // D15: the OSRM request is abortable and aborted after 12 seconds; the failure message still exists.
        $this->assertStringContainsString('new AbortController()', $html);
        $this->assertStringContainsString('controller.abort(); }, 12000)', $html);
        $this->assertStringContainsString('{ signal: controller.signal }', $html);
        $this->assertStringContainsString('Step-by-step directions are not available right now.', $html);
        $this->assertStringContainsString('router.project-osrm.org/route/v1/driving/', $html);

        Carbon::setTestNow();
    }
}
