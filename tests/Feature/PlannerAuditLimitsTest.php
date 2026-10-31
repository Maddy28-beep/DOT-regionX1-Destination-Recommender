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

    public function test_an_afternoon_request_can_cover_beach_and_wildlife_at_one_verified_combined_venue(): void
    {
        $this->place(Destination::class, ['type' => 'Beach & Leisure', 'rating' => 5]);
        $this->place(Destination::class, ['type' => 'Wildlife', 'rating' => 5]);
        $combined = $this->place(Destination::class, ['type' => 'Beach & Leisure', 'rating' => 2]);
        $combined->tags()->create(['kind' => 'category', 'value' => 'Wildlife (aviary)']);
        $p = $this->preference(['Beach & Island', 'Wildlife'], ['arrival_time' => '13:00']);
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $visits = $trip->items->where('kind', 'activity')->whereNotNull('destination_id');

        $this->assertCount(1, $visits);
        $this->assertSame($combined->id, $visits->first()->destination_id);
        $this->assertStringContainsString('Beach and wildlife', $visits->first()->title);
        $this->assertSame('pass', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['interests']['status']);
    }

    public function test_a_missing_interest_can_be_added_before_the_other_stop_when_its_gate_closes_early(): void
    {
        $this->place(Destination::class, ['type' => 'Cultural Heritage', 'visit_duration' => '90 minutes']);
        $this->place(Destination::class, ['type' => 'Beach & Leisure', 'latitude' => 7.071, 'visit_duration' => '150 minutes']);
        $early = $this->place(Destination::class, ['type' => 'Adventure & Hiking', 'latitude' => 7.072, 'visit_duration' => '150 minutes', 'hours' => '8:00 AM-12:00 PM']);
        $this->place(Accommodation::class);
        $p = $this->preference(['Cultural Heritage', 'Beach & Island', 'Hiking & Trekking'], ['travel_days' => 2, 'arrival_time' => '15:00']);
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $dayTwo = $trip->items->where('day_number', 2)->where('kind', 'activity')->whereNotNull('destination_id')->sortBy('sort_order');

        $this->assertSame($early->id, $dayTwo->first()->destination_id);
        $checks = collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key');
        $this->assertSame('pass', $checks['interests']['status']);
        $this->assertSame('pass', $checks['open']['status']);
        $this->assertSame('pass', $checks['duration']['status']);
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

    public function test_hotel_selection_counts_the_breakfast_transfer_instead_of_choosing_an_equally_close_stay(): void
    {
        $this->place(Destination::class, ['latitude' => 7.071, 'visit_duration' => '150 minutes']);
        $this->place(Destination::class, ['latitude' => 7.072, 'visit_duration' => '150 minutes']);
        $withoutDining = $this->place(Accommodation::class);
        $withDining = $this->place(Accommodation::class);
        $restaurant = \App\Models\Restaurant::create([
            'slug' => 'hotel-breakfast', 'name' => 'Named hotel dining venue', 'location' => 'Davao City',
            'region_id' => $withDining->region_id, 'latitude' => $withDining->latitude, 'longitude' => $withDining->longitude,
            'is_accredited' => true, 'opening_hours' => '6:00 AM-10:00 PM', 'host_accommodation_id' => $withDining->id,
        ]);
        $p = $this->preference(['Nature & Adventure'], ['travel_days' => 2, 'distance_pref' => 'near']);
        $costs = app(\App\Services\Recommendation\ItineraryScheduleBuilder::class)
            ->breakfastTransferCosts([$withoutDining, $withDining], $p, \Illuminate\Support\Carbon::parse('2026-11-01'));
        $this->assertSame(30, $costs[$withoutDining->id]);
        $this->assertSame(0, $costs[$withDining->id]);

        $trip = app(ItineraryGenerationService::class)->generate($p);
        $this->assertSame($withDining->id, $trip->items->firstWhere('kind', 'overnight')->accommodation_id);
        $breakfast = $trip->items->first(fn ($item) => $item->day_number === 2 && str_starts_with($item->title, 'Breakfast'));
        $this->assertSame($restaurant->id, $breakfast->restaurant_id);
        $this->assertSame('pass', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['travel_share']['status']);
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
        config(['itinerary.planner.reject_incomplete_trips' => false]);
        $this->place(Destination::class, ['price_tier' => 'Premium']);
        $p = $this->preference(['Nature & Adventure'], ['budget' => 'Budget-Friendly']);

        $trip = app(ItineraryGenerationService::class)->generate($p);
        $this->assertSame('fail', collect(app(ItineraryAudit::class)->audit($trip, $p))->keyBy('key')['budget']['status']);
        $trip->items()->delete();
        $trip->matches()->delete();
        $trip->delete();

        config(['itinerary.planner.reject_incomplete_trips' => true]);
        try {
            app(ItineraryGenerationService::class)->generate($p);
            $this->fail('A budget-incompatible catalogue must be reported when reject_incomplete_trips is enabled.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('budget', $e->errors());
        }
    }

    public function test_an_unavoidable_over_limit_trip_returns_best_plan_only_when_explicitly_configured(): void
    {
        config(['itinerary.planner.reject_incomplete_trips' => false]);
        $this->place(Destination::class, ['latitude' => 8.0]);
        $p = $this->preference(['Nature & Adventure']);

        // Explicit partial-plan mode retains its existing behavior.
        $trip = app(ItineraryGenerationService::class)->generate($p);
        $this->assertNotNull($trip->id);
        $trip->items()->delete();
        $trip->matches()->delete();
        $trip->delete();

        // Deliberate rejection mode: rejects and rolls back without saving a partial plan.
        config(['itinerary.planner.reject_incomplete_trips' => true]);
        try {
            app(ItineraryGenerationService::class)->generate($p);
            $this->fail('An over-limit route must be rejected when reject_incomplete_trips is enabled.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('distance_pref', $e->errors());
            $this->assertSame(0, \App\Models\Itinerary::count());
        }
    }

    public function test_the_audit_distinguishes_declined_requests_from_successful_plans(): void
    {
        config(['itinerary.planner.reject_incomplete_trips' => true]);
        $this->place(Destination::class, ['latitude' => 8.0]);
        $this->artisan('itinerary:audit', ['--scenario' => 'Solo day trip'])
            ->expectsOutputToContain('1 request(s) were declined as infeasible')
            ->assertSuccessful();
        $this->assertSame(0, \App\Models\Itinerary::count());
    }
}
