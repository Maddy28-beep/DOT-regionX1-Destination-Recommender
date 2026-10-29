<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\PreferenceFit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Objective 1.3.2.1: travel purpose, visitor type and length of stay now change which destinations score best. They
 * used to be the same number for every destination, so changing the answer could never change a recommendation.
 */
class PreferenceFitScoringTest extends TestCase
{
    use RefreshDatabase;

    // ---- the three fits on their own ---------------------------------------------------------------

    public function test_purpose_prefers_the_kind_of_place_that_suits_the_trip(): void
    {
        $this->assertGreaterThan(PreferenceFit::purpose('Adventure & Hiking', 'Peak', 'Medical'), PreferenceFit::purpose('Wellness & Spa', 'Spa', 'Medical'));
        $this->assertGreaterThan(PreferenceFit::purpose('Nature & Adventure', 'Falls', 'Business'), PreferenceFit::purpose('Events & Conventions', 'Hall', 'Business'));
        $this->assertGreaterThan(PreferenceFit::purpose('Beach & Leisure', 'Beach', 'Educational'), PreferenceFit::purpose('Cultural Heritage', 'Museum', 'Educational'));
        $this->assertSame(1.0, PreferenceFit::purpose('Beach & Leisure', 'Beach', 'Leisure'));
        $this->assertSame(0.4, PreferenceFit::purpose('Events & Conventions', 'Hall', 'Leisure'), 'a convention hall is not a leisure stop');
    }

    public function test_a_place_of_worship_counts_for_a_pilgrimage_whatever_its_type(): void
    {
        $this->assertSame(1.0, PreferenceFit::purpose('Nature & Leisure', 'Hilltop Chapel', 'Religious/Pilgrimage'));
        $this->assertSame(0.4, PreferenceFit::purpose('Nature & Leisure', 'Hilltop Park', 'Religious/Pilgrimage'));
    }

    public function test_an_unknown_or_other_purpose_is_neutral_and_never_zero(): void
    {
        $this->assertSame(0.7, PreferenceFit::purpose('Wildlife', 'Zoo', 'Other'));
        $this->assertSame(0.7, PreferenceFit::purpose('Wildlife', 'Zoo', null));
    }

    public function test_first_time_visitors_favour_popular_places_and_returning_ones_the_less_visited(): void
    {
        $this->assertGreaterThan(PreferenceFit::visitor(0.1, 'First-time Visitor'), PreferenceFit::visitor(0.9, 'First-time Visitor'));
        $this->assertLessThan(PreferenceFit::visitor(0.1, 'Returning Visitor'), PreferenceFit::visitor(0.9, 'Returning Visitor'));
        $this->assertLessThan(PreferenceFit::visitor(0.1, 'Regular / Local'), PreferenceFit::visitor(0.9, 'Regular / Local'));
        $this->assertSame(0.7, PreferenceFit::visitor(0.5, null));
    }

    public function test_a_short_trip_favours_short_stops_and_a_long_one_full_days(): void
    {
        $museum = fn (int $days) => PreferenceFit::duration('Cultural Heritage', null, $days);
        $hike = fn (int $days) => PreferenceFit::duration('Adventure & Hiking', null, $days);

        $this->assertGreaterThan($hike(1), $museum(1));
        $this->assertGreaterThan($museum(5), $hike(5));
        $this->assertSame($hike(4), $hike(9), 'four days and beyond are one band');
    }

    public function test_a_recorded_visit_duration_beats_the_typical_one_for_the_type(): void
    {
        // A museum filed as a full day, on a one-day trip, fits worse than the typical short museum.
        $this->assertLessThan(PreferenceFit::duration('Cultural Heritage', null, 1), PreferenceFit::duration('Cultural Heritage', 'full day', 1));
        $this->assertSame(2.0, PreferenceFit::hours('2 hours'));
        $this->assertSame(1.5, PreferenceFit::hours('1-2 hrs'));
        $this->assertSame(4.0, PreferenceFit::hours('Half day'));
        $this->assertSame(0.5, PreferenceFit::hours('30 minutes'));
        $this->assertNull(PreferenceFit::hours('varies'));
        $this->assertNull(PreferenceFit::hours(null));
    }

    // ---- the answers now change the ranking ---------------------------------------------------------

    private function place(string $name, string $type): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => $type, 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61, 'distance_km' => 5,
        ]);
    }

    /** @return list<string> destination names, best first */
    private function ranked(array $answers, array $interests = []): array
    {
        $preference = new TouristPreference($answers + [
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'near',
        ]);
        $preference->setRelation('activities', collect(array_map(fn ($a) => (object) ['activity' => $a], $interests)));
        $preference->setRelation('amenities', collect());

        return app(ContentBasedRecommendationService::class)->rank($preference)->map(fn ($r) => $r['destination']->name)->all();
    }

    public function test_the_purpose_of_the_trip_changes_the_order(): void
    {
        $this->place('Mountain Peak', 'Adventure & Hiking');
        $this->place('Day Spa', 'Wellness & Spa');

        $this->assertSame('Day Spa', $this->ranked(['travel_purpose' => 'Medical'])[0]);
        $this->assertSame('Mountain Peak', $this->ranked(['travel_purpose' => 'Leisure', 'travel_days' => 5])[0], 'for leisure on a long trip the full-day peak leads');
    }

    public function test_first_time_and_returning_visitors_are_sent_different_ways(): void
    {
        $busy = $this->place('Busy Park', 'Nature & Leisure');
        $quiet = $this->place('Quiet Park', 'Nature & Leisure');

        foreach (range(1, 6) as $i) {
            $survey = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $busy->id]);
        }
        $survey = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);
        ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $quiet->id]);

        $this->assertSame('Busy Park', $this->ranked(['visitor_type' => 'First-time Visitor'])[0]);
        $this->assertSame('Quiet Park', $this->ranked(['visitor_type' => 'Returning Visitor'])[0]);
    }

    public function test_the_length_of_the_stay_changes_the_order(): void
    {
        $this->place('Mountain Peak', 'Adventure & Hiking');
        $this->place('Old Museum', 'Cultural Heritage');

        $this->assertSame('Old Museum', $this->ranked(['travel_days' => 1])[0], 'a one-day trip fits the short stop');
        $this->assertSame('Mountain Peak', $this->ranked(['travel_days' => 5])[0], 'a five-day trip has room for the full day');
    }

    public function test_the_interests_picked_still_come_before_everything_else(): void
    {
        $this->place('Day Spa', 'Wellness & Spa');
        $this->place('Mountain Peak', 'Adventure & Hiking');

        // A medical trip would put the spa first, but this traveller picked hiking: the peak still leads.
        $this->assertSame('Mountain Peak', $this->ranked(['travel_purpose' => 'Medical'], ['Hiking & Trekking'])[0]);
        $this->assertSame('Day Spa', $this->ranked(['travel_purpose' => 'Medical'], ['Relaxation & Wellness'])[0]);
    }

    public function test_the_category_weights_still_add_up_to_one_hundred(): void
    {
        $weights = (new \ReflectionClass(ContentBasedRecommendationService::class))->getReflectionConstant('PM_WEIGHTS')->getValue();

        $this->assertSame(100, array_sum($weights));
        $this->assertGreaterThan(0, $weights['travel_purpose']);
        $this->assertGreaterThan(0, $weights['visitor_type']);
        $this->assertGreaterThan(0, $weights['duration_of_stay']);
    }
}
