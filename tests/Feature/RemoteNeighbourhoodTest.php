<?php

namespace Tests\Feature;

use App\Models\Destination;
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
    private function place(string $name, float $northKm, float $eastKm, float $rating, ?float $lat = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao Region', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => $rating, 'review_count' => 20, 'price_tier' => 'Mid-range',
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

    private function neighbourhood(\Illuminate\Support\Collection $ranked, int $stops): \Illuminate\Support\Collection
    {
        $method = new ReflectionMethod(ItineraryGenerationService::class, 'withinOneNeighbourhood');

        return $method->invoke(app(ItineraryGenerationService::class), $ranked, $stops, TouristPreference::make(['distance_pref' => 'far']));
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
