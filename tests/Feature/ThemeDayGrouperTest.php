<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\DestinationTag;
use App\Models\Itinerary;
use App\Models\Region;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme-based days: embeddings decide which stops share a day, never which stops
 * exist. The grouping must put alike places together, keep every stop and every
 * day size, stay within the travelling limit, be repeatable, and change nothing
 * when the vectors are not there.
 */
class ThemeDayGrouperTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private const ORIGIN = ['lat' => 7.10, 'lng' => 125.50];

    private const TWO_STOPS_A_DAY = [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::create(['name' => 'Davao City']);
    }

    private function place(string $name, array $vector, float $lat = 7.10, float $lng = 125.50, ?string $tag = null, bool $embed = true): Destination
    {
        $destination = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
        ]);

        if ($tag) {
            DestinationTag::create(['destination_id' => $destination->id, 'kind' => 'category', 'value' => $tag]);
        }

        if ($embed) {
            DestinationEmbedding::create([
                'destination_id' => $destination->id, 'model' => 'test', 'dimensions' => count($vector),
                'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise($vector),
            ]);
        }

        return $destination;
    }

    /** @param  array<int, Destination>  $places */
    private function sequence(array $places): array
    {
        return array_map(fn (Destination $d) => ['row' => ['destination' => $d->load('tags'), 'drs' => 4.0], 'distance_km' => 1.0], $places);
    }

    private function names(array $sequence): array
    {
        return array_map(fn ($e) => $e['row']['destination']->name, $sequence);
    }

    public function test_alike_places_are_put_in_the_same_day(): void
    {
        // route order: wildlife, farm, wildlife, farm -> plain days would mix them
        $zoo = $this->place('Zoo', [1, 0], tag: 'wildlife');
        $farm = $this->place('Farm', [0, 1], tag: 'farm');
        $aviary = $this->place('Aviary', [0.95, 0.05], tag: 'wildlife');
        $orchard = $this->place('Orchard', [0.05, 0.95], tag: 'farm');

        $result = app(ThemeDayGrouper::class)->group($this->sequence([$zoo, $farm, $aviary, $orchard]), self::TWO_STOPS_A_DAY, self::ORIGIN);

        $this->assertNotNull($result);
        // Two equally good answers exist (which pair takes day 1), so check the pairs, not the day order.
        $names = $this->names($result['sequence']);
        $days = [array_slice($names, 0, 2), array_slice($names, 2, 2)];
        sort($days[0]);
        sort($days[1]);
        $this->assertEqualsCanonicalizing([['Aviary', 'Zoo'], ['Farm', 'Orchard']], $days);
        $this->assertTrue($result['summary']['regrouped']);
        $this->assertGreaterThan($result['summary']['mean_similarity_before'], $result['summary']['mean_similarity_after']);
        $this->assertEqualsCanonicalizing(['Wildlife day', 'Farm day'], [$result['days'][1]['label'], $result['days'][2]['label']]);
    }

    public function test_every_stop_and_every_day_size_is_kept(): void
    {
        $places = [];
        foreach ([[1, 0], [0, 1], [0.9, 0.1], [0.1, 0.9], [0.5, 0.5]] as $i => $v) {
            $places[] = $this->place('Place '.$i, $v);
        }
        $capacities = [1 => ['Afternoon'], 2 => ['Morning', 'Afternoon'], 3 => ['Morning', 'Afternoon']];

        $result = app(ThemeDayGrouper::class)->group($this->sequence($places), $capacities, self::ORIGIN);

        $this->assertEqualsCanonicalizing($this->names($this->sequence($places)), $this->names($result['sequence']));
        $this->assertSame([1, 2, 2], array_map(fn ($d) => count($d['stops']), array_values($result['days'])));
    }

    public function test_it_will_not_regroup_when_that_would_add_too_much_travelling(): void
    {
        // two wildlife places 60 km apart: pairing them would more than double the travelling
        $nearZoo = $this->place('Near Zoo', [1, 0], 7.10, 125.50);
        $nearFarm = $this->place('Near Farm', [0, 1], 7.10, 125.51);
        $farZoo = $this->place('Far Zoo', [0.95, 0.05], 7.65, 125.50);
        $farFarm = $this->place('Far Farm', [0.05, 0.95], 7.65, 125.51);

        $sequence = $this->sequence([$nearZoo, $nearFarm, $farZoo, $farFarm]);
        $result = app(ThemeDayGrouper::class)->group($sequence, self::TWO_STOPS_A_DAY, self::ORIGIN);

        $this->assertSame($this->names($sequence), $this->names($result['sequence']));
        $this->assertFalse($result['summary']['regrouped']);
        $this->assertLessThanOrEqual(
            $result['summary']['distance_before_km'] * (1 + ThemeDayGrouper::MAX_EXTRA_DISTANCE) + ThemeDayGrouper::DISTANCE_ALLOWANCE_KM,
            $result['summary']['distance_after_km']
        );
    }

    public function test_the_same_trip_always_groups_the_same_way(): void
    {
        $places = [
            $this->place('A', [1, 0]), $this->place('B', [0, 1]), $this->place('C', [0.9, 0.1]), $this->place('D', [0.1, 0.9]),
        ];
        $grouper = app(ThemeDayGrouper::class);

        $first = $grouper->group($this->sequence($places), self::TWO_STOPS_A_DAY, self::ORIGIN);
        $second = $grouper->group($this->sequence($places), self::TWO_STOPS_A_DAY, self::ORIGIN);

        $this->assertSame($this->names($first['sequence']), $this->names($second['sequence']));
    }

    public function test_nothing_changes_when_a_stop_has_no_vector(): void
    {
        $places = [
            $this->place('A', [1, 0]), $this->place('B', [0, 1]), $this->place('C', [0.9, 0.1]),
            $this->place('D', [0.1, 0.9], embed: false),
        ];

        $this->assertNull(app(ThemeDayGrouper::class)->group($this->sequence($places), self::TWO_STOPS_A_DAY, self::ORIGIN));
    }

    public function test_a_trip_with_fewer_than_three_stops_is_left_alone(): void
    {
        $places = [$this->place('A', [1, 0]), $this->place('B', [0, 1])];

        $this->assertNull(app(ThemeDayGrouper::class)->group($this->sequence($places), self::TWO_STOPS_A_DAY, self::ORIGIN));
    }

    public function test_a_generated_itinerary_records_the_themes_and_the_page_shows_them(): void
    {
        foreach ([['Zoo', [1, 0], 'wildlife'], ['Farm', [0, 1], 'farm'], ['Aviary', [0.95, 0.05], 'wildlife'], ['Orchard', [0.05, 0.95], 'farm']] as $i => [$name, $vector, $tag]) {
            $this->place($name, $vector, 7.10 + $i * 0.01, 125.50, $tag);
        }

        $this->postJson('/plan', [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'far',
            'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        $itinerary = Itinerary::latest('generated_at')->first();
        $this->assertNotNull($itinerary->day_themes);
        $this->assertArrayHasKey('summary', $itinerary->day_themes);
        $this->assertNotEmpty($itinerary->day_themes['days']);

        $this->get(route('plan.itinerary'))->assertOk()->assertSee('these places are');
    }

    public function test_without_vectors_the_itinerary_has_no_themes_and_still_works(): void
    {
        foreach ([['Zoo', 'wildlife'], ['Farm', 'farm'], ['Aviary', 'wildlife'], ['Orchard', 'farm']] as $i => [$name, $tag]) {
            $this->place($name, [1, 0], 7.10 + $i * 0.01, 125.50, $tag, embed: false);
        }

        $this->postJson('/plan', [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'far',
            'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        $itinerary = Itinerary::latest('generated_at')->first();
        $this->assertNull($itinerary->day_themes);
        $this->assertTrue($itinerary->items()->whereNotNull('destination_id')->exists());
        $this->get(route('plan.itinerary'))->assertOk()->assertDontSee('Days grouped by theme');
    }
}
