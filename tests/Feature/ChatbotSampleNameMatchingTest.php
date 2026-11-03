<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Region;
use App\Services\Chatbot\ChatbotReplyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The live re-check on production found that most sample destinations are stored with a trailing " [SAMPLE]" (and some
 * with a parenthetical), while a model writes the name without it. The guard then did not recognise the listing, so it
 * neither labelled it nor checked its sentences. These tests use the stored names as they are in production.
 */
class ChatbotSampleNameMatchingTest extends TestCase
{
    use RefreshDatabase;

    private const TUKA = 'Tuka Beach ("Little Boracay of Santa Maria") [SAMPLE]';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.key' => 'test-key-not-a-real-key-123', 'services.groq.model' => 'test-model']);
    }

    private function dest(string $name, bool $sample, array $over = []): Destination
    {
        $d = Destination::create($over + [
            'slug' => str($name)->slug()->toString().'-'.(++$this->n), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Beach', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly', 'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id,
            'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-R11-').(++$this->n), 'status' => 'Active',
            'issue_date' => now()->subMonths(3), 'expiration_date' => now()->addYear(), 'is_placeholder' => $sample,
        ]);

        return $d;
    }

    private function modelSays(string $text): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => $text]]]])]);
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    public function test_name_variants_drop_a_trailing_marker_and_parenthetical_but_keep_the_stored_name(): void
    {
        $guard = app(ChatbotReplyGuard::class);

        $this->assertSame(['Lake Leonard [SAMPLE]', 'Lake Leonard'], $guard->nameVariants('Lake Leonard [SAMPLE]'));
        $this->assertSame([self::TUKA, 'Tuka Beach ("Little Boracay of Santa Maria")', 'Tuka Beach'], $guard->nameVariants(self::TUKA));
        $this->assertSame(['Philippine Eagle Center'], $guard->nameVariants('Philippine Eagle Center'));
        $this->assertSame(['Centennial Balite Tree (500-Year-Old Balite) [SAMPLE]', 'Centennial Balite Tree (500-Year-Old Balite)', 'Centennial Balite Tree'],
            $guard->nameVariants('Centennial Balite Tree (500-Year-Old Balite) [SAMPLE]'));
    }

    public function test_a_sample_listing_stored_with_a_suffix_is_labelled_when_the_model_leaves_the_suffix_off(): void
    {
        $this->dest(self::TUKA, true);
        // The production replies for the failing prompts.
        $this->modelSays('Tuka Beach is listed as a destination in the Davao Region. For specific activities, check the site.');

        $text = $this->ask('Any suggestions for activities?')['response'];

        $this->assertStringContainsString('Tuka Beach (sample data)', $text);
        $this->assertStringContainsString('test entries, not verified by DOT', $text);
    }

    public function test_unsupported_descriptors_about_such_a_listing_are_dropped(): void
    {
        $this->dest(self::TUKA, true);
        $this->modelSays('For a quick beach escape, try Tuka Beach. It’s a popular spot in the Davao Region. Enjoy the sand and sea for a relaxed weekend!');

        $text = $this->ask('Can you recommend a beach for a weekend getaway?')['response'];

        $this->assertStringNotContainsString('popular', $text);
        $this->assertStringNotContainsString('sand and sea', $text);
        $this->assertStringContainsString('Tuka Beach (sample data)', $text);
    }

    public function test_lake_leonard_without_its_suffix_cannot_be_called_tranquil(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true, ['type' => 'Lake']);
        $this->modelSays("Here are a few nature spots:\n- Lake Leonard – a tranquil lake area.");

        $lines = explode("\n", $this->ask('What nature spots should I see?')['response']);

        $this->assertSame('Here are a few nature spots:', $lines[0]);
        $this->assertStringNotContainsString('tranquil', implode(' ', $lines));
        $this->assertStringContainsString('Lake Leonard [SAMPLE] – lake, in Davao City.', implode("\n", $lines));
    }

    public function test_the_label_cannot_be_avoided_by_dropping_the_suffix_in_any_mention(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays('Try Lake Leonard. Lake Leonard is easy to find, and Lake Leonard [SAMPLE] is listed.');

        $text = $this->ask('lakes?')['response'];

        $this->assertSame(2, substr_count($text, 'Lake Leonard (sample data)'));
        $this->assertStringNotContainsString('[SAMPLE] (sample data)', $text);
    }

    public function test_a_mention_that_already_carries_the_stored_marker_is_not_labelled_twice(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays('Lake Leonard [SAMPLE] is one option.');

        $text = $this->ask('lakes?')['response'];

        $this->assertStringContainsString('Lake Leonard [SAMPLE] is one option.', $text);
        $this->assertStringNotContainsString('(sample data)', explode("\n", $text)[0]);
    }

    public function test_a_model_that_writes_its_own_label_after_the_short_name_is_not_doubled(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays('Lake Leonard (sample data, not DOT-verified) is one option.');

        $this->assertSame(0, substr_count(explode("\n", $this->ask('lakes?')['response'])[0], 'Lake Leonard (sample data) '));
    }

    public function test_a_genuine_listing_is_never_labelled_and_a_sample_one_in_the_same_reply_is(): void
    {
        $this->dest('Apo Golf and Country Club', false, ['type' => 'Golf']);
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays('You could visit Apo Golf and Country Club or Lake Leonard.');

        $text = $this->ask('where should I go?')['response'];

        $this->assertStringContainsString('Apo Golf and Country Club or', $text);
        $this->assertStringNotContainsString('Apo Golf and Country Club (', $text);
        $this->assertStringContainsString('Lake Leonard (sample data)', $text);
    }

    public function test_a_genuine_listing_whose_name_contains_a_sample_listings_short_name_is_not_labelled_sample(): void
    {
        $this->dest('Carolina Lake [SAMPLE]', true);
        $this->dest('Carolina Lake Resort', false, ['type' => 'Resort']);
        $this->modelSays('Carolina Lake Resort is a place to stay.');

        $text = $this->ask('where to stay?')['response'];

        $this->assertStringNotContainsString('sample', strtolower($text));
        $this->assertStringContainsString('Carolina Lake Resort is a place to stay.', $text);
    }

    public function test_several_sample_listings_in_one_reply_are_each_labelled_and_a_list_keeps_its_lines(): void
    {
        $this->dest(self::TUKA, true);
        $this->dest('Olanivan Island [SAMPLE]', true);
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->dest('Apo Golf and Country Club', false, ['type' => 'Golf']);
        $this->modelSays("Some ideas:\n- Tuka Beach\n- Olanivan Island\n- Lake Leonard\n- Apo Golf and Country Club");

        $lines = explode("\n", $this->ask('ideas?')['response']);

        $this->assertSame('Some ideas:', $lines[0]);
        $this->assertSame('- Tuka Beach (sample data)', $lines[1]);
        $this->assertSame('- Olanivan Island (sample data)', $lines[2]);
        $this->assertSame('- Lake Leonard (sample data)', $lines[3]);
        $this->assertSame('- Apo Golf and Country Club', $lines[4]);
    }

    public function test_a_claim_in_a_sentence_that_says_it_is_still_dropped(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays("Lake Leonard is an option.\nIt’s a famous, peaceful place with lots of fish.");

        $text = $this->ask('lakes?')['response'];

        $this->assertStringNotContainsString('famous', $text);
        $this->assertStringNotContainsString('peaceful', $text);
        $this->assertStringNotContainsString('fish', $text);
    }

    public function test_an_invented_price_or_time_for_such_a_listing_is_still_dropped(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true, ['entry_fee_min' => 50, 'entry_fee_max' => 50]);
        $this->modelSays('Lake Leonard costs ₱900 to enter and opens at 5:00 AM. The entrance fee is ₱50 / person.');

        $text = $this->ask('lake fee?')['response'];

        $this->assertStringNotContainsString('₱900', $text);
        $this->assertStringNotContainsString('5:00 AM', $text);
        $this->assertStringContainsString('₱50 / person', $text);
    }

    public function test_a_word_inside_another_word_is_not_a_mention(): void
    {
        $this->dest('Oak Island [SAMPLE]', true);
        $this->modelSays('Plan your trip with Soak Islands of calm.');

        $this->assertStringNotContainsString('sample', strtolower($this->ask('hello there friend')['response']));
    }

    public function test_the_cards_under_a_reply_now_include_the_suffixed_sample_listing_with_its_stored_name(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true);
        $this->modelSays('Lake Leonard is an option.');

        $cards = $this->ask('lakes?')['cards'];

        $this->assertSame(['Lake Leonard [SAMPLE]'], array_column($cards, 'name'));
        $this->assertTrue($cards[0]['sample']);
    }

    public function test_a_dropped_bullet_is_replaced_in_place_by_the_listings_record_and_a_genuine_listing_gets_no_praise(): void
    {
        $this->dest('Lake Leonard [SAMPLE]', true, ['type' => 'Lake']);
        $this->dest('Apo Golf and Country Club', false, ['type' => 'Golf']);
        $this->modelSays("Nature spots:\n- **Lake Leonard** – a tranquil lake area.\n- **Apo Golf and Country Club** is also a great place to unwind.");

        $lines = explode("\n", $this->ask('nature spots?')['response']);

        $this->assertSame('Nature spots:', $lines[0]);
        $this->assertSame('- Lake Leonard [SAMPLE] – lake, in Davao City.', $lines[1]);
        $this->assertSame('- Apo Golf and Country Club – golf, in Davao City.', $lines[2]);
    }

    public function test_the_stored_name_and_provenance_are_unchanged(): void
    {
        $d = $this->dest(self::TUKA, true);
        $this->modelSays('Tuka Beach is an option.');
        $this->ask('beach?');

        $this->assertSame(self::TUKA, $d->fresh()->name);
        $this->assertTrue((bool) AccreditationRecord::where('listing_id', $d->id)->value('is_placeholder'));
    }
}
