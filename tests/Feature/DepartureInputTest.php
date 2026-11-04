<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The departure fields on the planning form: validation, what is stored, and that a submitted departure reaches the
 * itinerary page (form -> controller -> planner -> page).
 */
class DepartureInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['itinerary.departure.airport_buffer_minutes' => 120]);

        // A typed place is looked up by name; nothing here may reach the network.
        $this->mock(AddressSuggestionService::class, function ($mock) {
            $mock->shouldReceive('resolve')->andReturnUsing(fn (string $q) => str_contains(strtolower($q), 'airport')
                ? ['label' => 'Davao International Airport', 'lat' => 7.1301, 'lng' => 125.6452]
                : null);
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
            'start_date' => '2026-10-28', 'arrival_time' => '08:00',
            'origin_lat' => '7.130', 'origin_lng' => '125.645', 'origin_label' => 'Davao International Airport',
            'activities' => ['Wildlife'], 'amenities' => ['wifi'],
        ];
    }

    private function leaving(array $over = []): array
    {
        return $this->form($over + [
            'departure_time' => '17:00',
            'departure_lat' => '7.130', 'departure_lng' => '125.645', 'departure_label' => 'Davao International Airport',
        ]);
    }

    // ---- 1. validation ------------------------------------------------------------------------------------

    public function test_a_departure_time_needs_a_departure_point_the_traveller_chose(): void
    {
        $response = $this->post(route('plan.update'), $this->form(['departure_time' => '17:00']));

        $response->assertSessionHasErrors('departure_label');
        $this->assertSame(0, TouristPreference::count(), 'nothing is saved or planned from a half-answer');
    }

    public function test_a_typed_place_the_lookup_cannot_find_is_not_accepted_as_a_departure_point(): void
    {
        $this->post(route('plan.update'), $this->form(['departure_time' => '17:00', 'departure_label' => 'Somewhere unknown']))
            ->assertSessionHasErrors('departure_label');
    }

    public function test_a_typed_place_the_lookup_finds_is_accepted_and_located(): void
    {
        $this->post(route('plan.update'), $this->form(['departure_time' => '17:00', 'departure_label' => 'Davao airport']))
            ->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertSame('17:00', substr((string) $preference->departure_time, 0, 5));
        $this->assertEqualsWithDelta(7.130, (float) $preference->departure_lat, 0.0005);
    }

    public function test_invalid_departure_values_are_rejected(): void
    {
        $this->post(route('plan.update'), $this->leaving(['departure_time' => '25:99']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['departure_lat' => '95', 'departure_lng' => '125']))->assertSessionHasErrors('departure_lat');
        $this->post(route('plan.update'), $this->leaving(['departure_lng' => null]))->assertSessionHasErrors('departure_lng');
    }

    // ---- 2. the departure date is derived: arrival date + (days - 1) -----------------------------------------

    /** @return array<string, array{0: string, 1: int, 2: string}> start date, days, the day the traveller leaves */
    public static function derivedDates(): array
    {
        return [
            'one day' => ['2026-10-28', 1, '2026-10-28'],
            'two days' => ['2026-10-28', 2, '2026-10-29'],
            'three days' => ['2026-10-28', 3, '2026-10-30'],
            'four days' => ['2026-10-28', 4, '2026-10-31'],
            'month end' => ['2026-10-30', 4, '2026-11-02'],
            'year end' => ['2026-12-30', 4, '2027-01-02'],
            'new year eve to new year day' => ['2026-12-31', 2, '2027-01-01'],
            'leap day reached' => ['2028-02-27', 3, '2028-02-29'],
            'leap day passed' => ['2028-02-27', 4, '2028-03-01'],
            'no leap day' => ['2027-02-27', 3, '2027-03-01'],
            'long trip' => ['2026-10-28', 30, '2026-11-26'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('derivedDates')]
    public function test_the_departure_date_is_the_last_day_of_the_trip(string $start, int $days, string $expected): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => $days, 'start_date' => $start, 'arrival_time' => '08:00']))
            ->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertSame($expected, $preference->departureDate()->toDateString());
        $this->assertSame($expected, $preference->departure_date->toDateString());
        // The planner's own window ends on the same day.
        $this->assertSame($expected, $preference->travelWindow()[1]->toDateString());
    }

    public function test_a_departure_time_without_an_arrival_date_is_planned_and_the_form_says_how_to_see_the_date(): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 2, 'start_date' => null]))->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertNull($preference->departureDate());
        $this->assertNull($preference->departure_date);
        $this->assertSame('17:00', substr((string) $preference->departure_time, 0, 5));

        $this->get(route('plan.itinerary'))->assertSee('Departure from Davao International Airport');
        $this->get(route('plan.edit'))->assertSee('Add your arrival date to see your departure date.');
    }

    public function test_a_posted_or_saved_departure_date_never_overrides_the_derived_one(): void
    {
        // A stale or hand-made departure_date in the request is not read, whatever it says.
        foreach (['2026-12-31', '2026-01-01', 'not-a-date', '2099-01-01'] as $stale) {
            $this->post(route('plan.update'), $this->leaving(['travel_days' => 3, 'start_date' => '2026-10-28', 'departure_date' => $stale]))
                ->assertSessionHasNoErrors();
            $this->assertSame('2026-10-30', TouristPreference::first()->departure_date->toDateString());
        }

        // A date saved before this change (the column is kept) is replaced on the next save and ignored by the planner.
        TouristPreference::query()->update(['departure_date' => '2026-01-01']);
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 3, 'start_date' => '2026-10-28']))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30', TouristPreference::first()->departure_date->toDateString());

        TouristPreference::query()->update(['departure_date' => '2026-01-01']);
        $this->post(route('plan.regenerate'))->assertRedirect(route('plan.itinerary'));
        $page = $this->get(route('plan.itinerary'));
        $page->assertSee('Departure from Davao International Airport');
        $page->assertSee('Be at Davao International Airport by 3:00 PM');
    }

    public function test_the_same_trip_is_planned_whatever_departure_date_a_form_posts(): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 2, 'start_date' => '2026-10-28']))->assertSessionHasNoErrors();
        $without = $this->get(route('plan.itinerary'))->getContent();

        $this->post(route('plan.update'), $this->leaving(['travel_days' => 2, 'start_date' => '2026-10-28', 'departure_date' => '2026-12-31']))->assertSessionHasNoErrors();
        $with = $this->get(route('plan.itinerary'))->getContent();

        // The page carries a CSRF token and randomly named SVG gradient ids; nothing else may differ.
        $strip = fn (string $html) => preg_replace('/<input[^>]*name="_token"[^>]*>|<meta name="csrf-token"[^>]*>|brand-mark-grad-[A-Za-z0-9]+/', '', $html);
        $this->assertSame($strip($without), $strip($with));
    }

    public function test_leaving_on_the_arrival_date_is_a_one_day_trip_and_must_come_after_arrival(): void
    {
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '07:30']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '08:00']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '17:00']))->assertSessionHasNoErrors();
    }

    // ---- 12. form -> controller -> planner -> page --------------------------------------------------------

    public function test_the_departure_reaches_the_stored_preference_the_planner_and_the_itinerary_page(): void
    {
        $this->post(route('plan.update'), $this->leaving())->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::first();
        $this->assertSame('2026-10-28', $preference->departure_date->toDateString());
        $this->assertSame('17:00', substr((string) $preference->departure_time, 0, 5));
        $this->assertSame('Davao International Airport', $preference->departure_label);

        $page = $this->get(route('plan.itinerary'));
        $page->assertOk();
        $page->assertSee('Departure from Davao International Airport');
        $page->assertSee('Be at Davao International Airport by 3:00 PM');
        $page->assertSee('2 hours before your 5:00 PM departure');
        $page->assertSee('planning allowance');
    }

    public function test_coordinates_are_rounded_to_three_places_like_the_starting_point(): void
    {
        $this->post(route('plan.update'), $this->leaving(['departure_lat' => '7.130456', 'departure_lng' => '125.645789']))->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertSame(7.130, (float) $preference->departure_lat);
        $this->assertSame(125.646, (float) $preference->departure_lng);
    }

    public function test_leaving_the_fields_blank_plans_the_trip_as_before_and_clears_an_earlier_answer(): void
    {
        $this->post(route('plan.update'), $this->leaving())->assertSessionHasNoErrors();
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $preference = TouristPreference::first();
        $this->assertNull($preference->departure_time);
        $this->assertNull($preference->departure_date);
        $this->assertNull($preference->departure_lat);
        $this->assertNull($preference->departure_label);

        $page = $this->get(route('plan.itinerary'));
        $page->assertDontSee('Departure from');
        $page->assertDontSee('Be at Davao International Airport');
    }

    public function test_the_form_shows_the_fields_and_the_disclosed_allowance(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('Departure time (optional)');
        $page->assertSee('name="departure_label"', false);
        $page->assertSee('We allow 2 hours there before you leave.');
        $page->assertSee('not an airline rule');
    }

    public function test_the_form_has_no_departure_date_input(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertDontSee('name="departure_date"', false);
        $page->assertDontSee('id="departure_date"', false);
        $page->assertDontSee('Departure date');
        $page->assertSee('name="departure_time"', false);
    }

    public function test_the_form_shows_the_derived_leaving_day_under_the_departure_time(): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 4, 'start_date' => '2026-11-05']))->assertSessionHasNoErrors();

        $this->get(route('plan.edit'))->assertSee('Leaving November 8');
    }

    public function test_a_rejected_arrival_date_does_not_break_the_form_or_its_leaving_line(): void
    {
        $this->post(route('plan.update'), $this->leaving(['start_date' => 'not-a-date', 'travel_days' => 99999]))->assertSessionHasErrors('start_date');

        $this->followingRedirects()->get(route('plan.edit'))->assertOk()->assertSee('Add your arrival date to see your departure date.');
    }

    public function test_the_form_explains_days_versus_nights_and_has_the_live_hint_slots(): void
    {
        $page = $this->get(route('plan.edit'));

        $page->assertOk();
        $page->assertSee('Days include your arrival and departure day; nights are one fewer.');
        $page->assertSee('data-trip-dates', false);
        $page->assertSee('data-departure-date', false);
        // The live wording lives in the script the page loads.
        $js = file_get_contents(public_path('js/plan-basics.js'));
        $this->assertStringContainsString('Add your arrival date to see your departure date.', $js);
        $this->assertStringContainsString('Leaving ${longDate(leaving)}', $js);
        $this->assertStringContainsString("n === 1 ? 'day' : 'days'", $js);
        $this->assertStringContainsString("n - 1 === 1 ? 'night' : 'nights'", $js);
        $this->assertStringNotContainsString("getElementById('departure_date')", $js);
    }
}
