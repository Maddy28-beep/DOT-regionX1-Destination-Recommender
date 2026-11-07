<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\PreferenceActivity;
use App\Models\PreferenceAmenity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post-trial prep (a, b). The ratings (RS) and popularity (PS) factors are neutral (3.0) for every place while the whole
 * catalogue has fewer than MIN_CATALOGUE_REVIEWS reviews, and the Amenities Score runs from 3.0 to 5.0, never below.
 */
class NeutralPriorAndAmenityRangeTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, int $reviews = 0, float $rating = 0): Destination
    {
        return Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure', 'is_accredited' => true,
            'rating' => $rating, 'review_count' => $reviews, 'price_tier' => 'Mid-range', 'hours' => '8:00 AM-5:00 PM',
            'latitude' => 7.1 + (crc32($name) % 100) / 1000, 'longitude' => 125.6,
        ]);
    }

    private function rank(array $amenities = []): \Illuminate\Support\Collection
    {
        $p = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate', 'start_date' => now()->addDays(10)->toDateString(), 'arrival_time' => '08:00',
            'origin_lat' => 7.1, 'origin_lng' => 125.6,
        ]);
        PreferenceActivity::create(['preference_id' => $p->id, 'activity' => 'Nature & Adventure']);
        foreach ($amenities as $a) {
            PreferenceAmenity::create(['preference_id' => $p->id, 'amenity' => $a]);
        }

        return app(ContentBasedRecommendationService::class)->rank($p->load('activities', 'amenities'))->keyBy(fn ($r) => $r['destination']->name);
    }

    public function test_the_threshold_is_explicit(): void
    {
        $this->assertSame(30, ContentBasedRecommendationService::MIN_CATALOGUE_REVIEWS);
    }

    public function test_with_too_few_reviews_every_place_gets_neutral_ratings_and_popularity(): void
    {
        $this->place('Reviewed Falls', 3, 5.0);
        $this->place('Plain Garden');
        $this->place('Other Garden');

        foreach ($this->rank() as $name => $row) {
            $this->assertSame(3.0, $row['rs'], "$name RS");
            $this->assertSame(3.0, $row['ps'], "$name PS");
        }
    }

    public function test_one_glowing_review_no_longer_lifts_every_unrated_place_to_5(): void
    {
        // Before: the catalogue mean was the single rated place's 5.0, so every unrated place scored RS = 5.00.
        $this->place('Reviewed Falls', 1, 5.0);
        $this->place('Plain Garden');

        $this->assertSame(3.0, $this->rank()['Plain Garden']['rs']);
    }

    public function test_at_the_threshold_reviews_count_again(): void
    {
        $this->place('Busy Falls', 30, 4.8);
        $this->place('Plain Garden');

        $rows = $this->rank();

        $this->assertGreaterThan(3.0, $rows['Busy Falls']['ps']);
        $this->assertGreaterThan(3.0, $rows['Busy Falls']['rs']);
        $this->assertSame(3.0, $rows['Plain Garden']['ps'], 'an unreviewed place still rests at neutral popularity');
    }

    public function test_one_review_below_the_threshold_is_still_inert(): void
    {
        $this->place('Busy Falls', 29, 4.8);
        $this->place('Plain Garden');

        $this->assertSame(3.0, $this->rank()['Busy Falls']['rs']);
    }

    public function test_the_amenities_score_runs_from_3_to_5_and_never_below(): void
    {
        $both = $this->place('Both Park');
        DestinationTag::create(['destination_id' => $both->id, 'kind' => 'amenity', 'value' => 'Parking Area']);
        DestinationTag::create(['destination_id' => $both->id, 'kind' => 'amenity', 'value' => 'Restroom']);
        $half = $this->place('Half Park');
        DestinationTag::create(['destination_id' => $half->id, 'kind' => 'amenity', 'value' => 'Parking Area']);
        $this->place('Bare Park');

        $none = $this->rank();
        $this->assertSame(5.0, $none['Bare Park']['as'], 'no amenity requested: nothing to penalise');

        $asked = $this->rank(['Parking Area', 'Restroom']);
        $this->assertSame(5.0, $asked['Both Park']['as'], 'every requested amenity on record');
        $this->assertSame(4.0, $asked['Half Park']['as'], 'half on record');
        $this->assertSame(3.0, $asked['Bare Park']['as'], 'none on record is neutral, not a penalty');
        foreach ($asked as $name => $row) {
            $this->assertGreaterThanOrEqual(3.0, $row['as'], $name);
            $this->assertLessThanOrEqual(5.0, $row['as'], $name);
        }
    }
}
