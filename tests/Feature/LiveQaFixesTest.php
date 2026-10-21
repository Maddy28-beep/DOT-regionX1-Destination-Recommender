<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fixes from the live QA of exploredavao.com: the rating stat, the sitemap and the share-preview tags. */
class LiveQaFixesTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, int $reviews, float $rating, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + ['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => $rating, 'review_count' => $reviews, 'price_tier' => 'Mid-range',
            'description' => 'A lovely place to visit in the Davao Region.']);
    }

    public function test_one_review_does_not_make_the_landing_page_boast_a_rating(): void
    {
        $this->place('Lonely Review Park', 1, 5.0);

        $this->get('/')->assertOk()->assertDontSee('Traveler Rating');
    }

    public function test_the_rating_stat_appears_once_there_are_enough_reviews(): void
    {
        $this->place('Busy Park', HomeController::MIN_REVIEWS_FOR_RATING_STAT, 4.5);

        $this->get('/')->assertOk()->assertSee('Traveler Rating');
    }

    public function test_the_sitemap_lists_public_pages_and_visible_listings_only(): void
    {
        $this->place('Visible Park', 0, 0);
        $this->place('Hidden Park', 0, 0, ['is_accredited' => false]);

        $response = $this->get('/sitemap.xml')->assertOk();
        $xml = $response->getContent();

        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<loc>'.route('destinations.index').'</loc>', $xml);
        $this->assertStringContainsString('/destinations/visible-park', $xml);
        $this->assertStringNotContainsString('hidden-park', $xml);
        $this->assertStringNotContainsString('/portal', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'the sitemap is well-formed XML');
    }

    public function test_pages_carry_a_description_open_graph_tags_and_a_canonical_link(): void
    {
        $this->place('Share Park', 0, 0);

        $home = $this->get('/')->assertOk();
        $home->assertSee('<meta name="description"', false)->assertSee('<meta property="og:title"', false)->assertSee('<link rel="canonical" href="'.url('/').'">', false);

        $this->get('/destinations/share-park')->assertOk()->assertSee('content="A lovely place to visit in the Davao Region."', false);
    }

    public function test_robots_txt_points_to_the_sitemap_and_keeps_private_areas_out(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: https://www.exploredavao.com/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /portal/', $robots);
    }

    public function test_a_long_trip_with_too_few_places_says_so_instead_of_showing_bare_days(): void
    {
        foreach ([['Zoo', 7.12, 125.64], ['Museum', 7.07, 125.61], ['Beach', 7.15, 125.71], ['Park', 7.03, 125.37]] as [$n, $la, $lo]) {
            $this->place($n, 0, 0, ['latitude' => $la, 'longitude' => $lo]);
        }

        $this->post('/plan', [
            'travel_days' => 8, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-11-09',
            'arrival_time' => '09:00', 'activities' => ['Nature & Adventure'], 'amenities' => ['Parking Area'],
        ])->assertRedirect(route('plan.itinerary'));

        $page = $this->get(route('plan.itinerary'))->assertOk();

        $page->assertSee('no sightseeing planned');
        $page->assertSee('Free day, no sightseeing planned');
    }

    public function test_a_trip_that_fills_its_days_shows_no_such_notice(): void
    {
        foreach ([['Zoo', 7.12, 125.64], ['Museum', 7.07, 125.61], ['Beach', 7.15, 125.71], ['Park', 7.03, 125.37]] as [$n, $la, $lo]) {
            $this->place($n, 0, 0, ['latitude' => $la, 'longitude' => $lo]);
        }

        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Cebu City', 'start_date' => '2026-11-09',
            'arrival_time' => '09:00', 'activities' => ['Nature & Adventure'], 'amenities' => ['Parking Area'],
        ]);

        $this->get(route('plan.itinerary'))->assertOk()->assertDontSee('no sightseeing planned');
    }
}
