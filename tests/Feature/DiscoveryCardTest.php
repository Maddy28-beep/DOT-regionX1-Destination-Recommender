<?php

namespace Tests\Feature;

use App\Http\Controllers\ExitSurveyController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Support\BudgetRanges;
use App\Support\MealTimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The homepage discovery card: one short form per tab, a budget dropdown whose meaning depends on the tab,
 * Any / Overnight / Day use, guest counters, and meals. Each filter is checked against the listing page it
 * lands on, since a box that sends something the page ignores would be a lie.
 */
class DiscoveryCardTest extends TestCase
{
    use RefreshDatabase;

    private function region(): Region
    {
        return Region::firstOrCreate(['name' => 'Davao City']);
    }

    private function stay(string $slug, ?float $price, array $extra = []): Accommodation
    {
        return Accommodation::create($extra + [
            'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
            'region_id' => $this->region()->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_per_night' => $price,
        ]);
    }

    private function place(string $slug, ?float $feeMin, ?float $feeMax): Destination
    {
        return Destination::create([
            'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
            'region_id' => $this->region()->id, 'type' => 'Wildlife', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'entry_fee_min' => $feeMin, 'entry_fee_max' => $feeMax,
        ]);
    }

    private function eatery(string $slug, ?string $hours, array $extra = []): Restaurant
    {
        return Restaurant::create($extra + [
            'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
            'region_id' => $this->region()->id, 'cuisine_type' => 'Filipino', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'opening_hours' => $hours,
        ]);
    }

    private function go(array $query): string
    {
        return urldecode((string) $this->get('/search?'.http_build_query($query))->assertRedirect()->headers->get('Location'));
    }

    // ---- The card -------------------------------------------------------------------------------------

    public function test_no_tab_asks_the_questions_of_another(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach ([
            'destinations' => ['budget', 'where', 'interest'],
            'accommodations' => ['budget', 'where', 'stay_mode', 'from', 'to', 'guests'],
            'restaurants' => ['budget', 'meal', 'date', 'cuisine', 'where'],
            'packages' => ['budget', 'where', 'interest', 'duration', 'travelers'],
            'souvenir_centers' => ['where'],
        ] as $purpose => $boxes) {
            $this->assertMatchesRegularExpression('/value="'.$purpose.'" data-tab-fields="'.implode(' ', $boxes).'"/', $html);
        }
    }

