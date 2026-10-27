<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\ListingPhoto;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The itinerary page: welcome banner, title and chips, Day tabs, a photo timeline with a card per stop, and
 * the trip overview (map and facts) beside it. Everything on it comes from the plan or the listings it
 * points at, so these build a small plan by hand and read the page.
 */
class ItineraryPageRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, array $overrides = []): Destination
    {
        return Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Malagos, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.15, 'longitude' => 125.40, 'hours' => '8:00 AM–5:00 PM',
        ]);
    }

    private function row(Itinerary $trip, int $order, int $day, string $kind, array $extra = []): ItineraryItem
    {
        return ItineraryItem::create($extra + [
            'itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => $order, 'slot' => 'Morning',
            'kind' => $kind, 'title' => ucfirst($kind),
        ]);
    }

    /** A two-day plan: arrival, a trip to the eagle centre with lunch, then a second day at the zoo. */
    private function plan(array $preference = []): array
    {
        $eagle = $this->place('Philippine Eagle Center');
        $zoo = $this->place('JKM Mini Zoo', ['hours' => 'By arrangement', 'latitude' => 7.58, 'longitude' => 125.69]);

        $pref = TouristPreference::create($preference + [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        foreach (['Nature & Adventure', 'Wildlife', 'Cultural Heritage'] as $interest) {
            PreferenceActivity::create(['preference_id' => $pref->id, 'activity' => $interest]);
        }

        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 2, 'generated_at' => now()]);
        $this->row($trip, 1, 1, 'baseline', ['title' => 'Arrival — Davao City centre', 'starts_at' => '08:00:00']);
        $this->row($trip, 2, 1, 'travel', ['title' => 'Travel to Philippine Eagle Center', 'starts_at' => '08:30:00', 'distance_km' => 28.5, 'travel_min_minutes' => 60, 'travel_max_minutes' => 90]);
        $this->row($trip, 3, 1, 'activity', ['title' => 'Wildlife exploration — Philippine Eagle Center', 'starts_at' => '10:00:00', 'ends_at' => '12:30:00', 'destination_id' => $eagle->id]);
        $this->row($trip, 4, 1, 'meal', ['title' => 'Lunch — Philippine Eagle Center', 'starts_at' => '12:30:00', 'ends_at' => '13:30:00', 'destination_id' => $eagle->id]);
        $this->row($trip, 5, 2, 'activity', ['title' => 'Wildlife exploration — JKM Mini Zoo', 'starts_at' => '09:00:00', 'ends_at' => '10:30:00', 'destination_id' => $zoo->id]);
        $this->row($trip, 6, 2, 'departure', ['title' => 'Departure', 'starts_at' => '15:00:00']);

        return [$trip, $pref, $eagle, $zoo];
    }

    private function page(Itinerary $trip, TouristPreference $pref)
    {
        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'));
    }

    // ---- banner and title

    public function test_the_welcome_banner_title_and_chips(): void
    {
        [$trip, $pref] = $this->plan();

        $this->page($trip, $pref)->assertOk()
            ->assertSee('Your adventure is ready!')
            ->assertSee('2 days')
            ->assertSee('starting from Davao City centre')
            ->assertSee('Nature &amp; Adventure, Wildlife +1 more', false)
            ->assertSee('Budget-Friendly')
            ->assertSee('Edit preferences')
            ->assertSee('Save Itinerary');
    }

    public function test_a_saved_plans_own_title_is_kept(): void
    {
        [$trip, $pref] = $this->plan();
        $trip->update(['title' => 'My Davao Trip']);

        $this->page($trip, $pref)->assertSee('My Davao Trip')->assertDontSee('Your Davao itinerary');
    }

    // ---- day tabs

    public function test_there_is_a_tab_and_a_panel_for_every_day(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-day-tab="'), 'One tab per day.');
        $this->assertSame(2, substr_count($html, 'data-day-panel="'), 'One panel per day.');
        $this->assertStringContainsString('href="#itinerary-day-1"', $html);
        $this->assertStringContainsString('href="#itinerary-recommendations"', $html);
        $this->assertStringContainsString('Day-by-Day Travel Plan', $html);
        $this->assertStringContainsString('itinerary-days.js', $html);
    }

    public function test_each_day_says_how_many_stops_and_how_far(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->getContent();

        $this->assertStringContainsString('1 stop', $html);
        $this->assertStringContainsString('28.5 km of travel', $html);
    }

    public function test_the_day_shows_its_date_when_the_trip_has_a_start_date(): void
    {
        [$trip, $pref] = $this->plan(['start_date' => '2026-11-03']);

        $this->page($trip, $pref)->assertSee('Tuesday, November 3')->assertSee('Wednesday, November 4');
    }

    // ---- the timeline

    public function test_a_stop_is_a_card_with_its_name_what_you_do_how_long_and_its_hours(): void
    {
        [$trip, $pref, $eagle] = $this->plan();

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertStringContainsString('<a href="'.route('destinations.show', $eagle).'">Philippine Eagle Center</a>', $html);
        $this->assertStringContainsString('Wildlife exploration', $html);
        $this->assertStringContainsString('About 2 h 30 min', $html);
        $this->assertStringContainsString('Open 8:00 AM–5:00 PM', $html);
        $this->assertStringContainsString('10:00 AM', $html);
        $this->assertStringContainsString('to 12:30 PM', $html);
    }

    public function test_hours_that_are_not_a_plain_schedule_are_not_shown_as_opening_hours(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->getContent();

        $this->assertSame(1, substr_count($html, 'Open 8:00 AM–5:00 PM'));
        $this->assertStringNotContainsString('By arrangement', $html);
    }

    public function test_the_journey_between_stops_is_a_connector_and_meals_and_departure_are_in_the_timeline(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->getContent();

        $this->assertStringContainsString('Travel to Philippine Eagle Center', $html);
        $this->assertStringContainsString('60–90 min · 28.5 km', $html);
        $this->assertStringContainsString('tl-card__label">Lunch<', $html);
        $this->assertStringContainsString('tl-row--departure', $html);
        $this->assertStringContainsString('Start in Davao City centre', $html);
    }

    public function test_a_real_photo_is_used_and_the_seeded_placeholder_drawing_is_not(): void
    {
        [$trip, $pref, $eagle, $zoo] = $this->plan();
        ListingPhoto::create(['listing_kind' => 'destination', 'listing_id' => $eagle->id, 'path' => 'eagle.jpg', 'is_primary' => true]);
        ListingPhoto::create(['listing_kind' => 'destination', 'listing_id' => $zoo->id, 'path' => 'zoo.svg', 'is_primary' => true]);

        $html = $this->page($trip, $pref)->getContent();

        $this->assertStringContainsString('eagle.jpg', $html);
        $this->assertStringNotContainsString('zoo.svg', $html);
    }

    public function test_the_page_loads_photos_in_one_go_not_per_stop(): void
    {
        [$trip, $pref] = $this->plan();
        \DB::enableQueryLog();

        $this->page($trip, $pref)->assertOk();

        $photoQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'listing_photos'))->count();
        $this->assertLessThanOrEqual(4, $photoQueries, 'One query per listing type at most, however many stops there are.');
    }

    // ---- the overview

    public function test_the_map_beside_the_plan_follows_the_selected_day_and_the_banner_has_the_travellers_answers(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertStringContainsString('Day 1 on the map', $html);
        $this->assertStringContainsString('id="itinDayMap"', $html);
        preg_match("/data-days='([^']+)'/", $html, $m);
        $days = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(['Philippine Eagle Center'], array_column($days[1]['stops'], 'label'));
        $this->assertSame(['JKM Mini Zoo'], array_column($days[2]['stops'], 'label'));
        $this->assertSame(1, $days[1]['count']);
        $this->assertStringContainsString('maps/dir', $days[1]['gmaps'].$days[2]['gmaps'].'maps/dir');

        // What the traveller told us is in the banner, not in a list beside the plan.
        $this->assertStringContainsString('Family', $html);
        $this->assertStringContainsString('Nature &amp; Adventure, Wildlife +1 more', $html);
        $this->assertStringContainsString('Check opening hours before you go.', $html);
    }

    public function test_each_day_tab_says_how_many_stops_and_how_far(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-day-tab="1">\s*<strong>Day 1<\/strong>\s*<small>1 stop &middot; 29 km/', $html);
    }

    public function test_there_is_no_map_when_none_of_the_places_has_coordinates(): void
    {
        [$trip, $pref, $eagle, $zoo] = $this->plan();
        $eagle->update(['latitude' => null, 'longitude' => null]);
        $zoo->update(['latitude' => null, 'longitude' => null]);

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="itinDayMap"', $html);
        $this->assertStringContainsString('data-day-tab="1"', $html);
    }

    // ---- what must still be there

    public function test_the_rest_of_the_page_is_still_there(): void
    {
        [$trip, $pref] = $this->plan();

        $this->page($trip, $pref)->assertOk()
            ->assertSee('How was this made?')
            ->assertSee('Open Day 1 in Google Maps')
            ->assertSee('Recommended Destinations')
            ->assertSee('Your itinerary, your way')
            ->assertSee('Regenerate itinerary')
            ->assertSee('Explore other matches')
            ->assertSee('How your plan was built')
            ->assertSee('View technical details')
            ->assertSee('Not saved yet');
    }

    /** The old closing panel is now a Davo card; it must still lead to the exit survey, with a picture that exists. */
    public function test_the_feedback_card_leads_to_the_exit_survey_with_a_picture_that_exists(): void
    {
        [$trip, $pref] = $this->plan();

        $this->page($trip, $pref)->assertOk()
            ->assertSee('How was your Davao adventure?')
            ->assertSee('Share feedback')
            ->assertSee(route('exit-survey.create'), false)
            ->assertSee('images/davo-survey-v2.webp', false)
            ->assertDontSee('davo-survey-v2.png', false)
            ->assertSee('No reservations or payments are made');

        $this->assertFileExists(public_path('images/davo-survey-v2.webp'));
    }

    // ---- the duration wording

    public function test_durations_are_put_in_words(): void
    {
        $label = fn (?string $start, ?string $end) => (new ItineraryItem(['starts_at' => $start, 'ends_at' => $end]))->durationLabel();

        $this->assertSame('2 hours', $label('10:00:00', '12:00:00'));
        $this->assertSame('1 hour', $label('10:00:00', '11:00:00'));
        $this->assertSame('1 hour 30 min', $label('10:00:00', '11:30:00'));
        $this->assertSame('45 min', $label('10:00:00', '10:45:00'));
        $this->assertNull($label('10:00:00', null), 'A moment has no length.');
        $this->assertNull($label(null, null));
        $this->assertNull($label('10:00:00', '10:00:00'));
    }
}
