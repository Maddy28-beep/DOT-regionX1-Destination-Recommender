<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Destination cards show the entry fee as a price ("150–450 / person") when one is known, the
 * way hotel cards show their nightly rate, and fall back to the price-band meter otherwise.
 */
class DestinationCardEntryFeeTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, ?float $min, ?float $max, string $tier = 'Mid-range'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => $tier,
            'entry_fee_min' => $min, 'entry_fee_max' => $max,
        ]);
    }

    public function test_a_range_a_single_price_and_the_unknown_cases(): void
    {
        $this->assertSame('150–450 / person', $this->destination('Range Park', 150, 450)->posterPriceAmount());
        $this->assertSame('150 / person', $this->destination('Flat Park', 150, 150)->posterPriceAmount());
        $this->assertSame('1,500 / person', $this->destination('Pricey Park', 1500, 1500)->posterPriceAmount());
        $this->assertSame('50 max / person', $this->destination('Beach', 0, 50)->posterPriceAmount());
        $this->assertNull($this->destination('Free Park', 0, 0, 'Free')->posterPriceAmount());
        $this->assertNull($this->destination('Unknown Park', null, null)->posterPriceAmount());
    }

    public function test_the_listing_page_shows_the_fee_on_the_card(): void
    {
        $this->destination('Eden Nature Park', 150, 450);

        $this->get('/destinations')->assertOk()
            ->assertSee('150–450 / person')
            ->assertSee('dpost-price__amount', false);
    }

    public function test_a_free_place_still_says_free_entry_and_an_unknown_fee_keeps_the_meter(): void
    {
        $this->destination('Peoples Park', 0, 0, 'Free');
        $this->destination('Mystery Cave', null, null, 'Mid-range');

        $html = $this->get('/destinations')->assertOk()->assertSee('Free entry')->getContent();

        $this->assertStringNotContainsString('/ person', $html);
        $this->assertStringContainsString('dpost-price', $html);
    }
}
