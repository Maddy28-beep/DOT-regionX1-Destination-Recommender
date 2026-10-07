<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\DestinationTag;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\SimilarDestinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Similar-place swaps: pretrained-embedding similarity ranks substitutes for a
 * stop, ordinary rules decide which substitutes are allowed, and an active
 * closure advisory offers the closest open substitute automatically.
 *
 * No test calls a real embedding model: vectors are written by hand, and the
 * model's HTTP endpoint is faked where the build step is exercised.
 */
class SimilarPlaceSwapTest extends TestCase
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
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
            'price_tier' => 'Mid-range', 'latitude' => 7.10, 'longitude' => 125.50,
        ]);

        DestinationEmbedding::create([
            'destination_id' => $destination->id, 'model' => 'test', 'dimensions' => count($vector),
            'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector),
        ]);

        return $destination;
    }

    /** An itinerary whose only stop is $stop. */
    private function tripWith(Destination $stop): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ]);
        $itinerary = Itinerary::create([
            'preference_id' => $preference->id, 'total_days' => 1, 'generated_at' => now(),
        ]);
        ItineraryItem::create([
            'itinerary_id' => $itinerary->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning',
            'kind' => 'activity', 'title' => 'Visit '.$stop->name, 'destination_id' => $stop->id,
        ]);

        return $itinerary;
    }

    public function test_cosine_similarity_is_one_for_the_same_meaning_and_zero_for_unrelated(): void
    {
        $a = DestinationEmbeddingService::normalise([1, 0, 0]);
        $b = DestinationEmbeddingService::normalise([0, 1, 0]);
        $c = DestinationEmbeddingService::normalise([3, 0, 0]);

        $this->assertEqualsWithDelta(1.0, DestinationEmbeddingService::similarity($a, $c), 1e-9);
        $this->assertEqualsWithDelta(0.0, DestinationEmbeddingService::similarity($a, $b), 1e-9);
    }

    public function test_alternatives_are_ranked_by_similarity_to_the_stop(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $close = $this->place('Mini Zoo', [0.9, 0.1, 0]);
        $far = $this->place('Museum', [0, 1, 0]);
        $middle = $this->place('Garden', [0.5, 0.5, 0]);

        $names = app(SimilarDestinationService::class)
            ->alternatives($stop, $this->tripWith($stop), 3)
            ->map(fn ($row) => $row['destination']->name)->all();

        $this->assertSame([$close->name, $middle->name, $far->name], $names);
    }

    public function test_alternatives_never_include_places_that_would_be_unfair_substitutes(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $inTrip = $this->place('Already Visiting', [0.99, 0.01, 0]);
        $archived = $this->place('Archived Park', [0.98, 0.02, 0], ['archived_at' => now()]);
        $private = $this->place('Unaccredited Park', [0.98, 0.02, 0], ['is_accredited' => false]);
        $venue = $this->place('Members Club', [0.98, 0.02, 0], ['itinerary_role' => 'excluded']);
        $closed = $this->place('Closed Park', [0.98, 0.02, 0]);
        $tooFar = $this->place('Distant Park', [0.98, 0.02, 0], ['latitude' => 8.50, 'longitude' => 126.80]);
        $ok = $this->place('Open Park', [0.7, 0.3, 0]);

        Advisory::create(['title' => 'Closed today', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $closed->id]);

        $trip = $this->tripWith($stop);
        ItineraryItem::create([
            'itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 2, 'slot' => 'Afternoon',
            'kind' => 'activity', 'title' => 'Visit', 'destination_id' => $inTrip->id,
        ]);

        $offered = app(SimilarDestinationService::class)->alternatives($stop, $trip, 10)
            ->map(fn ($row) => $row['destination']->name)->all();

        $this->assertSame(['Open Park'], $offered);
    }

    public function test_a_warning_advisory_does_not_close_a_place_but_a_danger_one_does(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $warned = $this->place('Warned Park', [0.9, 0.1, 0]);
        Advisory::create(['title' => 'Slippery trail', 'message' => 'x', 'severity' => 'warning', 'listing_kind' => 'destination', 'listing_id' => $warned->id]);

        $service = app(SimilarDestinationService::class);

        $trip = $this->tripWith($stop);

        $this->assertNotContains($warned->id, $service->unavailableDestinationIds($service->windowFor($trip)));
        $this->assertCount(1, $service->alternatives($stop, $trip, 3));
    }

    public function test_the_alternatives_endpoint_lists_the_closest_places_for_a_stop_in_the_trip(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Mini Zoo', [0.9, 0.1, 0]);
        $trip = $this->tripWith($stop);

        $this->withSession([\App\Http\Controllers\TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $stop->id))
            ->assertOk()
            ->assertJsonPath('original.name', 'Zoo')
            ->assertJsonPath('alternatives.0.name', 'Mini Zoo')
            ->assertJsonPath('advisory', null);
    }

    public function test_the_alternatives_endpoint_refuses_a_stop_that_is_not_in_the_trip(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $other = $this->place('Mini Zoo', [0.9, 0.1, 0]);
        $trip = $this->tripWith($stop);

        $this->withSession([\App\Http\Controllers\TripPlannerController::ITINERARY_KEY => $trip->id])
            ->getJson(route('plan.alternatives', $other->id))
            ->assertNotFound();
    }

    public function test_the_alternatives_endpoint_needs_an_itinerary_in_the_session(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->tripWith($stop);

        $this->getJson(route('plan.alternatives', $stop->id))->assertNotFound();
    }

    public function test_a_swap_to_a_place_the_suggester_would_not_offer_is_rejected(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $closed = $this->place('Closed Park', [0.98, 0.02, 0]);
        Advisory::create(['title' => 'Closed', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $closed->id]);
        $trip = $this->tripWith($stop);

        $this->withSession([
            \App\Http\Controllers\TripPlannerController::ITINERARY_KEY => $trip->id,
            \App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $trip->preference_id,
        ])->post(route('plan.swap'), ['original_id' => $stop->id, 'replacement_id' => $closed->id])
            ->assertRedirect(route('plan.itinerary'));

        $this->assertDatabaseCount('itineraries', 1);
    }

    public function test_swapping_a_planned_stop_rebuilds_the_itinerary_around_the_new_place(): void
    {
        foreach ([['Eden Nature Park', [1, 0, 0]], ['Eagle Center', [0.9, 0.1, 0]], ['Peoples Park', [0.2, 0.8, 0]], ['Crocodile Park', [0.8, 0.2, 0]], ['Museum', [0, 1, 0]], ['Zoo', [0.7, 0.3, 0]]] as $i => [$name, $vector]) {
            $this->place($name, $vector, ['latitude' => 7.0 + $i * 0.01, 'longitude' => 125.5]);
        }

        $this->postJson('/plan', [
            'travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time visitor', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel',
            'distance_pref' => 'moderate', 'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        $first = Itinerary::latest('generated_at')->first();
        $stop = $first->items()->where('kind', 'activity')->whereNotNull('destination_id')->first()->destination;

        $replacement = app(SimilarDestinationService::class)->alternatives($stop, $first, 1)->first();
        $this->assertNotNull($replacement, 'there should be a place outside the trip to swap in');

        $this->post(route('plan.swap'), ['original_id' => $stop->id, 'replacement_id' => $replacement['destination']->id])
            ->assertRedirect(route('plan.itinerary'));

        $rebuilt = Itinerary::where('id', '!=', $first->id)->latest('generated_at')->first();

        $this->assertNotNull($rebuilt);
        $this->assertSame([(string) $stop->id => $replacement['destination']->id], collect($rebuilt->swaps)->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all());
        $this->assertTrue($rebuilt->items()->where('destination_id', $replacement['destination']->id)->exists());
        $this->assertFalse($rebuilt->items()->where('destination_id', $stop->id)->exists());
    }

    public function test_the_itinerary_page_shows_swap_buttons_and_an_advisory_substitute(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $this->place('Mini Zoo', [0.9, 0.1, 0]);
        Advisory::create(['title' => 'Cage repairs', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $stop->id]);
        $trip = $this->tripWith($stop);

        $this->withSession([
            \App\Http\Controllers\TripPlannerController::ITINERARY_KEY => $trip->id,
            \App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $trip->preference_id,
        ])->get(route('plan.itinerary'))
            ->assertOk()
            ->assertSee('Swap this stop')
            ->assertSee('Cage repairs')
            ->assertSee('Closest open substitute')
            ->assertSee('Use Mini Zoo instead');
    }

    public function test_no_swap_buttons_appear_when_no_embeddings_are_stored(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $trip = $this->tripWith($stop);
        DestinationEmbedding::query()->delete();

        $this->withSession([
            \App\Http\Controllers\TripPlannerController::ITINERARY_KEY => $trip->id,
            \App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $trip->preference_id,
        ])->get(route('plan.itinerary'))
            ->assertOk()
            ->assertDontSee('Swap this stop');
    }

    public function test_build_embeds_each_destination_once_and_skips_unchanged_text(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        Http::fake(['ollama.test/api/embed' => fn ($request) => Http::response([
            'embeddings' => array_map(fn () => [3.0, 4.0, 0.0], $request['input']),
        ])]);

        $stop = Destination::create([
            'slug' => 'zoo', 'name' => 'Zoo', 'location' => 'Davao City', 'region_id' => $this->region->id,
            'type' => 'Wildlife', 'is_accredited' => true, 'description' => 'Animals and birds.',
        ]);
        DestinationTag::create(['destination_id' => $stop->id, 'kind' => 'category', 'value' => 'wildlife']);

        $service = app(DestinationEmbeddingService::class);

        $this->assertSame(['embedded' => 1, 'unchanged' => 0, 'total' => 1], $service->build());
        $this->assertSame(['embedded' => 0, 'unchanged' => 1, 'total' => 1], $service->build());
        foreach ([0.6, 0.8, 0.0] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, DestinationEmbedding::first()->vector[$i], 1e-9);
        }

        Http::assertSent(fn ($request) => str_starts_with($request['input'][0], 'search_document: Zoo')
            && str_contains($request['input'][0], 'wildlife'));
    }

    public function test_exported_vectors_import_into_a_fresh_database_without_the_model(): void
    {
        $stop = $this->place('Zoo', [1, 0, 0]);
        $path = tempnam(sys_get_temp_dir(), 'emb');

        $service = app(DestinationEmbeddingService::class);
        $this->assertSame(1, $service->export($path));

        DestinationEmbedding::query()->delete();
        Http::fake();
        $this->assertSame(1, $service->import($path));

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(1.0, DestinationEmbedding::where('destination_id', $stop->id)->first()->vector[0], 1e-6);
        @unlink($path);
    }
}
