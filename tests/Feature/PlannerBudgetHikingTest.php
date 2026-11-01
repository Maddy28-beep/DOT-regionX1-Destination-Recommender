<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\InterestCoverage;
use Database\Seeders\PlannerBudgetHikingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerBudgetHikingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sourced_trail_is_budget_eligible_without_invented_hours_fees_or_accreditation(): void
    {
        $this->seed(PlannerBudgetHikingSeeder::class);
        $trail = Destination::with('tags')->where('slug', 'magongawon-falls-forest-trail')->sole();
        $this->assertFalse($trail->is_accredited);
        $this->assertNull($trail->hours);
        $this->assertNull($trail->visit_duration);
        $this->assertNull($trail->entry_fee_min);
        $this->assertNull($trail->entry_fee_max);
        $this->assertSame(1, Destination::plannerEligible()->count());
        $this->assertTrue(InterestCoverage::covers($trail, 'Hiking & Trekking'));

        $preference = TouristPreference::create(['travel_days' => 4, 'travel_type' => 'Solo',
            'budget' => 'Budget-Friendly', 'distance_pref' => 'far', 'transport_pref' => 'jeep',
            'start_date' => '2026-11-02', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'accommodation_pref' => 'Any',
            'origin_lat' => 7.056, 'origin_lng' => 125.602]);
        $preference->activities()->create(['activity' => 'Hiking & Trekking']);
        $ranked = app(ContentBasedRecommendationService::class)->rank($preference);
        $this->assertContains($trail->id, $ranked->pluck('destination.id')->all());
    }

    public function test_reseeding_preserves_admin_corrections_and_adds_no_duplicate(): void
    {
        $this->seed(PlannerBudgetHikingSeeder::class);
        $trail = Destination::where('slug', 'magongawon-falls-forest-trail')->sole();
        $trail->update(['hours' => '8:00 AM-3:00 PM', 'latitude' => 7.0077, 'itinerary_role' => 'excluded']);
        $this->seed(PlannerBudgetHikingSeeder::class);
        $this->assertSame(1, Destination::count());
        $this->assertSame(2, $trail->tags()->count());
        $this->assertSame('8:00 AM-3:00 PM', $trail->fresh()->hours);
        $this->assertSame('7.0077000', $trail->fresh()->latitude);
        $this->assertSame('excluded', $trail->fresh()->itinerary_role);
    }
}