    public function test_stays_offer_any_overnight_and_day_use_with_any_first(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<select id="search-stay-mode" name="stay_mode"[^>]*>\s*<option value="">Any<\/option>\s*<option value="overnight">Overnight<\/option>\s*<option value="day_use">Day use<\/option>\s*<\/select>/',
            $html
        );
    }

    public function test_four_guest_counters_start_at_zero_and_cannot_go_below_it(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['adults', 'children', 'seniors', 'pwd'] as $kind) {
            $this->assertMatchesRegularExpression('/name="'.$kind.'" value="0" readonly data-min="0" data-max="20"/', $html, "{$kind} counter");
            $this->assertStringContainsString('aria-label="Fewer ', $html);
        }
        $this->assertStringContainsString('Any group size', $html, 'Zero guests means no limit on the group.');

        // The server clamps whatever it is sent: no negative, no absurd count, nothing non-numeric.
        $stays = $this->go(['purpose' => 'accommodations', 'adults' => -4, 'children' => 'abc', 'seniors' => 99, 'pwd' => 2]);
        $this->assertStringNotContainsString('adults=', $stays);
        $this->assertStringNotContainsString('children=', $stays);
        $this->assertStringContainsString('seniors=20', $stays);
        $this->assertStringContainsString('pwd=2', $stays);
    }

    public function test_dining_offers_exactly_breakfast_lunch_and_dinner(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<select id="search-meal" name="meal"[^>]*>\s*<option value="">Any meal<\/option>\s*<option value="breakfast">Breakfast<\/option>\s*<option value="lunch">Lunch<\/option>\s*<option value="dinner">Dinner<\/option>\s*<\/select>/',
            $html
        );
        $this->assertStringNotContainsString('value="brunch"', $html);
        $this->assertStringNotContainsString('value="snack"', $html);
    }

    public function test_the_card_asks_for_nothing_the_site_does_not_record(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Halal', 'Vegetarian', 'Allergy', 'Buffet', 'Plated'] as $claim) {
            $this->assertStringNotContainsString('<option value="'.$claim, $html, "{$claim} is not stored on any restaurant, so it is not offered.");
        }
        $this->assertStringContainsString('Dietary needs and plated or buffet service are not recorded', file_get_contents(public_path('js/hero-search.js')),
            'The Dining tab says so instead of offering a filter it cannot apply.');
    }

    public function test_interests_are_the_ones_the_site_already_has(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="">Any interest</option>', $html);
        foreach (ExitSurveyController::ACTIVITIES as $interest) {
            $this->assertStringContainsString('<option value="'.e($interest).'">', $html);
        }
        preg_match('/<select id="interest"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertSame(count(ExitSurveyController::ACTIVITIES) + 1, substr_count($m[1], '<option'), 'Nothing beyond the existing interests plus Any.');
    }

    public function test_where_defaults_to_anywhere_in_region_xi(): void
    {
        $this->region();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select id="search-region" name="region_id"[^>]*>\s*<option value="">Anywhere in Region XI<\/option>/', $html);
    }

    // ---- Budget ---------------------------------------------------------------------------------------

    public function test_a_budget_choice_becomes_a_peso_range_for_the_catalogue(): void
    {
        $this->assertStringContainsString('price_max=1499.99', $this->go(['purpose' => 'accommodations', 'budget' => 'under-1500']));
        $this->assertStringContainsString('price_min=4000', $this->go(['purpose' => 'accommodations', 'budget' => '4000-up']));
        $this->assertStringContainsString('price_min=2500', $this->go(['purpose' => 'packages', 'budget' => '2500-5000']));
        $this->assertStringContainsString('price_min=101', $this->go(['purpose' => 'destinations', 'budget' => '101-300']));
        $this->assertStringContainsString('price_tier=Mid-range', $this->go(['purpose' => 'restaurants', 'budget' => 'level:Mid-range']));
    }

    public function test_any_budget_removes_every_price_limit(): void
    {
        foreach (['destinations', 'accommodations', 'restaurants', 'packages'] as $purpose) {
            $this->assertStringNotContainsString('price_', $this->go(['purpose' => $purpose, 'budget' => '']), $purpose);
            $this->assertStringNotContainsString('price_', $this->go(['purpose' => $purpose]), $purpose);
        }
    }

    public function test_custom_budget_accepts_a_minimum_only_a_maximum_only_or_both(): void
    {
        $both = $this->go(['purpose' => 'accommodations', 'budget' => 'custom', 'budget_min' => '1,500', 'budget_max' => '₱3000']);
        $this->assertStringContainsString('price_min=1500', $both);
        $this->assertStringContainsString('price_max=3000', $both);

        $min = $this->go(['purpose' => 'accommodations', 'budget' => 'custom', 'budget_min' => '800']);
        $this->assertStringContainsString('price_min=800', $min);
        $this->assertStringNotContainsString('price_max', $min);

        $max = $this->go(['purpose' => 'accommodations', 'budget' => 'custom', 'budget_max' => '2500.50']);
        $this->assertStringContainsString('price_max=2500.5', $max);
        $this->assertStringNotContainsString('price_min', $max);

        $neither = $this->go(['purpose' => 'accommodations', 'budget' => 'custom']);
        $this->assertStringNotContainsString('price_', $neither, 'Custom with both boxes empty is Any budget.');
    }

    public function test_a_minimum_above_the_maximum_or_a_non_number_is_refused(): void
    {
        foreach ([['3000', '1500'], ['abc', '100'], ['-5', '100'], ['1e9', ''], ['', '99999999']] as [$min, $max]) {
            $this->get('/search?'.http_build_query(['purpose' => 'packages', 'budget' => 'custom', 'budget_min' => $min, 'budget_max' => $max]))
                ->assertRedirect(route('home').'#search')
                ->assertSessionHas('error');
        }
    }

    public function test_a_custom_amount_is_ignored_when_another_choice_is_made(): void
    {
        $location = $this->go(['purpose' => 'accommodations', 'budget' => 'under-1500', 'budget_min' => '9000', 'budget_max' => '9500']);

        $this->assertStringContainsString('price_max=1499.99', $location);
        $this->assertStringNotContainsString('price_min', $location);
    }

    public function test_dining_and_souvenirs_offer_no_peso_budget_because_none_is_recorded(): void
    {
        $this->assertSame(['' => 'Any budget'], BudgetRanges::options('souvenir_centers'));
        $this->assertFalse(BudgetRanges::has('souvenir_centers'));
        $this->assertStringNotContainsString('price_', $this->go(['purpose' => 'souvenir_centers', 'budget' => 'custom', 'budget_min' => '5']));
        $this->assertStringNotContainsString('price_min', $this->go(['purpose' => 'restaurants', 'budget' => 'custom', 'budget_min' => '5']));
        $this->assertArrayNotHasKey(BudgetRanges::CUSTOM, BudgetRanges::options('restaurants'));
    }

    public function test_only_ranges_that_hold_a_listing_are_offered_on_the_card(): void
    {
        $this->stay('cheap-inn', 900);
        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<select id="search-budget-accommodations"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertStringContainsString('Under ₱1,500', $m[1]);
        $this->assertStringNotContainsString('₱4,000 and above', $m[1], 'No stay costs that much, so the choice would be empty.');
        $this->assertStringContainsString('Custom budget', $m[1]);
    }

    public function test_the_stay_budget_filters_the_price_per_night(): void
    {
        $this->stay('cheap-inn', 900);
        $this->stay('mid-hotel', 2000);
        $this->stay('grand-hotel', 6000);
        $this->stay('unpriced-lodge', null);

        $under = $this->get('/accommodations?price_max=1499.99')->assertOk()->getContent();
        $this->assertStringContainsString('Cheap Inn', $under);
        $this->assertStringNotContainsString('Mid Hotel', $under);

        $mid = $this->get('/accommodations?price_min=1500&price_max=2499.99')->assertOk()->getContent();
        $this->assertStringContainsString('Mid Hotel', $mid);
        $this->assertStringNotContainsString('Grand Hotel', $mid);
        $this->assertStringNotContainsString('Unpriced Lodge', $mid, 'A stay with no price on file never fits a budget.');

        $min = $this->get('/accommodations?price_min=4000')->assertOk()->getContent();
        $this->assertStringContainsString('Grand Hotel', $min);
        $this->assertStringNotContainsString('Mid Hotel', $min);

        $any = $this->get('/accommodations')->assertOk()->getContent();
        foreach (['Cheap Inn', 'Mid Hotel', 'Grand Hotel', 'Unpriced Lodge'] as $name) {
            $this->assertStringContainsString($name, $any, "Any budget keeps {$name}.");
        }
    }

    public function test_the_destination_budget_is_the_entry_fee_overlap(): void
    {
        $this->place('free-park', 0, 0);
        $this->place('mixed-park', 0, 500);
        $this->place('paid-park', 300, 800);
        $this->place('no-fee-on-file', null, null);

        $free = $this->get('/destinations?price_min=0&price_max=0')->assertOk()->getContent();
        $this->assertStringContainsString('Free Park', $free);
        $this->assertStringContainsString('Mixed Park', $free, 'Free entry is available there.');
        $this->assertStringNotContainsString('Paid Park', $free);

        $over = $this->get('/destinations?price_min=701')->assertOk()->getContent();
        $this->assertStringContainsString('Paid Park', $over);
        $this->assertStringNotContainsString('Free Park', $over);
        $this->assertStringNotContainsString('No Fee On File', $over);

        $this->assertStringContainsString('No Fee On File', $this->get('/destinations')->assertOk()->getContent(), 'Any budget keeps a place with no fee on file.');
    }

    public function test_the_package_budget_is_the_total_for_the_travellers(): void
    {
        $region = $this->region();
        foreach ([['day-tour', 1200], ['grand-tour', 3200]] as [$slug, $price]) {
            Package::create([
                'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_per_pax' => $price,
            ]);
        }

        // One traveller: 1,200 and 3,200. Two travellers: 2,400 and 6,400.
        $one = $this->get('/packages?price_min=1000&price_max=2499.99')->assertOk()->getContent();
        $this->assertStringContainsString('Day Tour', $one);
        $this->assertStringNotContainsString('Grand Tour', $one);

        $two = $this->get('/packages?price_min=2000&price_max=2999.99&travelers=2')->assertOk()->getContent();
        $this->assertStringContainsString('Day Tour', $two, '1,200 x 2 = 2,400 is inside the range.');
        $this->assertStringNotContainsString('Grand Tour', $two);

        $big = $this->get('/packages?price_min=6000&travelers=2')->assertOk()->getContent();
        $this->assertStringContainsString('Grand Tour', $big, '3,200 x 2 = 6,400.');
        $this->assertStringNotContainsString('Day Tour', $big);

        $this->assertStringNotContainsString('Grand Tour', $this->get('/packages?price_min=6000')->assertOk()->getContent(), 'For one traveller 3,200 is below 6,000.');
    }

    // ---- Kind of stay ---------------------------------------------------------------------------------

    public function test_any_and_overnight_keep_every_stay_and_day_use_reads_the_listing_text(): void
    {
        $this->stay('plain-hotel', 2000, ['description' => 'Rooms in the city.']);
        $this->stay('cottage-resort', 2500, ['type' => 'Resort', 'description' => 'Day use cottages by the river, with overnight rooms too.']);

        $any = $this->get('/accommodations')->assertOk()->getContent();
        $this->assertStringContainsString('Plain Hotel', $any);
        $this->assertStringContainsString('Cottage Resort', $any);

        $overnight = $this->get('/accommodations?stay_mode=overnight')->assertOk()->getContent();
        $this->assertStringContainsString('Plain Hotel', $overnight);
        $this->assertStringContainsString('Cottage Resort', $overnight);

        $day = $this->get('/accommodations?stay_mode=day_use')->assertOk()->getContent();
        $this->assertStringContainsString('Cottage Resort', $day);
        $this->assertStringNotContainsString('Plain Hotel', $day);

        $back = $this->get('/accommodations')->assertOk()->getContent();
        $this->assertStringContainsString('Plain Hotel', $back, 'Going back to Any removes the restriction.');
    }

    public function test_dates_are_optional_validated_and_kept_in_the_url(): void
    {
        $this->assertStringNotContainsString('from=', $this->go(['purpose' => 'accommodations']));

        $ok = $this->go(['purpose' => 'accommodations', 'from' => '2026-12-01', 'to' => '2026-12-03']);
        $this->assertStringContainsString('from=2026-12-01', $ok);
        $this->assertStringContainsString('to=2026-12-03', $ok);

        $this->assertStringContainsString('from=2026-12-01', $this->go(['purpose' => 'accommodations', 'from' => '2026-12-01']), 'One date alone is fine.');

        foreach ([['2026-12-05', '2026-12-01'], ['2026-13-40', ''], ['tomorrow', '']] as [$from, $to]) {
            $this->get('/search?'.http_build_query(['purpose' => 'accommodations', 'from' => $from, 'to' => $to]))
                ->assertRedirect(route('home').'#search')->assertSessionHas('error');
        }
    }

    public function test_the_stay_page_shows_the_dates_and_guests_as_chips_and_keeps_them_when_filtering(): void
    {
        $this->stay('plain-hotel', 2000);

        $html = $this->get('/accommodations?from=2026-12-01&to=2026-12-03&adults=2&children=1&pwd=1&price_min=1500')->assertOk()->getContent();

        foreach (['Check-in: 1 Dec 2026', 'Check-out: 3 Dec 2026', '2 adults', '1 children', '1 PWD', 'From ₱1,500'] as $chip) {
            $this->assertStringContainsString($chip, $html);
        }
        $this->assertMatchesRegularExpression('/<input type="hidden" name="adults" value="2"/', $html, 'The filter panel carries the guests along.');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="price_min" value="1500"/', $html);
    }

    // ---- Dining ---------------------------------------------------------------------------------------

    public function test_a_meal_keeps_the_restaurants_open_at_that_hour(): void
    {
        $this->eatery('early-cafe', '6:00 AM-2:00 PM');
        $this->eatery('night-grill', '5:00 PM-11:00 PM');
        $this->eatery('all-day', '6:00 AM-11:00 PM');
        $this->eatery('odd-hours', 'By arrangement');
        $this->eatery('no-hours', null);

        $breakfast = $this->get('/restaurants?meal=breakfast')->assertOk()->getContent();
        $this->assertStringContainsString('Early Cafe', $breakfast);
        $this->assertStringContainsString('All Day', $breakfast);
        $this->assertStringNotContainsString('Night Grill', $breakfast);
        $this->assertStringContainsString('Odd Hours', $breakfast, 'Hours the parser cannot read put no limit on the meal.');
        $this->assertStringContainsString('No Hours', $breakfast);

        $dinner = $this->get('/restaurants?meal=dinner')->assertOk()->getContent();
        $this->assertStringContainsString('Night Grill', $dinner);
        $this->assertStringNotContainsString('Early Cafe', $dinner);

        $lunch = $this->get('/restaurants?meal=lunch')->assertOk()->getContent();
        $this->assertStringContainsString('Early Cafe', $lunch);
        $this->assertStringNotContainsString('Night Grill', $lunch);

        $any = $this->get('/restaurants')->assertOk()->getContent();
        $this->assertStringContainsString('Night Grill', $any, 'A meal is optional.');
        $this->assertStringContainsString('Early Cafe', $any);
    }

    public function test_a_dining_date_uses_the_hours_that_name_days(): void
    {
        // 2026-11-16 is a Monday.
        $this->eatery('closed-mondays', 'Closed Mondays');
        $this->eatery('always-open', '6:00 AM-11:00 PM');

        $monday = $this->get('/restaurants?meal=lunch&date=2026-11-16')->assertOk()->getContent();
        $this->assertStringNotContainsString('Closed Mondays', $monday);
        $this->assertStringContainsString('Always Open', $monday);

        $tuesday = $this->get('/restaurants?meal=lunch&date=2026-11-17')->assertOk()->getContent();
        $this->assertStringContainsString('Closed Mondays', $tuesday);
    }

    public function test_meal_times_are_the_planners(): void
    {
        $this->assertSame(['breakfast', 'lunch', 'dinner'], array_keys(MealTimes::MEALS));
        $this->assertTrue(MealTimes::servedAt('7:00 AM-9:00 PM', 'breakfast'));
        $this->assertFalse(MealTimes::servedAt('10:00 AM-9:00 PM', 'breakfast'));
        $this->assertTrue(MealTimes::servedAt('11:00 AM-3:00 PM', 'lunch'));
        $this->assertFalse(MealTimes::servedAt('11:00 AM-3:00 PM', 'dinner'));
        $this->assertTrue(MealTimes::servedAt('12:00 PM-2:00 AM', 'dinner'), 'Open past midnight still serves dinner.');
        $this->assertTrue(MealTimes::servedAt('Open 24 hours', 'dinner'));
    }

    public function test_the_dining_price_level_filters_the_existing_price_tier(): void
    {
        $this->eatery('cheap-eats', '6:00 AM-11:00 PM', ['price_tier' => 'Budget-Friendly']);
        $this->eatery('fine-dining', '6:00 AM-11:00 PM', ['price_tier' => 'Premium']);

        $html = $this->get($this->go(['purpose' => 'restaurants', 'budget' => 'level:Premium']))->assertOk()->getContent();

        $this->assertStringContainsString('Fine Dining', $html);
        $this->assertStringNotContainsString('Cheap Eats', $html);
    }

    public function test_a_bad_dining_date_is_refused_and_a_good_one_kept(): void
    {
        $this->get('/search?purpose=restaurants&date=31-12-2026')->assertRedirect(route('home').'#search')->assertSessionHas('error');
        $this->assertStringContainsString('date=2026-12-31', $this->go(['purpose' => 'restaurants', 'date' => '2026-12-31']));
    }

    // ---- Packages and souvenirs -----------------------------------------------------------------------

    public function test_packages_keep_their_existing_filters_and_a_traveller_count(): void
    {
        $location = $this->go(['purpose' => 'packages', 'interest' => 'Nature & Adventure', 'duration' => '3-4', 'travelers' => 4, 'region_id' => 3]);

        foreach (['interest=Nature & Adventure', 'duration=3-4', 'travelers=4', 'region_id=3'] as $kept) {
            $this->assertStringContainsString($kept, $location);
        }
        $this->assertStringNotContainsString('travelers=', $this->go(['purpose' => 'packages', 'travelers' => 1]), 'One traveller is the default and adds nothing.');
    }

    public function test_souvenir_shops_are_searched_by_place_only(): void
    {
        $location = $this->go(['purpose' => 'souvenir_centers', 'region_id' => 2, 'budget' => 'custom', 'budget_min' => '100', 'interest' => 'Wildlife', 'meal' => 'lunch', 'adults' => 3]);

        $this->assertSame(url('/souvenir-centers').'?region_id=2', $location);
        $this->get('/souvenir-centers')->assertOk();
    }

    public function test_the_stay_dates_in_the_past_are_not_blocked_by_the_server_but_the_card_sets_a_minimum(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $today = Carbon::now('Asia/Manila')->toDateString();

        $this->assertStringContainsString('name="from" min="'.$today.'"', $html);
        $this->assertStringContainsString('name="to" min="'.$today.'"', $html);
        $this->assertStringContainsString('name="date" min="'.$today.'"', $html);
    }

    public function test_reset_and_explore_are_on_the_card(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-search-reset', $html);
        $this->assertStringContainsString('hero-search__go', $html);
    }
}
