<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A visit that does not fit before the 18:00 sightseeing cutoff in full is shortened to what does fit, as long as
 * that is still a proper visit (90 minutes at least). Before, the whole stop was dropped, so a traveller landing at
 * 15:00 spent the arrival day on the drive to the stay.
 */
class VisitTrimTest extends TestCase
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

    /** One park 15 minutes from the start, on day 1 of a two-day trip (so it is not the last day's shortened visit). @return array{start: ?string, end: ?string}|null the visit, or null when none was planned */
    private function visitWhenArriving(string $arrival): ?array
    {
        $park = Destination::create([
            'slug' => 'near-park', 'name' => 'Near Park', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => 7.0489 + 0.002, 'longitude' => 125.5736,
        ]);
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-10-28', 'arrival_time' => $arrival,
        ]);
        $itinerary = Itinerary::create(['preference_id' => $preference->id, 'total_days' => 2, 'generated_at' => now()]);

        app(ItineraryScheduleBuilder::class)->build(
            $itinerary, [['row' => ['destination' => $park->load('region', 'tags'), 'drs' => 4.0], 'distance_km' => 1.0]], $preference, [1 => ['Afternoon'], 2 => ['Morning']],
            ['lat' => 7.0489, 'lng' => 125.5736, 'label' => 'Ma-a, Talomo, Davao City'], null,
        );

        // Day 1 only: a stop that does not fit the arrival afternoon moves to the next morning, which is not what is tested here.
        $visit = $itinerary->items()->where('day_number', 1)->where('kind', 'activity')->whereNotNull('destination_id')->first();

        return $visit ? ['start' => substr((string) $visit->starts_at, 0, 5), 'end' => substr((string) $visit->ends_at, 0, 5)] : null;
    }

    public function test_a_late_afternoon_arrival_gets_a_shortened_visit_instead_of_none(): void
    {
        // 15:00 arrival: ready 15:30, at the park by 15:45; a full 150-minute visit would end at 18:15.
        $this->assertSame(['start' => '15:45', 'end' => '18:00'], $this->visitWhenArriving('15:00'));
    }

    public function test_the_shortest_visit_that_is_still_planned_is_ninety_minutes(): void
    {
        // 15:45 arrival: ready 16:15, at the park by 16:30, exactly 90 minutes before the cutoff.
        $this->assertSame(['start' => '16:30', 'end' => '18:00'], $this->visitWhenArriving('15:45'));
    }

    public function test_less_than_ninety_minutes_before_the_cutoff_still_drops_the_stop(): void
    {
        // 16:00 arrival: ready 16:30, at the park by 16:45, 75 minutes left: not a visit.
        $this->assertNull($this->visitWhenArriving('16:00'));
    }

    public function test_an_early_arrival_still_gets_the_full_visit(): void
    {
        $visit = $this->visitWhenArriving('09:00');

        $this->assertNotNull($visit);

        $this->assertSame(150, (strtotime($visit['end']) - strtotime($visit['start'])) / 60);
    }

    public function test_no_visit_ever_ends_after_the_cutoff(): void
    {
        foreach (['10:00', '13:00', '14:30', '15:00', '15:30', '16:00'] as $arrival) {
            $this->refreshApplication();
            Carbon::setTestNow('2026-10-20 12:00:00');
            $this->artisan('migrate:fresh', ['--force' => true]);

            $visit = $this->visitWhenArriving($arrival);

            if ($visit !== null) {
                $this->assertLessThanOrEqual('18:00', $visit['end'], "arrival {$arrival}");
            }
        }
    }
}
