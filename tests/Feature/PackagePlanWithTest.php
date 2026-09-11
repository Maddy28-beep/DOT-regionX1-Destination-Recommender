<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Itinerary;
use App\Models\Package;
use App\Models\PackageItineraryDay;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Plan with this Package" lets a traveler adopt a tour operator's published
 * day-by-day schedule directly, instead of building a trip through the
 * preference survey and recommender. It reuses the ordinary Itinerary /
 * ItineraryItem tables so the rest of the planning UI keeps working
 * unchanged -- these tests cover that the copy is faithful, that the session
 * ends up pointed at it exactly like a generated plan, and that a fixed
 * package schedule can't be "regenerated" as if it were algorithmic.
 */
class PackagePlanWithTest extends TestCase
{
    use RefreshDatabase;

    private function packageWithDays(): Package
    {
        $package = Package::create([
            'slug' => 'plan-with-test-package', 'name' => 'Plan With Test Package', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'price_tier' => 'Mid-range', 'price_per_pax' => 2500, 'provider_name' => 'Test Operator',
        ]);

        PackageItineraryDay::create(['package_id' => $package->id, 'day_number' => 1, 'title' => 'Arrival', 'description' => 'Check in and orientation.']);
        PackageItineraryDay::create(['package_id' => $package->id, 'day_number' => 2, 'title' => 'Island Hopping', 'description' => null]);

        return $package;
    }

    public function test_adopting_a_package_creates_a_matching_itinerary(): void
    {
        $package = $this->packageWithDays();

        $response = $this->post(route('packages.plan-with', $package));

        $response->assertRedirect(route('plan.itinerary'));

        $itinerary = Itinerary::sole();
        $this->assertSame($package->id, $itinerary->package_id);
        $this->assertSame(2, $itinerary->total_days);
        $this->assertSame('2500.00', (string) $itinerary->est_budget_total);

        $items = $itinerary->items()->orderBy('day_number')->get();
        $this->assertCount(2, $items);
        $this->assertSame('Arrival', $items[0]->title);
        $this->assertSame('Check in and orientation.', $items[0]->note);
        $this->assertSame('Island Hopping', $items[1]->title);
        $this->assertNull($items[1]->note);
    }

    public function test_the_session_points_at_the_new_plan(): void
    {
        $package = $this->packageWithDays();

        $this->post(route('packages.plan-with', $package));

        $itinerary = Itinerary::sole();
        $this->assertSame($itinerary->preference_id, session(TripPlannerController::PREFERENCE_KEY));
        $this->assertSame($itinerary->id, session(TripPlannerController::ITINERARY_KEY));
    }

    public function test_the_itinerary_page_shows_the_package_based_plan(): void
    {
        $package = $this->packageWithDays();

        $this->post(route('packages.plan-with', $package));

        $this->get(route('plan.itinerary'))
            ->assertOk()
            ->assertSee('Arrival')
            ->assertSee('Check in and orientation.')
            ->assertSee('Island Hopping')
            ->assertSee($package->name)
            ->assertDontSee('Recommended Destinations');
    }

    public function test_a_package_with_no_published_days_cannot_be_adopted(): void
    {
        $package = Package::create([
            'slug' => 'empty-test-package', 'name' => 'Empty Test Package', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $this->post(route('packages.plan-with', $package))->assertRedirect();

        $this->assertSame(0, Itinerary::count());
    }

    public function test_an_archived_package_cannot_be_adopted(): void
    {
        $package = $this->packageWithDays();
        $package->update(['archived_at' => now()]);

        $this->post(route('packages.plan-with', $package))->assertNotFound();
    }

    public function test_regenerating_a_package_based_itinerary_is_refused(): void
    {
        $package = $this->packageWithDays();

        $this->post(route('packages.plan-with', $package));
        $itinerary = Itinerary::sole();

        $this->post(route('plan.regenerate'))->assertRedirect(route('plan.itinerary'));

        // Unchanged: no second itinerary was generated to replace it.
        $this->assertSame(1, Itinerary::count());
        $this->assertSame($itinerary->id, session(TripPlannerController::ITINERARY_KEY));
    }

    public function test_the_public_page_offers_the_button_only_once_days_are_published(): void
    {
        $withDays = $this->packageWithDays();
        $withoutDays = Package::create([
            'slug' => 'no-days-test-package', 'name' => 'No Days Test Package', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $this->get(route('packages.show', $withDays))
            ->assertOk()
            ->assertSee(route('packages.plan-with', $withDays), false);

        $this->get(route('packages.show', $withoutDays))
            ->assertOk()
            ->assertDontSee(route('packages.plan-with', $withoutDays), false);
    }
}
