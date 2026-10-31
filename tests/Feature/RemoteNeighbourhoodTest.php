<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A Remote trip has no distance limit, so which places it visits is decided by the ranking alone. Left like that it
 * took one stop from the north, one from the coast and one from the south-west and spent its days driving between
 * them. The places are now drawn from one neighbourhood: the one whose best places score the most, so the traveller's
 * interests still choose where the trip goes. Only the order of the pool changes; nothing is removed from it.
 */
class RemoteNeighbourhoodTest extends TestCase
{
    use RefreshDatabase;

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

    /** A place at an offset from the start, in km north and east (about 111 km to a degree). */
    private function place(string $name, float $northKm, float $eastKm, float $rating, ?float $lat = null, string $type = 'Nature & Leisure'): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao Region', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => $type, 'is_accredited' => true, 'rating' => $rating, 'review_count' => 20, 'price_tier' => 'Mid-range',
            'latitude' => $lat ?? 7.07 + $northKm / 111.2, 'longitude' => 125.61 + $eastKm / 110.3,
        ]);
    }

    private function rows(Destination ...$destinations): \Illuminate\Support\Collection
    {
        return collect($destinations)->values()->map(fn (Destination $d, int $i) => ['destination' => $d, 'drs' => 5.0 - $i * 0.1]);
    }

    private function order(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn (array $row) => $row['destination']->name)->all();
    }

    /** The same, with each place's score given instead of derived from its position in the list. */
    private function scoredRows(array $scored): \Illuminate\Support\Collection
    {
        return collect($scored)->values()->map(fn (array $pair) => ['destination' => $pair[0], 'drs' => $pair[1]]);
    }

    /** The start defaults to the origin the offsets in place() are measured from. */
    private function neighbourhood(\Illuminate\Support\Collection $ranked, int $stops, float $originLat = 7.07, float $originLng = 125.61, array $interests = []): \Illuminate\Support\Collection
    {
        $method = new ReflectionMethod(ItineraryGenerationService::class, 'withinOneNeighbourhood');
        $preference = TouristPreference::make(['distance_pref' => 'far'])
            ->setRelation('activities', collect($interests)->map(fn (string $interest) => new PreferenceActivity(['activity' => $interest])));

        return $method->invoke(app(ItineraryGenerationService::class), $ranked, $stops, $preference, $originLat, $originLng);
    }

    /** Two neighbourhoods 100 km apart, the one by the start scoring a little less. */
    private function twoNeighbourhoods(): array
    {
        $home = [$this->place('Home One', 0, 0, 4.0), $this->place('Home Two', 3, 3, 4.0), $this->place('Home Three', 5, 1, 4.0)];
        $away = [$this->place('Away One', 0, 100, 4.0), $this->place('Away Two', 3, 103, 4.0), $this->place('Away Three', 5, 101, 4.0)];

        return [
            $this->scoredRows(array_merge(array_map(fn ($d) => [$d, 3.0], $home), array_map(fn ($d) => [$d, 3.2], $away))),
            ['Home One', 'Home Two', 'Home Three'], ['Away One', 'Away Two', 'Away Three'],
        ];
    }

    /** A neighbourhood by the start and a clearly better one 100 km off whose places are all beaches. */
    private function homeAndBeaches(float $awayScore): array
    {
        $home = [$this->place('Home One', 0, 0, 4.0), $this->place('Home Two', 3, 3, 4.0), $this->place('Home Three', 5, 1, 4.0)];
        $beaches = [
            $this->place('Beach One', 0, 100, 4.0, null, 'Beach & Island'), $this->place('Beach Two', 3, 103, 4.0, null, 'Beach & Island'),
            $this->place('Beach Three', 5, 101, 4.0, null, 'Beach & Island'),
        ];

        return [
            $this->scoredRows(array_merge(array_map(fn ($d) => [$d, 3.0], $home), array_map(fn ($d) => [$d, $awayScore], $beaches))),
            ['Home One', 'Home Two', 'Home Three'], ['Beach One', 'Beach Two', 'Beach Three'],
        ];
    }

    public function test_a_neighbourhood_by_the_start_beats_a_slightly_better_one_a_long_way_off(): void
    {
        [$ranked, $home] = $this->twoNeighbourhoods();

        $ordered = $this->order($this->neighbourhood($ranked, 3));

        $this->assertSame($home, array_slice($ordered, 0, 3));
    }

    public function test_the_better_neighbourhood_still_wins_when_the_start_is_beside_it(): void
    {
        [$ranked, , $away] = $this->twoNeighbourhoods();

        // Starting at the far neighbourhood's own corner of the map.
        $ordered = $this->order($this->neighbourhood($ranked, 3, 7.07, 125.61 + 100 / 110.3));

        $this->assertSame($away, array_slice($ordered, 0, 3));
    }

    public function test_a_far_neighbourhood_is_not_ruled_out_when_it_is_much_better(): void
    {
        // One mediocre place by the start against a strong neighbourhood hours away: distance costs points, it never forbids.
        $near = $this->place('Lone Near', 0, 0, 4.0);
        $away = [$this->place('Strong One', 0, 100, 4.0), $this->place('Strong Two', 3, 103, 4.0), $this->place('Strong Three', 5, 101, 4.0)];

        $ordered = $this->order($this->neighbourhood($this->scoredRows([[$near, 1.0], [$away[0], 5.0], [$away[1], 5.0], [$away[2], 5.0]]), 3));

        $this->assertSame(['Strong One', 'Strong Two', 'Strong Three'], array_slice($ordered, 0, 3));
        $this->assertSame('Lone Near', $ordered[3], 'the place by the start is not dropped, it only goes behind');
    }

    public function test_a_far_neighbourhood_that_serves_the_chosen_interest_is_worth_the_journey(): void
    {
        [$ranked, $home, $beaches] = $this->homeAndBeaches(4.5);

        $noInterest = $this->order($this->neighbourhood($ranked, 3));
        $beachTraveller = $this->order($this->neighbourhood($ranked, 3, 7.07, 125.61, ['Beach & Island']));

        $this->assertSame($home, array_slice($noInterest, 0, 3), 'with no reason to go far the trip stays by the start');
        $this->assertSame($beaches, array_slice($beachTraveller, 0, 3), 'the beaches are what the traveller asked for');
    }

    public function test_a_far_neighbourhood_that_does_not_serve_the_chosen_interest_is_not_worth_it(): void
    {
        [$ranked, $home] = $this->homeAndBeaches(4.5);

        // The traveller wants hiking; the far neighbourhood is all beaches, so it pays the full price of the way there.
        $ordered = $this->order($this->neighbourhood($ranked, 3, 7.07, 125.61, ['Hiking & Trekking']));

        $this->assertSame($home, array_slice($ordered, 0, 3));
    }

    public function test_a_shorter_trip_pays_more_for_the_way_to_a_neighbourhood(): void
    {
        // The same two neighbourhoods; the far one is better by 7 points a stop.
        [$ranked, $home, $away] = $this->twoNeighbourhoods();
        $scored = $ranked->map(fn (array $row) => ['destination' => $row['destination'], 'drs' => in_array($row['destination']->name, $away, true) ? 10.0 : 3.0]);

        $oneStop = $this->order($this->neighbourhood($scored, 1));
        $threeStops = $this->order($this->neighbourhood($scored, 3));

        $this->assertSame('Home One', $oneStop[0], 'a one-stop trip does not travel hours for one better place');
        $this->assertSame($away, array_slice($threeStops, 0, 3), 'a trip of three stops does');
        $this->assertSame($home, array_slice($threeStops, 3, 3));
    }

    public function test_the_neighbourhood_with_the_most_score_comes_first_whatever_the_single_best_place(): void
    {
        // The single best place is on the coast, alone; the north holds four good places a few km apart.
        $coast = $this->place('Coast Gem', 0, 90, 5.0);
        $north = [
            $this->place('North One', 80, 0, 4.0), $this->place('North Two', 84, 4, 4.0),
            $this->place('North Three', 88, 2, 4.0), $this->place('North Four', 82, 6, 4.0),
        ];

        $ordered = $this->order($this->neighbourhood($this->rows($coast, ...$north), 4));

        $this->assertSame(['North One', 'North Two', 'North Three', 'North Four'], array_slice($ordered, 0, 4));
        $this->assertSame('Coast Gem', $ordered[4], 'the far place is not dropped, it only goes behind');
    }

    public function test_a_small_neighbourhood_is_filled_with_the_nearest_places_outside_it(): void
    {
        $a = $this->place('Hub One', 80, 0, 5.0);
        $b = $this->place('Hub Two', 84, 3, 5.0);
        $nearOutside = $this->place('Next Door', 80, 75, 1.0);
        $farOutside = $this->place('Faraway', 0, 150, 4.9);

        // The neighbourhood holds two of the four stops the trip needs; the nearer of the other two fills first.
        $ordered = $this->order($this->neighbourhood($this->rows($farOutside, $a, $b, $nearOutside), 4));

        $this->assertSame(['Hub One', 'Hub Two'], array_slice($ordered, 0, 2));
        $this->assertSame(['Next Door', 'Faraway'], array_slice($ordered, 2));
    }

    public function test_places_without_a_position_go_last_and_nothing_is_removed(): void
    {
        $a = $this->place('Mapped One', 80, 0, 5.0);
        $b = $this->place('Mapped Two', 84, 3, 4.5);
        $unmapped = Destination::create([
            'slug' => 'no-position', 'name' => 'No Position', 'location' => 'Davao Region', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 5.0, 'review_count' => 20, 'price_tier' => 'Mid-range',
        ]);

        $ordered = $this->order($this->neighbourhood($this->rows($unmapped, $a, $b), 2));

        $this->assertSame(['Mapped One', 'Mapped Two', 'No Position'], $ordered);
    }

    public function test_no_mapped_place_leaves_the_ranking_as_it_was(): void
    {
        $x = Destination::create(['slug' => 'x', 'name' => 'X', 'location' => 'Davao Region', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 5.0, 'review_count' => 20, 'price_tier' => 'Mid-range']);
        $ranked = $this->rows($x);

        $this->assertSame($this->order($ranked), $this->order($this->neighbourhood($ranked, 2)));
    }

    private function plan(string $distance, int $days = 2)
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $distance,
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-10-28',
            'arrival_time' => '07:00', 'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start',
        ]);

        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), 7.07, 125.61);
    }

    private function visited($itinerary): array
    {
        return $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->with('destination')->get()
            ->map(fn ($row) => $row->destination->name)->unique()->values()->all();
    }

    public function test_a_remote_trip_visits_one_neighbourhood_not_a_place_from_each(): void
    {
        // Two neighbourhoods about 4 hours apart; the north is the stronger one, the coast has the single best place.
        $this->place('Coast Gem', 0, 90, 5.0);
        $this->place('Coast Two', 3, 94, 5.0);
        foreach (['North One' => [80, 0], 'North Two' => [84, 4], 'North Three' => [88, 2], 'North Four' => [82, 6]] as $name => [$n, $e]) {
            $this->place($name, $n, $e, 3.8);
        }

        $visited = $this->visited($this->plan('far'));

        $this->assertNotEmpty($visited);
        $this->assertSame([], array_filter($visited, fn (string $name) => str_starts_with($name, 'Coast')), 'no stop is taken from the other neighbourhood');
    }
}
