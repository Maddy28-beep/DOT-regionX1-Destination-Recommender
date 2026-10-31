<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryAudit;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerAuditLimitsTest extends TestCase
{
    use RefreshDatabase;

    private int $serial = 0;

    private function place(string $model, array $extra = [])
    {
        $n = ++$this->serial;

        return $model::create($extra + ['slug' => 'place-'.$n, 'name' => 'Place '.$n,
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'location' => 'Davao City',
            'type' => $model === Accommodation::class ? 'Hotel' : 'Nature & Adventure',
            'latitude' => 7.07, 'longitude' => 125.61, 'rating' => 4, 'review_count' => 5,
            'is_accredited' => true, 'price_tier' => 'Budget-Friendly'] + ($model === Destination::class ? ['hours' => '8:00 AM–6:00 PM', 'visit_duration' => '90 minutes'] : []));
    }

    private function preference(array $interests, array $extra = []): TouristPreference
    {
        $p = TouristPreference::create($extra + ['travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Hotel', 'distance_pref' => 'far', 'arrival_time' => '08:00', 'start_date' => '2026-10-31',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'transport_pref' => 'personal_car',
            'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'City centre']);
        foreach ($interests as $interest) $p->activities()->create(['activity' => $interest]);

        return $p->load('activities', 'amenities');
    }

    public function test_a_remote_first_stop_cannot_bypass_the_distance_and_travel_share_limits(): void
    {
        $remote = $this->place(Destination::class, ['latitude' => 8.0, 'rating' => 5]);
        $near = $this->place(Destination::class, ['latitude' => 7.071, 'rating' => 3]);
        $p = $this->preference(['Nature & Adventure']);
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $checks = collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key');

        $this->assertSame('pass', $checks['day_km']['status']);
        $this->assertSame('pass', $checks['travel_share']['status']);
        $this->assertFalse($trip->items->where('kind', 'activity')->contains('destination_id', $remote->id));
        $this->assertTrue($trip->items->where('kind', 'activity')->contains('destination_id', $near->id));
    }

    public function test_a_hotel_outside_forty_kilometres_is_not_used_as_a_fallback(): void
    {
        $this->place(Destination::class);
        $far = $this->place(Accommodation::class, ['latitude' => 7.8]);
        $near = $this->place(Accommodation::class, ['latitude' => 7.071]);
        $p = $this->preference(['Nature & Adventure'], ['travel_days' => 2]);
        $trip = app(ItineraryGenerationService::class)->generate($p);

        $this->assertTrue($trip->items->where('kind', 'overnight')->contains('accommodation_id', $near->id));
        $this->assertFalse($trip->items->contains('accommodation_id', $far->id));
        $near->delete();
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $this->assertFalse($trip->items->contains('accommodation_id', $far->id));
    }

    public function test_an_interest_lost_to_opening_hours_gets_a_feasible_replacement(): void
    {
        $this->place(Destination::class, ['type' => 'Wildlife', 'rating' => 5]);
        $closed = $this->place(Destination::class, ['hours' => '8:00 AM–8:15 AM', 'rating' => 5]);
        $replacement = $this->place(Destination::class, ['rating' => 1]);
        $p = $this->preference(['Wildlife', 'Nature & Adventure']);
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $checks = collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key');

        $this->assertSame('pass', $checks['interests']['status']);
        $this->assertFalse($trip->items->contains('destination_id', $closed->id));
        $this->assertTrue($trip->items->contains('destination_id', $replacement->id));
    }

    public function test_repairs_keep_known_spending_tiers_within_budget(): void
    {
        $this->place(Destination::class, ['type' => 'Wildlife', 'price_tier' => 'Premium']);
        $this->place(Destination::class, ['price_tier' => 'Budget-Friendly']);
        $p = $this->preference(['Wildlife', 'Nature & Adventure'], ['budget' => 'Budget-Friendly']);
        $trip = app(ItineraryGenerationService::class)->generate($p);

        $this->assertSame('pass', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['budget']['status']);
    }

    public function test_a_hotel_is_rechosen_for_the_actual_day_after_a_closed_visit_moves(): void
    {
        $city = $this->place(Destination::class, ['hours' => 'Sun 8:00 AM–6:00 PM; Closed Sat', 'visit_duration' => '150 minutes']);
        $remote = $this->place(Destination::class, ['latitude' => 7.5, 'visit_duration' => '150 minutes']);
        $this->place(Accommodation::class);
        $remoteHotel = $this->place(Accommodation::class, ['latitude' => 7.5]);
        $p = $this->preference(['Nature & Adventure'], ['travel_days' => 2]);
        $trip = app(ItineraryGenerationService::class)->generate($p);

        $this->assertTrue($trip->items->where('day_number', 1)->where('kind', 'activity')->contains('destination_id', $remote->id));
        $this->assertTrue($trip->items->where('day_number', 2)->where('kind', 'activity')->contains('destination_id', $city->id));
        $night = $trip->items->firstWhere('kind', 'overnight');
        $this->assertNotNull($night, 'Rechoosing a stay must not lose the required overnight.');
        $this->assertSame($remoteHotel->id, $night->accommodation_id);
        $this->assertSame('pass', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['stay']['status']);
    }

    public function test_an_interest_only_offered_above_budget_is_disclosed_as_unavailable(): void
    {
        $this->place(Destination::class, ['type' => 'Wildlife', 'price_tier' => 'Premium']);
        $this->place(Destination::class);
        $p = $this->preference(['Wildlife', 'Nature & Adventure'], ['budget' => 'Budget-Friendly']);
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $checks = collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key');

        $this->assertSame('unknown', $checks['interests']['status']);
        $this->assertStringContainsString('spending tier', $checks['interests']['detail']);
        $this->assertSame('pass', $checks['budget']['status']);
    }

    public function test_a_catalogue_entirely_above_budget_does_not_produce_an_empty_successful_plan(): void
    {
        $this->place(Destination::class, ['price_tier' => 'Premium']);
        $p = $this->preference(['Nature & Adventure'], ['budget' => 'Budget-Friendly']);

        try {
            app(ItineraryGenerationService::class)->generate($p);
            $this->fail('A budget-incompatible catalogue must be reported.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('budget', $e->errors());
        }
    }

    public function test_an_unavoidable_over_limit_trip_is_rejected_without_saving_a_partial_plan(): void
    {
        $this->place(Destination::class, ['latitude' => 8.0]);
        $p = $this->preference(['Nature & Adventure']);
        try {
            app(ItineraryGenerationService::class)->generate($p);
            $this->fail('An over-limit route must not be returned as a successful plan.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('distance_pref', $e->errors());
            $this->assertSame(0, \App\Models\Itinerary::count());
        }
    }

    public function test_the_audit_distinguishes_declined_requests_from_successful_plans(): void
    {
        $this->place(Destination::class, ['latitude' => 8.0]);
        $this->artisan('itinerary:audit', ['--scenario' => 'Solo day trip'])
            ->expectsOutputToContain('1 request(s) were declined as infeasible')
            ->assertSuccessful();
        $this->assertSame(0, \App\Models\Itinerary::count());
    }
}
