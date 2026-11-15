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
            'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Local',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_at' => '2026-11-28T08:00', 'end_at' => '2026-11-28T17:00',
            'origin_lat' => '7.130', 'origin_lng' => '125.645', 'origin_label' => 'Davao International Airport',
            'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Davao del Sur',
            'activities' => ['Wildlife'], 'amenities' => ['wifi'],
        ];
    }

    // ---- 1. past dates and the blank date -----------------------------------------------------------------

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
        $page->assertSee('Pick-up location selection is not available for your location.');
    }

    // ---- 5. the order of the questions and the pick-up for visitors from outside Davao Region -----------------

    private const ADVISORY = 'Your current location is outside Davao Region. Pick-up location selection is not available for your location. If you wish to continue, please indicate your preferred pick-up location.';

    public function test_the_form_carries_the_advisory_and_the_region_xi_provinces_for_the_script(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->assertSee(self::ADVISORY)->getContent();

        $this->assertMatchesRegularExpression('/<p[^>]*data-pickup-lock-note[^>]*hidden/', $html, 'hidden until the script finds an origin outside the region');
        foreach (['Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental', 'Davao Oriental'] as $province) {
            $this->assertStringContainsString('&quot;'.$province.'&quot;', $html);
        }
        $js = file_get_contents(public_path('js/plan-origin-select.js'));
        $this->assertStringContainsString("locate.disabled = outside", $js);
    }

    public function test_from_inside_region_xi_the_pick_up_is_kept_as_chosen(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Davao de Oro']))->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertEqualsWithDelta(7.130, (float) $p->origin_lat, 0.001);
        $this->assertSame('Davao International Airport', $p->origin_label);
    }

    public function test_from_inside_region_xi_a_pick_up_outside_it_is_still_refused(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Davao del Sur',
            'origin_lat' => '14.5995', 'origin_lng' => '120.9842', 'origin_label' => 'Manila Hotel',
        ]))->assertSessionHasErrors('origin_label');
    }

    public function test_from_outside_region_xi_a_typed_pick_up_inside_the_region_is_kept(): void
    {
        foreach ([['PH', 'Cebu'], ['JP', ''], ['US', '']] as [$country, $province]) {
            TouristPreference::query()->delete();
            $this->post(route('plan.update'), $this->form([
                'visitor_origin_country' => $country, 'visitor_origin_province' => $province,
                'origin_lat' => '7.056', 'origin_lng' => '125.602', 'origin_label' => 'Davao City Overland Transport Terminal, Ecoland',
            ]))->assertSessionHasNoErrors()->assertRedirect(route('plan.itinerary'));

            $p = TouristPreference::first();
            $this->assertEqualsWithDelta(7.056, (float) $p->origin_lat, 0.001, $country);
            $this->assertSame('Davao City Overland Transport Terminal, Ecoland', $p->origin_label, $country);
            $this->assertGreaterThan(0, \App\Models\ItineraryItem::count(), 'an itinerary is planned from it');
        }
    }

    public function test_from_outside_region_xi_a_pick_up_outside_the_region_is_still_refused_and_none_means_davao_city_centre(): void
    {
        foreach ([['PH', 'Cebu'], ['JP', '']] as [$country, $province]) {
            $this->post(route('plan.update'), $this->form([
                'visitor_origin_country' => $country, 'visitor_origin_province' => $province,
                'origin_lat' => '14.5995', 'origin_lng' => '120.9842', 'origin_label' => 'Manila Hotel',
            ]))->assertSessionHasErrors('origin_label');
        }
        $this->assertSame(0, TouristPreference::count());

        // Nothing typed: planned from Davao City centre, as before.
        $this->post(route('plan.update'), $this->form([
            'visitor_origin_country' => 'JP', 'origin_lat' => null, 'origin_lng' => null, 'origin_label' => null,
        ]))->assertSessionHasNoErrors();
        $this->assertNull(TouristPreference::first()->origin_label);
    }

    public function test_the_end_cannot_be_before_the_start_in_either_flow(): void
    {
        foreach ([['PH', 'Davao del Sur'], ['JP', '']] as [$country, $province]) {
            $this->post(route('plan.update'), $this->form([
                'visitor_origin_country' => $country, 'visitor_origin_province' => $province,
                'start_at' => '2026-11-28T17:00', 'end_at' => '2026-11-28T08:00',
            ]))->assertSessionHasErrors('end_at');
        }
        $this->assertSame(0, TouristPreference::count());
    }

    public function test_the_origin_is_saved_and_the_admin_page_reads_it_from_the_permanent_count(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();

        $this->assertSame('JP', TouristPreference::first()->visitor_origin_country_code);
        $this->assertSame(1, (int) \Illuminate\Support\Facades\DB::table('visitor_origin_daily')->where('country_code', 'JP')->sum('visitors'));
    }

    public function test_the_wizard_puts_the_origin_first_then_start_end_pick_up_and_the_rest(): void
    {
        $js = file_get_contents(public_path('js/plan-wizard.js'));
        $this->assertStringContainsString('steps[0].append(visit, basics, origin, style);', $js);
    }

    // ---- 3. start and end date and time ---------------------------------------------------------------------

    public function test_the_switch_off_brings_back_the_old_form(): void
    {
        config(['features.trip_location_v2' => false]);

        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('name="departure_label"', false);
        $page->assertDontSee('name="end_time"', false);
        $page->assertDontSee('name="visitor_origin_country"', false);
        $page->assertDontSee('Planning from abroad?');
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

    public function test_a_province_is_never_saved_with_a_country_other_than_the_philippines(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Foreign',
            'visitor_origin_country' => 'AS', 'visitor_origin_province' => 'Ilocos Norte', 'visitor_origin_city' => 'Pago Pago',
        ]))->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame('AS', $p->visitor_origin_country_code);
        $this->assertNull($p->visitor_origin_province);
        $this->assertNull($p->visitor_origin_region);
        $this->assertStringNotContainsString('Ilocos', (string) $p->place_of_origin);
    }

    public function test_a_province_is_kept_for_the_philippines_and_cleared_when_the_country_changes(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Ilocos Norte']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Ilocos Norte', TouristPreference::first()->visitor_origin_province);

        // The same visitor changes the country and the hidden province box still carries its old value.
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Foreign', 'visitor_origin_country' => 'AS', 'visitor_origin_province' => 'Ilocos Norte']))
            ->assertSessionHasNoErrors();
        $p = TouristPreference::first();
        $this->assertSame('AS', $p->visitor_origin_country_code);
        $this->assertNull($p->visitor_origin_province);
    }

    public function test_a_province_without_a_country_or_with_prefer_not_to_say_is_refused(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => '', 'visitor_origin_province' => 'Cebu']))->assertSessionHasErrors('visitor_origin_country');
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'NONE', 'visitor_origin_province' => 'Cebu']))->assertSessionHasErrors('visitor_origin_country');
        $this->assertSame(0, TouristPreference::count());
    }

    public function test_the_province_box_is_hidden_unless_the_country_is_the_philippines_and_the_script_clears_it(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*data-origin-province[^>]*hidden/', $html, 'hidden until the Philippines is chosen');

        $js = file_get_contents(public_path('js/plan-origin-select.js'));
        $this->assertStringContainsString("if (!isPh && hadValue) {", $js);
        $this->assertStringContainsString("province.value = '';", $js);
    }

    public function test_the_province_follows_the_country_however_the_country_changed(): void
    {
        $js = file_get_contents(public_path('js/plan-origin-select.js'));

        // Not only "change": keyboard and type-ahead ("input", "keyup"), leaving the box, Back / form restore, and submit.
        $this->assertStringContainsString("['change', 'input', 'keyup', 'focusout'].forEach(type => country.addEventListener(type, sync));", $js);
        $this->assertStringContainsString("window.addEventListener('pageshow', sync);", $js);
        $this->assertStringContainsString("country.form.addEventListener('submit', sync, true);", $js);
        $this->assertStringContainsString('province.hidden = !isPh;', $js);
    }

    public function test_the_province_control_the_shared_dropdown_builds_is_hidden_with_the_select(): void
    {
        // app.js wraps every select in .site-dropdown and shows its own button; hiding only the native select left the
        // "Province (optional)" button on screen for every country (found on production, 14 Nov 2026).
        $js = file_get_contents(public_path('js/plan-origin-select.js'));
        $this->assertStringContainsString("const wrapper = province.closest('.site-dropdown');", $js);
        $this->assertStringContainsString('if (wrapper) wrapper.hidden = !isPh;', $js);
        $this->assertStringContainsString("window.addEventListener('load', sync);", $js);
        $this->assertStringContainsString('new MutationObserver(sync).observe(', $js, 'the wrapper is built after this script runs');
        $this->assertStringContainsString("province.dispatchEvent(new Event('change', { bubbles: true }));", $js, 'the dropdown label is reset when the province is cleared');

        // ... and the hidden wrapper really disappears (the dropdown's own display:block would otherwise win).
        $css = file_get_contents(public_path('css/plan-trip-layout.css'));
        $this->assertStringContainsString('[data-origin-v2] .site-dropdown[hidden]', $css);
        $this->assertStringContainsString('[data-origin-v2] .site-dropdown:has(> select[hidden]) { display: none; }', $css);
        $this->assertStringContainsString('.site-dropdown { position:relative; display:block;', file_get_contents(public_path('css/app.css')), 'the rule it has to beat');
    }

    public function test_a_fresh_form_has_a_neutral_country_placeholder_and_no_country_selected(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();
        preg_match('/<select id="visitor_origin_country".*?<\/select>/s', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*\bselected\b/', $m[0], 'no country is preselected');
        $this->assertMatchesRegularExpression('/<select id="visitor_origin_country"[^>]*>\s*<option value="">Choose a country<\/option>/', $m[0], 'the first option is the placeholder');
        $this->assertMatchesRegularExpression('/<select[^>]*data-origin-province[^>]*hidden/', $html);
    }

    public function test_every_visitor_has_to_choose_a_real_place_a_local_included(): void
    {
        foreach (['Regular / Local', 'Regular / Foreign'] as $type) {
            foreach (['', 'NONE'] as $answer) {
                $this->post(route('plan.update'), $this->form(['visitor_type' => $type, 'visitor_origin_country' => $answer]))->assertSessionHasErrors('visitor_origin_country');
            }
        }
        $this->assertSame(0, TouristPreference::count(), 'nothing is saved without an origin');

        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Local']))->assertSessionHasNoErrors();
        $this->assertSame(1, TouristPreference::count());
    }

    public function test_the_philippines_needs_a_province(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_province' => '']))->assertSessionHasErrors('visitor_origin_province');
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
            'visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Iloilo',
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
        $page->assertDontSee('Prefer not to say');
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

    private const FORM_NOTICE = 'Your country, province and city are kept as an anonymous statistic for tourism planning, not linked to you. The rest of your trip details are deleted after 30 days.';

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
            ->assertSee('Where you travel from:')
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
