<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A traveller who reaches the hotel after dinner time is not given a dinner at 1:45 AM. */
class LateArrivalPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function day1(string $arrival): array
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([['Zoo', 7.12, 125.64], ['Museum', 7.07, 125.61], ['Beach', 7.15, 125.71], ['Park', 7.03, 125.37]] as $i => [$n, $la, $lo]) {
            Destination::create(['slug' => strtolower($n), 'name' => $n, 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Wildlife', 'is_accredited' => true,
                'rating' => 5 - $i * 0.1, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $la, 'longitude' => $lo]);
        }
        Accommodation::create(['slug' => 'inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61]);
        $p = TouristPreference::create(['travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-11-02', 'arrival_time' => $arrival]);
        PreferenceActivity::create(['preference_id' => $p->id, 'activity' => 'Wildlife']);

        return app(ItineraryGenerationService::class)->generate($p->load('activities', 'amenities'))
            ->items()->where('day_number', 1)->orderBy('sort_order')->get()->all();
    }

    /** @dataProvider lateArrivals */
    public function test_a_late_arrival_gets_a_check_in_and_no_dinner(string $arrival): void
    {
        $rows = $this->day1($arrival);
        $kinds = array_map(fn ($r) => $r->kind, $rows);

        $this->assertNotContains('meal', $kinds, "no dinner for a $arrival arrival");
        $this->assertSame('overnight', end($rows)->kind);
        $this->assertSame('Late check-in and overnight stay', end($rows)->title);
    }

    public static function lateArrivals(): array
    {
        return ['11:30 PM' => ['23:30'], '10:00 PM' => ['22:00']];
    }

    public function test_an_evening_arrival_that_reaches_the_hotel_in_time_still_gets_dinner(): void
    {
        $rows = $this->day1('15:00');

        $this->assertContains('meal', array_map(fn ($r) => $r->kind, $rows));
        $this->assertSame('Overnight stay', end($rows)->title);
    }
}
