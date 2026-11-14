<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Support\TripWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The trip starts and ends at real moments in Philippine time (trip_location_v2): validation, the derived length, what the
 * planner does with the two moments, and how a trip saved before they existed is mapped.
 */
class TripStartEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 20 Nov 12:00 UTC is 20 Nov 20:00 in Manila.
        Carbon::setTestNow('2026-11-20 12:00:00');
        config(['itinerary.departure.airport_buffer_minutes' => 120, 'features.trip_location_v2' => true]);

        $this->mock(AddressSuggestionService::class, function ($mock) {
            $mock->shouldReceive('resolve')->andReturn(null);
            $mock->shouldReceive('suggest')->andReturn(collect());
        });

        $region = Region::create(['name' => 'Davao City']);
        foreach ([['Near Park', 7.17, 125.645], ['River Falls', 7.22, 125.66], ['Old Church', 7.10, 125.62], ['Hill Garden', 7.20, 125.60], ['Bay Beach', 7.05, 125.70], ['Eagle Centre', 7.19, 125.62]] as [$name, $lat, $lng]) {
            Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Wildlife',
                'is_accredited' => true, 'rating' => 5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }
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
            'start_at' => '2026-12-01T14:00', 'end_at' => '2026-12-03T10:00',
            'origin_lat' => '7.130', 'origin_lng' => '125.645', 'origin_label' => 'Davao International Airport',
            'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Davao del Sur',
            'activities' => ['Wildlife'], 'amenities' => ['wifi'],
        ];
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    // ---- validation edges --------------------------------------------------------------------------------

    public function test_both_moments_are_required_and_have_to_be_real_dates_and_times(): void
    {
        $this->post(route('plan.update'), $this->form(['start_at' => null]))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['end_at' => null]))->assertSessionHasErrors('end_at');
        $this->post(route('plan.update'), $this->form(['start_at' => 'tomorrow']))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01']))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['start_at' => '2027-02-30T10:00', 'end_at' => '2027-03-02T10:00']))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-12-03T25:00']))->assertSessionHasErrors('end_at');
        $this->assertSame(0, TouristPreference::count());
    }

    public function test_the_end_has_to_be_after_the_start(): void
    {
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-12-01T13:00']))->assertSessionHasErrors(['end_at' => 'End must be after the start.']);
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-12-01T14:00']))->assertSessionHasErrors(['end_at' => 'End must be after the start.']);
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-11-30T14:00']))->assertSessionHasErrors('end_at');
    }

    public function test_the_minimum_gap_is_three_hours(): void
    {
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-12-01T16:59']))->assertSessionHasErrors(['end_at' => 'End must be at least 3 hours after the start.']);
        $this->post(route('plan.update'), $this->form(['end_at' => '2026-12-01T17:00']))->assertSessionHasNoErrors();
    }

    public function test_the_maximum_is_thirty_days_and_it_is_configurable(): void
    {
        // Exactly 30 days of length, and the calendar days it touches are 31: too long. The plan is built per calendar day.
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-31T09:00']))->assertSessionHasErrors(['end_at' => 'A trip can be at most 30 days long.']);
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-31T08:59']))->assertSessionHasErrors('end_at');
        // 30 calendar days, 29 days 23 hours long.
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-30T20:00']))->assertSessionHasNoErrors();

        config(['itinerary.trip_window.max_days' => 3]);
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-05T10:00']))->assertSessionHasErrors('end_at');
        config(['itinerary.trip_window.min_hours' => 6]);
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-01T14:00']))->assertSessionHasErrors(['end_at' => 'End must be at least 6 hours after the start.']);
    }

    public function test_a_start_more_than_two_years_ahead_is_refused(): void
    {
        $this->post(route('plan.update'), $this->form(['start_at' => '2062-12-01T09:00', 'end_at' => '2062-12-02T09:00']))->assertSessionHasErrors('start_at');
    }

    // ---- the future, and who may start in the past ---------------------------------------------------------

    public function test_a_start_in_the_past_is_refused_for_a_visitor(): void
    {
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-19T10:00', 'end_at' => '2026-11-19T20:00']))
            ->assertSessionHasErrors(['start_at' => 'Start must be in the future (Philippine time).']);
        $this->assertSame(0, TouristPreference::count());
    }

    public function test_an_administrator_who_is_signed_in_may_start_in_the_past(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('plan.update'), $this->form(['start_at' => '2026-11-19T10:00', 'end_at' => '2026-11-19T20:00']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, TouristPreference::count());
    }

    public function test_the_demo_setting_lets_a_past_start_through(): void
    {
        config(['itinerary.trip_window.allow_past_start' => true]);

        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-19T10:00', 'end_at' => '2026-11-19T20:00']))->assertSessionHasNoErrors();
    }

    public function test_now_is_philippine_time_not_utc(): void
    {
        // 20:00 UTC on the 20th is 04:00 on the 21st in Manila.
        Carbon::setTestNow('2026-11-20 20:00:00');

        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-21T03:59', 'end_at' => '2026-11-21T10:00']))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-21T04:00', 'end_at' => '2026-11-21T10:00']))->assertSessionHasNoErrors();
    }

    public function test_a_start_at_exactly_this_minute_is_allowed(): void
    {
        // 12:00 UTC is 20:00 in Manila.
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-20T19:59', 'end_at' => '2026-11-20T23:30']))->assertSessionHasErrors('start_at');
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-11-20T20:00', 'end_at' => '2026-11-20T23:30']))->assertSessionHasNoErrors();
    }

    // ---- the derived length ----------------------------------------------------------------------------------

    public function test_the_length_is_rounded_up_for_queries_and_shown_in_days_and_hours(): void
    {
        $start = TripWindow::parse('2026-12-01T14:00');
        $end = TripWindow::parse('2026-12-03T10:00');

        $this->assertSame(3, TripWindow::durationDays($start, $end), 'the calendar days touched, like the Day 1, 2, 3 tabs');
        $this->assertSame('1 day, 20 hours', TripWindow::text($start, $end));
        $this->assertSame(3, TripWindow::calendarDays($start, $end), 'the plan has a partial first day, a full day and a partial last day');
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: int}> */
    public static function lengths(): array
    {
        return [
            'three hours' => ['2026-12-01T09:00', '2026-12-01T12:00', '3 hours', 1, 1],
            'a day and a half' => ['2026-12-01T09:00', '2026-12-02T21:00', '1 day, 12 hours', 2, 2],
            'exactly two days' => ['2026-12-01T09:00', '2026-12-03T09:00', '2 days', 3, 3],
            'minutes count' => ['2026-12-01T09:00', '2026-12-01T12:30', '3 hours, 30 minutes', 1, 1],
            'three days five hours' => ['2026-12-01T08:00', '2026-12-04T13:00', '3 days, 5 hours', 4, 4],
            'across midnight' => ['2026-12-01T22:00', '2026-12-02T03:00', '5 hours', 2, 2],
            'across new year' => ['2026-12-31T20:00', '2027-01-01T08:00', '12 hours', 2, 2],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lengths')]
    public function test_length_text_and_days(string $start, string $end, string $text, int $durationDays, int $calendarDays): void
    {
        $s = TripWindow::parse($start);
        $e = TripWindow::parse($end);

        $this->assertSame($text, TripWindow::text($s, $e));
        $this->assertSame($durationDays, TripWindow::durationDays($s, $e));
        $this->assertSame($calendarDays, TripWindow::calendarDays($s, $e));
    }

    public function test_the_two_moments_and_what_follows_from_them_are_stored(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame('2026-12-01 14:00:00', substr((string) $p->getRawOriginal('start_at'), 0, 19));
        $this->assertSame('2026-12-03 10:00:00', substr((string) $p->getRawOriginal('end_at'), 0, 19));
        $this->assertSame(3, $p->duration_days, 'calendar days touched; the exact length is shown separately');
        // What the planner is fed from, derived and never entered.
        $this->assertSame(3, (int) $p->travel_days);
        $this->assertSame('2026-12-01', $p->start_date->toDateString());
        $this->assertSame('14:00', substr((string) $p->arrival_time, 0, 5));
        $this->assertSame('10:00', substr((string) $p->end_time, 0, 5));
        $this->assertSame('2026-12-03', $p->end_date->toDateString());
        $this->assertSame('2026-12-01T14:00:00+08:00', TripWindow::iso($p->tripStart()));
        $this->assertSame('2026-12-03T10:00:00+08:00', TripWindow::iso($p->tripEnd()));
    }

    public function test_days_arrival_and_finish_by_posted_by_an_old_form_are_never_read(): void
    {
        $this->post(route('plan.update'), $this->form(['travel_days' => 9, 'start_date' => '2030-01-01', 'arrival_time' => '05:00', 'end_time' => '23:00']))
            ->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame(3, (int) $p->travel_days);
        $this->assertSame('2026-12-01', $p->start_date->toDateString());
        $this->assertSame('14:00', substr((string) $p->arrival_time, 0, 5));
        $this->assertSame('10:00', substr((string) $p->end_time, 0, 5));
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public function test_the_form_asks_for_start_and_end_and_no_longer_for_days_arrival_or_finish_by(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('name="start_at"', $html);
        $this->assertStringContainsString('name="end_at"', $html);
        $this->assertStringContainsString('type="datetime-local"', $html);
        foreach (['name="travel_days"', 'name="start_date"', 'name="arrival_time"', 'name="end_time"', 'name="departure_time"'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertStringNotContainsString('Number of travel days', $html);
        $this->assertStringNotContainsString('Arrival date', $html);
        $this->assertStringNotContainsString('Finish by', $html);
        $this->assertStringContainsString('data-trip-duration', $html);
        $this->assertStringContainsString('Pick-up location', $html);
        $this->assertStringContainsString('Philippine time', $html);
    }

    public function test_the_pick_up_location_starts_as_the_arrival_airport_and_the_start_has_a_minimum(): void
    {
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="origin_label"[^>]*value="Davao International Airport"/s', $html);
        $this->assertStringContainsString('id="origin_lat" value="7.13"', $html);
        $this->assertStringContainsString('min="2026-11-20T20:00"', $html, 'the earliest start is now, in Philippine time');
    }

    public function test_an_administrator_sees_no_minimum_start(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="start_at"[^>]*min=/', $html);
    }

    public function test_the_steps_are_in_the_asked_order(): void
    {
        $js = file_get_contents(public_path('js/plan-wizard.js'));
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();

        // Where from first (About you), then the start and end, then the pick-up location, then who is coming.
        $this->assertStringContainsString('if (tripWindow) steps[0].append(visit, basics, origin, style);', $js);
        $this->assertStringContainsString('visitor_origin_country', $html);
    }

    public function test_the_wide_layout_is_one_column_so_the_order_is_the_asked_order(): void
    {
        $css = str_replace("
", "
", file_get_contents(public_path('css/plan-trip-layout.css')));

        $this->assertStringContainsString('#plan-step-0:not([hidden]) { display: block; }', $css);
        $this->assertStringNotContainsString('.pc-trip-visitor { grid-column', $css, 'no fixed grid row can put a block out of order');
        $this->assertStringNotContainsString('.pc-trip-start { grid-column', $css);
    }

    public function test_the_script_shows_the_length_and_blocks_a_bad_end(): void
    {
        $js = file_get_contents(public_path('js/plan-trip-window.js'));

        $this->assertStringContainsString('Your trip is ${text(e - s)} long.', $js);
        $this->assertStringContainsString("'End must be after the start.'", $js);
        $this->assertStringContainsString('End must be at least ${minHours} hours after the start.', $js);
        $this->assertStringContainsString('Start must be in the future (Philippine time).', $js);
        $this->assertStringNotContainsString('Date.now', $js, 'the device clock is never used');
        $this->assertStringNotContainsString('getTimezoneOffset', $js);
    }

    public function test_the_switch_off_keeps_the_old_form_and_accepts_the_old_fields(): void
    {
        config(['features.trip_location_v2' => false]);
        $html = $this->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('name="travel_days"', $html);
        $this->assertStringContainsString('name="start_date"', $html);
        $this->assertStringContainsString('name="arrival_time"', $html);
        $this->assertStringNotContainsString('name="start_at"', $html);

        $this->post(route('plan.update'), [
            'travel_days' => 2, 'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Local',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-11-01', 'arrival_time' => '08:00', 'activities' => ['Wildlife'],
        ])->assertSessionHasNoErrors();

        $p = TouristPreference::first();
        $this->assertSame(2, (int) $p->travel_days);
        $this->assertNull($p->getRawOriginal('start_at'));
    }

    // ---- the planner uses the two moments ----------------------------------------------------------------

    public function test_the_plan_never_starts_before_the_start_or_ends_after_the_end_including_the_trip_back(): void
    {
        // Start 14:00, end 10:00 two days later: a partial first day, a full day and a partial last day.
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T14:00', 'end_at' => '2026-12-03T10:00']))->assertSessionHasNoErrors();

        $items = ItineraryItem::query()->orderBy('day_number')->orderBy('starts_at')->get();
        $this->assertGreaterThan(0, $items->count());
        $this->assertSame([1, 2, 3], $items->pluck('day_number')->unique()->values()->all(), 'one planning day for each calendar day');

        $time = fn (?string $t) => $t === null ? null : substr($t, 0, 5);
        foreach ($items->where('day_number', 1) as $item) {
            if ($item->starts_at !== null) {
                $this->assertGreaterThanOrEqual('14:00', $time($item->starts_at), 'nothing is scheduled before the start: '.(string) $item->title);
            }
        }
        foreach ($items->where('day_number', 3) as $item) {
            foreach ([$item->starts_at, $item->ends_at] as $t) {
                if ($t !== null) {
                    $this->assertLessThanOrEqual('10:00', $time($t), 'nothing is scheduled after the end, the way back included: '.(string) $item->title);
                }
            }
        }

        // The last visit ends early enough for the trip back, with the planning allowance (2 hours) to spare.
        $visits = $items->where('day_number', 3)->filter(fn ($i) => $i->destination_id !== null);
        foreach ($visits as $visit) {
            $this->assertLessThanOrEqual('08:00', $time($visit->ends_at), 'a visit ends before the end minus the allowance: '.$visit->name);
        }
    }

    public function test_the_first_visit_comes_after_the_start_plus_the_travel_from_the_pick_up(): void
    {
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-01T20:00']))->assertSessionHasNoErrors();

        $first = ItineraryItem::query()->whereNotNull('destination_id')->orderBy('starts_at')->first();
        $this->assertNotNull($first);
        $this->assertGreaterThan('09:00', substr((string) $first->starts_at, 0, 5), 'the journey from the pick-up and the preparation come first');
    }

    public function test_a_one_day_trip_ends_by_its_end_time(): void
    {
        $this->post(route('plan.update'), $this->form(['start_at' => '2026-12-01T09:00', 'end_at' => '2026-12-01T18:00']))->assertSessionHasNoErrors();

        foreach (ItineraryItem::query()->get() as $item) {
            foreach ([$item->starts_at, $item->ends_at] as $t) {
                if ($t !== null) {
                    $this->assertLessThanOrEqual('18:00', substr($t, 0, 5), (string) $item->title);
                    $this->assertGreaterThanOrEqual('09:00', substr($t, 0, 5), (string) $item->title);
                }
            }
        }
    }

    public function test_the_itinerary_header_states_origin_pick_up_start_end_and_length(): void
    {
        $this->post(route('plan.update'), $this->form([
            'visitor_type' => 'Regular / Domestic', 'visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'Cebu City',
        ]))->assertSessionHasNoErrors();

        $this->get(route('plan.itinerary'))->assertOk()->assertSee(
            'From Cebu City, Cebu, Philippines · Pick-up at Davao International Airport · Tue, 1 Dec 2026, 2:00 PM PHT → Thu, 3 Dec 2026, 10:00 AM PHT (1 day, 20 hours)',
            false
        );
    }

    public function test_a_local_visitor_who_gives_no_origin_does_not_inherit_the_last_trips_origin(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Foreign', 'visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('From Japan', $this->get(route('plan.itinerary'))->getContent());

        // A local has to say where from too now: with no answer the form is refused and the last trip's origin is not kept.
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Local', 'visitor_origin_country' => '']))->assertSessionHasErrors('visitor_origin_country');
    }

    public function test_the_header_leaves_out_the_origin_when_it_was_not_given(): void
    {
        // "Prefer not to say" is no longer an answer, and a home place in Davao Region keeps the pick-up.
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Foreign', 'visitor_origin_country' => 'NONE']))->assertSessionHasErrors('visitor_origin_country');
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Local']))->assertSessionHasNoErrors();

        $html = $this->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringContainsString('Pick-up at Davao International Airport · Tue, 1 Dec 2026, 2:00 PM PHT', $html);
        $this->assertStringNotContainsString('From Prefer not to say', $html);
    }

    public function test_the_pick_up_has_to_be_inside_davao_region_as_before(): void
    {
        $this->post(route('plan.update'), $this->form(['origin_lat' => '14.5995', 'origin_lng' => '120.9842', 'origin_label' => 'Manila']))->assertSessionHasErrors('origin_label');
    }

    // ---- trips saved before start and end existed ------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: ?string, 2: int, 3: ?string, 4: ?string, 5: ?string, 6: ?int}> */
    public static function oldTrips(): array
    {
        return [
            // arrival date, arrival time, days, finish-by, expected start, expected end, expected duration days
            'with an arrival time, no finish-by' => ['2026-12-01', '14:30:00', 2, null, '2026-12-01 14:30:00', '2026-12-02 23:59:00', 2],
            'no arrival time means 09:00' => ['2026-12-01', null, 3, null, '2026-12-01 09:00:00', '2026-12-03 23:59:00', 3],
            'with a finish-by' => ['2026-12-01', '08:00:00', 2, '17:00:00', '2026-12-01 08:00:00', '2026-12-02 17:00:00', 2],
            'a one-day trip with a finish-by' => ['2026-12-01', '09:00:00', 1, '18:00:00', '2026-12-01 09:00:00', '2026-12-01 18:00:00', 1],
            'a finish-by before the start is ignored' => ['2026-12-01', '08:00:00', 1, '07:00:00', '2026-12-01 08:00:00', '2026-12-01 23:59:00', 1],
            'no arrival date at all' => [null, null, 3, null, null, null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oldTrips')]
    public function test_an_old_trip_is_mapped_to_a_start_and_an_end(?string $date, ?string $time, int $days, ?string $finishBy, ?string $start, ?string $end, ?int $duration): void
    {
        $p = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => $date, 'arrival_time' => $time, 'end_time' => $finishBy,
        ]);

        $this->assertSame($start, $p->tripStart()?->format('Y-m-d H:i:s'));
        $this->assertSame($end, $p->tripEnd()?->format('Y-m-d H:i:s'));
        $this->assertSame($start === null ? $days : $duration, $p->tripDurationDays());
    }

    public function test_the_migration_fills_start_and_end_for_trips_that_already_exist(): void
    {
        $make = fn (array $over) => TouristPreference::create($over + ['travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate']);
        $withTime = $make(['start_date' => '2026-12-01', 'arrival_time' => '14:30:00']);
        $withoutTime = $make(['start_date' => '2026-12-01', 'arrival_time' => null, 'travel_days' => 3]);
        $withFinish = $make(['start_date' => '2026-12-01', 'arrival_time' => '08:00:00', 'end_time' => '17:00:00']);
        $noDate = $make(['start_date' => null]);

        // Run the migration again from the schema as it was before it.
        Schema::table('tourist_preferences', fn ($table) => $table->dropColumn(['start_at', 'end_at', 'duration_days']));
        $migration = require database_path('migrations/2026_11_14_000100_add_trip_start_end_to_preferences.php');
        $migration->up();

        $row = fn (TouristPreference $p) => TouristPreference::find($p->id);
        $this->assertSame('2026-12-01 14:30:00', substr((string) $row($withTime)->getRawOriginal('start_at'), 0, 19));
        $this->assertSame('2026-12-02 23:59:00', substr((string) $row($withTime)->getRawOriginal('end_at'), 0, 19));
        $this->assertSame(2, (int) $row($withTime)->duration_days);

        $this->assertSame('2026-12-01 09:00:00', substr((string) $row($withoutTime)->getRawOriginal('start_at'), 0, 19));
        $this->assertSame('2026-12-03 23:59:00', substr((string) $row($withoutTime)->getRawOriginal('end_at'), 0, 19));

        $this->assertSame('2026-12-02 17:00:00', substr((string) $row($withFinish)->getRawOriginal('end_at'), 0, 19));

        $this->assertNull($row($noDate)->getRawOriginal('start_at'));
        $this->assertNull($row($noDate)->duration_days);

        // The old columns are untouched, so turning the switch off still works.
        $this->assertSame(3, (int) $row($withoutTime)->travel_days);
        $this->assertSame('2026-12-01', $row($withoutTime)->start_date->toDateString());
    }

    public function test_a_saved_trip_whose_start_has_passed_asks_for_a_new_start_instead_of_showing_the_old_one(): void
    {
        $p = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-11-10', 'arrival_time' => '08:00:00',
        ]);
        $html = $this->withSession([\App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $p->id])->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('data-start-passed', $html);
        $this->assertStringContainsString('Your saved trip started on Tue, 10 Nov 2026, 8:00 AM (Philippine time), which has passed. Please choose a new start and end date and time.', $html);
        $this->assertStringNotContainsString('value="2026-11-10T08:00"', $html);
        $this->assertMatchesRegularExpression('/id="start_at" name="start_at" value=""/', $html);

        // A future saved trip is shown as it was, with no message; an administrator keeps a past one.
        $future = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-12-05', 'arrival_time' => '08:00:00',
        ]);
        $ok = $this->withSession([\App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $future->id])->get(route('plan.edit'))->getContent();
        $this->assertStringNotContainsString('data-start-passed', $ok);
        $this->assertStringContainsString('value="2026-12-05T08:00"', $ok);

        $admin = $this->actingAs($this->admin(), 'admin')->withSession([\App\Http\Controllers\TripPlannerController::PREFERENCE_KEY => $p->id])->get(route('plan.edit'))->getContent();
        $this->assertStringNotContainsString('data-start-passed', $admin);
        $this->assertStringContainsString('value="2026-11-10T08:00"', $admin);
    }

    public function test_the_itinerary_says_the_end_includes_the_return_to_the_pick_up(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $this->get(route('plan.itinerary'))->assertOk()
            ->assertSee('data-trip-span-note', false)
            ->assertSee('The end time includes the return to your pick-up location, with 2 hours to spare.', false);
    }

    public function test_the_return_line_says_end_time_not_finish_by(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $html = $this->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringContainsString('before your 10:00 AM end time, a planning allowance.', $html);
        $this->assertStringNotContainsString('finish-by', $html);
    }

    public function test_the_origin_label_keeps_travelling_from_and_the_old_script_leaves_it_alone(): void
    {
        $this->get(route('plan.edit'))->assertOk()->assertSee('Where are you travelling from?', false);

        // app.js used to overwrite the label with "visiting from" for everyone; it must skip the new origin block.
        $js = file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString("if (label && !field.hasAttribute('data-origin-v2')) label.textContent = 'Where are you visiting from?';", $js);
    }

    public function test_the_form_for_an_old_trip_shows_the_start_and_end_it_works_out_to(): void
    {
        $p = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-12-01', 'arrival_time' => '14:30:00', 'end_time' => '17:00:00',
        ]);
        $this->withSession(['plan.preference_id' => $p->id]);

        $reflect = new \ReflectionClassConstant(\App\Http\Controllers\TripPlannerController::class, 'PREFERENCE_KEY');
        $html = $this->withSession([$reflect->getValue() => $p->id])->get(route('plan.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('value="2026-12-01T14:30"', $html);
        $this->assertStringContainsString('value="2026-12-02T17:00"', $html);
    }

    // ---- admin and reports ---------------------------------------------------------------------------------

    public function test_the_trip_plan_report_shows_the_derived_length_and_the_start(): void
    {
        $this->post(route('plan.update'), $this->form())->assertSessionHasNoErrors();

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get('/portal/admin/reports/export.csv?'.http_build_query(['report_type' => 'Trip Plans Created', 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('"Travel Type",Purpose,Budget,Days,Starts,Created', $csv);
        $this->assertStringContainsString('Couple,Leisure,Mid-range,3,"2026-12-01 14:00"', $csv, 'Days is the calendar days touched (3), like the Day 1, 2, 3 tabs');
    }
}
