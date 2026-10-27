<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TourOperator;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\TourOperatorSuggester;
use App\Services\Recommendation\TransportMode;
use App\Services\Recommendation\TravelTimeEstimator;
use Database\Seeders\ResortSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How the traveller gets around now sets the pace of every journey estimate, and a resort can be asked for by
 * its setting (Mountain, Island, Lake...). Both are optional, and an unanswered one must behave exactly as the
 * planner did before the question existed.
 */
class TransportAndResortSettingTest extends TestCase
{
    use RefreshDatabase;

    // ── Transport: journey time ───────────────────────────────────────────

    public function test_an_unanswered_mode_is_planned_as_a_personal_car(): void
    {
        $estimator = new TravelTimeEstimator();

        // 20 km straight -> 26 km by road: 39–62 min of driving + 10 min overhead, to the quarter hour.
        $unanswered = $estimator->estimate(20.0);

        $this->assertSame($unanswered, $estimator->estimate(20.0, null, null, TransportMode::PERSONAL_CAR));
        $this->assertSame($unanswered, $estimator->estimate(20.0, null, null, 'not-a-mode'));
        $this->assertSame(45, $unanswered['min_minutes']);
        $this->assertSame(75, $unanswered['max_minutes']);
    }

    public function test_a_jeep_takes_longer_than_a_car_over_the_same_distance(): void
    {
        $estimator = new TravelTimeEstimator();

        $car = $estimator->estimate(20.0, null, null, TransportMode::PERSONAL_CAR);
        $jeep = $estimator->estimate(20.0, null, null, TransportMode::JEEP);

        $this->assertGreaterThan($car['max_minutes'], $jeep['max_minutes']);
        $this->assertGreaterThan($car['min_minutes'], $jeep['min_minutes']);
        // The road distance is the same; only the pace differs.
        $this->assertSame($car['distance_km'], $jeep['distance_km']);
    }

    public function test_a_taxi_and_a_rented_car_move_at_the_pace_of_a_private_car(): void
    {
        $estimator = new TravelTimeEstimator();
        $car = $estimator->estimate(15.0, null, null, TransportMode::PERSONAL_CAR);

        $this->assertSame($car, $estimator->estimate(15.0, null, null, TransportMode::TAXI));
        $this->assertSame($car, $estimator->estimate(15.0, null, null, TransportMode::RENTAL_CAR));
    }

    public function test_the_ferry_to_samal_is_added_whatever_the_mode(): void
    {
        $estimator = new TravelTimeEstimator();

        foreach (TransportMode::keys() as $mode) {
            $crossing = $estimator->estimate(10.0, 'Davao City', 'Island Garden City of Samal', $mode);
            $mainland = $estimator->estimate(10.0, 'Davao City', 'Davao City', $mode);

            $this->assertTrue($crossing['ferry'], $mode);
            $this->assertSame(30, $crossing['max_minutes'] - $mainland['max_minutes'], "{$mode}: ferry adds 30 minutes");
        }
    }

    public function test_every_mode_has_a_label_and_a_sane_speed_range(): void
    {
        foreach (TransportMode::keys() as $mode) {
            $this->assertNotSame('', TransportMode::label($mode));
            $this->assertGreaterThan(0, TransportMode::slowKmh($mode));
            $this->assertGreaterThanOrEqual(TransportMode::slowKmh($mode), TransportMode::fastKmh($mode), $mode);
        }

        $this->assertSame('Personal Car', TransportMode::label(null));
    }

    // ── Transport: the form ───────────────────────────────────────────────

    private function surveyPayload(array $overrides = []): array
    {
        return $overrides + [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time visitor', 'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel',
            'distance_pref' => 'moderate', 'activities' => ['Nature'], 'amenities' => ['Parking Area'],
            'place_of_origin' => 'Cebu City',
        ];
    }

