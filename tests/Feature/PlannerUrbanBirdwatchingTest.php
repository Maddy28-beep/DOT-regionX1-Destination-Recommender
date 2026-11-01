<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\InterestCoverage;
use App\Services\Recommendation\ItineraryAudit;
use App\Services\Recommendation\ItineraryGenerationService;
use Database\Seeders\PlannerEvidenceUpdatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerUrbanBirdwatchingTest extends TestCase
{
    use RefreshDatabase;

    private function park(): Destination
    {
        $park = Destination::create([
            'slug' => 'peoples-park', 'name' => "People's Park", 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Cultural Heritage', 'price_tier' => 'Free',
            'latitude' => 7.07096, 'longitude' => 125.607847,
            'hours' => '1:00 PM-10:00 PM', 'entry_fee_min' => 0, 'entry_fee_max' => 0,
            'is_accredited' => false, 'planner_verified_at' => '2026-10-31',
            'planner_verification_source' => 'Existing official park evidence',
        ]);
        $park->tags()->create(['kind' => 'category', 'value' => 'Nature']);

        return $park;
    }

    public function test_birdwatching_evidence_preserves_existing_verified_fields_and_is_repeatable(): void
    {
        $park = $this->park();
        $before = $park->only(['hours', 'latitude', 'longitude', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'is_accredited', 'visit_duration']);
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $park->refresh();

        $this->assertSame($before, $park->only(array_keys($before)));
        $this->assertSame(1, $park->tags()->where('value', 'Wildlife (urban birdwatching)')->count());
        $this->assertTrue(InterestCoverage::covers($park, 'Wildlife'));
        $this->assertStringContainsString('10.56899/151.05.32', $park->planner_verification_source);
        $this->assertStringContainsString('not a zoo', $park->planning_notes);
        $this->assertStringContainsString('historical count', $park->planning_notes);
    }

    public function test_budget_family_schedules_explicit_birdwatching_within_public_hours(): void
    {
        $park = $this->park();
        $this->seed(PlannerEvidenceUpdatesSeeder::class);
        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Family', 'budget' => 'Budget-Friendly',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near', 'transport_pref' => 'rental_car',
            'arrival_time' => '06:30', 'start_date' => '2026-11-02', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'origin_lat' => 7.07, 'origin_lng' => 125.61,
        ]);
        foreach (['Wildlife', 'Nature & Adventure'] as $interest) {
            $preference->activities()->create(['activity' => $interest]);
        }
        $trip = app(ItineraryGenerationService::class)->generate($preference);
        $visit = $trip->items->where('kind', 'activity')->where('destination_id', $park->id)->sole();
        $this->assertSame("Urban birdwatching and park visit — People's Park", $visit->title);
        $this->assertGreaterThanOrEqual('13:00', \Illuminate\Support\Carbon::parse($visit->starts_at)->format('H:i'));
        $this->assertLessThanOrEqual('18:00', \Illuminate\Support\Carbon::parse($visit->ends_at)->format('H:i'));
        $this->assertStringContainsString('not a zoo', $visit->note);
        $checks = collect(app(ItineraryAudit::class)->audit($trip, $preference))->keyBy('key');
        $this->assertSame('pass', $checks['interests']['status']);
        $this->assertFalse($checks->contains(fn ($check) => $check['status'] === 'fail'));
    }
}
