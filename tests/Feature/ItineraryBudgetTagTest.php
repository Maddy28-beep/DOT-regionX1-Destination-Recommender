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

/** A stop's card says "Within your budget" only when its price band fits the band the traveller chose. */
class ItineraryBudgetTagTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 'Within your budget';

    private function page(string $budget, ?string $tier): string
    {
        $destination = Destination::create([
            'slug' => 'budget-tag-place', 'name' => 'Budget Tag Place', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => $tier,
            'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => $budget, 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit', 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $destination->id]);

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    public function test_a_stop_at_or_below_the_chosen_budget_is_tagged(): void
    {
        $this->assertStringContainsString(self::TAG, $this->page('Mid-range', 'Budget-Friendly'));
    }

    public function test_a_stop_in_the_same_band_is_tagged(): void
    {
        $this->assertStringContainsString(self::TAG, $this->page('Budget-Friendly', 'Budget-Friendly'));
    }

    public function test_a_stop_above_the_chosen_budget_is_not_tagged(): void
    {
        $this->assertStringNotContainsString(self::TAG, $this->page('Budget-Friendly', 'Premium'));
    }

    public function test_a_stop_with_no_price_band_is_not_tagged(): void
    {
        $this->assertStringNotContainsString(self::TAG, $this->page('Premium', null));
    }
}
