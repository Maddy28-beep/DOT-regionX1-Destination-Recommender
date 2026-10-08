<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regrouping stops into days must never cost the traveller a stop, must report an independent
 * coherence measure, and must leave the content-based ranking exactly as it was.
 *
 * A stand-in grouper forces a regrouping, and a stand-in schedule builder drops a stop whenever it is
 * given that regrouped order, which is what the real builder does when a day cannot finish by its
 * cutoff. That makes the guard's decision deterministic to test.
 */
class ThemeDayGuardTest extends TestCase
{
    use RefreshDatabase;

    private function places(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([
            ['Zoo', 'Wildlife', 7.12, 125.64], ['Eagle Centre', 'Wildlife', 7.15, 125.41],
            ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Old Church', 'Cultural Heritage', 7.06, 125.60],
            ['Beach One', 'Beach & Leisure', 7.15, 125.71], ['Eden Park', 'Nature & Adventure', 7.03, 125.37],
        ] as $i => [$name, $type, $lat, $lng]) {
            $destination = Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => $type, 'is_accredited' => true, 'rating' => 4.5 - $i * 0.1,
                'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
            DestinationEmbedding::create([
                'destination_id' => $destination->id, 'model' => 'test', 'dimensions' => 2,
                'text_hash' => sha1($name), 'vector' => DestinationEmbeddingService::normalise([$i % 2, 1 - $i % 2]),
            ]);
        }
    }

    private function preference(): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'far', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'place_of_origin' => 'Cebu City',
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);
        PreferenceAmenity::create(['preference_id' => $preference->id, 'amenity' => 'Parking Area']);

        return $preference->load('activities', 'amenities');
    }

    /** Always regroups: reverses the route order, and says so. */
    private function reversingGrouper(): ThemeDayGrouper
    {
        return new class extends ThemeDayGrouper {
            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                return [
                    'sequence' => array_reverse($sequence),
                    'days' => [],
                    'summary' => [
                        'regrouped' => true, 'mean_similarity_before' => 50, 'mean_similarity_after' => 60,
                        'distance_before_km' => 10.0, 'distance_after_km' => 11.0,
                        'types_per_day_before' => 2.0, 'types_per_day_after' => 1.0,
                    ],
                ];
            }
        };
    }

    /** Records every stop of the order it is given, except that it drops one when asked to. */
    private function builder(bool $dropOneWhenReversed): ItineraryScheduleBuilder
    {
        return new class($dropOneWhenReversed) extends ItineraryScheduleBuilder {
            public function __construct(private bool $dropOneWhenReversed) {}

            public function build($itinerary, array $sequence, $preference, array $dayCapacities, array $origin, ?array $stay): void
            {
                $ids = array_map(fn ($e) => $e['row']['destination']->id, $sequence);
                $reversed = $ids !== [] && $ids[0] > end($ids);

                foreach ($sequence as $i => $entry) {
                    if ($this->dropOneWhenReversed && $reversed && $i === count($sequence) - 1) {
                        continue; // the cutoff: this stop no longer fits
                    }
                    ItineraryItem::create([
                        'itinerary_id' => $itinerary->id, 'day_number' => intdiv($i, 2) + 1, 'sort_order' => $i, 'slot' => 'Morning',
                        'kind' => 'activity', 'title' => 'Visit '.$entry['row']['destination']->name,
                        'destination_id' => $entry['row']['destination']->id,
                    ]);
                }
            }
        };
    }

    private function service(ThemeDayGrouper $grouper, ItineraryScheduleBuilder $builder): ItineraryGenerationService
    {
        return new ItineraryGenerationService(
            app(ContentBasedRecommendationService::class), app(AprioriService::class), $builder, $grouper
        );
    }

    private function placed($itinerary): int
    {
        return $itinerary->items()->whereNotNull('destination_id')->distinct()->count('destination_id');
    }

    public function test_a_regrouping_that_would_lose_a_stop_is_undone(): void
    {
        $this->places();

        $itinerary = $this->service($this->reversingGrouper(), $this->builder(dropOneWhenReversed: true))->generate($this->preference());

        $this->assertSame(6, $this->placed($itinerary), 'All six stops stay in the plan.');
        $this->assertFalse($itinerary->day_themes['summary']['regrouped']);
        $this->assertTrue($itinerary->day_themes['summary']['guard']);
        $this->assertSame(
            $itinerary->day_themes['summary']['mean_similarity_before'],
            $itinerary->day_themes['summary']['mean_similarity_after'],
            'The summary must not claim a gain that was undone.'
        );
        $this->assertSame(1, $itinerary->items()->where('destination_id', $itinerary->items->first()->destination_id)->count());
    }

    public function test_a_regrouping_that_loses_nothing_is_kept(): void
    {
        $this->places();

        $itinerary = $this->service($this->reversingGrouper(), $this->builder(dropOneWhenReversed: false))->generate($this->preference());

        $this->assertSame(6, $this->placed($itinerary));
        $this->assertTrue($itinerary->day_themes['summary']['regrouped']);
        $this->assertArrayNotHasKey('guard', $itinerary->day_themes['summary']);
    }

    public function test_the_content_based_ranking_is_identical_with_and_without_regrouping(): void
    {
        $this->places();

        $plain = $this->service(new class extends ThemeDayGrouper {
            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                return null;
            }
        }, $this->builder(false))->generate($this->preference());

        $regrouped = $this->service($this->reversingGrouper(), $this->builder(true))->generate($this->preference());

        $rows = fn ($it) => $it->matches()->orderBy('rank')->get(['destination_id', 'rank', 'match_score', 'pm', 'rs', 'ps', 'ds', 'as'])->toArray();

        $this->assertSame($rows($plain), $rows($regrouped), 'Ranks and the five DRS factors must not change.');
    }

    public function test_the_real_grouper_reports_an_independent_measure(): void
    {
        $this->places();
        $sequence = array_map(
            fn (Destination $d) => ['row' => ['destination' => $d->load('tags'), 'drs' => 4.0], 'distance_km' => 1.0],
            Destination::orderBy('id')->get()->all()
        );

        $result = app(ThemeDayGrouper::class)->group($sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon'], 3 => ['Morning', 'Afternoon']], ['lat' => 7.10, 'lng' => 125.50]);

        $this->assertNotNull($result);
        $this->assertIsFloat($result['summary']['types_per_day_before']);
        $this->assertIsFloat($result['summary']['types_per_day_after']);
        $this->assertGreaterThanOrEqual(1.0, $result['summary']['types_per_day_before']);
    }
}
