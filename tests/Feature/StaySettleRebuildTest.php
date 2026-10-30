<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression test for the settle step. It plans the stays again for the stops that were placed and
 * rebuilds with them. This test uses the REAL generator with a spy in place of the schedule builder and checks the one thing
 * the other tests could not: that the rebuild is actually given the new stays.
 *
 * It failed before the build closure shared the stays by reference (the rebuild was given the first stays again).
 */
class SpyBuilder extends ItineraryScheduleBuilder
{
    /** @var array<int, array<int, int|null>> night => stay id, one entry per build */
    public static array $stayCalls = [];

    public static bool $first = true;

    public function build(Itinerary $itinerary, array $sequence, TouristPreference $preference, array $dayCapacities, array $origin, ?array $stay): void
    {
        self::$stayCalls[] = array_map(fn ($s) => $s['listing']->id ?? null, $stay ?? []);
        parent::build($itinerary, $sequence, $preference, $dayCapacities, $origin, $stay);

        // On the first build only, the schedule "loses" the last two days' stops, as the real one does when they cannot be reached.
        if (self::$first) {
            self::$first = false;
            $itinerary->items()->whereIn('day_number', [3, 4])->where('kind', 'activity')->delete();
        }
    }
}

/** The settle step plans the stays again for the stops that were placed, and the rebuild must really be given them. */
class StaySettleRebuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rebuild_is_given_the_stays_planned_for_the_stops_that_were_placed(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['dot.accreditation_test_grace_until' => null, 'itinerary.stays.force_mode' => null, 'itinerary.stays.transfer_penalty_minutes.far' => 30]);
        $this->app->bind(ItineraryScheduleBuilder::class, SpyBuilder::class);
        SpyBuilder::$stayCalls = [];
        SpyBuilder::$first = true;

        $region = Region::firstOrCreate(['name' => 'Davao City'])->id;
        $place = fn (string $name, float $km, float $rating) => Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => $rating, 'review_count' => 20, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km,
        ]);
        $stay = function (string $name, float $km) use ($region) {
            $s = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region, 'type' => 'Hotel', 'is_accredited' => true,
                'rating' => 4, 'review_count' => 10, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61]);
            AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $s->id, 'accreditation_number' => 'DOT-T-'.$s->id, 'issue_date' => '2024-01-01', 'expiration_date' => '2028-01-01', 'status' => 'Active']);

            return $s;
        };
        // Exactly as many places as the trip has slots (four days, two a day), so no reserve place can change the plan.
        foreach ([[3, 4.9], [5, 4.8], [7, 4.7], [9, 4.6], [100, 4.5], [102, 4.4], [104, 4.3], [106, 4.2]] as $i => [$km, $rating]) {
            $place('Place '.($i + 1), $km, $rating);
        }
        $near = $stay('Near Stay', 6);
        $far = $stay('Far Stay', 103);

        $preference = TouristPreference::create([
            'travel_days' => 4, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00',
            'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start',
        ]);
        app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'), 7.07, 125.61);

        $calls = SpyBuilder::$stayCalls;
        $this->assertGreaterThanOrEqual(2, count($calls), 'the stays were planned again and the trip rebuilt');
        $this->assertSame($far->id, $calls[0][3], 'the first plan, made for the stops the trip set out to visit, sleeps the last night beside the far stops');
        $this->assertSame($near->id, $calls[1][3], 'the rebuild sleeps there instead: those stops were never placed');
        $this->assertNotSame($calls[0], $calls[1], 'the rebuild is given the settled stays, not the first ones again');
    }
}
