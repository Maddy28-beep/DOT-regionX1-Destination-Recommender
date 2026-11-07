<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The paper's statement: the deterministic planner decides the days; the Groq AI day-grouping runs only when switched on
 * (AI_SKELETON_ENABLED=true) and keyed. A key alone must never send a trip to the outside service.
 */
class GroqOffByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function places(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            Destination::create([
                'slug' => "place-$i", 'name' => "Place $i", 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
                'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'hours' => '8:00 AM-5:00 PM', 'latitude' => 7.07 + $i / 100, 'longitude' => 125.61,
            ]);
        }
    }

    private function trip(): void
    {
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'start_date' => now()->addDays(10)->toDateString(), 'arrival_time' => '08:00',
        ]);

        app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));
    }

    public function test_with_a_groq_key_and_the_flag_unset_no_request_is_made_for_itinerary_grouping(): void
    {
        $this->places();
        Http::fake();
        config(['services.groq.key' => 'gsk_test_key_not_real', 'ai-skeleton.enabled' => null]);

        $this->trip();

        Http::assertNothingSent();
    }

    public function test_an_empty_flag_and_an_explicit_false_also_make_no_request(): void
    {
        $this->places();
        Http::fake();

        foreach ([null, '', 'false', false] as $flag) {
            config(['services.groq.key' => 'gsk_test_key_not_real', 'ai-skeleton.enabled' => $flag]);
            $this->trip();
        }

        Http::assertNothingSent();
    }

    public function test_an_empty_groq_key_counts_as_no_key(): void
    {
        $this->assertNull(config('services.groq.key'), 'the test environment has GROQ_API_KEY= (empty): that is no key, not a blank key');
        $this->assertFalse(app(\App\Services\Chatbot\GroqChatbotClient::class)->isConfigured());
    }

    public function test_switching_it_on_with_a_key_does_ask_the_service(): void
    {
        $this->places();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200)]);
        config(['services.groq.key' => 'gsk_test_key_not_real', 'ai-skeleton.enabled' => 'true']);

        $this->trip();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'groq'));
    }
}
