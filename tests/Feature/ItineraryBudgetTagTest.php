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

/** A stop's card says "Fits your budget band" only when its price band fits the band the traveller chose. */
class ItineraryBudgetTagTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 'Fits your budget band';

    private function page(string $budget, ?string $tier, string $slug = 'budget-tag-place'): string
    {
        $destination = Destination::create([
            'slug' => 'budget-tag-'.$slug, 'name' => 'Budget Tag Place '.$slug, 'location' => 'Davao City',
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

    /** Every traveller band against every stop band: tagged exactly when the stop's band is at or below the chosen one. */
    public function test_all_sixteen_band_combinations_follow_the_at_or_below_rule(): void
    {
        $rank = ['Free' => 0, 'Budget-Friendly' => 1, 'Mid-range' => 2, 'Premium' => 3];

        foreach ($rank as $budget => $budgetRank) {
            foreach ($rank as $tier => $tierRank) {
                $tagged = str_contains($this->page($budget, $tier, $budget.'-'.$tier), self::TAG);

                $this->assertSame($tierRank <= $budgetRank, $tagged, "budget {$budget}, stop {$tier}");
            }
        }
    }

    public function test_the_old_wording_that_implied_a_price_is_gone(): void
    {
        $this->assertStringNotContainsString('Within your budget', $this->page('Premium', 'Free'));
    }

    public function test_an_unrecognised_budget_never_tags_a_stop(): void
    {
        foreach (['Luxury', '0', 'mid-range', ''] as $budget) {
            $this->assertStringNotContainsString(self::TAG, $this->page($budget, 'Free', 'x'.md5($budget)), "budget '{$budget}'");
        }
    }

    public function test_only_destination_stops_are_tagged_not_meals_or_stays(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $base = ['location' => 'Davao City', 'region_id' => $region->id, 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Free', 'latitude' => 7.15, 'longitude' => 125.40];
        $cafe = \App\Models\Restaurant::create($base + ['slug' => 'band-cafe', 'name' => 'Band Cafe', 'cuisine_type' => 'Cafe']);
        $inn = \App\Models\Accommodation::create($base + ['slug' => 'band-inn', 'name' => 'Band Inn', 'type' => 'Hotel']);
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Premium', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Lunch', 'kind' => 'meal',
            'title' => 'Lunch', 'starts_at' => '12:00:00', 'ends_at' => '13:00:00', 'restaurant_id' => $cafe->id]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 2, 'slot' => 'Night', 'kind' => 'overnight',
            'title' => 'Stay', 'starts_at' => '20:00:00', 'ends_at' => '21:00:00', 'accommodation_id' => $inn->id]);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertStringContainsString('Band Cafe', $html);
        $this->assertStringNotContainsString(self::TAG, $html);
    }
}
