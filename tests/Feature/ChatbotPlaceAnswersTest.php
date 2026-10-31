<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assistant answers a question about one named place from the listing itself: where it is, what it costs, and
 * above all whether it is open or accredited. These run the rule-based answers (no model key).
 */
class ChatbotPlaceAnswersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.key' => null]);
    }

    private function place(string $name, array $overrides = []): Destination
    {
        return Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Malagos, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'entry_fee_min' => 150, 'entry_fee_max' => 150,
            'hours' => '8:00 AM–5:00 PM',
        ]);
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    public function test_a_question_with_part_of_a_name_finds_the_place_and_gives_the_facts(): void
    {
        $this->place('Philippine Eagle Center');
        $this->place('Eden Nature Park', ['type' => 'Nature']);

        $reply = $this->ask('Where can I see the Philippine eagle?');

        $this->assertSame('place', $reply['intent']);
        $this->assertStringContainsString('Philippine Eagle Center is in Malagos, Davao City', $reply['response']);
        $this->assertStringContainsString('Hours: 8:00 AM–5:00 PM', $reply['response']);
        $this->assertStringContainsString('₱150 / person', $reply['response']);
        $this->assertSame(['Philippine Eagle Center'], array_column($reply['cards'], 'name'));
    }

    public function test_a_bare_name_works_too(): void
    {
        $this->place('Philippine Eagle Center');

        $this->assertSame('place', $this->ask('eagle center')['intent']);
    }

    public function test_a_closed_place_is_reported_as_closed_not_recommended(): void
    {
        $this->place("People's Park", ['operating_status' => 'temporarily_closed', 'closure_reason' => 'Repairs']);

        $reply = $this->ask("Is People's Park open?");

        $this->assertStringContainsString('Temporarily closed', $reply['response']);
        $this->assertStringContainsString('Repairs', $reply['response']);
        $this->assertStringNotContainsString('Hours:', $reply['response']);
    }

    public function test_a_place_that_is_not_accredited_is_not_recommended_and_gets_no_card(): void
    {
        $this->place('Belviz Farm', ['is_accredited' => false]);

        $reply = $this->ask('Is Belviz Farm open?');

        $this->assertStringContainsString('not shown as DOT-accredited', $reply['response']);
        $this->assertSame([], $reply['cards']);
    }

    public function test_asking_about_accreditation_gets_a_plain_yes(): void
    {
        $park = $this->place('Eden Nature Park');
        \App\Models\AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $park->id, 'accreditation_number' => 'DOT-R11-TST-00001-2026',
            'status' => 'Active', 'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01', 'is_placeholder' => false]);

        $this->assertStringStartsWith('Yes, Eden Nature Park is DOT-accredited.', $this->ask('Is Eden Nature Park accredited?')['response']);
    }

    public function test_a_place_with_no_accreditation_record_is_never_called_accredited(): void
    {
        $this->place('Eden Nature Park');

        $reply = $this->ask('Is Eden Nature Park accredited?')['response'];

        $this->assertStringContainsString('not yet verified', $reply);
        $this->assertStringNotContainsString('is DOT-accredited', $reply);
    }

    public function test_a_greeting_is_not_mistaken_for_a_place_called_hello(): void
    {
        TourOperator::create([
            'slug' => 'hello-world-tour', 'name' => 'Hello World Tour, Inc.', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'specialization' => 'General Travel & Tours',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);

        $this->assertSame('greeting', $this->ask('hello')['intent']);
    }

    public function test_a_closed_place_is_left_out_of_the_lists_it_would_have_appeared_in(): void
    {
        $this->place('Open Falls', ['type' => 'Nature']);
        $this->place('Shut Falls', ['type' => 'Nature', 'operating_status' => 'closed']);

        $names = array_column($this->ask('waterfall things to do')['cards'], 'name');

        $this->assertContains('Open Falls', $names);
        $this->assertNotContains('Shut Falls', $names);
    }

    public function test_when_nothing_fits_the_assistant_says_what_to_ask_and_shows_places(): void
    {
        $this->place('Eden Nature Park', ['type' => 'Nature']);

        $reply = $this->ask('qwerty zxcv');

        $this->assertStringContainsString('You could ask things like', $reply['response']);
        $this->assertSame(['Eden Nature Park'], array_column($reply['cards'], 'name'));
    }

    public function test_an_open_question_without_a_place_asks_which_place(): void
    {
        $this->assertStringContainsString('Which place do you mean?', $this->ask('is it open on sunday')['response']);
    }
}
