<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Whether the pre-trained model grouped a plan's days is recorded on the plan,
 * shown on the itinerary page ("AI-assisted" or "Standard order"), and counted
 * on the admin overview.
 */
class AiAssistedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function preference(): TouristPreference
    {
        $region = Region::create(['name' => 'Davao City']);
        $coords = [[7.0731, 125.6128], [7.0800, 125.6200], [7.0650, 125.6050], [7.0900, 125.6300]];
        foreach ($coords as $i => [$lat, $lng]) {
            Destination::create([
                'slug' => "stop-{$i}", 'name' => "Stop {$i}", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        return TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure',
        ]);
    }

    private function generate(TouristPreference $preference): Itinerary
    {
        return app(ItineraryGenerationService::class)->generate($preference);
    }

    private function viewPage(Itinerary $itinerary, TouristPreference $preference)
    {
        return $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get(route('plan.itinerary'));
    }

    public function test_without_the_model_the_plan_says_standard_order(): void
    {
        $preference = $this->preference();
        $itinerary = $this->generate($preference);

        $this->assertFalse($itinerary->ml_skeleton_applied);
        $this->assertNull($itinerary->ml_skeleton_repaired);

        $this->viewPage($itinerary, $preference)->assertOk()->assertSee('Standard order')->assertDontSee('AI-assisted');
    }

    public function test_when_the_model_groups_the_days_the_plan_says_ai_assisted_and_records_how_it_went(): void
    {
        config(['services.phi4mini.url' => 'http://localhost:11434', 'services.phi4mini.model' => 'phi4-mini']);
        $preference = $this->preference();
        $ids = Destination::orderBy('id')->pluck('id')->all();

        // A valid, complete answer for two days of two stops.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'day_1' => ['morning' => $ids[0], 'afternoon' => $ids[1]],
            'day_2' => ['morning' => $ids[2], 'afternoon' => $ids[3]],
        ])])]);

        $itinerary = $this->generate($preference);

        $this->assertTrue($itinerary->ml_skeleton_applied);
        $this->assertFalse($itinerary->ml_skeleton_repaired);
        $this->assertNotNull($itinerary->ml_skeleton_seconds);

        $this->viewPage($itinerary, $preference)->assertOk()->assertSee('AI-assisted');
    }

    public function test_a_repaired_answer_is_counted_as_repaired(): void
    {
        config(['services.phi4mini.url' => 'http://localhost:11434', 'services.phi4mini.model' => 'phi4-mini']);
        $preference = $this->preference();
        $ids = Destination::orderBy('id')->pluck('id')->all();

        // The first id is used twice, so one place is missing and has to be filled back in.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'day_1' => ['morning' => $ids[0], 'afternoon' => $ids[0]],
            'day_2' => ['morning' => $ids[2], 'afternoon' => $ids[3]],
        ])])]);

        $itinerary = $this->generate($preference);

        $this->assertTrue($itinerary->ml_skeleton_applied);
        $this->assertTrue($itinerary->ml_skeleton_repaired);
    }

    public function test_a_plan_with_no_recorded_outcome_shows_no_badge(): void
    {
        $preference = $this->preference();
        $itinerary = $this->generate($preference);
        $itinerary->update(['ml_skeleton_applied' => null]);

        $this->viewPage($itinerary, $preference)->assertOk()->assertDontSee('AI-assisted')->assertDontSee('Standard order');
    }

    public function test_the_admin_overview_counts_ai_assisted_plans(): void
    {
        $preference = $this->preference();
        $this->generate($preference);                               // standard order
        $assisted = $this->generate($preference);
        $assisted->update(['ml_skeleton_applied' => true, 'ml_skeleton_repaired' => true, 'ml_skeleton_seconds' => 4.0]);

        $admin = AdminUser::create([
            'email' => 'ai-admin@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.overview'))->assertOk();

        $ai = $response->viewData('ai');
        $this->assertSame(2, $ai['total']);
        $this->assertSame(1, $ai['applied']);
        $this->assertSame(1, $ai['standard']);
        $this->assertSame(1, $ai['repaired']);
        $this->assertEquals(4.0, $ai['avg_seconds']);
        $this->assertSame('not_configured', $ai['status']);
        $response->assertSee('Itinerary day grouping: pre-trained model')->assertSee('Plans AI-assisted');
    }
}