    public function test_the_form_offers_the_transport_choices_and_the_new_distance_names(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        foreach (['Tour Operator Pick-up', 'Commute: Public Transport', 'Jeepney', 'Taxi', 'Commute: Personal Car', 'Commute: Rent a Car'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Commute from Venue', $html);
        foreach (['Close Proximity', 'Commutable', 'Remote'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Moderate distance', $html);
    }

    public function test_the_transport_choice_is_saved_and_may_be_left_blank(): void
    {
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'jeep']))->assertRedirect();
        $this->assertSame('jeep', TouristPreference::latest('id')->first()->transport_pref);

        $this->post('/plan', $this->surveyPayload())->assertRedirect();
        $this->assertNull(TouristPreference::latest('id')->first()->transport_pref);
    }

    public function test_an_unknown_transport_value_is_rejected(): void
    {
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'helicopter']))
            ->assertSessionHasErrors('transport_pref');
    }

    public function test_a_planned_trip_travels_more_slowly_by_jeep_than_by_car(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([['a', 7.07, 125.61], ['b', 7.18, 125.45], ['c', 7.02, 125.41]] as [$slug, $lat, $lng]) {
            Destination::create([
                'slug' => $slug, 'name' => 'Place '.$slug, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        $legs = function (?string $mode) {
            $this->post('/plan', $this->surveyPayload([
                'travel_days' => 1, 'distance_pref' => 'far', 'transport_pref' => $mode,
                'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Davao City',
            ]))->assertRedirect();

            return TouristPreference::latest('id')->first()->itineraries()->latest('id')->first()
                ->items()->where('kind', 'travel')->whereNotNull('distance_km')->orderBy('sort_order')->get();
        };

        $car = $legs('personal_car');
        $jeep = $legs('jeep');

        $this->assertNotEmpty($car);
        $this->assertNotEmpty($jeep);
        // Same first leg (same origin, same ranking), but slower on a jeep.
        $this->assertSame($car->first()->distance_km, $jeep->first()->distance_km);
        $this->assertGreaterThan($car->first()->travel_max_minutes, $jeep->first()->travel_max_minutes);
    }

    // ── Transport: a tour operator, and the four-step form ───────────────

    public function test_a_tour_operator_pick_up_is_a_private_vehicle_with_a_little_more_overhead(): void
    {
        $estimator = new TravelTimeEstimator();
        $car = $estimator->estimate(20.0, null, null, TransportMode::PERSONAL_CAR);
        $operator = $estimator->estimate(20.0, null, null, TransportMode::TOUR_OPERATOR);

        $this->assertTrue(TransportMode::isTourOperator(TransportMode::TOUR_OPERATOR));
        $this->assertFalse(TransportMode::isTourOperator(TransportMode::JEEP));
        $this->assertSame('Tour Operator Pick-up', TransportMode::label(TransportMode::TOUR_OPERATOR));
        $this->assertSame($car['distance_km'], $operator['distance_km']);
        $this->assertGreaterThanOrEqual($car['max_minutes'], $operator['max_minutes']);
        $this->assertFalse(TransportMode::isKnown('venue_commute'), 'The old "commute from venue" mode is gone.');
    }

    public function test_the_form_has_four_steps_and_a_choice_already_made_for_the_required_questions(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertStringContainsString('4 · Getting around', $html);
        $this->assertStringContainsString('3 · Stay &amp; budget', $html);
        $this->assertMatchesRegularExpression('/name="budget" value="Mid-range" checked/', $html);
        $this->assertMatchesRegularExpression('/name="accommodation_pref" value="Any"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="distance_pref" value="moderate" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="transport_pref" value="[a-z_]+" checked/', $html, 'Transport is optional, so none is pre-picked.');
    }

    public function test_public_transport_is_saved_as_the_kind_chosen_under_it(): void
    {
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'public', 'public_kind' => 'taxi']))->assertRedirect();
        $this->assertSame('taxi', TouristPreference::latest('id')->first()->transport_pref);

        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'public', 'public_kind' => 'jeep']))->assertRedirect();
        $this->assertSame('jeep', TouristPreference::latest('id')->first()->transport_pref);

        // No kind (or a bogus one) is a jeepney, the common case, never an error.
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'public']))->assertRedirect();
        $this->assertSame('jeep', TouristPreference::latest('id')->first()->transport_pref);
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'public', 'public_kind' => 'helicopter']))->assertRedirect();
        $this->assertSame('jeep', TouristPreference::latest('id')->first()->transport_pref);
    }

    public function test_a_tour_operator_pick_up_and_a_rented_car_are_saved_as_chosen(): void
    {
        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'tour_operator']))->assertRedirect();
        $this->assertSame('tour_operator', TouristPreference::latest('id')->first()->transport_pref);

        $this->post('/plan', $this->surveyPayload(['transport_pref' => 'rental_car']))->assertRedirect();
        $this->assertSame('rental_car', TouristPreference::latest('id')->first()->transport_pref);
    }

    public function test_the_form_shows_the_saved_public_kind_under_the_public_transport_card(): void
    {
        $this->planATrip('public', ['public_kind' => 'taxi']);

        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="transport_pref" value="public" checked/', $html);
        $this->assertMatchesRegularExpression('/name="public_kind" value="taxi" checked/', $html);
    }

    public function test_only_resort_settings_with_a_resort_are_offered(): void
    {
        $this->assertStringNotContainsString('What kind of resort?', $this->get('/plan')->getContent(), 'No resort is filed under a setting yet.');

        $this->resort('Lakeside Resort', 'Resort', ['Lake']);
        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertStringContainsString('What kind of resort?', $html);
        $this->assertStringContainsString('Lake Resort', $html);
        $this->assertStringNotContainsString('Mountain Resort', $html, 'A setting with no resort is not offered.');
    }

    // ── The itinerary reflects the choices ───────────────────────────────

    private function planATrip(?string $mode, array $extra = []): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        foreach ([['a', 7.07, 125.61], ['b', 7.18, 125.45], ['c', 7.02, 125.41]] as [$slug, $lat, $lng]) {
            Destination::firstOrCreate(['slug' => $slug], [
                'name' => 'Place '.$slug, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        $this->post('/plan', $this->surveyPayload($extra + [
            'travel_days' => 1, 'distance_pref' => 'far', 'transport_pref' => $mode,
            'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Davao City',
        ]))->assertRedirect();
    }

    private function planThenGet(?string $mode, array $extra = []): string
    {
        $this->planATrip($mode, $extra);

        return $this->get('/plan/itinerary')->assertOk()->getContent();
    }

    public function test_the_itinerary_page_renders_for_every_way_of_getting_around(): void
    {
        foreach ([null, ...TransportMode::keys()] as $mode) {
            $this->planThenGet($mode);
        }

        $this->assertTrue(true);
    }

    public function test_the_itinerary_says_what_it_was_planned_around(): void
    {
        $html = $this->planThenGet('jeep', ['accommodation_pref' => 'Resort', 'resort_setting' => 'Island', 'distance_pref' => 'moderate']);

        $this->assertStringContainsString('Planned around', $html);
        $this->assertStringContainsString('Island Resort', $html);
        $this->assertStringContainsString('Commutable', $html);
        $this->assertStringContainsString('Jeep', $html, 'The way of travelling is named on the journeys.');
    }

    public function test_a_trip_with_no_stated_way_of_getting_around_names_none(): void
    {
        $html = $this->planThenGet(null);

        $this->assertStringNotContainsString('Pick-up by your tour operator', $html);
        $this->assertStringNotContainsString('class="tl-mode"', $html);
    }

    public function test_a_tour_operator_pick_up_shows_a_pick_up_and_nearby_operators(): void
    {
        $this->operator('Near Tours', 7.071, 125.611, 'Davao City', ['specialization' => 'General Travel & Tours', 'contact_number' => '082 555 0100']);

        $html = $this->planThenGet('tour_operator');

        $this->assertStringContainsString('Pick-up by your tour operator', $html);
        $this->assertStringContainsString('Tour operators for your pick-up', $html);
        $this->assertStringContainsString('Near Tours', $html);
        $this->assertStringContainsString('confirm with them first', $html);
        $this->assertStringContainsString('1 operator near you', $html);
    }

    public function test_commuting_on_your_own_shows_no_pick_up_and_no_operators(): void
    {
        $this->operator('Near Tours', 7.071, 125.611);

        $html = $this->planThenGet('personal_car');

        $this->assertStringNotContainsString('Pick-up by your tour operator', $html);
        $this->assertStringNotContainsString('Tour operators for your pick-up', $html);
    }

    public function test_a_day_with_a_lot_of_travel_gets_a_note(): void
    {
        $this->planATrip('jeep');
        $itinerary = TouristPreference::latest('id')->first()->itineraries()->latest('id')->first();
        $itinerary->items()->where('kind', 'travel')->update(['travel_max_minutes' => 150]);

        $html = $this->get('/plan/itinerary')->assertOk()->getContent();

        $this->assertStringContainsString('class="itin-longday"', $html);
        $this->assertStringContainsString('of travel by jeep', $html);
    }

    public function test_a_short_day_gets_no_note(): void
    {
        $this->planATrip('personal_car');
        $itinerary = TouristPreference::latest('id')->first()->itineraries()->latest('id')->first();
        $itinerary->items()->where('kind', 'travel')->update(['travel_max_minutes' => 30]);

        $this->assertStringNotContainsString('class="itin-longday"', $this->get('/plan/itinerary')->assertOk()->getContent());
    }

    public function test_the_day_says_how_it_is_travelled(): void
    {
        $this->assertMatchesRegularExpression('/\d km by tour operator/', $this->planThenGet('tour_operator'));
        $this->assertMatchesRegularExpression('/\d km by jeep/', $this->planThenGet('jeep'));
        $this->assertMatchesRegularExpression('/\d km of travel/', $this->planThenGet(null));
    }

    public function test_the_pick_up_points_to_the_suggested_operators_and_the_journeys_carry_no_badge(): void
    {
        $this->operator('Near Tours', 7.071, 125.611);

        $html = $this->planThenGet('tour_operator');

        $this->assertStringContainsString('href="#itin-ops"', $html);
        $this->assertStringContainsString('id="itin-ops"', $html);
        $this->assertStringNotContainsString('tl-mode', $html, 'The way of travelling is said once a day, not on every journey.');
    }

    public function test_the_overview_does_not_repeat_what_the_planned_around_strip_says(): void
    {
        $html = $this->planThenGet('jeep', ['accommodation_pref' => 'Resort', 'resort_setting' => 'Island']);

        $this->assertStringContainsString('Planned around', $html);
        $this->assertStringNotContainsString('Getting around', $html, 'The strip says it; the overview does not repeat it.');
    }

    public function test_the_nights_stay_is_named_with_the_settings_it_has(): void
    {
        $this->resort('Valley Resort', 'Resort', ['Inland', 'All-Inclusive']);

        // A one-day trip has no night to sleep, so plan two.
        $html = $this->planThenGet('personal_car', ['travel_days' => 2, 'accommodation_pref' => 'Resort', 'resort_setting' => 'Inland']);

        $this->assertStringContainsString('>Valley Resort</a></h4>', $html);
        $this->assertStringContainsString('Dinner &amp; overnight stay', $html, 'Dinner and the night at the same place are one card.');
        $this->assertStringContainsString('Matches your choice', $html);
        $this->assertStringContainsString('All Inclusive', $html);
    }

    public function test_an_operator_can_be_called_from_the_card(): void
    {
        $this->operator('Near Tours', 7.071, 125.611, 'Davao City', ['contact_number' => '0917 158 8309 / (082) 224-7284']);

        $html = $this->planThenGet('tour_operator');

        $this->assertStringContainsString('href="tel:09171588309"', $html, 'The first number is the one to call.');
        $this->assertStringContainsString('0917 158 8309', $html);
        $this->assertStringContainsString('km away', $html);
    }

    public function test_every_operator_has_a_google_maps_link_even_with_no_position(): void
    {
        $this->operator('Located Tours', 7.071, 125.611);
        $this->operator('Unlocated Tours', null, null, 'Davao City', ['rating' => 4.0]);

        $html = $this->planThenGet('tour_operator');

        $this->assertStringContainsString('https://www.google.com/maps/search/?api=1&amp;query=7.0710000,125.6110000', $html);
        $this->assertStringContainsString('query=Unlocated%20Tours%20Davao%20City', $html);
    }

    public function test_the_pick_up_has_a_planned_time_that_is_when_the_first_journey_leaves(): void
    {
        $html = $this->planThenGet('tour_operator', ['arrival_time' => '09:00']);

        $itinerary = TouristPreference::latest('id')->first()->itineraries()->latest('id')->first();
        $leaves = \Illuminate\Support\Carbon::parse($itinerary->items()->where('day_number', 1)->where('kind', 'travel')->orderBy('sort_order')->first()->starts_at)->format('g:i A');

        $this->assertMatchesRegularExpression('/tl-row--pickup.*?<strong>'.preg_quote($leaves, '/').'<\/strong>/s', $html);
        $this->assertStringContainsString('The operator confirms the exact time and place', $html);
    }

    public function test_a_journey_is_a_quiet_line_with_its_time_and_distance(): void
    {
        $html = $this->planThenGet('personal_car', ['arrival_time' => '09:00']);

        $this->assertStringNotContainsString('tl-time__leave', $html);
        $this->assertMatchesRegularExpression('/class="tl-travel">.*?(\d+(–\d+)? min · [\d.]+ km|Travel time varies)/s', $html);
    }

    public function test_a_leg_with_no_coordinates_is_allowed_time_at_the_pace_of_the_way_of_travelling(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach (['u1', 'u2', 'u3'] as $slug) {
            Destination::create([
                'slug' => $slug, 'name' => 'Unlocated '.$slug, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'distance_km' => 14,
            ]);
        }

        $unmeasuredAllowance = function (?string $mode) {
            $this->post('/plan', $this->surveyPayload(['travel_days' => 1, 'arrival_time' => '08:00', 'transport_pref' => $mode]))->assertRedirect();

            return TouristPreference::latest('id')->first()->itineraries()->latest('id')->first()
                ->items()->where('kind', 'travel')->whereNull('distance_km')->pluck('travel_max_minutes')->unique()->values()->all();
        };

        $this->assertSame([45], $unmeasuredAllowance('personal_car'));
        $this->assertSame([45], $unmeasuredAllowance(null), 'No answer is planned as a car.');
        $this->assertSame([75], $unmeasuredAllowance('jeep'), 'A jeep at 15 km/h needs 5/3 of a car\'s 45 minutes.');
    }

    // ── Suggesting tour operators ────────────────────────────────────────

    private function operator(string $name, ?float $lat, ?float $lng, string $region = 'Davao City', array $extra = []): TourOperator
    {
        return TourOperator::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => $region,
            'region_id' => Region::firstOrCreate(['name' => $region])->id, 'is_accredited' => true,
            'latitude' => $lat, 'longitude' => $lng, 'rating' => 0, 'review_count' => 0,
        ]);
    }

    public function test_operators_with_a_position_come_nearest_first(): void
    {
        $this->operator('Far Tours', 7.30, 125.80);
        $this->operator('Near Tours', 7.074, 125.613);
        $this->operator('Mid Tours', 7.12, 125.66);

        $picked = (new TourOperatorSuggester())->near(7.0731, 125.6128);

        $this->assertSame(['Near Tours', 'Mid Tours', 'Far Tours'], $picked->map(fn ($row) => $row['operator']->name)->all());
        $this->assertNotNull($picked->first()['km']);
    }

    public function test_operators_with_no_position_fill_from_the_same_region_and_get_no_distance(): void
    {
        $this->operator('Located', 7.074, 125.613);
        $this->operator('Davao Unlocated A', null, null, 'Davao City', ['rating' => 4.5]);
        $this->operator('Davao Unlocated B', null, null, 'Davao City', ['rating' => 3.0]);
        $this->operator('Samal Unlocated', null, null, 'Island Garden City of Samal', ['rating' => 5.0]);

        $picked = (new TourOperatorSuggester())->near(7.0731, 125.6128);

        $this->assertSame(['Located', 'Davao Unlocated A', 'Davao Unlocated B'], $picked->map(fn ($row) => $row['operator']->name)->all());
        $this->assertNull($picked[1]['km'], 'A distance is never made up for an operator whose position is unknown.');
    }

    public function test_only_accredited_operators_that_are_still_listed_are_suggested(): void
    {
        $this->operator('Listed', 7.074, 125.613);
        $this->operator('Not Accredited', 7.074, 125.613, 'Davao City', ['is_accredited' => false]);
        $this->operator('Archived', 7.074, 125.613, 'Davao City', ['archived_at' => now()]);

        $names = (new TourOperatorSuggester())->near(null, null)->map(fn ($row) => $row['operator']->name)->all();

        $this->assertSame(['Listed'], $names);
    }

    // ── Resort setting ────────────────────────────────────────────────────

    private function resort(string $name, string $type, ?array $settings, float $lat = 7.07, float $lng = 125.61): Accommodation
    {
        return Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => $type, 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            'resort_settings' => $settings === null ? null : Accommodation::encodeResortSettings($settings),
        ]);
    }

    private function pick(array $preference): ?array
    {
        $dest = Destination::firstOrCreate(['slug' => 'stop'], [
            'name' => 'Stop', 'location' => 'Davao',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);

        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'pickAccommodation');
        $method->setAccessible(true);

        $pref = TouristPreference::create($preference + [
            'travel_days' => 2, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Resort', 'distance_pref' => 'moderate',
        ]);

        return $method->invoke(app(ItineraryGenerationService::class), [['row' => ['destination' => $dest], 'distance_km' => null]], $pref);
    }

    public function test_a_resort_is_chosen_by_the_setting_the_traveller_asked_for(): void
    {
        $this->resort('Hilltop Resort', 'Resort', ['Mountain']);
        $lake = $this->resort('Lakeside Resort', 'Resort', ['Lake', 'All-Inclusive']);
        $this->resort('Plain Resort', 'Resort', ['Inland']);

        $this->assertSame($lake->id, $this->pick(['resort_setting' => 'Lake'])['listing']->id);
        $this->assertSame($lake->id, $this->pick(['resort_setting' => 'All-Inclusive'])['listing']->id);
    }

    public function test_an_island_resort_filed_as_a_beach_resort_still_counts_as_a_resort(): void
    {
        $island = $this->resort('Pearl Cove', 'Beach Resort', ['Island']);
        $this->resort('City Hotel', 'Hotel', ['Island']);

        $this->assertSame($island->id, $this->pick(['resort_setting' => 'Island'])['listing']->id);
    }

    public function test_inland_does_not_match_a_resort_that_is_only_on_an_island(): void
    {
        $island = $this->resort('Pearl Cove', 'Beach Resort', ['Island']);
        $inland = $this->resort('Valley Resort', 'Resort', ['Inland']);

        $this->assertSame($inland->id, $this->pick(['resort_setting' => 'Inland'])['listing']->id);
        $this->assertNotSame($island->id, $this->pick(['resort_setting' => 'Inland'])['listing']->id);
    }

    public function test_with_no_resort_of_that_setting_another_resort_is_offered_before_a_hotel(): void
    {
        $this->resort('Hotel Plaza', 'Hotel', null);
        $resort = $this->resort('Hilltop Resort', 'Resort', ['Mountain']);

        $this->assertSame($resort->id, $this->pick(['resort_setting' => 'Hot Spring'])['listing']->id);
    }

    public function test_the_setting_is_ignored_unless_a_resort_was_asked_for(): void
    {
        $hotel = $this->resort('Hotel Plaza', 'Hotel', null);
        $this->resort('Lakeside Resort', 'Resort', ['Lake']);

        $picked = $this->pick(['accommodation_pref' => 'Hotel', 'resort_setting' => 'Lake']);

        $this->assertSame($hotel->id, $picked['listing']->id);
    }

    public function test_a_stale_setting_is_cleared_when_the_preference_is_not_a_resort(): void
    {
        $this->post('/plan', $this->surveyPayload(['accommodation_pref' => 'Resort', 'resort_setting' => 'Lake']))->assertRedirect();
        $this->assertSame('Lake', TouristPreference::latest('id')->first()->resort_setting);

        $this->post('/plan', $this->surveyPayload(['accommodation_pref' => 'Hotel', 'resort_setting' => 'Lake']))->assertRedirect();
        $this->assertNull(TouristPreference::latest('id')->first()->resort_setting);
    }

    public function test_an_unknown_resort_setting_is_rejected(): void
    {
        $this->post('/plan', $this->surveyPayload(['accommodation_pref' => 'Resort', 'resort_setting' => 'Underwater']))
            ->assertSessionHasErrors('resort_setting');
    }

    // ── Classifying resorts ───────────────────────────────────────────────

    public function test_a_resort_is_classified_only_from_what_its_own_text_says(): void
    {
        $this->assertSame(['Lake'], ResortSettingSeeder::settingsFor('Lake Holon Lodge', null, 'Resort'));
        $this->assertSame(['Hot Spring'], ResortSettingSeeder::settingsFor('Bukidnon Hot Springs Resort', null, 'Resort'));
        $this->assertSame(['Island', 'All-Inclusive'], ResortSettingSeeder::settingsFor('Samal Sands', 'An all inclusive getaway', 'Beach Resort'));
        $this->assertSame(['Mountain'], ResortSettingSeeder::settingsFor('Eden View Mountain Resort', null, 'Resort'));
    }

    public function test_inland_is_claimed_only_for_a_plain_resort_with_nothing_pointing_to_the_coast(): void
    {
        $this->assertSame(['Inland'], ResortSettingSeeder::settingsFor('Green Valley Resort', null, 'Resort'));
        $this->assertSame([], ResortSettingSeeder::settingsFor('Dahican Resort', null, 'Resort'), 'Dahican is on the coast');
        $this->assertSame([], ResortSettingSeeder::settingsFor('Casa Julieta', null, 'Hotel'), 'a hotel is never called inland');
        $this->assertSame([], ResortSettingSeeder::settingsFor('Sunset Resort', null, 'Beach Resort'), 'a beach resort is not inland');
    }

    public function test_the_seeder_fills_only_unclassified_resorts(): void
    {
        $fresh = $this->resort('Eden View Mountain Resort', 'Resort', null);
        $corrected = $this->resort('Lake Sebu Resort', 'Resort', ['Hot Spring']);
        $hotel = $this->resort('Casa Julieta', 'Hotel', null);

        (new ResortSettingSeeder())->run();

        $this->assertSame(['Mountain'], $fresh->fresh()->resortSettingList());
        $this->assertSame(['Hot Spring'], $corrected->fresh()->resortSettingList(), 'a hand-set value is never overwritten');
        $this->assertSame([], $hotel->fresh()->resortSettingList());
    }

    public function test_settings_round_trip_through_the_stored_string(): void
    {
        $this->assertSame('|Lake|All-Inclusive|', Accommodation::encodeResortSettings(['All-Inclusive', 'Lake', 'Bogus']));
        $this->assertNull(Accommodation::encodeResortSettings(['Bogus']));
        $this->assertSame(['Lake', 'All-Inclusive'], (new Accommodation(['resort_settings' => '|Lake|All-Inclusive|']))->resortSettingList());
    }
}
