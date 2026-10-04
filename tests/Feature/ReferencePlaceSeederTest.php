<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Models\TouristPreference;
use Database\Seeders\ReferencePlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reference places let published itineraries be matched to a row without ever
 * presenting an unaccredited place to travelers.
 */
class ReferencePlaceSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedWithRegions(): void
    {
        foreach (['Davao City', 'Island Garden City of Samal', 'Davao Oriental'] as $name) {
            Region::firstOrCreate(['name' => $name]);
        }
        $this->seed(ReferencePlaceSeeder::class);
    }

    public function test_places_are_stored_unaccredited_with_nothing_unverified_filled_in(): void
    {
        $this->seedWithRegions();

        $place = Destination::where('slug', 'museo-dabawenyo')->firstOrFail();

        $this->assertFalse($place->is_accredited);
        $this->assertNull($place->latitude);
        $this->assertNull($place->entry_fee_min);
        $this->assertSame(0, (int) $place->review_count);
        $this->assertNotNull($place->region_id);
    }

    public function test_they_are_invisible_to_the_public_site_the_picker_and_the_recommender(): void
    {
        $this->seedWithRegions();

        $this->assertSame(0, Destination::publiclyVisible()->count());
        $this->get(route('destinations.index'))->assertOk()->assertDontSee('Museo Dabawenyo');
        $this->get(route('exit-survey.create'))->assertOk()->assertDontSee('Museo Dabawenyo');

        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
        ]);
        $this->assertTrue(app(ContentBasedRecommendationService::class)->rank($preference)->isEmpty());
    }

    public function test_rerunning_does_not_duplicate_them(): void
    {
        $this->seedWithRegions();
        $count = Destination::count();

        $this->seed(ReferencePlaceSeeder::class);

        $this->assertSame($count, Destination::count());
    }
}
