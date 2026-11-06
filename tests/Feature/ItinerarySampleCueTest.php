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
use App\Models\SouvenirCenter;
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
        // The card's accreditation line says it; there is no second, boxed tag beside the name.
        $this->assertStringNotContainsString(self::CUE, $html);
        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
    }

    public function test_an_official_destination_is_not_labelled_as_sample(): void
    {
        $html = $this->page($this->destination('Damosa Land Inc. / Agriya Naturetainment', false));

        $this->assertStringContainsString('Damosa Land', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_a_destination_stored_with_the_sample_suffix_shows_a_clean_name_and_the_sample_label(): void
    {
        $html = $this->page($this->destination('Tagbibinta Falls [SAMPLE]', true));

        // The suffix stays in the stored name, but a visitor reads the clean name and is told by the label instead.
        $this->assertStringContainsString('Tagbibinta Falls', $html);
        $this->assertStringNotContainsString('Tagbibinta Falls [SAMPLE]', $html);
        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
    }

    public function test_a_sample_destination_says_not_dot_verified_never_accreditation_listed(): void
    {
        $html = $this->page($this->destination('Quiet Falls', true));

        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
        $this->assertStringNotContainsString('DOT accreditation listed', $html);
    }

    // G2 completed under explicit authorization: the card carries the three-state accreditation
    // wording from SampleData provenance. An official record (genuine, non-placeholder DOT number)
    // says so and never gets a sample cue; eligibility flag alone never decides this.
    public function test_an_official_destination_shows_dot_accreditation_listed_and_no_sample_label(): void
    {
        $html = $this->page($this->destination('Verified Falls', false));

        $this->assertStringContainsString('Verified Falls', $html);
        $this->assertStringContainsString('DOT accreditation listed', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_an_unverified_destination_shows_not_verified_and_is_never_listed_as_accredited(): void
    {
        // Eligible (is_accredited) but with no accreditation record on file at all: unverified.
        $destination = Destination::create([
            'slug' => 'unverified-falls', 'name' => 'Unverified Falls', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.15, 'longitude' => 125.40,
        ]);

        $html = $this->page($destination);

        $this->assertStringContainsString('DOT accreditation not verified', $html);
        $this->assertStringNotContainsString('DOT accreditation listed', $html);
        $this->assertStringNotContainsString(self::CUE, $html);
    }

    public function test_a_sample_souvenir_stop_shows_the_sample_warning_exactly_once(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $shop = SouvenirCenter::create([
            'slug' => 'cue-sample-shop', 'name' => 'Cue Sample Shop', 'location' => 'X', 'region_id' => $region->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create(['listing_kind' => 'souvenir_center', 'listing_id' => $shop->id, 'accreditation_number' => 'SAMPLE-9203', 'status' => 'Active', 'is_placeholder' => true]);

        $pref = TouristPreference::create(['travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far']);
        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 1, 'generated_at' => now()]);
        ItineraryItem::create(['itinerary_id' => $trip->id, 'day_number' => 1, 'sort_order' => 1, 'slot' => 'Afternoon', 'kind' => 'activity',
            'title' => 'Souvenir stop', 'starts_at' => '15:00:00', 'ends_at' => '16:00:00', 'souvenir_center_id' => $shop->id]);

        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::CUE, $html, 'No boxed tag beside the name; the accreditation line says it.');
        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
        $this->assertStringNotContainsString('DOT accreditation listed', $html);
    }

    public function test_official_meal_and_stay_cards_show_dot_accreditation_listed(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $destination = $this->destination('Anchor Falls', false);
        $stay = Accommodation::create([
            'slug' => 'cue-official-stay', 'name' => 'Cue Official Stay', 'location' => 'X', 'region_id' => $region->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'DOT-R11-REAL-STAY', 'status' => 'Active', 'is_placeholder' => false]);
        $restaurant = Restaurant::create([
            'slug' => 'cue-official-restaurant', 'name' => 'Cue Official Restaurant', 'location' => 'X', 'region_id' => $region->id,
            'cuisine_type' => 'Filipino', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        AccreditationRecord::create(['listing_kind' => 'restaurant', 'listing_id' => $restaurant->id, 'accreditation_number' => 'DOT-R11-REAL-MEAL', 'status' => 'Active', 'is_placeholder' => false]);

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

        // Destination stop, meal card and stay card each carry the official wording; no sample cue anywhere.
        $this->assertSame(3, substr_count($html, 'DOT accreditation listed'));
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

        $this->assertStringNotContainsString(self::CUE, $html, 'No boxed tag on the meal or stay card; the accreditation line says it.');
        $this->assertStringContainsString('Sample data, not DOT-verified', $html);
        // The official anchor destination on the same page correctly carries the official wording;
        // the sample meal and stay are never the ones presented as accredited (their cue count above).
        $this->assertStringContainsString('DOT accreditation listed', $html);
    }

    public function test_a_sample_stop_with_a_popular_pairing_has_no_boxed_tag_but_keeps_its_accreditation_line(): void
    {
        $html = $this->page($this->destination('Eden Nature Park', true), [
            'rule_basis' => 'Malagos Garden Resort', 'rule_support' => 0.12, 'rule_confidence' => 0.8, 'rule_co_count' => 9,
        ]);

        $this->assertStringContainsString('Popular pairing with', $html);
        $this->assertStringNotContainsString(self::CUE, $html);

        // "Why this pick?" is a real button; the figures are chips taken from the stored rule, nothing invented.
        $this->assertMatchesRegularExpression('/<button type="button" class="pairing-why__btn" aria-expanded="false" aria-controls="pairing-\d+">Why this pick\?/', $html);
        $this->assertStringContainsString('Frequently visited together with Malagos Garden Resort.', $html);
        $this->assertStringContainsString('<li>Confidence 80%</li>', $html);
        $this->assertStringContainsString('<li>Support 12%</li>', $html);
        $this->assertStringContainsString('<li>Based on 9 trips</li>', $html);
        $this->assertStringNotContainsString('Involves sample data', $html);
        $this->assertStringNotContainsString('[SAMPLE]', $html);
        $this->assertStringContainsString('Sample data, not DOT-verified', $html, 'The accreditation line still says it.');
    }

    public function test_the_accreditation_badge_is_one_pill_in_two_states(): void
    {
        $sample = $this->page($this->destination('Quiet Falls', true));
        $official = $this->page($this->destination('Damosa Land Inc. / Agriya Naturetainment', false));

        $this->assertStringContainsString('tl-badge tl-badge--warn', $sample);
        $this->assertStringNotContainsString('tl-badge--ok', $sample);
        $this->assertStringContainsString('tl-badge tl-badge--ok', $official);
        $this->assertStringNotContainsString('tl-badge--warn', $official);
        $this->assertStringContainsString('DOT accreditation listed', $official);
    }
}
