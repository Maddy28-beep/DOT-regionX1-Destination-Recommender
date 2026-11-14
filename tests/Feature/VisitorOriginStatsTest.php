<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Geocoding\AddressSuggestionService;
use App\Services\Privacy\AnonymousDataPurger;
use App\Services\Privacy\VisitorOriginStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The permanent anonymous visitor-origin counts (visitor_origin_daily): one count per browser session, a changed answer
 * moves the count, nothing identifying is kept, no counter goes below zero. Behind the trip_location_v2 switch.
 */
class VisitorOriginStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 12:00:00');
        config(['itinerary.departure.airport_buffer_minutes' => 120, 'features.trip_location_v2' => true]);

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
            'travel_type' => 'Couple', 'travel_purpose' => 'Leisure', 'visitor_type' => 'Regular / Foreign',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_at' => '2026-11-28T08:00', 'end_at' => '2026-11-28T17:00',
            'origin_lat' => '7.130', 'origin_lng' => '125.645', 'origin_label' => 'Davao International Airport',
            'activities' => ['Wildlife'], 'amenities' => ['wifi'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function counts(): array
    {
        return DB::table('visitor_origin_daily')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function total(): int
    {
        return (int) DB::table('visitor_origin_daily')->sum('visitors');
    }

    private function groupCount(string $country, string $province = '', string $city = '', string $answer = 'chosen'): int
    {
        return (int) DB::table('visitor_origin_daily')
            ->where(['answer' => $answer, 'country_code' => $country, 'province' => $province, 'city' => $city])->sum('visitors');
    }

    // ---- what is kept -------------------------------------------------------------------------------------

    public function test_the_table_has_no_column_that_could_identify_a_person_or_a_trip(): void
    {
        $columns = Schema::getColumnListing('visitor_origin_daily');
        sort($columns);

        $this->assertSame(['answer', 'city', 'country_code', 'day', 'id', 'province', 'visitors'], $columns);
    }

    // ---- the six cases ------------------------------------------------------------------------------------

    public function test_the_first_answer_adds_one_on_the_philippine_day(): void
    {
        // 20 Nov 17:30 UTC is 21 Nov 01:30 in Manila: the day is the Philippine day.
        Carbon::setTestNow('2026-11-20 17:30:00');

        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_province' => 'Cebu', 'visitor_origin_city' => 'cebu CITY']))->assertSessionHasNoErrors();

        $rows = $this->counts();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-11-21', substr((string) $rows[0]['day'], 0, 10));
        $this->assertSame(1, (int) $rows[0]['visitors']);
        $this->assertSame(1, $this->groupCount('PH', 'Cebu', 'Cebu City'), 'the city is trimmed and in Title Case');
    }

    public function test_a_changed_answer_moves_the_count_and_does_not_add_one(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP', 'visitor_origin_city' => 'Tokyo']))->assertSessionHasNoErrors();
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'KR', 'visitor_origin_city' => 'Seoul']))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->groupCount('JP', '', 'Tokyo'), 'taken off the old group');
        $this->assertSame(1, $this->groupCount('KR', '', 'Seoul'), 'added to the new group');
        $this->assertSame(1, $this->total(), 'still one session');
    }

    public function test_an_unchanged_answer_counts_nothing_more(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        // Something else about the trip changes, the origin does not.
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP', 'budget' => 'Premium']))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->groupCount('JP'));
        $this->assertSame(1, $this->total());
    }

    public function test_a_regenerate_counts_nothing(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();

        $this->post(route('plan.regenerate'))->assertRedirect(route('plan.itinerary'));
        $this->post(route('plan.regenerate'))->assertRedirect(route('plan.itinerary'));

        $this->assertSame(1, $this->total());
    }

    public function test_a_cleared_answer_takes_the_count_off_and_a_new_answer_adds_it_again(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        // Now a local, who is not asked and answers nothing.
        $this->post(route('plan.update'), $this->form(['visitor_type' => 'Regular / Local', 'visitor_origin_country' => '']))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->total());

        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->total());
    }

    public function test_prefer_not_to_say_is_counted_as_its_own_answer(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'NONE', 'visitor_origin_city' => 'Hidden']))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->groupCount('', '', '', 'unspecified'));
        $this->assertSame(0, (int) DB::table('visitor_origin_daily')->where('city', 'Hidden')->count(), 'no city is kept with it');
    }

    // ---- concurrent saves ---------------------------------------------------------------------------------

    public function test_many_saves_of_the_same_group_add_up_exactly(): void
    {
        $answer = ['answer' => 'chosen', 'country' => 'PH', 'province' => 'Cebu', 'city' => 'Cebu City'];

        // Each "save" is its own session: no earlier answer. 40 of them land on the same group.
        for ($i = 0; $i < 40; $i++) {
            VisitorOriginStats::move(null, $answer, now());
        }

        $this->assertSame(40, $this->groupCount('PH', 'Cebu', 'Cebu City'));
        $this->assertSame(1, DB::table('visitor_origin_daily')->count(), 'one row, never a duplicate');
    }

    public function test_a_row_that_another_save_created_a_moment_earlier_is_simply_counted_into(): void
    {
        $answer = ['answer' => 'chosen', 'country' => 'JP', 'province' => '', 'city' => ''];
        // The other save has already created the row (and not yet counted): our insert must do nothing, not fail.
        DB::table('visitor_origin_daily')->insert(['day' => '2026-11-20', 'answer' => 'chosen', 'country_code' => 'JP', 'province' => '', 'city' => '', 'visitors' => 0]);

        VisitorOriginStats::move(null, $answer, now());
        VisitorOriginStats::move(null, $answer, now());

        $this->assertSame(2, $this->groupCount('JP'));
        $this->assertSame(1, DB::table('visitor_origin_daily')->count());
    }

    // ---- never below zero ---------------------------------------------------------------------------------

    public function test_a_counter_never_goes_below_zero(): void
    {
        $answer = ['answer' => 'chosen', 'country' => 'JP', 'province' => '', 'city' => ''];

        // Taking off a group that was never counted does nothing, and does not create a negative row.
        VisitorOriginStats::move($answer, null, now());
        $this->assertSame(0, DB::table('visitor_origin_daily')->count());

        VisitorOriginStats::move(null, $answer, now());
        VisitorOriginStats::move($answer, null, now());
        VisitorOriginStats::move($answer, null, now());   // twice: the second must not go to -1
        VisitorOriginStats::move($answer, null, now());

        $this->assertSame(0, $this->groupCount('JP'));
        $this->assertSame(0, (int) DB::table('visitor_origin_daily')->where('visitors', '<', 0)->count());
    }

    public function test_the_column_cannot_hold_a_negative_number(): void
    {
        $column = collect(Schema::getColumns('visitor_origin_daily'))->firstWhere('name', 'visitors');

        $this->assertStringContainsString('int', strtolower((string) ($column['type_name'] ?? $column['type'] ?? '')));
    }

    // ---- the city -----------------------------------------------------------------------------------------

    public function test_a_city_is_cleaned_before_it_is_kept_for_good(): void
    {
        foreach ([
            ['  davao   city ', 'Davao City'],
            ['DAVAO CITY', 'Davao City'],
            ['Davao City', 'Davao City'],
            ["o'brien-town st. mary", "O'brien-Town St. Mary"],
            ['San José', 'San José'],
        ] as [$in, $out]) {
            $this->assertSame($out, VisitorOriginStats::cleanCity($in), $in);
        }
        $this->assertSame(60, mb_strlen((string) VisitorOriginStats::cleanCity(str_repeat('a', 90))));

        foreach (['Room 12', '12 Main St', 'me@example.com', 'visit https://x.example', 'www.example.com', 'shop.example.com', '0917 123 4567', '', '   ', '---', '<b></b>'] as $bad) {
            $this->assertNull(VisitorOriginStats::cleanCity($bad), $bad);
        }
    }

    public function test_two_spellings_of_a_city_are_one_group(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_city' => 'davao city']))->assertSessionHasNoErrors();
        $this->flushSession();
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_city' => ' Davao  City ']))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->groupCount('PH', '', 'Davao City'));
        $this->assertSame(1, DB::table('visitor_origin_daily')->count());
    }

    public function test_an_address_typed_as_a_city_is_not_kept_but_the_country_still_counts(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'PH', 'visitor_origin_city' => '12 Rizal St, mia@x.com']))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->groupCount('PH', '', ''));
        $this->assertSame(0, (int) DB::table('visitor_origin_daily')->where('city', '!=', '')->count());
    }

    // ---- the rest ------------------------------------------------------------------------------------------

    public function test_nothing_is_counted_with_the_switch_off(): void
    {
        config(['features.trip_location_v2' => false]);

        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP', 'travel_days' => 1, 'start_date' => '2026-11-28', 'arrival_time' => '08:00', 'end_time' => '17:00']));

        $this->assertSame(0, DB::table('visitor_origin_daily')->count());
    }

    public function test_the_thirty_day_purge_leaves_the_counts_alone_and_a_refused_form_counts_nothing(): void
    {
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'JP']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->total());

        // Refused (an unknown country): nothing is counted.
        $this->post(route('plan.update'), $this->form(['visitor_origin_country' => 'ZZ']))->assertSessionHasErrors('visitor_origin_country');
        $this->assertSame(1, $this->total());

        // 31 days later the trip rows are purged exactly as before; the anonymous count stays.
        DB::table('itineraries')->update(['generated_at' => now()->subDays(40)]);
        app(AnonymousDataPurger::class)->purge(now());

        $this->assertSame(0, TouristPreference::count(), 'the trip row is gone, as before');
        $this->assertSame(1, $this->groupCount('JP'), 'the anonymous count is kept');
    }

    public function test_the_migration_does_not_copy_old_answers_into_the_table(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_11_14_000100_create_visitor_origin_daily_table.php'));

        $this->assertStringNotContainsString('tourist_preferences', str_replace('trip rows', '', explode('return new class', $source)[1]), 'no backfill');
    }
}
