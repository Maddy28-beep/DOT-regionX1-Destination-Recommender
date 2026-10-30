<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit is what lets "the itinerary is accurate" be said with a number, so each check has to catch the fault it
 * names and stay quiet when the plan is right. Plans are built by hand here, so the cause of a result is never in doubt.
 */
class ItineraryAuditTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + [
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
            'latitude' => 7.07, 'longitude' => 125.61, 'hours' => '9:00 AM–5:00 PM', 'price_tier' => 'Mid-range',
        ]);
    }

    private function plan(array $preference = [], int $days = 1): array
    {
        $p = TouristPreference::create($preference + [
            'travel_days' => $days, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => '2026-11-07', 'arrival_time' => '08:00',
        ]);

        return [$p, Itinerary::create(['preference_id' => $p->id, 'total_days' => $days, 'generated_at' => now()])];
    }

    private function item(Itinerary $it, int $day, int $order, string $kind, array $extra = []): ItineraryItem
    {
        return ItineraryItem::create($extra + ['itinerary_id' => $it->id, 'day_number' => $day, 'sort_order' => $order, 'slot' => 'Morning', 'kind' => $kind, 'title' => ucfirst($kind)]);
    }

    private function audited(TouristPreference $p, Itinerary $it): array
    {
        return collect(app(ItineraryAudit::class)->audit($it, $p->load('activities')))->keyBy('key')->all();
    }

    public function test_a_stop_visited_after_closing_fails_and_one_inside_hours_passes(): void
    {
        [$p, $it] = $this->plan();
        $park = $this->destination('Eden Nature Park');
        $this->item($it, 1, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '16:00', 'ends_at' => '18:00']);

        $checks = $this->audited($p, $it);
        $this->assertSame('fail', $checks['open']['status']);
        $this->assertStringContainsString('Eden Nature Park day 1 16:00–18:00', $checks['open']['detail']);

        ItineraryItem::query()->update(['starts_at' => '10:00', 'ends_at' => '12:30']);
        $this->assertSame('pass', $this->audited($p, $it)['open']['status']);
    }

    public function test_a_stop_with_no_readable_hours_is_unknown_not_a_pass(): void
    {
        [$p, $it] = $this->plan();
        $park = $this->destination('Mystery Falls', ['hours' => null]);
        $this->item($it, 1, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $this->assertSame('unknown', $this->audited($p, $it)['open']['status']);
    }

    public function test_a_stop_closed_that_day_fails(): void
    {
        [$p, $it] = $this->plan(['start_date' => '2026-11-08']);   // a Sunday
        $museum = $this->destination('Weekday Museum', ['hours' => 'Mon–Sat 9:00 AM–5:00 PM']);
        $this->item($it, 1, 1, 'activity', ['destination_id' => $museum->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $this->assertSame('fail', $this->audited($p, $it)['open']['status']);
    }

    public function test_a_stop_without_coordinates_and_a_journey_without_a_distance_both_fail(): void
    {
        [$p, $it] = $this->plan();
        $farm = $this->destination('Unmapped Farm', ['latitude' => null, 'longitude' => null]);
        $this->item($it, 1, 1, 'travel', ['starts_at' => '08:30', 'distance_km' => null]);
        $this->item($it, 1, 2, 'activity', ['destination_id' => $farm->id, 'starts_at' => '09:00', 'ends_at' => '11:00']);

        $checks = $this->audited($p, $it);
        $this->assertSame('fail', $checks['located']['status']);
        $this->assertSame('Unmapped Farm', $checks['located']['detail']);
        $this->assertSame('fail', $checks['journeys']['status']);
    }

    public function test_a_long_day_of_driving_fails_the_distance_and_the_travel_share_checks(): void
    {
        [$p, $it] = $this->plan();   // commutable by the default personal car: a 50 km reach, so up to 110 km a day
        $this->item($it, 1, 1, 'travel', ['starts_at' => '08:00', 'distance_km' => 70, 'travel_min_minutes' => 90, 'travel_max_minutes' => 120]);
        $this->item($it, 1, 2, 'travel', ['starts_at' => '13:00', 'distance_km' => 60, 'travel_min_minutes' => 60, 'travel_max_minutes' => 90, 'ends_at' => '16:00']);

        $checks = $this->audited($p, $it);
        $this->assertSame('fail', $checks['day_km']['status']);
        $this->assertStringContainsString('day 1 is 130 km', $checks['day_km']['detail']);
        $this->assertSame('fail', $checks['travel_share']['status']);

        ItineraryItem::query()->update(['distance_km' => 10, 'travel_max_minutes' => 20]);
        $checks = $this->audited($p, $it);
        $this->assertSame('pass', $checks['day_km']['status']);
        $this->assertSame('pass', $checks['travel_share']['status']);
    }

    public function test_an_interest_with_no_stop_fails_and_a_matching_tag_or_type_covers_it(): void
    {
        [$p, $it] = $this->plan();
        PreferenceActivity::create(['preference_id' => $p->id, 'activity' => 'Cultural Heritage']);
        PreferenceActivity::create(['preference_id' => $p->id, 'activity' => 'Food Tourism']);
        $museum = $this->destination('Old Museum', ['type' => 'Cultural Heritage']);
        $this->item($it, 1, 1, 'activity', ['destination_id' => $museum->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $checks = $this->audited($p, $it);
        $this->assertSame('fail', $checks['interests']['status']);
        $this->assertSame('no stop for Food Tourism', $checks['interests']['detail']);

        // The ranking matches a tag that contains the keyword, so "Food Tasting" covers Food Tourism.
        $museum->tags()->create(['kind' => 'category', 'value' => 'Food Tasting']);
        $this->assertSame('pass', $this->audited($p, $it)['interests']['status']);
    }

    public function test_an_empty_day_fails_except_a_first_day_after_a_late_arrival(): void
    {
        [$p, $it] = $this->plan(['arrival_time' => '18:30'], 2);
        $park = $this->destination('Eden Nature Park');
        $this->item($it, 2, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $this->assertSame('pass', $this->audited($p, $it)['stops_per_day']['status'], 'Nothing to see on the evening of arrival is expected.');

        $p->update(['arrival_time' => '08:00']);
        $this->assertSame('fail', $this->audited($p->fresh(), $it)['stops_per_day']['status']);
    }

    public function test_a_stop_before_the_traveller_has_arrived_fails(): void
    {
        [$p, $it] = $this->plan(['arrival_time' => '10:00']);
        $park = $this->destination('Eden Nature Park');
        $this->item($it, 1, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '10:15', 'ends_at' => '12:00']);

        $this->assertSame('fail', $this->audited($p, $it)['arrival']['status']);
    }

    public function test_a_lunch_at_three_in_the_afternoon_fails(): void
    {
        [$p, $it] = $this->plan();
        $this->item($it, 1, 1, 'meal', ['title' => 'Lunch — Eden Nature Park', 'starts_at' => '15:00']);
        $this->assertSame('fail', $this->audited($p, $it)['meals']['status']);

        ItineraryItem::query()->update(['starts_at' => '12:00']);
        $this->assertSame('pass', $this->audited($p, $it)['meals']['status']);
    }

    public function test_a_stay_far_from_the_days_stops_fails(): void
    {
        [$p, $it] = $this->plan();
        $park = $this->destination('Eden Nature Park');
        $hotel = Accommodation::create([
            'slug' => 'far-hotel', 'name' => 'Far Hotel', 'location' => 'Tagum', 'region_id' => $park->region_id, 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range', 'latitude' => 7.45, 'longitude' => 125.8,
        ]);
        $this->item($it, 1, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);
        $this->item($it, 1, 2, 'overnight', ['accommodation_id' => $hotel->id, 'starts_at' => '20:00']);

        $this->assertSame('fail', $this->audited($p, $it)['stay']['status']);
    }

    public function test_a_stop_above_the_budget_fails(): void
    {
        [$p, $it] = $this->plan(['budget' => 'Budget-Friendly']);
        $spa = $this->destination('Luxury Spa', ['price_tier' => 'Premium']);
        $this->item($it, 1, 1, 'activity', ['destination_id' => $spa->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $this->assertSame('fail', $this->audited($p, $it)['budget']['status']);
    }

    public function test_a_typed_start_that_could_not_be_located_fails(): void
    {
        [$p, $it] = $this->plan(['origin_label' => 'My hotel', 'origin_lat' => null, 'origin_lng' => null]);
        $this->assertSame('fail', $this->audited($p, $it)['start']['status']);

        $p->update(['origin_lat' => 7.13, 'origin_lng' => 125.645]);
        $this->assertSame('pass', $this->audited($p->fresh(), $it)['start']['status']);
    }

    public function test_the_day_distance_limit_follows_the_reach_of_the_trip(): void
    {
        $audit = app(ItineraryAudit::class);
        $limit = fn (array $pref) => $audit->dayKmLimit(new TouristPreference($pref));

        $this->assertSame(20, $limit(['distance_pref' => 'near']), 'A round trip to the edge of 5 km, plus slack.');
        $this->assertSame(40, $limit(['distance_pref' => 'moderate', 'transport_pref' => 'jeep']));
        $this->assertSame(70, $limit(['distance_pref' => 'moderate', 'transport_pref' => 'taxi']));
        $this->assertSame(110, $limit(['distance_pref' => 'moderate', 'transport_pref' => 'rental_car']));
        $this->assertSame(110, $limit(['distance_pref' => 'moderate']), 'No answer is the personal car.');
        $this->assertSame(150, $limit(['distance_pref' => 'far']), 'A remote trip has no radius, so a fixed cap.');
    }

    public function test_a_widened_trip_is_held_to_the_tier_it_was_built_with(): void
    {
        $audit = app(ItineraryAudit::class);
        $near = new TouristPreference(['distance_pref' => 'near', 'transport_pref' => 'rental_car']);

        $this->assertSame(20, $audit->dayKmLimit($near));
        $this->assertSame(110, $audit->dayKmLimit($near, 'moderate'), 'It asked for close proximity but ran short, so it was built commutable.');
        $this->assertSame(150, $audit->dayKmLimit($near, 'far'));

        [$p, $it] = $this->plan(['distance_pref' => 'near', 'transport_pref' => 'rental_car']);
        $this->item($it, 1, 1, 'travel', ['starts_at' => '08:00', 'distance_km' => 66, 'travel_min_minutes' => 60, 'travel_max_minutes' => 90]);
        $this->assertSame('fail', $this->audited($p, $it)['day_km']['status']);

        $it->update(['range_tier_used' => 'moderate', 'range_widened' => true]);
        $this->assertSame('pass', $this->audited($p, $it->fresh())['day_km']['status']);
    }

    public function test_the_same_day_of_driving_passes_by_car_and_fails_for_a_close_proximity_trip(): void
    {
        [$p, $it] = $this->plan(['distance_pref' => 'moderate', 'transport_pref' => 'rental_car']);
        $this->item($it, 1, 1, 'travel', ['starts_at' => '08:00', 'distance_km' => 90, 'travel_min_minutes' => 90, 'travel_max_minutes' => 120]);

        $this->assertSame('pass', $this->audited($p, $it)['day_km']['status'], '90 km is inside the 110 km a 50 km reach allows.');

        $p->update(['distance_pref' => 'near']);
        $this->assertSame('fail', $this->audited($p->fresh(), $it)['day_km']['status'], '90 km is far beyond a 5 km trip.');

        $p->update(['distance_pref' => 'moderate', 'transport_pref' => 'jeep']);
        $this->assertSame('fail', $this->audited($p->fresh(), $it)['day_km']['status'], 'A jeep rider reaches 15 km, so 40 km is the limit.');
    }

    public function test_a_plan_with_no_failing_check_is_clean(): void
    {
        [$p, $it] = $this->plan();
        $park = $this->destination('Eden Nature Park');
        $this->item($it, 1, 1, 'activity', ['destination_id' => $park->id, 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $audit = app(ItineraryAudit::class);
        $this->assertTrue($audit->clean($audit->audit($it, $p->load('activities'))));
    }

    public function test_the_command_builds_the_fixed_trips_and_reports_without_keeping_anything(): void
    {
        $this->destination('Eden Nature Park');
        $before = TouristPreference::count();

        $this->artisan('itinerary:audit', ['--scenario' => 'Solo day trip'])
            ->expectsOutputToContain('trips are clean')
            ->assertSuccessful();

        $this->assertSame($before, TouristPreference::count(), 'The audit trips are rolled back, so it is safe on live data.');
    }

    public function test_the_command_can_fail_a_run_below_a_minimum_pass_rate(): void
    {
        $this->artisan('itinerary:audit', ['--scenario' => 'Solo day trip', '--min-pass' => 100])->assertFailed();
    }
}
