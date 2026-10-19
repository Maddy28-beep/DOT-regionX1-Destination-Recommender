<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\SimilarDestinationService;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A place that is closed on the weekday of the stop being replaced is not offered as a substitute. */
class SwapClosedDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_substitutes_closed_on_the_stops_weekday_are_not_offered(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([['Zoo', 7.12, 125.64], ['Eagle', 7.15, 125.41], ['Museum', 7.07, 125.61], ['Church', 7.06, 125.60], ['Beach', 7.15, 125.71], ['Park', 7.03, 125.37], ['Falls', 7.10, 125.50], ['Garden', 7.08, 125.55], ['Market', 7.06, 125.62], ['Island', 7.14, 125.70]] as $i => [$n, $la, $lo]) {
            $d = Destination::create(['slug' => strtolower($n), 'name' => $n, 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Wildlife', 'is_accredited' => true,
                'rating' => 5 - $i * 0.1, 'review_count' => 5, 'price_tier' => 'Mid-range', 'latitude' => $la, 'longitude' => $lo]);
            DestinationEmbedding::create(['destination_id' => $d->id, 'model' => 'test', 'dimensions' => 2, 'text_hash' => sha1($n),
                'vector' => DestinationEmbeddingService::normalise([1, 0.1 * $i])]);
        }

        $preference = TouristPreference::create(['travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-19']);   // a Monday
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));
        $stop = $itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')->first();
        $original = Destination::find($stop->destination_id);
        $date = now()->parse('2026-10-19')->addDays($stop->day_number - 1);
        $weekday = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'][$date->dayOfWeekIso - 1];

        $service = app(SimilarDestinationService::class);
        $before = $service->alternatives($original, $itinerary, 10)->pluck('destination');
        $this->assertNotEmpty($before, 'there must be substitutes to start with');

        $closed = $before->first();
        $closed->update(['hours' => 'Closed '.$weekday]);

        $after = $service->alternatives($original, $itinerary, 10)->pluck('destination.id');

        $this->assertNotContains($closed->id, $after->all());
    }
}
