<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Support\TravelWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression tests for the three code defects found by the 2026-11 algorithm audit, written before the fixes:
 *
 *  F1  meals never offer a restaurant that is closed (own status, or a danger advisory) on the trip dates;
 *  F2  the 18:00 sightseeing cutoff still holds after the traveller waits for a place to open;
 *  F3  one driving ceiling (MAX_DAILY_DRIVE_MINUTES) applies to every trip and every leg of the day: a day's first stop,
 *      the souvenir stop and the way back all count; a single stop that alone exceeds it is placed on its own day
 *      with a visible warning;
 *  2d  guard: a late check-in never carries a time earlier than the drive that precedes it (the "18:36" report was an
 *      audit-script artefact), and the 5.5-hour evening drive fixture stays inside the ceiling or is flagged.
 *
 * All inputs are fixed; nothing here is random.
 */
class ScheduleAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 7.0731;

    private const ORIGIN_LNG = 125.6128;

    private const KM = 111.0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        TravelWindow::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function region(string $name = 'Davao City'): int
    {
        return Region::firstOrCreate(['name' => $name])->id;
    }

    /** A destination $northKm north of the origin (negative = south). */
    private function stop(string $name, float $northKm, ?string $hours = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / self::KM, 'longitude' => self::ORIGIN_LNG, 'hours' => $hours,
        ]);
    }

    private function restaurant(string $name, float $northKm, array $extra = []): Restaurant
    {
        return Restaurant::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'cuisine_type' => 'Cafe', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'latitude' => self::ORIGIN_LAT + $northKm / self::KM, 'longitude' => self::ORIGIN_LNG, 'opening_hours' => '10:00 AM–9:00 PM',
        ] + $extra);
    }

    private function shop(string $name, float $northKm): SouvenirCenter
    {
        return SouvenirCenter::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'latitude' => self::ORIGIN_LAT + $northKm / self::KM, 'longitude' => self::ORIGIN_LNG,
        ]);
    }

    private function stay(string $name, float $northKm): Accommodation
    {
        return Accommodation::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region(),
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / self::KM, 'longitude' => self::ORIGIN_LNG,
        ]);
    }

    /**
     * @param  array<int, Destination>  $stops
     * @param  array<int, array<int, string>>  $capacities
     */
    private function build(array $stops, array $capacities, string $distancePref = 'moderate', string $arrival = '07:00', ?string $transport = null, ?Accommodation $stay = null): Itinerary
    {
        $preference = TouristPreference::create([
            'travel_days' => count($capacities), 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => $distancePref, 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila', 'start_date' => '2026-10-28', 'arrival_time' => $arrival, 'transport_pref' => $transport,
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => count($capacities), 'generated_at' => now()]);

        TravelWindow::set(Carbon::parse('2026-10-28'), Carbon::parse('2026-10-28')->addDays(count($capacities) - 1));

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary,
            array_map(fn (Destination $d) => ['row' => ['destination' => $d->load('region', 'tags'), 'drs' => 4.0], 'distance_km' => 1.0], $stops),
            $preference,
            $capacities,
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'],
            $stay ? ['listing' => $stay, 'rule' => null] : null,
        );

        $itinerary = $itinerary->fresh('items');
        if (getenv('DUMP')) {
            fwrite(STDERR, "
".$itinerary->items->sortBy('sort_order')->map(fn ($r) => $r->day_number.' '.substr((string) $r->starts_at, 0, 5).' '.$r->kind.' '.$r->title.' '.$r->travel_max_minutes.' '.$r->note)->implode("
")."
");
        }

        return $itinerary;
    }

    /** Minutes of driving written on one day (the upper estimate shown on each travel row). */
    private function drivingMinutes(Itinerary $itinerary, int $day): int
    {
        return (int) $itinerary->items->where('day_number', $day)->where('kind', 'travel')->sum('travel_max_minutes');
    }

    private function sights(Itinerary $itinerary, int $day)
    {
        return $itinerary->items->where('day_number', $day)->where('kind', 'activity')->whereNotNull('destination_id');
    }

    // ---------------------------------------------------------------- F1

    public function test_f1_lunch_is_never_a_restaurant_that_is_temporarily_closed(): void
    {
        $falls = $this->stop('Hagimit Falls', 2);
        $closed = $this->restaurant('Closer Cafe', 1, ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-12-01']);
        $open = $this->restaurant('Farther Cafe', 5);

        $itinerary = $this->build([$falls], [1 => ['Afternoon']], 'moderate', '12:00');
        $meals = $itinerary->items->where('kind', 'meal');

        $this->assertNotEmpty($meals);
        $this->assertFalse($meals->contains('restaurant_id', $closed->id), 'a closed restaurant was offered for a meal');
        $this->assertTrue($meals->contains('restaurant_id', $open->id), 'the open restaurant takes the meal instead');
    }

    public function test_f1_lunch_is_never_a_restaurant_under_a_closing_advisory_on_the_trip_dates(): void
    {
        $falls = $this->stop('Hagimit Falls', 2);
        $flooded = $this->restaurant('Closer Cafe', 1);
        $open = $this->restaurant('Farther Cafe', 5);
        Advisory::create(['title' => 'Flooded', 'message' => 'Closed', 'severity' => 'danger', 'listing_kind' => 'restaurant', 'listing_id' => $flooded->id, 'starts_at' => '2026-10-20', 'ends_at' => '2026-11-30']);

        $meals = $this->build([$falls], [1 => ['Afternoon']], 'moderate', '12:00')->items->where('kind', 'meal');

        $this->assertFalse($meals->contains('restaurant_id', $flooded->id), 'a restaurant under a danger advisory was offered');
        $this->assertTrue($meals->contains('restaurant_id', $open->id));
    }

    // ---------------------------------------------------------------- F2

    public function test_f2_a_visit_delayed_by_opening_time_still_ends_by_the_6_pm_cutoff(): void
    {
        // A night market that opens at 4 PM: arriving at about 3:30 PM means a short wait, and the 150-minute visit that
        // follows would run on to 6:30 PM. Day 1 is not the last day, so the visit is not trimmed for the way home.
        $market = $this->stop('Evening Night Market', 2, '4:00 PM–10:00 PM');

        $itinerary = $this->build([$market], [1 => ['Afternoon', 'Evening'], 2 => ['Morning']], 'moderate', '15:00');

        $this->assertNotEmpty($this->sights($itinerary, 1), 'the market is planned on day 1');
        foreach ($itinerary->items->where('kind', 'activity')->whereNotNull('destination_id') as $row) {
            $this->assertLessThanOrEqual('18:00:00', (string) $row->ends_at, 'a sightseeing stop ends after 18:00: '.$row->starts_at.'-'.$row->ends_at);
        }
    }

    // ---------------------------------------------------------------- F3

    public function test_f3_a_single_stop_that_alone_exceeds_the_ceiling_gets_its_own_day_with_a_visible_warning(): void
    {
        // 100 km out: about 5.5 h each way, 11 h in the car for one stop. Not dropped, not hidden: alone, and flagged.
        $far = $this->stop('Far Highland Falls', 100);
        $near = $this->stop('City Garden', 3);

        $itinerary = $this->build([$far, $near], [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], 'moderate', '07:00', null, $this->stay('City Hotel', 2));

        foreach ([1, 2] as $day) {
            $driving = $this->drivingMinutes($itinerary, $day);
            if ($driving > ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES) {
                $this->assertCount(1, $this->sights($itinerary, $day), "day $day drives $driving min and carries more than the one stop");
                $this->assertTrue(
                    $itinerary->items->where('day_number', $day)->contains(fn ($r) => str_contains((string) $r->note, 'hours of driving')),
                    "day $day drives $driving min with no visible warning",
                );
            }
        }

        $this->assertTrue($itinerary->items->contains('destination_id', $far->id), 'the far stop is still planned');
        $farDay = $itinerary->items->firstWhere('destination_id', $far->id)->day_number;
        $this->assertCount(1, $this->sights($itinerary, $farDay), 'the far stop shares its day with nothing else');
        $this->assertTrue(
            $itinerary->items->where('day_number', $farDay)->contains(fn ($r) => str_contains((string) $r->note, 'hours of driving')),
            'the far stop is placed without any warning about the driving',
        );
    }

    public function test_f3_a_souvenir_stop_that_would_push_the_day_over_the_ceiling_is_left_out_on_a_moderate_trip(): void
    {
        // Stop 62 km out (3.5 h each way, 7 h on the road); a shop 14 km further on would make it 8.5 h.
        $stop = $this->stop('Upland Falls', 62);
        $this->shop('Far Crafts', 76);

        $itinerary = $this->build([$stop], [1 => ['Morning', 'Afternoon', 'Evening']], 'moderate', '07:00');

        $this->assertSame(1, $this->sights($itinerary, 1)->count(), 'the stop itself is planned');
        $this->assertFalse($itinerary->items->contains(fn ($r) => $r->title === 'Souvenir shopping'), 'the souvenir stop pushed the day over the ceiling');
        $this->assertLessThanOrEqual(ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES, $this->drivingMinutes($itinerary, 1));
    }

    // ---------------------------------------------------------------- 2d

    public function test_2d_guard_a_late_checkin_never_carries_a_time_earlier_than_the_drive_before_it(): void
    {
        $itinerary = $this->eveningDriveFixture();

        foreach ($itinerary->items->groupBy('day_number') as $rows) {
            $rows = $rows->sortBy('sort_order')->values();
            foreach ($rows as $i => $row) {
                if ($row->kind !== 'overnight') {
                    continue;
                }
                $travel = $rows->take($i)->where('kind', 'travel')->last();
                if ($row->starts_at !== null && $travel?->starts_at !== null) {
                    $this->assertGreaterThanOrEqual((string) $travel->starts_at, (string) $row->starts_at, 'the overnight row is stamped earlier than the drive that ends at it');
                } else {
                    $this->assertStringContainsString('after midnight', (string) $row->note, 'a row with no time must say why');
                }
            }
        }
    }

    public function test_2d_fixture_a_five_and_a_half_hour_evening_drive_stays_inside_the_ceiling_or_is_flagged(): void
    {
        $itinerary = $this->eveningDriveFixture();

        $drive = $itinerary->items->where('day_number', 1)->where('kind', 'travel')->sortByDesc('sort_order')->first();
        $this->assertGreaterThanOrEqual(300, $drive->travel_max_minutes, 'the fixture really has a long evening drive');
        $this->assertGreaterThanOrEqual('19:00:00', (string) $drive->starts_at, 'and it starts after dinner');

        $driving = $this->drivingMinutes($itinerary, 1);
        $this->assertTrue(
            $driving <= ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES
                || $itinerary->items->where('day_number', 1)->contains(fn ($r) => str_contains((string) $r->note, 'hours of driving')),
            "day 1 drives $driving min with no warning",
        );
    }

    /** Day 1: a stop open 3:00-5:30 PM, dinner at 6, then a jeep drive of about 5.5 hours to a stay far away. */
    private function eveningDriveFixture(): Itinerary
    {
        $stop = $this->stop('Afternoon Garden', 10, '3:00 PM–5:30 PM');
        $stay = $this->stay('Far Beach Resort', -50);

        return $this->build([$stop], [1 => ['Afternoon'], 2 => ['Morning']], 'moderate', '07:00', 'jeep', $stay);
    }
}
