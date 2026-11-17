<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_price_tiers_show_one_midpoint_category_without_changing_saved_classification(): void
    {
        $stay = new Accommodation(['type' => 'Condominium Unit']);
        $this->assertSame(['label' => 'Mid-range', 'estimated' => true], \App\Support\StayPricing::tier($stay));
        $this->assertNull($stay->price_tier);
        foreach ([2499 => 'Budget-Friendly', 2500 => 'Mid-range', 6999 => 'Mid-range', 7000 => 'Premium'] as $rate => $label) {
            $stay->price_per_night = $rate;
            $this->assertSame(['label' => $label, 'estimated' => true], \App\Support\StayPricing::tier($stay));
            $this->assertEquals($rate, $stay->nightlyPricing()['min']);
        }
        $stay->price_tier = 'Budget-Friendly';
        $this->assertSame(['label' => 'Budget-Friendly', 'estimated' => false], \App\Support\StayPricing::tier($stay));
    }

    public function test_all_property_types_have_explicit_estimates_without_mutating_rates(): void
    {
        foreach (['Hotel', 'Apartment Hotel', 'Condominium Unit', 'Homestay', 'Beach Resort', 'Resort', 'Unknown'] as $type) {
            $stay = new Accommodation(['name' => 'Unpriced stay', 'type' => $type]);
            $range = $stay->nightlyPricing();
            $this->assertSame('estimated', $range['kind']);
            $this->assertGreaterThan(0, $range['min']);
            $this->assertGreaterThan($range['min'], $range['max']);
            $this->assertStringContainsString('not a quote', $range['note']);
            $this->assertNull($stay->price_per_night);
            $this->assertNull($stay->price_per_day);
            $this->assertNull($stay->posterPriceAmount(), 'Chat price facts must not receive presentation estimates.');
        }
    }

    public function test_published_range_has_source_and_room_occupancy_context(): void
    {
        $stay = new Accommodation(['name' => 'Paradise Island Park and Beach Resort']);
        $range = $stay->nightlyPricing();
        $this->assertSame('published', $range['kind']);
        $this->assertSame('7,000–8,200', $range['amount']);
        $this->assertSame('https://paradiseislandsamal.com/rooms/', $range['source']);
        $this->assertStringContainsString('two guests', $range['note']);
    }

    public function test_recorded_rates_including_zero_override_other_ranges(): void
    {
        foreach ([0, 4500] as $rate) {
            $stay = new Accommodation(['name' => 'Paradise Island Park and Beach Resort', 'price_per_night' => $rate, 'price_per_day' => 500]);
            $range = $stay->nightlyPricing();
            $this->assertSame('recorded', $range['kind']);
            $this->assertEquals($rate, $range['min']);
            $this->assertEquals($rate, $range['max']);
            $this->assertSame('500.00', $stay->price_per_day);
        }
    }

    public function test_estimates_render_and_match_overnight_budgets_without_mutating_recorded_rates(): void
    {
        $stay = Accommodation::create(['name' => 'Unpriced Hotel', 'slug' => 'unpriced-hotel', 'type' => 'Hotel', 'location' => 'Davao City', 'rating' => 4, 'review_count' => 1, 'is_accredited' => true]);
        $this->get('/accommodations')->assertOk()->assertSee('Estimated range')->assertSee('1,500–7,000');
        $this->get('/accommodations/'.$stay->slug)->assertOk()->assertSee('Estimated / night')->assertSee('not a quote from this property')
            ->assertSee('Estimated price tier')->assertSee('Mid-range')->assertDontSee('Budget–Premium')->assertDontSee('Not classified');
        $this->assertSame(1, $this->get('/accommodations?price_max=7000')->viewData('accommodations')->total());
        $this->assertNull($stay->fresh()->price_per_night);
    }

    public function test_overnight_budget_uses_inclusive_overlap_for_published_estimated_and_recorded_ranges(): void
    {
        foreach ([['Published', 'Paradise Island Park and Beach Resort', null], ['Estimated', 'Estimate Hotel', null], ['Recorded', 'Recorded Hotel', 9000]] as [$slug, $name, $rate]) {
            Accommodation::create(['name' => $name, 'slug' => strtolower($slug), 'type' => 'Hotel', 'location' => 'Davao City', 'is_accredited' => true, 'price_per_night' => $rate, 'price_per_day' => 300]);
        }
        $result = $this->get('/accommodations?stay_mode=overnight&price_min=7000&price_max=7000')->assertOk();
        $this->assertSame(['Estimated', 'Published'], $result->viewData('accommodations')->pluck('slug')->map(fn ($s) => ucfirst($s))->sort()->values()->all());
        $result->assertSee('Nightly budgets match overlapping price ranges')->assertSee('Estimated range')->assertSee('Published room range');
        $this->assertSame(1, $this->get('/accommodations?price_min=7000.01&price_max=8200')->viewData('accommodations')->total());
        $this->assertSame(0, $this->get('/accommodations?price_max=1499')->viewData('accommodations')->total());
        $this->assertSame(1, $this->get('/accommodations?price_min=8500')->viewData('accommodations')->total());
        $this->assertSame(0, $this->get('/accommodations?price_min=7000&price_max=1500')->viewData('accommodations')->total());
        $daily = $this->get('/accommodations?stay_mode=day_use&price_max=300')->assertOk();
        $this->assertSame(3, $daily->viewData('accommodations')->total());
        $daily->assertDontSee('Estimated range')->assertDontSee('Nightly budgets match');
        $this->assertSame(0, $this->get('/accommodations?stay_mode=day_use&price_min=1500')->viewData('accommodations')->total());
    }
}
