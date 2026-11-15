<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Promotion;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A stay's live promo: the card on its page, the badge and Deals chip on the listing, and the homepage row. */
class PromoDealsTest extends TestCase
{
    use RefreshDatabase;

    private function stay(string $name = 'Acacia Hotel Davao', array $extra = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ], $extra));
    }

    private function promo(Accommodation $stay, array $extra = []): Promotion
    {
        return Promotion::create(array_merge([
            'listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'title' => 'Weekday staycation', 'code' => null, 'description' => null,
            'starts_at' => null, 'ends_at' => null,
        ], $extra));
    }

    public function test_the_validity_line_says_what_the_dates_mean(): void
    {
        $this->assertSame('No end date — shown until removed', (new Promotion)->validityLine());
        $this->assertSame('Until 28 Feb 2027', (new Promotion(['ends_at' => '2027-02-28']))->validityLine());
        $this->assertSame('From 1 Dec 2026', (new Promotion(['starts_at' => '2026-12-01']))->validityLine());
        $this->assertSame('1 Dec 2026 – 28 Feb 2027', (new Promotion(['starts_at' => '2026-12-01', 'ends_at' => '2027-02-28']))->validityLine());
    }

    public function test_status_is_showing_scheduled_or_ended_by_the_dates(): void
    {
        $today = now('Asia/Manila');

        $this->assertSame('showing', (new Promotion)->status());
        $this->assertSame('showing', (new Promotion(['starts_at' => $today->copy()->subDay(), 'ends_at' => $today->copy()->addDay()]))->status());
        $this->assertSame('scheduled', (new Promotion(['starts_at' => $today->copy()->addDays(3)]))->status());
        $this->assertSame('ended', (new Promotion(['ends_at' => $today->copy()->subDay()]))->status());
    }

    public function test_the_stay_page_shows_the_promo_in_the_owners_words_with_its_code(): void
    {
        $stay = $this->stay('Acacia Hotel Davao', ['website_url' => 'https://acacia.example.com']);
        $this->promo($stay, ['title' => 'Breakfast for two', 'code' => 'STAYDVO', 'description' => "Sunday to Thursday.\nShow this code.", 'ends_at' => '2099-02-28']);

        $this->get(route('accommodations.show', $stay))->assertOk()
            ->assertSee('Your Promo')->assertSee('Showing')->assertSee('Breakfast for two')
            ->assertSee('Sunday to Thursday.')->assertSee('STAYDVO')->assertSee('Until 28 Feb 2099')
            ->assertSee('Ask about this promo')->assertSee('https://acacia.example.com', false)
            ->assertDontSee('% off', false);
    }

    public function test_without_a_code_or_a_link_those_parts_are_left_out(): void
    {
        $stay = $this->stay();
        $this->promo($stay, ['title' => 'Free late checkout']);

        $this->get(route('accommodations.show', $stay))->assertOk()
            ->assertSee('Free late checkout')->assertSee('No end date — shown until removed')
            ->assertDontSee('data-copy-code', false)->assertDontSee('Ask about this promo');
    }

    public function test_a_promo_that_is_not_showing_is_never_public(): void
    {
        $stay = $this->stay();
        $this->promo($stay, ['title' => 'Old deal', 'ends_at' => now('Asia/Manila')->subDays(2)]);
        $this->promo($stay, ['title' => 'Coming deal', 'starts_at' => now('Asia/Manila')->addDays(5)]);

        $this->get(route('accommodations.show', $stay))->assertOk()->assertDontSee('Old deal')->assertDontSee('Coming deal')->assertDontSee('Your Promo');
        $this->get(route('accommodations.index'))->assertOk()->assertDontSee('Old deal')->assertDontSee('Coming deal')->assertDontSee('chip--deal', false);
        $this->get('/')->assertOk()->assertDontSee('Special offers in Davao');
    }

    public function test_the_listing_marks_stays_with_a_promo_and_the_deals_chip_narrows_to_them(): void
    {
        $with = $this->stay('Acacia Hotel Davao');
        $this->stay('Quiet Inn');
        $this->promo($with, ['title' => 'Weekday staycation']);

        $page = $this->get(route('accommodations.index'))->assertOk()->assertSee('chip--deal', false);
        $this->assertSame(1, substr_count($page->getContent(), 'class="dcard__promo"'), 'only the stay with a promo wears the badge');
        $page->assertSee('Weekday staycation');

        $deals = $this->get(route('accommodations.index', ['deals' => 1]))->assertOk()->original->getData()['accommodations'];
        $this->assertSame(['Acacia Hotel Davao'], collect($deals->items())->pluck('name')->all());
    }

    public function test_the_homepage_row_lists_stays_with_a_live_promo_and_links_to_them(): void
    {
        $stay = $this->stay();
        $this->promo($stay, ['title' => 'Breakfast for two', 'code' => 'STAYDVO']);
        $this->stay('Quiet Inn');

        $this->get('/')->assertOk()
            ->assertSee('Limited-Time Offers')->assertSee('Special offers in Davao')->assertSee('Breakfast for two')
            ->assertSee('Acacia Hotel Davao')->assertSee('STAYDVO')->assertSee('See all deals')
            ->assertSee(route('accommodations.show', $stay), false)->assertSee(route('accommodations.index', ['deals' => 1]), false)
            ->assertDontSee('Quiet Inn');
    }

    public function test_home_offers_appear_once_between_search_and_counts(): void
    {
        $this->promo($this->stay(), ['title' => 'Current stay offer']);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="deals"'));
        $this->assertLessThan(strpos($html, 'id="deals"'), strpos($html, 'data-hero-search'));
        $this->assertLessThan(strpos($html, 'class="stats"'), strpos($html, 'id="deals"'));
    }

    public function test_home_counts_exclude_hidden_and_sample_listings_and_unused_regions(): void
    {
        $this->stay('Real Stay');
        $this->stay('Archived Stay', ['archived_at' => now()]);
        $this->stay('Hidden Stay', ['is_accredited' => false]);
        $sampleRegion = Region::create(['name' => 'Sample-only area']);
        $this->stay('Sample Demo Stay [SAMPLE]', ['region_id' => $sampleRegion->id]);
        $placeholder = $this->stay('Placeholder Stay', ['region_id' => $sampleRegion->id]);
        \App\Models\AccreditationRecord::create([
            'listing_kind' => 'accommodation', 'listing_id' => $placeholder->id,
            'accreditation_number' => 'SAMPLE-001', 'is_placeholder' => true, 'status' => 'Active',
        ]);
        Region::create(['name' => 'Unused area']);
        $stats = $this->get('/')->assertOk()->original->getData()['stats'];
        $this->assertSame(1, $stats['accommodations']);
        $this->assertSame(0, $stats['destinations']);
        $this->assertSame(1, $stats['regions']);
    }

    public function test_an_archived_or_unaccredited_stay_does_not_show_its_deal(): void
    {
        $stay = $this->stay('Hidden Inn', ['is_accredited' => false]);
        $this->promo($stay, ['title' => 'Secret deal']);

        $this->get('/')->assertOk()->assertDontSee('Secret deal');
        $this->get(route('accommodations.index'))->assertOk()->assertDontSee('chip--deal', false);
    }
}
