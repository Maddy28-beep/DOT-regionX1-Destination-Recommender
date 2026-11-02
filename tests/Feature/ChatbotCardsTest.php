<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The chat answers with listing cards (photo, name, place, price and a link) under the sentence that introduces
 * them, instead of a bulleted list of web addresses.
 */
class ChatbotCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.key' => null]);   // the rule-based answers
    }

    private function eagleCenter(): Destination
    {
        $destination = Destination::create([
            'slug' => 'philippine-eagle-center', 'name' => 'Philippine Eagle Center', 'location' => 'Malagos, Davao City',
            'region_id' => Region::create(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'entry_fee_min' => 150, 'entry_fee_max' => 150,
        ]);
        ListingPhoto::create(['listing_kind' => 'destination', 'listing_id' => $destination->id, 'path' => 'photos/eagle.jpg', 'is_primary' => true, 'sort_order' => 0]);

        return $destination;
    }

    public function test_a_listing_answer_carries_cards_with_a_photo_a_place_a_price_and_a_link(): void
    {
        $destination = $this->eagleCenter();

        $card = $this->postJson(route('chatbot.respond'), ['message' => 'Where can I see the eagle? things to do'])
            ->assertOk()
            ->json('cards.0');

        $this->assertSame('Philippine Eagle Center', $card['name']);
        $this->assertSame('Malagos, Davao City', $card['where']);
        $this->assertSame('₱150 / person', $card['meta']);
        $this->assertStringContainsString('photos/eagle.jpg', $card['image']);
        $this->assertSame(route('destinations.show', $destination), $card['url']);
    }

    public function test_the_sentence_no_longer_lists_web_addresses(): void
    {
        $this->eagleCenter();

        $response = $this->postJson(route('chatbot.respond'), ['message' => 'things to do'])->json('response');

        $this->assertStringNotContainsString('http', $response);
        $this->assertStringContainsString('Tap one', $response);
    }

    public function test_an_answer_that_is_not_about_listings_has_no_cards(): void
    {
        $this->eagleCenter();

        $this->postJson(route('chatbot.respond'), ['message' => 'hello'])->assertOk()->assertJsonPath('cards', []);
    }

    public function test_a_sample_listing_is_never_introduced_as_dot_accredited_and_its_card_is_flagged(): void
    {
        $destination = $this->eagleCenter();
        AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $destination->id, 'accreditation_number' => 'SAMPLE-9001', 'status' => 'Active', 'is_placeholder' => true]);

        $response = $this->postJson(route('chatbot.respond'), ['message' => 'things to do'])->assertOk();
        $text = $response->json('response');
        $this->assertStringNotContainsString('DOT-accredited', $text);
        $this->assertStringContainsString('Sample data', $text);
        $this->assertTrue($response->json('cards.0.sample'));
    }

    public function test_an_official_only_answer_keeps_dot_accredited_and_unflagged_cards(): void
    {
        $this->eagleCenter();

        $response = $this->postJson(route('chatbot.respond'), ['message' => 'things to do'])->assertOk();
        $this->assertStringContainsString('DOT-accredited', $response->json('response'));
        $this->assertFalse($response->json('cards.0.sample'));
    }

    public function test_cards_are_capped_at_three(): void
    {
        foreach (range(1, 5) as $i) {
            Destination::create([
                'slug' => "park-$i", 'name' => "Park Number $i", 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
                'type' => 'Park', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
        }

        $this->assertCount(3, $this->postJson(route('chatbot.respond'), ['message' => 'a park to visit'])->json('cards'));
    }

    public function test_the_trip_suggestion_chip_is_understood_as_a_trip_question(): void
    {
        $this->postJson(route('chatbot.respond'), ['message' => 'Plan a 3-day trip'])
            ->assertOk()
            ->assertJsonPath('intent', 'itinerary');
    }

    public function test_asking_to_plan_a_trip_points_at_the_trip_planner(): void
    {
        $this->postJson(route('chatbot.respond'), ['message' => 'Can you plan a trip for me?'])
            ->assertOk()
            ->assertJsonPath('action.label', 'Plan my trip')
            ->assertJsonPath('action.url', route('plan.choose'));
    }

    public function test_other_questions_carry_no_action(): void
    {
        $this->postJson(route('chatbot.respond'), ['message' => 'hello'])->assertOk()->assertJsonPath('action', null);
    }

    public function test_the_system_prompt_forbids_invented_listing_facts_and_marks_sample_data(): void
    {
        $destination = $this->eagleCenter();
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $destination->id,
            'accreditation_number' => 'SAMPLE-9999', 'status' => 'Active', 'is_placeholder' => true,
        ]);

        $prompt = (new ReflectionMethod(ChatbotService::class, 'buildSystemPrompt'))
            ->invoke(app(ChatbotService::class));

        // The retrieval context carries only names/types, so the model must be told not to embellish them
        // (a live answer once described a nature park as a "wildlife refuge" from prior knowledge alone).
        $this->assertStringContainsString('Do not add descriptive facts about a listing', $prompt);
        $this->assertStringContainsString('Philippine Eagle Center [sample data, not DOT-verified]', $prompt);
        $this->assertStringContainsString('never call it DOT-accredited or verified', $prompt);
    }
}
