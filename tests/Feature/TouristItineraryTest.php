<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Package;
use App\Models\PackageItineraryDay;
use App\Models\Region;
use App\Models\TouristAccount;
use App\Models\TouristHealthProfile;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Saving a generated itinerary to the optional tourist account. A saved
 * itinerary is the same Itinerary/ItineraryItem row the anonymous flow
 * already produces, just claimed by setting tourist_account_id -- these
 * tests guard that claiming doesn't disturb the anonymous path, that
 * ownership is enforced, and that health/accessibility answers are stripped
 * rather than retained once a plan is saved permanently.
 */
class TouristItineraryTest extends TestCase
{
    use RefreshDatabase;

    private function seedDestinations(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([['eden-nature-park', 'Eden Nature Park', 7.0206, 125.4103], ['peoples-park', 'Peoples Park', 7.0731, 125.6128]] as [$slug, $name, $lat, $lng]) {
            Destination::create([
                'slug' => $slug, 'name' => $name, 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng, 'distance_km' => 12,
            ]);
        }
    }

    private function surveyPayload(array $overrides = []): array
    {
        return $overrides + [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'place_of_origin' => 'Cebu City',
        ];
    }

    private function tourist(string $alias = 'explorer1'): TouristAccount
    {
        return TouristAccount::create(['alias' => $alias, 'password_hash' => Hash::make('password123')]);
    }

    public function test_anonymous_generation_and_viewing_is_unaffected_by_this_feature(): void
    {
        $this->seedDestinations();

        $this->post('/plan', $this->surveyPayload())->assertRedirect(route('plan.itinerary'));

        $this->get('/plan/itinerary')->assertOk()->assertSee('Save Itinerary');

        $itinerary = Itinerary::sole();
        $this->assertNull($itinerary->tourist_account_id);
    }

    public function test_a_guest_clicking_save_is_sent_to_register_without_mutating_the_itinerary(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());
        $itinerary = Itinerary::sole();

        $this->post(route('plan.itinerary.save'))->assertRedirect(route('account.register'));

        $itinerary->refresh();
        $this->assertNull($itinerary->tourist_account_id);
        $this->assertTrue(session('pending_save_itinerary'));
    }

    public function test_registering_with_a_pending_save_surfaces_the_prompt_on_return(): void
    {
        $this->seedDestinations();
        $this->post('/plan', $this->surveyPayload());

        $this->post(route('plan.itinerary.save'));

        $this->post(route('account.register'), [
            'alias' => 'explorer1', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('plan.itinerary'));

        $this->get(route('plan.itinerary'))->assertOk()->assertSee('Save your itinerary');
    }

    public function test_a_logged_in_tourist_can_save_the_current_itinerary(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');
        $this->post('/plan', $this->surveyPayload());

        $this->post(route('plan.itinerary.save'), ['title' => 'My Davao Trip'])
            ->assertRedirect();

        $itinerary = Itinerary::sole();
        $this->assertNotNull($itinerary->tourist_account_id);
        $this->assertSame('My Davao Trip', $itinerary->title);
    }

    public function test_a_default_title_is_used_when_none_is_given(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');
        $this->post('/plan', $this->surveyPayload());

        $this->post(route('plan.itinerary.save'));

        $this->assertSame('2-Day Davao Region Trip', Itinerary::sole()->title);
    }

    /**
     * Health/accessibility answers exist to shape THIS trip's recommendations
     * and are explicitly meant to live only as long as the plan does -- saving
     * the plan permanently must not quietly turn that into indefinite
     * retention.
     */
    public function test_saving_strips_the_linked_health_profile(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');

        $this->post('/plan', $this->surveyPayload([
            'health_consent' => '1', 'health_conditions' => ['mobility'],
        ]));

        $preferenceId = session(TripPlannerController::PREFERENCE_KEY);
        $this->assertNotNull(TouristHealthProfile::where('preference_id', $preferenceId)->first());

        $this->post(route('plan.itinerary.save'));

        $this->assertNull(TouristHealthProfile::where('preference_id', $preferenceId)->first());
    }

    public function test_a_saved_itinerary_appears_in_my_itineraries(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');
        $this->post('/plan', $this->surveyPayload());
        $this->post(route('plan.itinerary.save'), ['title' => 'My Davao Trip']);

        $this->get(route('account.itineraries'))->assertOk()->assertSee('My Davao Trip');
    }

    public function test_opening_a_saved_itinerary_loads_it_into_session_and_renders_it(): void
    {
        $this->seedDestinations();
        $tourist = $this->tourist();
        $this->actingAs($tourist, 'tourist');
        $this->post('/plan', $this->surveyPayload());
        $this->post(route('plan.itinerary.save'), ['title' => 'My Davao Trip']);
        $itinerary = Itinerary::sole();

        $this->get(route('account.itineraries.show', $itinerary))
            ->assertRedirect(route('plan.itinerary'));

        $this->assertSame($itinerary->id, session(TripPlannerController::ITINERARY_KEY));
        $this->get(route('plan.itinerary'))->assertOk()->assertSee('My Davao Trip');
    }

    public function test_a_tourist_cannot_view_another_tourists_itinerary(): void
    {
        $this->seedDestinations();
        $owner = $this->tourist('owner');
        $this->actingAs($owner, 'tourist');
        $this->post('/plan', $this->surveyPayload());
        $this->post(route('plan.itinerary.save'));
        $itinerary = Itinerary::sole();

        $intruder = $this->tourist('intruder');
        $this->actingAs($intruder, 'tourist');

        $this->get(route('account.itineraries.show', $itinerary))->assertForbidden();
        $this->get(route('account.itineraries.edit', $itinerary))->assertForbidden();
        $this->delete(route('account.itineraries.destroy', $itinerary))->assertForbidden();
    }

    public function test_deleting_a_saved_itinerary_removes_it_and_its_items(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');
        $this->post('/plan', $this->surveyPayload());
        $this->post(route('plan.itinerary.save'));
        $itinerary = Itinerary::sole();

        $this->delete(route('account.itineraries.destroy', $itinerary))
            ->assertRedirect(route('account.itineraries'));

        $this->assertSame(0, Itinerary::count());
        $this->assertDatabaseCount('itinerary_items', 0);
    }

    /** A package-adopted itinerary must be saveable the same way as a generated one. */
    public function test_a_package_adopted_itinerary_can_be_saved(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        $package = Package::create([
            'slug' => 'tourist-save-test-package', 'name' => 'Tourist Save Test Package', 'location' => 'Davao City',
            'region_id' => $region->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
        PackageItineraryDay::create(['package_id' => $package->id, 'day_number' => 1, 'title' => 'Arrival']);

        $this->actingAs($this->tourist(), 'tourist');
        $this->post(route('packages.plan-with', $package));

        $this->post(route('plan.itinerary.save'))->assertRedirect();

        $itinerary = Itinerary::sole();
        $this->assertNotNull($itinerary->tourist_account_id);
        $this->assertSame($package->id, $itinerary->package_id);
        $this->assertSame('Tourist Save Test Package', $itinerary->title);
    }

    public function test_editing_a_saved_itinerary_loads_its_preference_and_goes_to_the_planner(): void
    {
        $this->seedDestinations();
        $this->actingAs($this->tourist(), 'tourist');
        $this->post('/plan', $this->surveyPayload());
        $this->post(route('plan.itinerary.save'));
        $itinerary = Itinerary::sole();

        $this->get(route('account.itineraries.edit', $itinerary))
            ->assertRedirect(route('plan.edit'));

        $this->assertSame($itinerary->preference_id, session(TripPlannerController::PREFERENCE_KEY));
    }
}
