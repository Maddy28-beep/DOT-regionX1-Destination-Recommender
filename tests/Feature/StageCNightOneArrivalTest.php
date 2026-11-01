<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Stage C: arrival-day and Night-1 handling for Remote ('far') trips.
 *
 * C1: a multi-night Remote trip landing so late that not even a 90-minute visit fits before 18:00 (the day starts at
 *     arrival + 30 minutes, never before 08:00) has no sightseeing slot on its arrival day.
 * C2: when the arrival day has no sightseeing and the first night would be a late check-in (after 19:30), the stay
 *     plan avoids the late stay when a replacement actually improves the built itinerary.
 */
class StageCNightOneArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 7.0731;

    private const ORIGIN_LNG = 125.6128;

    protected function setUp(): void
    {
        parent::setUp();

        // These synthetic long-drive fixtures inspect arrival repair in isolation.
        config(['itinerary.planner.reject_incomplete_trips' => false]);

        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    private function destination(string $name, float $northKm, float $rating = 4.5, ?string $hours = null): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(),
            'name' => $name,
            'location' => 'Davao City',
            'region_id' => $this->region(),
            'type' => 'Nature & Adventure',
            'is_accredited' => true,
            'rating' => $rating,
            'review_count' => 15,
            'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / 111.195,
            'longitude' => self::ORIGIN_LNG,
            'hours' => $hours,
        ]);
    }

    private function stay(string $name, float $northKm): Accommodation
    {
        $stay = Accommodation::create([
            'slug' => str($name)->slug()->toString(),
            'name' => $name,
            'location' => 'Davao City',
            'region_id' => $this->region(),
            'type' => 'Hotel',
            'is_accredited' => true,
            'rating' => 4.2,
            'review_count' => 10,
            'price_tier' => 'Mid-range',
            'latitude' => self::ORIGIN_LAT + $northKm / 111.195,
            'longitude' => self::ORIGIN_LNG,
        ]);

        AccreditationRecord::create([
            'listing_kind' => 'accommodation',
            'listing_id' => $stay->id,
            'accreditation_number' => 'DOT-R1-'.$stay->id,
            'issue_date' => '2024-01-01',
            'expiration_date' => '2028-12-31',
            'status' => 'Active',
        ]);

        return $stay;
    }

    private function preference(int $days, string $distancePref, ?string $arrival = '08:00'): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days,
            'travel_type' => 'Couple',
            'budget' => 'Mid-range',
            'accommodation_pref' => 'Any',
            'distance_pref' => $distancePref,
            'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila',
            'start_date' => '2026-10-28',
            'arrival_time' => $arrival,
            'origin_lat' => self::ORIGIN_LAT,
            'origin_lng' => self::ORIGIN_LNG,
            'origin_label' => 'Davao City centre',
        ]);

        PreferenceActivity::create([
            'preference_id' => $preference->id,
            'activity' => 'Nature & Adventure',
        ]);

        return $preference;
    }

    /**
     * @param  list<Destination>  $stops
     * @param  array<int, array<int, string>>  $capacities
     */
    private function buildSchedule(
        array $stops,
        TouristPreference $preference,
        array $capacities,
        ?Accommodation $stay = null,
    ): array {
        $itinerary = Itinerary::create([
            'preference_id' => $preference->id,
            'total_days' => count($capacities),
            'generated_at' => now(),
        ]);

        $sequence = array_map(
            fn (Destination $d) => ['row' => ['destination' => $d->load('region'), 'drs' => 4.0], 'distance_km' => 1.0],
            $stops,
        );

        $stayArg = $stay ? ['listing' => $stay, 'rule' => null] : null;
        $builder = app(ItineraryScheduleBuilder::class);
        $builder->forgetRecordedAttempts();

        $builder->build(
            $itinerary,
            $sequence,
            $preference,
            $capacities,
            ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'],
            $stayArg,
        );

        return [
            'itinerary' => $itinerary->fresh('items'),
            'attempts' => $builder->recordedAttempts($sequence, $stayArg, $capacities),
        ];
    }

    private function placedNames(Itinerary $itinerary, ?int $day = null): array
    {
        $items = $itinerary->items->where('kind', 'activity')->whereNotNull('destination_id');
        if ($day !== null) {
            $items = $items->where('day_number', $day);
        }

        return $items->sortBy('sort_order')->map(fn ($item) => Destination::find($item->destination_id)->name)->values()->all();
    }

    private function capacities(TouristPreference $preference, int $days): array
    {
        $method = new \ReflectionMethod(ItineraryGenerationService::class, 'dayCapacities');
        $method->setAccessible(true);

        return $method->invoke(app(ItineraryGenerationService::class), $preference, $days);
    }

    private function expiredStay(string $name, float $northKm): Accommodation
    {
        $stay = $this->stay($name, $northKm);
        AccreditationRecord::where('listing_kind', 'accommodation')->where('listing_id', $stay->id)
            ->update(['status' => 'Expired', 'expiration_date' => '2024-02-01']);

        return $stay;
    }

    private function overnightRows(Itinerary $trip): Collection
    {
        return $trip->items->where('kind', 'overnight')->sortBy('day_number')->values();
    }

    // ---- C1: arrival-day capacity ----

    public function test_c1_arrival_after_1600_empties_the_arrival_day_of_a_multi_night_remote_trip(): void
    {
        $this->assertSame([], $this->capacities($this->preference(3, 'far', '16:30'), 3)[1]);
        $this->assertSame([], $this->capacities($this->preference(3, 'far', '16:01'), 3)[1]);
    }

    public function test_c1_arrival_exactly_at_the_boundary_and_before_keeps_its_slot(): void
    {
        // 16:00 + 30 minutes = 16:30: exactly 90 minutes remain, which is not "less than 90".
        $this->assertSame(['Evening'], $this->capacities($this->preference(3, 'far', '16:00'), 3)[1]);
        $this->assertSame(['Evening'], $this->capacities($this->preference(3, 'far', '15:00'), 3)[1]);
        $this->assertSame(['Afternoon', 'Evening'], $this->capacities($this->preference(3, 'far', '11:00'), 3)[1]);
    }

    public function test_c1_blank_arrival_time_is_planned_as_before(): void
    {
        $preference = $this->preference(3, 'far', null);

        $this->assertSame(['Morning', 'Afternoon'], $this->capacities($preference, 3)[1]);
        $this->assertTrue(app(ItineraryScheduleBuilder::class)->arrivalDayHasRoomForAVisit($preference));
    }

    public function test_c1_one_day_remote_and_non_remote_trips_are_untouched(): void
    {
        $this->assertSame(['Evening'], $this->capacities($this->preference(1, 'far', '16:30'), 1)[1]);
        $this->assertSame(['Evening'], $this->capacities($this->preference(3, 'moderate', '16:30'), 3)[1]);
        $this->assertSame(['Evening'], $this->capacities($this->preference(3, 'near', '16:30'), 3)[1]);
    }

    public function test_c1_later_days_keep_their_full_capacity(): void
    {
        $capacities = $this->capacities($this->preference(3, 'far', '16:30'), 3);

        $this->assertSame(['Morning', 'Afternoon'], $capacities[2]);
        $this->assertSame(['Morning', 'Afternoon'], $capacities[3]);
    }

    public function test_c1_room_for_a_visit_follows_the_arrival_rule(): void
    {
        $builder = app(ItineraryScheduleBuilder::class);

        $this->assertTrue($builder->arrivalDayHasRoomForAVisit($this->preference(3, 'far', '07:00')), 'an early arrival starts at 08:00');
        $this->assertTrue($builder->arrivalDayHasRoomForAVisit($this->preference(3, 'far', '16:00')));
        $this->assertFalse($builder->arrivalDayHasRoomForAVisit($this->preference(3, 'far', '16:01')));
    }

    // ---- C2: Night-1 late check-in ----

    public function test_c2_projection_sees_a_distant_stay_as_late_and_a_near_one_as_on_time(): void
    {
        $builder = app(ItineraryScheduleBuilder::class);
        $preference = $this->preference(3, 'far', '16:30');
        $origin = ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'label' => 'Davao City centre'];

        $far = $builder->projectArrivalDayCheckIn($preference, $origin, $this->stay('Distant Hub Inn', 140.0));
        $near = $builder->projectArrivalDayCheckIn($preference, $origin, $this->stay('Roadside Inn', 30.0));

        $this->assertTrue($far->greaterThan(Carbon::parse('19:30')), 'a 140 km night drive checks in after 19:30');
        $this->assertTrue($near->lessThanOrEqualTo(Carbon::parse('19:30')), 'a 30 km drive checks in on time');
    }

    private function hubTrip(int $days, string $arrival, array $stays): Itinerary
    {
        $this->destination('Hub Ridge A', 140.0, 4.9);
        $this->destination('Hub Ridge B', 141.0, 4.8);
        $this->destination('Hub Ridge C', 142.0, 4.7);
        $this->destination('Hub Ridge D', 140.5, 4.6);
        foreach ($stays as [$name, $km, $expired]) {
            $expired ? $this->expiredStay($name, $km) : $this->stay($name, $km);
        }

        $preference = $this->preference($days, 'far', $arrival);

        return app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');
    }

    public function test_strict_generation_rejects_the_infeasible_late_arrival_hub_request(): void
    {
        config(['itinerary.planner.reject_incomplete_trips' => true]);
        $this->expectException(ValidationException::class);
        $this->hubTrip(3, '16:30', [['Hub Inn', 140.0, false], ['Roadside Inn', 10.0, false]]);
    }

    public function test_c2_a_late_night_one_stay_is_replaced_by_one_the_traveller_reaches_on_time(): void
    {
        $trip = $this->hubTrip(3, '16:30', [['Hub Inn', 140.0, false], ['Roadside Inn', 10.0, false]]);

        $night1 = $this->overnightRows($trip)->firstWhere('day_number', 1);
        $this->assertNotNull($night1);
        $this->assertStringNotContainsString('Late check-in', (string) $night1->title);
        $this->assertSame('Roadside Inn', Accommodation::find($night1->accommodation_id)->name);
    }

    public function test_c2_when_no_stay_can_be_reached_in_time_the_trip_is_still_built(): void
    {
        $trip = $this->hubTrip(3, '16:30', [['Hub Inn', 140.0, false]]);

        $night1 = $this->overnightRows($trip)->firstWhere('day_number', 1);
        $this->assertNotNull($night1, 'the only eligible stay is still used');
        $this->assertSame('Hub Inn', Accommodation::find($night1->accommodation_id)->name);
        $this->assertNotEmpty($trip->items->where('kind', 'activity')->whereNotNull('destination_id'));
    }

    public function test_c2_an_expired_accreditation_is_never_chosen_as_the_replacement(): void
    {
        $trip = $this->hubTrip(3, '16:30', [['Hub Inn', 140.0, false], ['Roadside Inn', 10.0, true]]);

        $names = $this->overnightRows($trip)->map(fn ($r) => $r->accommodation_id ? Accommodation::find($r->accommodation_id)->name : null)->all();
        $this->assertNotContains('Roadside Inn', $names);
    }

    public function test_c2_no_transfer_is_made_when_the_first_night_is_not_late(): void
    {
        // The hub stay is 40 km out: reachable well before 19:30, so one stay serves every night.
        $this->destination('Hub Ridge A', 40.0, 4.9);
        $this->destination('Hub Ridge B', 41.0, 4.8);
        $this->destination('Hub Ridge C', 42.0, 4.7);
        $this->destination('Hub Ridge D', 40.5, 4.6);
        $this->stay('Hub Inn', 40.0);
        $this->stay('Roadside Inn', 10.0);
        $preference = $this->preference(3, 'far', '16:30');
        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');

        $ids = $this->overnightRows($trip)->pluck('accommodation_id')->filter()->unique();
        $this->assertCount(1, $ids, 'no unnecessary change of stay');
    }

    public function test_c2_replacement_respects_driving_ceiling_cutoff_and_return_rules(): void
    {
        $trip = $this->hubTrip(3, '16:30', [['Hub Inn', 140.0, false], ['Roadside Inn', 10.0, false]]);

        foreach ($trip->items->groupBy('day_number') as $day => $rows) {
            $this->assertLessThanOrEqual(ItineraryScheduleBuilder::MAX_DAILY_DRIVE_MINUTES, (int) $rows->where('kind', 'travel')->sum('travel_max_minutes'), "day $day driving ceiling");
        }
        foreach ($trip->items->where('kind', 'activity')->whereNotNull('destination_id') as $row) {
            $this->assertLessThanOrEqual('18:00:00', substr((string) $row->ends_at, 0, 8), 'sightseeing ends by 18:00');
        }
        $departure = $trip->items->where('kind', 'departure')->first();
        if ($departure) {
            $this->assertLessThan('20:00:00', substr((string) $departure->starts_at, 0, 8), 'home arrival before 20:00');
        }
    }

    public function test_c2_a_one_day_remote_trip_is_unaffected(): void
    {
        $this->destination('Hub Ridge A', 20.0, 4.9);
        $preference = $this->preference(1, 'far', '16:30');
        $trip = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'))->fresh('items');

        $this->assertSame(1, (int) $trip->total_days);
        $this->assertTrue($trip->items->where('kind', 'overnight')->isEmpty());
    }
}
