<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;
use App\Services\Recommendation\FreeTimeIdeas;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Optional souvenir ideas under Day 1's "Free time until dinner" row.
 *
 * They are suggestions beside the schedule: read from an itinerary that already exists, never stored, never a
 * stop, with no time. Shops must be public, open and free of a closing advisory on Day 1, have coordinates (as
 * must the accommodation), and the round trip on the slow travel estimate plus a 60-minute visit must fit in the
 * free time. At most three, nearest first, a shop the trip already uses is never offered, and with nothing
 * suitable nothing is shown.
 *
 * The clock is frozen and the trip date fixed (Day 1 = 2026-10-28). The fixture produces a 120-minute free time
 * on a 09:00 arrival (checked in every test that depends on it), so the 60-minute visit leaves 60 minutes for the
 * journey both ways: a shop on a 30-minute leg fits exactly, one on a 45-minute leg does not.
 */
class FreeTimeIdeasTest extends TestCase
{
    use RefreshDatabase;

    private const INN_LAT = 7.07;

    private const INN_LNG = 125.61;

    private const NO_IDEAS_WORDING = 'Optional ideas for this free time';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    private function region(): Region
    {
        return Region::firstOrCreate(['name' => 'Davao City']);
    }

    private function fixture(bool $withInn = true): void
    {
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64, 5.0], ['Museum', 'Cultural Heritage', 7.07, 125.61, 4.9], ['Beach One', 'Beach & Leisure', 7.15, 125.71, 4.8], ['Eden Park', 'Nature & Adventure', 7.03, 125.37, 4.7], ['Spare Garden', 'Wildlife', 7.09, 125.63, 3.0]] as [$name, $type, $lat, $lng, $rating]) {
            Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region()->id,
                'type' => $type, 'is_accredited' => true, 'rating' => $rating, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        if ($withInn) {
            Accommodation::create([
                'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $this->region()->id,
                'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
                'latitude' => self::INN_LAT, 'longitude' => self::INN_LNG,
            ]);
        }
    }

    /** A shop roughly $km north of the inn (a degree of latitude is about 111.2 km). */
    private function shop(string $name, ?float $km, array $extra = []): SouvenirCenter
    {
        return SouvenirCenter::create(array_merge([
            'slug' => str($name)->slug()->toString().'-'.uniqid(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region()->id,
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'latitude' => $km === null ? null : self::INN_LAT + $km / 111.2, 'longitude' => $km === null ? null : self::INN_LNG,
        ], $extra));
    }

    private function preference(string $arrival, int $days = 2): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'near', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival,
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        return $preference;
    }

    /** @return array{0: Itinerary, 1: TouristPreference} */
    private function trip(string $arrival = '09:00', int $days = 2): array
    {
        $preference = $this->preference($arrival, $days);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), null, null, []);
        $this->withoutTheBuildersOwnSouvenirStop($itinerary);

        return [$this->loaded($itinerary), $preference];
    }

    /**
     * The builder gives the last day a souvenir stop and may pick one of this test's shops, which the ideas then
     * (rightly) leave out. Removing that stop from the stored test trip keeps these tests about the ideas alone;
     * test_a_shop_the_trip_already_uses_is_not_offered_again covers the exclusion itself.
     */
    private function withoutTheBuildersOwnSouvenirStop(Itinerary $itinerary): void
    {
        $itinerary->items()->whereNotNull('souvenir_center_id')->delete();
    }

    private function loaded(Itinerary $itinerary): Itinerary
    {
        return Itinerary::with('items.accommodation.region')->findOrFail($itinerary->id);
    }

    private function ideas(Itinerary $itinerary, TouristPreference $preference): array
    {
        return app(FreeTimeIdeas::class)->forItinerary($itinerary, $preference);
    }

    /** The Day 1 free row that follows the return journey. */
    private function eveningFree(Itinerary $itinerary)
    {
        return $itinerary->items->where('day_number', 1)->firstWhere('title', 'Free time until dinner');
    }

    private function names(array $ideas): array
    {
        return array_map(fn ($idea) => $idea['shop']->name, array_values($ideas)[0] ?? []);
    }

    private function windowOf($row): int
    {
        return (int) ((strtotime($row->ends_at) - strtotime($row->starts_at)) / 60);
    }

    // ---------------------------------------------------------------------------------------------
    // What gets offered, and where
    // ---------------------------------------------------------------------------------------------

    public function test_nearby_shops_are_offered_under_day_ones_free_time_nearest_first(): void
    {
        $this->fixture();
        $this->shop('Far Shop', 3);
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip();
        $free = $this->eveningFree($itinerary);

        $this->assertNotNull($free, 'Day 1 has the evening free time');
        $this->assertSame(120, $this->windowOf($free), 'the fixture produces the 120-minute window this suite is written for');

        $ideas = $this->ideas($itinerary, $preference);

        $this->assertSame([$free->id], array_keys($ideas), 'the ideas belong to that one row');
        $this->assertSame(['Near Shop', 'Far Shop'], $this->names($ideas));
        $this->assertEqualsWithDelta(1.3, $ideas[$free->id][0]['km'], 0.1, 'the distance is the same approximate road figure the timeline shows (straight line x 1.3)');
    }

    public function test_the_early_arrival_free_time_never_gets_ideas(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip('04:00');

        $early = $itinerary->items->where('day_number', 1)->firstWhere('title', 'Free time until 8:00 AM');
        $this->assertNotNull($early, 'a 04:00 arrival has the early free time');

        $this->assertNotContains($early->id, array_keys($this->ideas($itinerary, $preference)));
    }

    public function test_only_day_one_gets_ideas_never_a_later_or_the_final_day(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip('09:00', 3);

        $dayOfRow = $itinerary->items->pluck('day_number', 'id');
        $this->assertGreaterThanOrEqual(1, $itinerary->items->where('day_number', 2)->where('title', 'Free time until dinner')->count() + 1);

        foreach (array_keys($this->ideas($itinerary, $preference)) as $rowId) {
            $this->assertSame(1, (int) $dayOfRow[$rowId]);
        }
    }

    public function test_a_trip_without_free_time_gets_no_ideas(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip('11:00');

        $this->assertNull($this->eveningFree($itinerary), 'a 11:00 arrival leaves no gap before dinner on this fixture');
        $this->assertSame([], $this->ideas($itinerary, $preference));
    }

    public function test_a_short_free_time_gets_no_ideas_even_with_a_shop_next_door(): void
    {
        $this->fixture();
        $this->shop('Next Door', 0.3);

        [$itinerary, $preference] = $this->trip('10:30');
        $free = $this->eveningFree($itinerary);

        $this->assertSame(30, $this->windowOf($free));
        $this->assertSame([], $this->ideas($itinerary, $preference), '2 x the shortest journey (10 min + overhead) + 60 min does not fit in 30');
    }

    public function test_the_round_trip_plus_the_visit_has_to_fit_exactly(): void
    {
        $this->fixture();
        $this->shop('Fits Exactly', 8);   // 8 km straight = 10.4 km by road: slow estimate 30 min each way, 2 x 30 + 60 = 120
        $this->shop('Just Too Far', 10);  // 10 km straight: slow estimate 45 min each way, 2 x 45 + 60 = 150

        [$itinerary, $preference] = $this->trip();

        $this->assertSame(120, $this->windowOf($this->eveningFree($itinerary)));
        $this->assertSame(['Fits Exactly'], $this->names($this->ideas($itinerary, $preference)));
    }

    // ---------------------------------------------------------------------------------------------
    // Who is excluded
    // ---------------------------------------------------------------------------------------------

    public function test_closed_hidden_unmapped_and_advisory_blocked_shops_are_left_out(): void
    {
        $this->fixture();
        $this->shop('Good Shop', 1);
        $this->shop('No Coordinates', null);
        $archived = $this->shop('Archived Shop', 1.1);
        $archived->forceFill(['archived_at' => now()])->save();
        $this->shop('Not Accredited', 1.2, ['is_accredited' => false]);
        $this->shop('Closed For Good', 1.3, ['operating_status' => 'closed']);
        $this->shop('Closed No Date', 1.4, ['operating_status' => 'temporarily_closed']);
        $this->shop('Reopens After', 1.5, ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-11-15']);
        $blocked = $this->shop('Flooded', 1.6);
        Advisory::create(['title' => 'Flooding', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'souvenir_center', 'listing_id' => $blocked->id, 'starts_at' => '2026-10-27', 'ends_at' => '2026-10-29']);

        [$itinerary, $preference] = $this->trip();

        $this->assertSame(['Good Shop'], $this->names($this->ideas($itinerary, $preference)));
    }

    public function test_a_place_that_has_reopened_and_non_closing_advisories_are_still_offered(): void
    {
        $this->fixture();
        $this->shop('Reopened', 1, ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-10-20']);
        $warned = $this->shop('Just A Warning', 2);
        Advisory::create(['title' => 'Crowds', 'message' => 'x', 'severity' => 'warning', 'listing_kind' => 'souvenir_center', 'listing_id' => $warned->id, 'starts_at' => '2026-10-27', 'ends_at' => '2026-10-29']);
        $later = $this->shop('Closing Later', 3);
        Advisory::create(['title' => 'Repairs', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'souvenir_center', 'listing_id' => $later->id, 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-12']);

        [$itinerary, $preference] = $this->trip();

        $this->assertSame(['Reopened', 'Just A Warning', 'Closing Later'], $this->names($this->ideas($itinerary, $preference)));
    }

    public function test_without_coordinates_for_the_accommodation_there_are_no_ideas(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip();
        Accommodation::query()->update(['latitude' => null, 'longitude' => null]);

        $this->assertSame([], $this->ideas($this->loaded($itinerary), $preference));
    }

    public function test_a_trip_without_an_accommodation_gets_no_ideas(): void
    {
        $this->fixture(withInn: false);
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip();

        $this->assertSame([], $this->ideas($itinerary, $preference));
    }

    public function test_a_package_itinerary_gets_no_ideas(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        [$itinerary, $preference] = $this->trip();
        $itinerary->forceFill(['package_id' => 1]);

        $this->assertSame([], $this->ideas($itinerary, $preference));
    }

    public function test_a_shop_the_trip_already_uses_is_not_offered_again(): void
    {
        $this->fixture();
        $used = $this->shop('Already In The Plan', 1);
        $this->shop('Other Shop', 2);

        [$itinerary, $preference] = $this->trip();
        $itinerary->items()->create(['day_number' => 2, 'sort_order' => 999, 'slot' => 'Afternoon', 'kind' => 'activity', 'title' => 'Souvenir shopping', 'souvenir_center_id' => $used->id, 'starts_at' => '15:00:00', 'ends_at' => '16:00:00']);
        $itinerary = $this->loaded($itinerary);

        $names = $this->names($this->ideas($itinerary, $preference));

        $this->assertNotContains('Already In The Plan', $names);
        $this->assertNotContains($used->name, $names);
    }

    // ---------------------------------------------------------------------------------------------
    // Order, duplicates, the limit
    // ---------------------------------------------------------------------------------------------

    public function test_listings_with_the_same_name_count_once_and_the_nearer_one_wins(): void
    {
        $this->fixture();
        $near = $this->shop('Davao Crafts', 1);
        $this->shop('davao crafts ', 2);
        $this->shop('Other Shop', 3);

        [$itinerary, $preference] = $this->trip();
        $ideas = array_values($this->ideas($itinerary, $preference))[0];

        $this->assertSame(['Davao Crafts', 'Other Shop'], array_map(fn ($i) => $i['shop']->name, $ideas));
        $this->assertSame($near->id, $ideas[0]['shop']->id);
    }

    public function test_ties_go_to_the_better_rated_shop_then_the_older_listing(): void
    {
        $this->fixture();
        $first = $this->shop('Tie A', 1, ['rating' => 4]);
        $second = $this->shop('Tie B', 1, ['rating' => 4]);
        $best = $this->shop('Tie Best', 1, ['rating' => 5]);

        [$itinerary, $preference] = $this->trip();
        $ids = array_map(fn ($i) => $i['shop']->id, array_values($this->ideas($itinerary, $preference))[0]);

        $this->assertSame([$best->id, $first->id, $second->id], $ids);
    }

    public function test_no_more_than_three_are_offered_and_fewer_is_fine(): void
    {
        $this->fixture();
        foreach ([1, 2, 3, 4, 5] as $km) {
            $this->shop("Shop {$km}", $km);
        }

        [$itinerary, $preference] = $this->trip();

        $this->assertSame(['Shop 1', 'Shop 2', 'Shop 3'], $this->names($this->ideas($itinerary, $preference)));

        SouvenirCenter::query()->where('name', '!=', 'Shop 2')->delete();
        $this->assertSame(['Shop 2'], $this->names($this->ideas($itinerary, $preference)), 'one qualifying shop: one idea, no padding');
    }

    // ---------------------------------------------------------------------------------------------
    // The page
    // ---------------------------------------------------------------------------------------------

    private function planPage(string $arrival = '09:00')
    {
        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28',
            'arrival_time' => $arrival, 'activities' => ['Wildlife'], 'amenities' => ['Parking Area'],
        ])->assertRedirect(route('plan.itinerary'));

        $this->withoutTheBuildersOwnSouvenirStop(Itinerary::query()->latest('id')->firstOrFail());

        return $this->get(route('plan.itinerary'))->assertOk();
    }

    public function test_the_page_shows_a_collapsed_optional_section_with_the_disclosure(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);
        $this->shop('<b>Bold</b> Shop', 2);

        $page = $this->planPage();

        $page->assertSee('Optional ideas for this free time (2)');
        $page->assertSee("These are ideas only. They are not part of your plan, nothing is booked, and we can't promise they're open.", false);
        $page->assertSee('Souvenir shop');
        $page->assertSee('Approx. 1.3 km from your accommodation');
        $page->assertSee('Opening hours are not listed. Check before visiting.');
        $page->assertSee('<details class="tl-ideas">', false);
        $page->assertDontSee('<details class="tl-ideas" open', false);
        $page->assertSee('&lt;b&gt;Bold&lt;/b&gt; Shop', false);
        $page->assertDontSee('<b>Bold</b> Shop', false);
        $page->assertSee('Free time until dinner');
    }

    public function test_the_page_shows_nothing_extra_when_no_shop_qualifies(): void
    {
        $this->fixture();
        $this->shop('Too Far Away', 30);

        $page = $this->planPage();

        $page->assertSee('Free time until dinner');
        $page->assertDontSee(self::NO_IDEAS_WORDING);
        $page->assertDontSee('tl-ideas');
    }

    public function test_the_early_arrival_row_shows_no_ideas_on_the_page(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);

        $page = $this->planPage('04:00');

        $page->assertSee('Free time until 8:00 AM');
        $this->assertSame(1, substr_count($page->getContent(), '<details class="tl-ideas">'), 'only the evening row carries ideas');
    }

    // ---------------------------------------------------------------------------------------------
    // Proof that it changes nothing
    // ---------------------------------------------------------------------------------------------

    private function snapshot(Itinerary $itinerary): array
    {
        $fresh = Itinerary::with('items')->findOrFail($itinerary->id);

        return [
            'rows' => $fresh->items->sortBy(['day_number', 'sort_order'])->map(fn ($r) => $r->getAttributes())->values()->all(),
            'route' => $fresh->routeStops(),
            'stops' => $fresh->items->where('kind', 'activity')->whereNotNull('destination_id')->count(),
            'dinners' => $fresh->items->filter(fn ($r) => $r->kind === 'meal' && str_starts_with((string) $r->title, 'Dinner'))->map(fn ($r) => [$r->day_number, $r->starts_at])->values()->all(),
            'nights' => $fresh->items->where('kind', 'overnight')->map(fn ($r) => [$r->day_number, $r->starts_at])->values()->all(),
            'itineraries' => Itinerary::count(),
        ];
    }

    public function test_rendering_the_page_changes_no_itinerary_data_and_writes_nothing(): void
    {
        $this->fixture();
        $this->shop('Near Shop', 1);
        $this->shop('Other Shop', 2);

        $this->planPage();
        $itinerary = Itinerary::query()->latest('id')->firstOrFail();
        $before = $this->snapshot($itinerary);
        $this->assertNotEmpty($before['rows']);
        $this->assertNotEmpty($before['dinners']);
        $this->assertNotEmpty($before['nights']);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $page = $this->get(route('plan.itinerary'))->assertOk();
        $page->assertSee(self::NO_IDEAS_WORDING);

        $this->assertSame([], $writes, 'showing the ideas writes nothing');
        $this->assertSame($before, $this->snapshot($itinerary), 'every row, the route, the stop count, dinner and overnight are as generated');
    }

    public function test_the_number_of_shop_queries_does_not_grow_with_the_number_of_shops(): void
    {
        $this->fixture();
        $this->shop('Shop 1', 1);
        $this->planPage();

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('plan.itinerary'))->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'souvenir_centers"') && str_starts_with(strtolower(ltrim($q['query'])), 'select'))->count();
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();

        foreach ([2, 3, 4, 5, 6] as $km) {
            $this->shop("Shop {$km}", $km);
        }

        $this->assertSame($one, $count(), 'one candidate query however many shops there are');
    }
}
