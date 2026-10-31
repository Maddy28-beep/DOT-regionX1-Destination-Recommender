<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerAuditCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function plan(int $days = 2): array
    {
        $p = TouristPreference::create(['travel_days' => $days, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near', 'start_date' => '2026-11-07',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'transport_pref' => 'personal_car']);

        return [$p, Itinerary::create(['preference_id' => $p->id, 'total_days' => $days, 'generated_at' => now()])];
    }

    private function checks($p, $it)
    {
        return collect(app(ItineraryAudit::class)->audit($it, $p->load('activities')))->keyBy('key');
    }

    public function test_missing_nights_fail_and_a_day_trip_does_not_require_a_hotel(): void
    {
        [$p, $trip] = $this->plan();
        $this->assertSame('fail', $this->checks($p, $trip)['stay_complete']['status']);
        [$p, $trip] = $this->plan(1);
        $this->assertSame('pass', $this->checks($p, $trip)['stay_complete']['status']);
    }

    public function test_a_mapped_but_expired_hotel_does_not_satisfy_a_night(): void
    {
        config(['dot.accreditation_test_grace_until' => null]);
        [$p, $trip] = $this->plan();
        $stay = Accommodation::create(['slug' => 'expired', 'name' => 'Expired Hotel', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'location' => 'Davao City', 'type' => 'Hotel', 'is_accredited' => true, 'latitude' => 7.07, 'longitude' => 125.61]);
        $record = AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id,
            'accreditation_number' => 'HOTEL-1', 'status' => 'Active', 'issue_date' => '2025-01-01', 'expiration_date' => '2026-10-31']);
        $trip->items()->create(['day_number' => 1, 'sort_order' => 1, 'slot' => 'Evening', 'kind' => 'overnight', 'title' => 'Check in', 'accommodation_id' => $stay->id]);
        $this->assertSame('fail', $this->checks($p, $trip)['stay_complete']['status']);
        $record->update(['expiration_date' => '2028-01-01']);
        $this->assertSame('pass', $this->checks($p, $trip)['stay_complete']['status']);
    }

    public function test_an_overnight_trek_and_overlapping_visits_are_detected(): void
    {
        [$p, $trip] = $this->plan(1);
        $d = Destination::create(['slug' => 'trek', 'name' => 'Two Day Trek', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'location' => 'Davao City', 'type' => 'Adventure & Hiking', 'is_accredited' => true,
            'latitude' => 7.07, 'longitude' => 125.61, 'visit_duration' => '2 days / 1 night', 'hours' => '08:00-18:00']);
        $trip->items()->create(['day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity', 'title' => 'Trek', 'destination_id' => $d->id, 'starts_at' => '09:00', 'ends_at' => '11:00']);
        $trip->items()->create(['day_number' => 1, 'sort_order' => 2, 'slot' => 'Morning', 'kind' => 'meal', 'title' => 'Lunch', 'starts_at' => '10:30', 'ends_at' => '11:30']);
        $checks = $this->checks($p, $trip);
        $this->assertSame('fail', $checks['duration']['status']);
        $this->assertSame('fail', $checks['schedule']['status']);
        $this->assertSame('unknown', $checks['meal_venues']['status']);
    }

    public function test_a_travel_distance_without_time_is_not_verified(): void
    {
        [$p, $trip] = $this->plan(1);
        $trip->items()->create(['day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'travel', 'title' => 'Transfer', 'starts_at' => '09:00', 'distance_km' => 5]);
        $this->assertSame('fail', $this->checks($p, $trip)['journeys']['status']);
    }
}
