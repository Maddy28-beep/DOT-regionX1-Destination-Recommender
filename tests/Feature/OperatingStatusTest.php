<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A closed place must not be recommended. Two things can close one for a trip:
 * its own operating status (temporarily closed until a date, or closed for
 * good) and a danger advisory. Both are judged against the traveller's own
 * dates, so a closure that ends before the trip, or starts after it, does not
 * matter. Warning and info advisories only warn.
 */
class OperatingStatusTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->region = Region::create(['name' => 'Davao City']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(string $name, array $overrides = []): Destination
    {
        return Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
            'price_tier' => 'Mid-range', 'latitude' => 7.10, 'longitude' => 125.50, 'distance_km' => 10,
        ]);
    }

    private function trip(string $startsOn, int $days = 2): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far', 'start_date' => $startsOn,
        ]);
    }

    /** @return array<int, string> names the recommender would consider for this trip */
    private function recommended(TouristPreference $preference): array
    {
        return app(ContentBasedRecommendationService::class)->rank($preference)
            ->map(fn ($row) => $row['destination']->name)->values()->all();
    }

    public function test_an_open_place_is_recommended(): void
    {
        $this->place('Open Park');

        $this->assertSame(['Open Park'], $this->recommended($this->trip('2026-10-05')));
    }

    public function test_a_place_closed_until_after_the_trip_starts_is_not_recommended(): void
    {
        $this->place('Open Park');
        $this->place('Repair Park', ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-10-20']);

        $this->assertSame(['Open Park'], $this->recommended($this->trip('2026-10-05')));
    }

    public function test_the_same_place_is_recommended_for_a_trip_that_starts_after_it_reopens(): void
    {
        $this->place('Repair Park', ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-10-20']);

        $this->assertSame(['Repair Park'], $this->recommended($this->trip('2026-10-20')));
        $this->assertSame(['Repair Park'], $this->recommended($this->trip('2026-11-02')));
    }

    public function test_a_temporary_closure_with_no_reopening_date_keeps_the_place_out(): void
    {
        $this->place('Open Park');
        $this->place('Undated Closure', ['operating_status' => 'temporarily_closed']);

        $this->assertSame(['Open Park'], $this->recommended($this->trip('2027-03-01')));
    }

    public function test_a_place_closed_for_good_is_never_recommended(): void
    {
        $this->place('Open Park');
        $this->place('Gone Park', ['operating_status' => 'closed']);

        $this->assertSame(['Open Park'], $this->recommended($this->trip('2027-06-01')));
    }

    public function test_a_danger_advisory_that_overlaps_the_trip_removes_the_place(): void
    {
        $this->place('Open Park');
        $closed = $this->place('Flooded Park');
        Advisory::create([
            'title' => 'Flooding', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination',
            'listing_id' => $closed->id, 'starts_at' => '2026-10-04', 'ends_at' => '2026-10-10',
        ]);

        $this->assertSame(['Open Park'], $this->recommended($this->trip('2026-10-08')));
    }

    public function test_a_danger_advisory_outside_the_trip_dates_does_not_matter(): void
    {
        $closed = $this->place('Flooded Park');
        Advisory::create([
            'title' => 'Flooding', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination',
            'listing_id' => $closed->id, 'starts_at' => '2026-10-04', 'ends_at' => '2026-10-10',
        ]);

        $this->assertSame(['Flooded Park'], $this->recommended($this->trip('2026-10-15')));
        $this->assertSame(['Flooded Park'], $this->recommended($this->trip('2026-09-25', 3)));
    }

    public function test_an_advisory_with_no_end_date_stays_in_force(): void
    {
        $closed = $this->place('Closed Trail');
        Advisory::create(['title' => 'Closed', 'message' => 'x', 'severity' => 'danger', 'listing_kind' => 'destination', 'listing_id' => $closed->id]);

        $this->assertSame([], $this->recommended($this->trip('2027-01-15')));
    }

    public function test_warning_and_info_advisories_do_not_remove_a_place(): void
    {
        $slippery = $this->place('Slippery Trail');
        $info = $this->place('Busy Park');
        Advisory::create(['title' => 'Slippery', 'message' => 'x', 'severity' => 'warning', 'listing_kind' => 'destination', 'listing_id' => $slippery->id]);
        Advisory::create(['title' => 'Crowded', 'message' => 'x', 'severity' => 'info', 'listing_kind' => 'destination', 'listing_id' => $info->id]);

        $this->assertEqualsCanonicalizing(['Slippery Trail', 'Busy Park'], $this->recommended($this->trip('2026-10-05')));
    }

    public function test_a_generated_itinerary_leaves_out_a_closed_place_and_its_page_still_loads_with_a_notice(): void
    {
        $this->place('Open Park');
        $closed = $this->place('Repair Park', ['operating_status' => 'temporarily_closed', 'closure_reason' => 'Roof repairs', 'reopens_on' => '2026-12-01']);

        $this->postJson('/plan', [
            'travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'far', 'start_date' => '2026-10-05',
            'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        $itinerary = \App\Models\Itinerary::latest('generated_at')->first();
        $this->assertTrue($itinerary->items()->where('destination_id', '!=', $closed->id)->whereNotNull('destination_id')->exists());
        $this->assertFalse($itinerary->items()->where('destination_id', $closed->id)->exists());

        $this->get(route('destinations.show', $closed))
            ->assertOk()
            ->assertSee('Temporarily closed until December 1')
            ->assertSee('Roof repairs');
    }

    public function test_a_closed_stay_is_not_chosen_as_the_accommodation(): void
    {
        $this->place('Open Park');
        $make = fn (string $name, array $extra = []) => Accommodation::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4.0, 'latitude' => 7.10, 'longitude' => 125.50,
        ]);
        $closed = $make('Closed Hotel', ['rating' => 5.0, 'operating_status' => 'closed']);
        $open = $make('Open Hotel');

        $this->postJson('/plan', [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Hotel', 'distance_pref' => 'far', 'start_date' => '2026-10-05',
            'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
        ])->assertSuccessful();

        $itinerary = \App\Models\Itinerary::latest('generated_at')->first();
        $this->assertTrue($itinerary->items()->where('accommodation_id', $open->id)->exists());
        $this->assertFalse($itinerary->items()->where('accommodation_id', $closed->id)->exists());
    }

    public function test_an_admin_can_mark_a_place_closed_and_reopening_clears_the_notice(): void
    {
        $admin = AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
        $place = $this->place('Test Park');

        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $place->id]), [
            'name' => 'Test Park', 'operating_status' => 'temporarily_closed', 'reopens_on' => '2026-11-15', 'closure_reason' => 'Typhoon damage',
        ])->assertRedirect();

        $place->refresh();
        $this->assertSame('temporarily_closed', $place->operating_status);
        $this->assertSame('Temporarily closed until November 15: Typhoon damage', $place->operatingNotice());

        $this->actingAs($admin, 'admin')->put(route('admin.listings.update', ['destinations', $place->id]), [
            'name' => 'Test Park', 'operating_status' => 'open', 'reopens_on' => '2026-11-15', 'closure_reason' => 'stale',
        ])->assertRedirect();

        $place->refresh();
        $this->assertSame('open', $place->operating_status);
        $this->assertNull($place->reopens_on);
        $this->assertNull($place->closure_reason);
        $this->assertNull($place->operatingNotice());
    }

    public function test_the_notice_disappears_once_the_reopening_date_has_passed(): void
    {
        $place = $this->place('Late Park', ['operating_status' => 'temporarily_closed', 'reopens_on' => '2026-09-20']);

        $this->assertNull($place->operatingNotice());
    }
}
