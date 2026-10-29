<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A multi-day trip with no overnight stay at all must say so. Otherwise a traveller reads a plan with no place to sleep
 * as a choice the planner made, when really no DOT-accredited stay was open for their dates.
 */
class NoAccommodationNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'No DOT-accredited accommodation is available for your trip.';

    private function trip(int $days, bool $withOvernight = false): string
    {
        $place = Destination::create([
            'slug' => 'eagle-'.$days.($withOvernight ? 'o' : ''), 'name' => 'Philippine Eagle Center', 'location' => 'Malagos, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        $pref = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => $days, 'generated_at' => now()]);

        $order = 1;
        for ($day = 1; $day <= $days; $day++) {
            ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => $order++, 'slot' => 'Morning', 'kind' => 'activity',
                'title' => 'Visit — '.$place->name, 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $place->id]);
        }
        if ($withOvernight) {
            ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => $order++, 'slot' => 'Evening', 'kind' => 'overnight',
                'title' => 'Overnight stay', 'starts_at' => '20:00:00']);
        }

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    public function test_a_two_day_trip_with_no_overnight_stay_says_no_accredited_accommodation_is_available(): void
    {
        $html = $this->trip(2);

        $this->assertStringContainsString(self::NOTICE, $html);
        $this->assertStringContainsString(route('accommodations.index'), $html);
    }

    public function test_a_trip_that_has_an_overnight_stay_has_no_notice(): void
    {
        $this->assertStringNotContainsString(self::NOTICE, $this->trip(2, true));
    }

    public function test_a_single_day_trip_needs_no_stay_and_has_no_notice(): void
    {
        $this->assertStringNotContainsString(self::NOTICE, $this->trip(1));
    }
}
