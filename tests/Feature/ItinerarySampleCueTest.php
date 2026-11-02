<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stop on the itinerary page says when it is sample data. The seven well-known placeholders (Samal Island, Eden
 * Nature Park and so on) have no "[SAMPLE]" in their names, so the card itself has to carry the label; a name that
 * already says "[SAMPLE]" must not get a second one, and an official DOT record must never get one.
 */
class ItinerarySampleCueTest extends TestCase
{
    use RefreshDatabase;

    private const CUE = 'class="sample-tag"';

    private int $records = 0;

    private function destination(string $name, bool $placeholder): Destination
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.15, 'longitude' => 125.40,
        ]);
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id,
            'accreditation_number' => ($placeholder ? 'SAMPLE-' : 'DOT-R11-REAL-').(++$this->records),
            'status' => 'Active', 'issue_date' => now()->subMonths(3), 'expiration_date' => now()->addYear(), 'is_placeholder' => $placeholder,
        ]);

        return $d;
    }

    /** Renders the real itinerary page for a one-day trip that visits the given destination. */
    private function page(Destination $destination, array $item = []): string
    {
        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create($item + ['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit', 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $destination->id]);

        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
    }

    public function test_a_placeholder_destination_without_sample_in_its_name_shows_the_sample_label(): void
    {
        $html = $this->page($this->destination('Samal Island', true));

        $this->assertStringContainsString('Samal Island', $html);
        $this->assertSame(1, substr_count($html, self::CUE));
        $this->assertStringContainsString('Sample data', $html);
    }

    public function test_an_official_destination_is_not_labelled_as_sample(): void
    {
        $html = $this->page($this->destination('Damosa Land Inc. / Agriya Naturetainment', false));

        $this->assertStringContainsString('Damosa Land', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_a_destination_already_named_sample_gets_no_second_label(): void
    {
        $html = $this->page($this->destination('Tagbibinta Falls [SAMPLE]', true));

        $this->assertStringContainsString('Tagbibinta Falls [SAMPLE]', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_a_sample_destination_says_not_dot_verified_never_accreditation_listed(): void
    {
        $html = $this->page($this->destination('Quiet Falls', true));

        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
        $this->assertStringNotContainsString('DOT accreditation listed', $html);
    }

    // Adapted for main at Gate 3 branch construction: main's timeline row carries no
    // accreditation-label line at all (removed by the merged planner rollback), so the
    // official-side expectation is "no sample cue", not a restored label. Resurrecting
    // the removed label just to satisfy this assertion would undo merged main behaviour.
    public function test_an_official_destination_carries_no_sample_label(): void
    {
        $html = $this->page($this->destination('Verified Falls', false));

        $this->assertStringContainsString('Verified Falls', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_sample_stay_and_meal_cards_carry_the_sample_label(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $destination = $this->destination('Anchor Falls', false);
        $stay = Accommodation::create([
            'slug' => 'cue-sample-stay', 'name' => 'Cue Sample Stay', 'location' => 'X', 'region_id' => $region->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'SAMPLE-9201', 'status' => 'Active', 'is_placeholder' => true]);
        $restaurant = Restaurant::create([
            'slug' => 'cue-sample-restaurant', 'name' => 'Cue Sample Restaurant', 'location' => 'X', 'region_id' => $region->id,
            'cuisine_type' => 'Filipino', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create(['listing_kind' => 'restaurant', 'listing_id' => $restaurant->id, 'accreditation_number' => 'SAMPLE-9202', 'status' => 'Active', 'is_placeholder' => true]);

        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Morning', 'kind' => 'activity',
            'title' => 'Visit', 'starts_at' => '10:00:00', 'ends_at' => '12:00:00', 'destination_id' => $destination->id]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 2, 'slot' => 'Afternoon', 'kind' => 'meal',
            'title' => 'Lunch', 'starts_at' => '12:00:00', 'ends_at' => '13:00:00', 'restaurant_id' => $restaurant->id]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 3, 'slot' => 'Evening', 'kind' => 'overnight',
            'title' => 'Overnight stay', 'starts_at' => '20:00:00', 'ends_at' => null, 'accommodation_id' => $stay->id]);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, self::CUE), 'The meal card and the stay card each carry one sample label.');
    }

    public function test_a_sample_stop_with_a_popular_pairing_still_carries_only_one_label(): void
    {
        $html = $this->page($this->destination('Eden Nature Park', true), [
            'rule_basis' => 'Malagos Garden Resort', 'rule_support' => 0.12, 'rule_confidence' => 0.8, 'rule_co_count' => 9,
        ]);

        $this->assertStringContainsString('Popular pairing with', $html);
        $this->assertSame(1, substr_count($html, self::CUE));
    }
}
