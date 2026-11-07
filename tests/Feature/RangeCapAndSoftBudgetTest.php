<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Support\TravelWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F3 follow-up. (a) A Close or Commutable trip that has to widen its range never admits a place whose round trip would not
 * fit the 8-hour driving ceiling, and the traveller is told how many were left out; Remote keeps them. (b) A souvenir stop
 * has to fit inside the day's driving budget (3.5 / 5 / 7 hours), not only the 8-hour ceiling.
 */
class RangeCapAndSoftBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 7.0731;

    private const LNG = 125.6128;

    private const KM = 111.0;

    protected function tearDown(): void
    {
        TravelWindow::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function stop(string $name, float $northKm): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'hours' => '8:00 AM-5:00 PM',
            'latitude' => self::LAT + $northKm / self::KM, 'longitude' => self::LNG,
        ]);
    }

    private function preference(string $distance, int $days = 4): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => $distance, 'start_date' => now()->addDays(10)->toDateString(), 'arrival_time' => '07:00',
            'origin_lat' => self::LAT, 'origin_lng' => self::LNG,
        ]);
    }

    /** Two places inside 5 km, one 60 km out (a day trip), one 130 km out (about 7 hours each way). */
    private function catalogue(): array
    {
        return ['a' => $this->stop('Garden A', 1), 'b' => $this->stop('Garden B', 2), 'day' => $this->stop('Day Trip Falls', 60), 'far' => $this->stop('Remote Coast', 130)];
    }

    public function test_a_widened_close_trip_leaves_out_places_it_cannot_drive_to_and_back_in_a_day(): void
    {
        $d = $this->catalogue();
        $service = app(ContentBasedRecommendationService::class);

        $ids = $service->rank($this->preference('near'))->pluck('destination.id')->all();

        $this->assertTrue($service->lastRangeWidened, 'two places inside 5 km are not enough for four days');
        $this->assertContains($d['day']->id, $ids, 'a day trip that fits the ceiling is still offered');
        $this->assertNotContains($d['far']->id, $ids, 'a place with a round trip over 8 hours is not');
        $this->assertSame(1, $service->lastRangeCappedCount);
    }

    public function test_the_same_cap_applies_to_a_commutable_trip(): void
    {
        $d = $this->catalogue();
        $service = app(ContentBasedRecommendationService::class);

        $ids = $service->rank($this->preference('moderate', 6))->pluck('destination.id')->all();

        $this->assertNotContains($d['far']->id, $ids);
        $this->assertSame(1, $service->lastRangeCappedCount);
    }

    public function test_a_remote_trip_keeps_the_far_place_and_reports_nothing_capped(): void
    {
        $d = $this->catalogue();
        $service = app(ContentBasedRecommendationService::class);

        $ids = $service->rank($this->preference('far'))->pluck('destination.id')->all();

        $this->assertContains($d['far']->id, $ids);
        $this->assertSame(0, $service->lastRangeCappedCount);
    }

    public function test_a_close_trip_that_does_not_widen_is_not_capped(): void
    {
        foreach (['Garden C' => 1, 'Garden D' => 2, 'Garden E' => 3, 'Garden F' => 4] as $name => $km) {
            $this->stop($name, $km);
        }
        $service = app(ContentBasedRecommendationService::class);

        $service->rank($this->preference('near', 1));

        $this->assertFalse($service->lastRangeWidened);
        $this->assertSame(0, $service->lastRangeCappedCount);
    }

    public function test_the_trip_keeps_the_count_and_the_page_tells_the_traveller(): void
    {
        $d = $this->catalogue();
        $preference = $this->preference('near');

        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');

        $this->assertSame(1, (int) $trip->range_capped_places);
        $this->assertNotContains($d['far']->id, $trip->items->pluck('destination_id')->all());

        $page = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $preference->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringContainsString('too far to visit in a day', $page);
    }

    public function test_a_remote_trip_shows_no_such_notice(): void
    {
        $this->catalogue();
        $preference = $this->preference('far');

        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh();

        $this->assertSame(0, (int) $trip->range_capped_places);
        $page = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $preference->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringNotContainsString('too far to visit in a day', $page);
    }

    public function test_a_souvenir_stop_that_fits_the_ceiling_but_not_the_days_budget_is_left_out(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        // A Close day has a 3.5 hour budget (210 min). The stop alone uses most of it; a shop 6 km further on keeps the day
        // under 8 hours but over 3.5.
        $stop = $this->stop('Hill Falls', 28);
        SouvenirCenter::create([
            'slug' => 'hill-crafts', 'name' => 'Hill Crafts', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'latitude' => self::LAT + 34 / self::KM, 'longitude' => self::LNG,
        ]);

        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Manila', 'start_date' => '2026-10-28', 'arrival_time' => '07:00',
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 1, 'generated_at' => now()]);
        TravelWindow::set(Carbon::parse('2026-10-28'), Carbon::parse('2026-10-28'));

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary,
            [['row' => ['destination' => $stop->load('region', 'tags'), 'drs' => 4.0], 'distance_km' => 1.0]],
            $preference,
            [1 => ['Morning', 'Afternoon', 'Evening']],
            ['lat' => self::LAT, 'lng' => self::LNG, 'label' => 'Davao City centre'],
            null,
        );

        $items = $itinerary->fresh('items')->items;
        $this->assertTrue($items->contains('destination_id', $stop->id));
        $this->assertFalse($items->contains(fn ($r) => $r->title === 'Souvenir shopping'), 'the shop would take a Close day past its 3.5 hour budget');
        $this->assertLessThanOrEqual(480, (int) $items->where('kind', 'travel')->sum('travel_max_minutes'));
    }
}
