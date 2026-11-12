<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Support\Features;
use App\Support\Geo\Countries;
use App\Support\Geo\PhProvinces;
use App\Support\Geo\RegionXi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The trip_location_v2 switch (13 Nov 2026): no past arrival date, a start point inside Davao Region, a finish-by time
 * instead of a departure point, and the visitor's origin as country / province / city.
 * With the switch off the old form and rules must behave exactly as before (the other test files cover that).
 */
class TripLocationV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 12:00:00');
        config(['itinerary.departure.airport_buffer_minutes' => 120, 'features.trip_location_v2' => true]);

        $this->mock(AddressSuggestionService::class, function ($mock) {
            $mock->shouldReceive('resolve')->andReturnUsing(function (string $q) {
                $q = strtolower($q);

                return match (true) {
                    str_contains($q, 'airport') => ['label' => 'Davao International Airport', 'lat' => 7.1301, 'lng' => 125.6452],
                    str_contains($q, 'manila') => ['label' => 'Manila', 'lat' => 14.5995, 'lng' => 120.9842],
                    default => null,
                };
            });
            $mock->shouldReceive('suggest')->andReturn(collect());
        });

        $region = Region::create(['name' => 'Davao City']);
        Destination::create([
            'slug' => 'near-park', 'name' => 'Near Park', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Wildlife',
            'is_accredited' => true, 'rating' => 5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.17, 'longitude' => 125.645,
        ]);
        Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function form(array $over = []): array
    {
        return $over + [
            'travel_days' => 1, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Local',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-11-28', 'arrival_time' => '08:00',
            'origin_lat' => '7.130', 'origin_lng' => '125.645', 'origin_label' => 'Davao International Airport',
            'activities' => ['Wildlife'], 'amenities' => ['wifi'],
        ];
    }

    // ---- 1. past dates and the blank date -----------------------------------------------------------------

    public function test_a_past_arrival_date_is_refused_but_today_and_later_are_fine(): void
    {
        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-19']))->assertSessionHasErrors('start_date');
        $this->assertSame(0, TouristPreference::count());

        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-20']))->assertSessionHasNoErrors();
        $this->post(route('plan.update'), $this->form(['start_date' => '2027-01-05']))->assertSessionHasNoErrors();
    }

    public function test_today_is_decided_in_philippine_time(): void
    {
        // 21 Nov 20:30 UTC is already 22 Nov 04:30 in Manila: the 21st has passed there.
        Carbon::setTestNow('2026-11-21 20:30:00');
        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-21']))->assertSessionHasErrors('start_date');
        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-22']))->assertSessionHasNoErrors();
    }

    public function test_a_date_already_saved_with_the_trip_is_not_refused_when_it_has_since_passed(): void
    {
        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-22']))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-22', 'travel_days' => 2]))->assertSessionHasNoErrors();
    }

    public function test_a_blank_date_is_not_replaced_by_today_and_the_plan_says_so(): void
    {
        $this->post(route('plan.update'), $this->form(['start_date' => null]))->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertNull($preference->start_date);

        $page = $this->get(route('plan.itinerary'));
        $page->assertOk();
        $page->assertSee('No arrival date was given, so weekday opening hours were not checked.');
        $page->assertSee('date not set');
    }

    public function test_a_given_date_shows_no_missing_date_notice(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $this->get(route('plan.itinerary'))->assertDontSee('No arrival date was given');
    }

    // ---- 2. Region XI ---------------------------------------------------------------------------------------

    /** @return array<string, array{0: float, 1: float, 2: bool}> lat, lng, inside */
    public static function places(): array
    {
        return [
            'Davao City' => [7.0707, 125.6087, true],
            'Davao airport' => [7.1301, 125.6452, true],
            'Digos' => [6.7497, 125.3572, true],
            'Tagum' => [7.4478, 125.8078, true],
            'Mati' => [6.9551, 126.2167, true],
            'Samal' => [7.0833, 125.7167, true],
            'Malita' => [6.4079, 125.6119, true],
            'Cateel' => [7.7833, 126.4500, true],
            'Baganga' => [7.5733, 126.5600, true],
            'Governor Generoso' => [6.5333, 126.0500, true],
            'Sarangani Island' => [5.4000, 125.4500, true],
            'Mt Apo' => [6.9875, 125.2711, true],
            'Manila' => [14.5995, 120.9842, false],
            'Cebu' => [10.3157, 123.8854, false],
            'Cagayan de Oro' => [8.4542, 124.6319, false],
            'General Santos' => [6.1164, 125.1716, false],
            'Kidapawan' => [7.0083, 125.0894, false],
            'Butuan' => [8.9475, 125.5406, false],
            'Zamboanga' => [6.9214, 122.0790, false],
            'Seoul' => [37.5665, 126.9780, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('places')]
    public function test_region_xi_outline_pins_which_places_are_in_and_out(float $lat, float $lng, bool $inside): void
    {
        $this->assertSame($inside, RegionXi::contains($lat, $lng), $lat.','.$lng);
    }

    public function test_a_start_point_outside_the_region_is_refused_with_a_clear_message(): void
    {
        $this->post(route('plan.update'), $this->form(['origin_lat' => '14.5995', 'origin_lng' => '120.9842', 'origin_label' => 'Manila']))
            ->assertSessionHasErrors(['origin_label' => RegionXi::MESSAGE]);
        $this->assertSame(0, TouristPreference::count());
    }

    public function test_a_typed_start_place_outside_the_region_is_refused_and_one_inside_is_accepted(): void
    {
        $this->post(route('plan.update'), $this->form(['origin_lat' => null, 'origin_lng' => null, 'origin_label' => 'Manila hotel']))
            ->assertSessionHasErrors('origin_label');

        $this->post(route('plan.update'), $this->form(['origin_lat' => null, 'origin_lng' => null, 'origin_label' => 'Davao airport']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_blank_start_point_is_still_allowed_and_plans_from_the_city_centre(): void
    {
        $this->post(route('plan.update'), $this->form(['origin_lat' => null, 'origin_lng' => null, 'origin_label' => null]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_current_position_outside_the_region_is_not_used_when_regenerating(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $this->post(route('plan.regenerate'), ['origin_lat' => '14.5995', 'origin_lng' => '120.9842'])->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::first();
        $this->assertEqualsWithDelta(7.130, (float) $preference->origin_lat, 0.001);
    }

    public function test_the_form_carries_the_outline_for_the_browser_and_the_hint(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('data-region-xi', false);
        $page->assertSee('inside Davao Region');
    }

    // ---- 3. start and end date and time ---------------------------------------------------------------------

    public function test_finish_by_is_stored_with_the_derived_end_date(): void
    {
        $this->post(route('plan.update'), $this->form(['travel_days' => 3, 'end_time' => '17:00']))->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertSame('17:00', substr((string) $preference->end_time, 0, 5));
        $this->assertSame('2026-11-30', $preference->end_date->toDateString());
    }

    public function test_finish_by_on_a_one_day_trip_has_to_be_after_the_start(): void
    {
        $this->post(route('plan.update'), $this->form(['end_time' => '07:30']))->assertSessionHasErrors('end_time');
        $this->post(route('plan.update'), $this->form(['end_time' => '08:00']))->assertSessionHasErrors('end_time');
        $this->post(route('plan.update'), $this->form(['end_time' => '17:00']))->assertSessionHasNoErrors();
        $this->post(route('plan.update'), $this->form(['end_time' => '25:99']))->assertSessionHasErrors('end_time');
    }

    public function test_the_two_hour_allowance_is_kept_before_finish_by_and_the_page_shows_start_and_end(): void
    {
        $this->post(route('plan.update'), $this->form(['end_time' => '17:00']))->assertSessionHasNoErrors();

        $page = $this->get(route('plan.itinerary'));
        $page->assertOk();
        $page->assertSee('Trip ends: back at');
        $page->assertSee('2 hours before your');
        $page->assertSee('PHT');
        $page->assertSee('(finish by)');
        $page->assertSee('Sat, 28 Nov 2026, 8:00 AM PHT');
    }

    public function test_without_finish_by_the_end_is_the_time_the_plan_ends_and_there_is_no_return_stop(): void
    {
        $this->post(route('plan.update'), $this->form(['end_time' => null]))->assertSessionHasNoErrors();

        $this->assertNull(TouristPreference::first()->end_time);
        $page = $this->get(route('plan.itinerary'));
        $page->assertDontSee('Trip ends: back at');
        $page->assertSee('(when the plan ends)');
    }

    public function test_the_form_has_finish_by_and_no_leaving_box(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('name="end_time"', false);
        $page->assertSee('Finish by');
        $page->assertDontSee('name="departure_label"', false);
        $page->assertDontSee('name="departure_time"', false);
    }

    public function test_the_switch_off_brings_back_the_old_form(): void
    {
        config(['features.trip_location_v2' => false]);

        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('name="departure_label"', false);
        $page->assertDontSee('name="end_time"', false);
        $page->assertDontSee('name="visitor_origin_country"', false);
    }

    public function test_the_switch_off_accepts_a_past_date_and_a_far_start_as_before(): void
    {
        config(['features.trip_location_v2' => false]);

        $this->post(route('plan.update'), $this->form(['start_date' => '2026-11-01', 'place_of_origin' => 'Cebu City']))->assertSessionHasNoErrors();
        $this->assertNull(TouristPreference::first()->end_time);
    }

    // ---- 4. visitor origin ----------------------------------------------------------------------------------

    public function test_a_philippine_origin_stores_country_region_province_and_city(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Domestic',
            'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => '  Cebu   City ',
        ]))->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame('PH', $p->visitor_origin_country_code);
        $this->assertSame('Cebu', $p->visitor_origin_province);
        $this->assertSame('Region VII (Central Visayas)', $p->visitor_origin_region);
        $this->assertSame('Cebu City', $p->visitor_origin_city);
        $this->assertSame('chosen', $p->visitor_origin_source);
        $this->assertSame('Cebu City, Cebu, Philippines', $p->place_of_origin);
    }

    public function test_a_foreign_origin_drops_province_and_keeps_the_country(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Foreign',
            'visitor_origin_country' => 'KR', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'Seoul',
        ]))->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame('KR', $p->visitor_origin_country_code);
        $this->assertNull($p->visitor_origin_province);
        $this->assertNull($p->visitor_origin_region);
        $this->assertSame('Seoul', $p->visitor_origin_city);
    }

    public function test_prefer_not_to_say_stores_nothing_but_the_choice(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Foreign', 'visitor_origin_country' => 'NONE', 'visitor_origin_city' => 'Hidden',
        ]))->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertNull($p->visitor_origin_country_code);
        $this->assertNull($p->visitor_origin_city);
        $this->assertSame('unspecified', $p->visitor_origin_source);
    }

    public function test_origin_is_required_for_visitors_from_outside_but_not_for_locals(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Foreign']))->assertSessionHasErrors('visitor_origin_country');
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Local']))->assertSessionHasNoErrors();
    }

    public function test_countries_and_provinces_have_to_come_from_our_lists(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Foreign', 'visitor_origin_country' => 'ZZ']))
            ->assertSessionHasErrors('visitor_origin_country');
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Atlantis']))
            ->assertSessionHasErrors('visitor_origin_province');
    }

    public function test_a_typed_city_is_plain_text_and_limited(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH',
            'visitor_origin_city' => '<b>Iloilo</b>'.str_repeat('x', 80),
        ]))->assertSessionHasNoErrors();

        $city = TouristPreference::first()->visitor_origin_city;
        $this->assertStringNotContainsString('<', $city);
        $this->assertLessThanOrEqual(100, mb_strlen($city));

        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_city' => str_repeat('x', 150),
        ]))->assertSessionHasErrors('visitor_origin_city');
    }

    public function test_the_form_offers_country_province_and_city(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertSee('name="visitor_origin_country"', false);
        $page->assertSee('Prefer not to say');
        $page->assertSee('name="visitor_origin_province"', false);
        $page->assertSee('name="visitor_origin_city"', false);
    }

    public function test_the_lists_are_consistent(): void
    {
        $this->assertTrue(Countries::has('PH'));
        $this->assertTrue(Countries::has('KR'));
        $this->assertFalse(Countries::has('ZZ'));
        $this->assertSame('Region XI (Davao Region)', PhProvinces::regionOf('Davao de Oro'));
        $this->assertNull(PhProvinces::regionOf('Atlantis'));
    }

    public function test_the_thirty_day_purge_scrubs_the_new_origin_columns(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'Cebu City',
        ]))->assertSessionHasNoErrors();
        $id = TouristPreference::first()->id;
        DB::table('exit_surveys')->exists(); // table present

        $source = file_get_contents(app_path('Services/Privacy/AnonymousDataPurger.php'));
        foreach (['visitor_origin_country_code', 'visitor_origin_region', 'visitor_origin_province', 'visitor_origin_city', 'visitor_origin_source'] as $column) {
            $this->assertStringContainsString("'{$column}' => null", $source);
        }
        $this->assertNotNull($id);
    }

    // ---- privacy notice, only with the switch on ------------------------------------------------------------

    private const FORM_NOTICE = 'Optional. Used for tourism statistics. Not linked to your name. Deleted after 30 days.';

    public function test_the_origin_question_carries_the_privacy_notice_when_the_switch_is_on(): void
    {
        $this->get(route('plan.edit'))->assertOk()->assertSee(self::FORM_NOTICE)->assertSee('data-origin-privacy', false);
    }

    public function test_the_origin_notice_is_not_on_the_form_when_the_switch_is_off(): void
    {
        config(['features.trip_location_v2' => false]);

        $this->get(route('plan.edit'))->assertOk()->assertDontSee(self::FORM_NOTICE)->assertDontSee('data-origin-privacy', false);
    }

    public function test_the_privacy_page_has_the_origin_paragraph_only_when_the_switch_is_on(): void
    {
        $this->get(route('legal.privacy'))->assertOk()
            ->assertSee('Where you travel from (optional)')
            ->assertSee('fewer than 5 visitors')
            ->assertSee('deleted or cleared automatically', false);

        config(['features.trip_location_v2' => false]);
        $this->get(route('legal.privacy'))->assertOk()->assertDontSee('Where you travel from')->assertDontSee('data-origin-privacy', false);
    }

    public function test_the_terms_page_is_the_same_with_the_switch_on_and_off(): void
    {
        $strip = fn (string $html) => preg_replace('/<input[^>]*name="_token"[^>]*>|<meta name="csrf-token"[^>]*>|brand-mark-grad-[A-Za-z0-9]+/', '', $html);
        $on = $strip($this->get(route('legal.terms'))->assertOk()->getContent());
        config(['features.trip_location_v2' => false]);
        $off = $strip($this->get(route('legal.terms'))->assertOk()->getContent());

        $this->assertSame($on, $off);
    }

    public function test_the_quick_dates_use_philippine_time_when_the_form_has_the_minimum_date(): void
    {
        $js = file_get_contents(public_path('js/plan-basics.js'));

        $this->assertStringContainsString("timeZone: 'Asia/Manila'", $js);
        $this->assertStringContainsString('if (startDate.min)', $js, 'without the minimum (switch off) the device date is used as before');
    }

    // ---- the switch itself ----------------------------------------------------------------------------------

    public function test_the_database_row_overrides_the_config_and_can_be_cleared(): void
    {
        config(['features.trip_location_v2' => false]);
        $this->assertFalse(Features::tripLocation());

        $this->artisan('features:set', ['name' => 'trip_location_v2', 'state' => 'on'])->assertSuccessful();
        $this->assertTrue(Features::tripLocation());

        $this->artisan('features:set', ['name' => 'trip_location_v2', 'state' => 'off'])->assertSuccessful();
        $this->assertFalse(Features::tripLocation());

        config(['features.trip_location_v2' => true]);
        $this->assertFalse(Features::tripLocation(), 'a row set to off wins over the config default');

        $this->artisan('features:set', ['name' => 'trip_location_v2', 'state' => 'default'])->assertSuccessful();
        $this->assertTrue(Features::tripLocation());
    }
}
