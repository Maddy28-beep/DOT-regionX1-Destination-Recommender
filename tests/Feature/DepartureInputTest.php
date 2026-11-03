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
            'departure_date' => '2026-10-28', 'departure_time' => '17:00',
            'departure_lat' => '7.130', 'departure_lng' => '125.645', 'departure_label' => 'Davao International Airport',
        ]);
    }

    // ---- 1. validation ------------------------------------------------------------------------------------

    public function test_a_departure_date_needs_a_departure_time(): void
    {
        $this->post(route('plan.update'), $this->form(['departure_date' => '2026-10-28']))->assertSessionHasErrors('departure_time');
    }

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
        $this->post(route('plan.update'), $this->leaving(['departure_date' => 'not-a-date']))->assertSessionHasErrors('departure_date');
        $this->post(route('plan.update'), $this->leaving(['departure_time' => '25:99']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['departure_date' => '2099-01-01']))->assertSessionHasErrors('departure_date');
        $this->post(route('plan.update'), $this->leaving(['departure_lat' => '95', 'departure_lng' => '125']))->assertSessionHasErrors('departure_lat');
        $this->post(route('plan.update'), $this->leaving(['departure_lng' => null]))->assertSessionHasErrors('departure_lng');
    }

    public function test_a_departure_date_before_the_arrival_date_is_rejected(): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 3, 'start_date' => '2026-10-28', 'departure_date' => '2026-10-27']))
            ->assertSessionHasErrors(['departure_date' => 'Your departure date is before your arrival date.']);
    }

    public function test_a_departure_date_that_disagrees_with_the_number_of_days_is_rejected(): void
    {
        // Arriving Oct 28 and leaving Oct 31 is four days, not three.
        $response = $this->post(route('plan.update'), $this->leaving(['travel_days' => 3, 'start_date' => '2026-10-28', 'departure_date' => '2026-10-31']));

        $response->assertSessionHasErrors('departure_date');
        $this->assertStringContainsString('is 4 days, not 3', session('errors')->first('departure_date'));
    }

    public function test_a_departure_date_that_matches_the_days_is_accepted(): void
    {
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 3, 'start_date' => '2026-10-28', 'departure_date' => '2026-10-30']))
            ->assertSessionHasNoErrors();
    }

    public function test_leaving_on_the_arrival_date_is_a_one_day_trip_and_must_come_after_arrival(): void
    {
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '07:30']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '08:00']))->assertSessionHasErrors('departure_time');
        $this->post(route('plan.update'), $this->leaving(['arrival_time' => '08:00', 'departure_time' => '17:00']))->assertSessionHasNoErrors();
        // Same date but two days is inconsistent.
        $this->post(route('plan.update'), $this->leaving(['travel_days' => 2, 'departure_date' => '2026-10-28']))->assertSessionHasErrors('departure_date');
    }

    public function test_a_date_alone_in_the_past_is_rejected_when_there_is_no_arrival_date(): void
    {
        $this->post(route('plan.update'), $this->leaving(['start_date' => null, 'departure_date' => '2026-10-01']))->assertSessionHasErrors('departure_date');
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
        $page->assertSee('Departure date (optional)');
        $page->assertSee('Departure time (optional)');
        $page->assertSee('name="departure_label"', false);
        $page->assertSee('We allow 2 hours there before you leave.');
        $page->assertSee('not an airline rule');
    }
}
