<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Found by the QA sweep (D14): on the model path, a model reply that named a closed place had its false "open daily" claim
 * removed but was then described from the record with no word about the closure, so the visitor was left to believe the
 * place was open. A closed place now carries the record's own notice, and the model is not offered closed places at all.
 */
class ChatbotClosedPlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.key' => 'test-key-not-real', 'services.groq.model' => 'test-model']);
    }

    private function place(string $name, string $status = 'open'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Beach', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'hours' => '6:00 AM-6:00 PM',
            'latitude' => 7.15, 'longitude' => 125.40, 'operating_status' => $status,
        ]);
    }

    private function ask(string $modelReply): string
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $modelReply]]]], 200)]);

        return (string) $this->postJson('/chatbot/message', ['message' => 'Suggest something nice to do around Davao'])->json('response');
    }

    public function test_a_closed_place_named_by_the_model_is_followed_by_the_closure_notice(): void
    {
        $this->place('Silver Cove', 'closed');

        $reply = $this->ask('Silver Cove is open daily 8am to 10pm and I strongly recommend it for tomorrow.');

        $this->assertStringNotContainsString('open daily', $reply);
        $this->assertStringContainsString('Closed for good', $reply);
    }

    public function test_a_temporarily_closed_place_says_so_too(): void
    {
        $this->place('Silver Cove', 'temporarily_closed');

        $this->assertStringContainsString('Temporarily closed', $this->ask('Try Silver Cove, a lovely spot.'));
    }

    public function test_an_open_place_gets_no_closure_notice(): void
    {
        $this->place('Silver Cove');

        $reply = $this->ask('Silver Cove is a place you could visit.');

        $this->assertStringNotContainsString('closed', strtolower($reply));
    }

    public function test_the_model_is_not_offered_a_closed_place(): void
    {
        $this->place('Silver Cove', 'closed');
        $this->place('Bright Bay');

        $this->ask('Bright Bay is a place you could visit.');

        Http::assertSent(function ($request) {
            $prompt = json_encode($request->data());

            return str_contains($prompt, 'Bright Bay') && ! str_contains($prompt, 'Silver Cove');
        });
    }

    public function test_a_closed_place_gets_no_card_but_an_open_one_does(): void
    {
        $this->place('Silver Cove', 'closed');
        $this->place('Bright Bay');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Try Silver Cove or Bright Bay.']]]], 200)]);

        $cards = collect($this->postJson('/chatbot/message', ['message' => 'Suggest something nice to do around Davao'])->json('cards'));

        $this->assertSame(['Bright Bay'], $cards->pluck('name')->all());
    }

    public function test_asking_about_a_closed_place_by_name_says_it_is_closed_and_shows_no_card(): void
    {
        config(['services.groq.key' => null]);
        $this->place('Silver Cove', 'closed');

        $reply = $this->postJson('/chatbot/message', ['message' => 'Is Silver Cove open?']);

        $this->assertStringContainsString('losed', (string) $reply->json('response'));
        $this->assertSame([], $reply->json('cards'));
    }
}
