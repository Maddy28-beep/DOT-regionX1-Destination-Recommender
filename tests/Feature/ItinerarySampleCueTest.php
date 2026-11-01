<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Region;
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

    public function test_a_sample_stop_with_a_popular_pairing_still_carries_only_one_label(): void
    {
        $html = $this->page($this->destination('Eden Nature Park', true), [
            'rule_basis' => 'Malagos Garden Resort', 'rule_support' => 0.12, 'rule_confidence' => 0.8, 'rule_co_count' => 9,
        ]);

        $this->assertStringContainsString('Popular pairing with', $html);
        $this->assertSame(1, substr_count($html, self::CUE));
    }
}
