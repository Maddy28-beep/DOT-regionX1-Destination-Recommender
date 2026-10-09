<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\SimilarDestinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Swap suggestions are ordered by meaning, the traveller's interests and distance together (not by meaning
 * alone), each says why it is a fair swap, a swap that cannot be scheduled is refused instead of silently
 * deleting the stop, and a swap that works says what it did to the plan.
 */
class SwapSuggestionRankingTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::create(['name' => 'Davao City']);
    }

    private function place(string $name, array $vector, array $overrides = []): Destination
    {
        $destination = Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
            'price_tier' => 'Mid-range', 'latitude' => 7.10, 'longitude' => 125.50,
        ]);

        DestinationEmbedding::create([
            'destination_id' => $destination->id, 'model' => 'test', 'dimensions' => count($vector),
            'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector),
        ]);

        return $destination;
    }

    private function tripWith(Destination $stop, array $interests = []): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        foreach ($interests as $interest) {
            PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $interest]);
        }
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create([
            'itinerary_id' => $itinerary->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning',
            'kind' => 'activity', 'title' => 'Visit '.$stop->name, 'destination_id' => $stop->id,
        ]);

        return $itinerary;
    }

    private function names(Destination $stop, Itinerary $trip): array
    {
        return app(SimilarDestinationService::class)->alternatives($stop, $trip, 5)
            ->map(fn ($row) => $row['destination']->name)->all();
    }

    // ---- ordering

    public function test_a_place_that_fits_the_travellers_interests_can_outrank_one_that_only_means_nearly_the_same(): void
    {
        $stop = $this->place('Convention Hall', [1, 0, 0], ['type' => 'Events & Conventions']);
        // Almost the same meaning, but a spa, and the traveller asked for nature.
        $this->place('Day Spa', [0.99, 0.1, 0], ['type' => 'Wellness & Spa']);
        $this->place('Nature Trail', [0.8, 0.6, 0], ['type' => 'Nature & Adventure']);

        $trip = $this->tripWith($stop, ['Nature & Adventure']);

        $this->assertSame(['Nature Trail', 'Day Spa'], $this->names($stop, $trip));
    }

    public function test_without_picked_interests_the_order_is_meaning_then_distance(): void
    {
        $stop = $this->place('Convention Hall', [1, 0, 0], ['type' => 'Events & Conventions']);
        $this->place('Day Spa', [0.99, 0.1, 0], ['type' => 'Wellness & Spa']);
        $this->place('Nature Trail', [0.8, 0.6, 0], ['type' => 'Nature & Adventure']);

        $this->assertSame(['Day Spa', 'Nature Trail'], $this->names($stop, $this->tripWith($stop)));
    }

    public function test_a_closer_place_wins_between_equally_similar_ones(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Far Zoo', [0.9, 0.1, 0], ['latitude' => 7.50, 'longitude' => 125.50]);
        $this->place('Near Zoo', [0.9, 0.1, 0], ['latitude' => 7.11, 'longitude' => 125.50]);

        $this->assertSame(['Near Zoo', 'Far Zoo'], $this->names($stop, $this->tripWith($stop)));
    }

    public function test_the_hard_rules_still_apply_whatever_the_blend(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Distant Park', [1, 0, 0], ['latitude' => 8.50, 'longitude' => 126.80]);
        $this->place('Archived Park', [1, 0, 0], ['archived_at' => now()]);

        $this->assertSame([], $this->names($stop, $this->tripWith($stop, ['Nature & Adventure'])));
    }

    // ---- reasons

    public function test_each_suggestion_says_why_it_fits_and_when_it_is_open(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Open Park', [0.9, 0.1, 0], ['hours' => '8:00 AM–5:00 PM']);
        $trip = $this->tripWith($stop, ['Nature & Adventure']);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $stop->id))
            ->assertOk()
            ->assertJsonPath('alternatives.0.reason', 'Matches your interests · Open 8:00 AM–5:00 PM');
    }

    public function test_a_suggestion_outside_the_picked_interests_says_so_instead_of_pretending(): void
    {
        $stop = $this->place('Convention Hall', [1, 0, 0], ['type' => 'Events & Conventions']);
        $this->place('Day Spa', [0.9, 0.1, 0], ['type' => 'Wellness & Spa']);
        $trip = $this->tripWith($stop, ['Nature & Adventure']);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $stop->id))
            ->assertJsonPath('alternatives.0.reason', 'Not one of your picked interests');
    }

    public function test_a_tagged_place_that_fits_one_of_several_picked_interests_counts_as_fitting(): void
    {
        $stop = $this->place('Convention Hall', [1, 0, 0], ['type' => 'Events & Conventions']);
        $farm = $this->place('Sample Farm', [0.9, 0.1, 0], ['type' => 'Farm Tourism']);
        \App\Models\DestinationTag::create(['destination_id' => $farm->id, 'kind' => 'category', 'value' => 'Nature']);
        $trip = $this->tripWith($stop, ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage']);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $stop->id))
            ->assertJsonPath('alternatives.0.reason', 'Matches your interests');
    }

    public function test_no_interest_line_is_shown_when_the_traveller_picked_none(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Mini Zoo', [0.9, 0.1, 0]);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $this->tripWith($stop)->id])
            ->getJson(route('plan.alternatives', $stop->id))
            ->assertJsonPath('alternatives.0.reason', '');
    }

    // ---- swapping

    /** A planned trip over a handful of places, the way the planner builds one for a visitor. */
    private function plannedTrip(int $days = 2): Itinerary
    {
        foreach ([['Eden Nature Park', [1, 0, 0]], ['Eagle Center', [0.9, 0.1, 0]], ['Peoples Park', [0.2, 0.8, 0]], ['Crocodile Park', [0.8, 0.2, 0]]] as $i => [$name, $vector]) {
            $this->place($name, $vector, ['latitude' => 7.0 + $i * 0.01, 'longitude' => 125.5]);
        }

        $this->postJson('/plan', [
            'travel_days' => $days, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time visitor', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel',
            'distance_pref' => 'moderate', 'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        return Itinerary::latest('generated_at')->first();
    }

    private function firstStop(Itinerary $trip): Destination
    {
        return $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')->first()->destination;
    }

    public function test_a_swap_that_cannot_be_scheduled_is_refused_and_the_plan_is_left_alone(): void
    {
        $trip = $this->plannedTrip();
        $stop = $this->firstStop($trip);

        // Open only from 5 to 8 in the morning: shut before anyone could get there.
        $dawn = $this->place('Sunrise Point', [0.95, 0.05, 0], ['latitude' => 7.02, 'longitude' => 125.5, 'hours' => '5:00 AM–8:00 AM']);

        $response = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $trip->preference_id])
            ->post(route('plan.swap'), ['original_id' => $stop->id, 'replacement_id' => $dawn->id]);

        $response->assertRedirect(route('plan.itinerary'));
        $response->assertSessionHas('error', 'Could not swap');
        $this->assertStringContainsString('does not fit your schedule', session('status_detail'));

        $this->assertSame(1, Itinerary::count(), 'The attempt is not kept.');
        $this->assertTrue($trip->fresh()->items()->where('destination_id', $stop->id)->exists(), 'The original stop is still in the plan.');
        $this->assertSame($trip->id, session(TripPlannerController::ITINERARY_KEY), 'The page still shows the original plan.');
    }

    public function test_a_swap_to_a_place_that_closes_early_says_the_visit_is_shortened(): void
    {
        $trip = $this->plannedTrip();
        $stop = $this->firstStop($trip);

        $morning = $this->place('Morning Garden', [0.95, 0.05, 0], ['latitude' => 7.02, 'longitude' => 125.5, 'hours' => '8:00 AM–11:00 AM']);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $trip->preference_id])
            ->post(route('plan.swap'), ['original_id' => $stop->id, 'replacement_id' => $morning->id])
            ->assertRedirect(route('plan.itinerary'))
            ->assertSessionHas('status', 'Stop swapped');

        $this->assertStringContainsString('Morning Garden closes at 11:00 AM, so the visit is shortened to', session('status_detail'));
        $this->assertSame(2, Itinerary::count());
    }

    public function test_a_plain_swap_has_no_warnings(): void
    {
        $trip = $this->plannedTrip();
        $stop = $this->firstStop($trip);
        $open = $this->place('Open Garden', [0.95, 0.05, 0], ['latitude' => 7.02, 'longitude' => 125.5, 'hours' => '8:00 AM–6:00 PM']);

        $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $trip->preference_id])
            ->post(route('plan.swap'), ['original_id' => $stop->id, 'replacement_id' => $open->id])
            ->assertSessionHas('status', 'Stop swapped');

        $this->assertSame($stop->name.' is now Open Garden. Your schedule was rebuilt around it.', session('status_detail'));
    }
}
