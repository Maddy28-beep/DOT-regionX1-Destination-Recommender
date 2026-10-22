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

/** A traveller who lands in the small hours gets neutral free time until 8 AM, not a 5:30 AM outing. */
class EarlyArrivalPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function rows(string $arrival): array
    {
        $region = Region::create(['name' => 'Davao City']);
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64], ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Beach One', 'Beach & Leisure', 7.15, 125.71], ['Eden Park', 'Nature & Adventure', 7.03, 125.37]] as $i => [$n, $t, $la, $lo]) {
            Destination::create(['slug' => str($n)->slug()->toString(), 'name' => $n, 'location' => 'Davao City', 'region_id' => $region->id, 'type' => $t,
                'is_accredited' => true, 'rating' => 5 - $i * 0.1, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $la, 'longitude' => $lo]);
        }
        Accommodation::create(['slug' => 'inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61]);
        $p = TouristPreference::create(['travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-20', 'arrival_time' => $arrival]);
        PreferenceActivity::create(['preference_id' => $p->id, 'activity' => 'Wildlife']);

        return app(ItineraryGenerationService::class)->generate($p->load('activities', 'amenities'))
            ->items()->where('day_number', 1)->orderBy('sort_order')->get()->all();
    }

    public function test_a_4am_arrival_has_free_time_before_the_first_outing(): void
    {
        $rows = $this->rows('04:00');

        $this->assertSame('baseline', $rows[0]->kind);
        $this->assertSame('free', $rows[1]->kind, 'nothing is assumed about a room or a meal');
        $this->assertSame('Free time until 8:00 AM', $rows[1]->title);

        $firstOuting = collect($rows)->first(fn ($r) => $r->kind === 'activity');
        $this->assertNotNull($firstOuting);
        $this->assertGreaterThanOrEqual('08:00', substr((string) $firstOuting->starts_at, 0, 5), 'no sightseeing before 8 AM');
    }

    public function test_a_normal_morning_arrival_is_unchanged(): void
    {
        $rows = $this->rows('09:00');

        $this->assertSame('baseline', $rows[0]->kind);
        $this->assertNotSame('meal', $rows[1]->kind, 'no rest block for a 9 AM arrival');
        $this->assertSame('09:30', substr((string) $rows[1]->starts_at, 0, 5));
    }
}
