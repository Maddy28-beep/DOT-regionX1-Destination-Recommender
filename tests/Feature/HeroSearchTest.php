<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage hero's search bar used to GET to the trip planner, which read
 * none of its four inputs -- so every selection was dropped and the button
 * only navigated. These cover what it does now: send the visitor to the
 * catalogue that answers "I want to...", carrying the rest across as filters.
 */
class HeroSearchTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug, string $type, string $priceTier = 'Mid-range'): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => $type, 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => $priceTier,
        ]);
    }

    private function location(string $header): string
    {
        return (string) $header;
    }

    public function test_each_purpose_lands_on_the_catalogue_that_answers_it(): void
    {
        $targets = [
            'destinations' => '/destinations',
            'accommodations' => '/accommodations',
            'packages' => '/packages',
            'restaurants' => '/restaurants',
        ];

        foreach ($targets as $purpose => $path) {
            $location = $this->get('/search?purpose='.$purpose)
                ->assertRedirect()
                ->headers->get('Location');

            $this->assertStringContainsString($path, $location,
                "\"{$purpose}\" should land on {$path}.");
        }
    }

    /** An unrecognised or absent purpose still has to go somewhere sensible. */
    public function test_an_unknown_purpose_falls_back_to_destinations(): void
    {
        foreach (['/search', '/search?purpose=', '/search?purpose=nonsense'] as $url) {
            $this->assertStringContainsString('/destinations',
                (string) $this->get($url)->assertRedirect()->headers->get('Location'));
        }
    }

    /**
     * The whole point of the fix: the selections have to survive the trip and
     * actually narrow the results.
     */
    public function test_budget_and_interest_reach_the_results(): void
    {
        $this->destination('davao-crocodile-park', 'Wildlife');
        $this->destination('peoples-park', 'Cultural Heritage');

        $location = (string) $this->get('/search?purpose=destinations&budget=Mid-range&interest=Wildlife')
            ->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('price_tier=Mid-range', urldecode($location));
        $this->assertStringContainsString('interest=Wildlife', urldecode($location));

        $html = $this->get($location)->assertOk()->getContent();
        $this->assertStringContainsString('Davao Crocodile Park', $html);
        $this->assertStringNotContainsString('Peoples Park', $html,
            'A Cultural Heritage listing must not survive a Wildlife search.');
    }

    /**
     * One interest covers several stored types -- "Beach & Island" is filed as
     * both "Beach & Leisure" and "Beach & Surfing" -- so an equality filter on
     * `type` found nothing for it. This is the case that made the search look
     * broken even once it was wired up.
     */
    public function test_an_interest_spanning_several_stored_types_finds_all_of_them(): void
    {
        $this->destination('dahican-beach', 'Beach & Surfing');
        $this->destination('samal-island', 'Beach & Leisure');
        $this->destination('davao-crocodile-park', 'Wildlife');

        $html = $this->get('/destinations?interest='.urlencode('Beach & Island'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Dahican Beach', $html);
        $this->assertStringContainsString('Samal Island', $html);
        $this->assertStringNotContainsString('Davao Crocodile Park', $html);
    }

    /** The browse filter and the recommender must agree on what an interest covers. */
    public function test_the_interest_vocabulary_is_shared_with_the_recommender(): void
    {
        $beach = ContentBasedRecommendationService::typesForInterest('Beach & Island');

        $this->assertContains('Beach & Leisure', $beach);
        $this->assertContains('Beach & Surfing', $beach);
        $this->assertContains('Beach & Island', $beach, 'Packages store the interest name as their own type.');
        $this->assertNotContains('Wildlife', $beach);
    }

    /**
     * A filter the chosen catalogue cannot act on is dropped rather than
     * passed along to be quietly ignored: an interest says nothing about a
     * hotel, and only packages record a length.
     */
    public function test_filters_that_cannot_apply_are_not_carried_over(): void
    {
        $location = urldecode((string) $this->get(
            '/search?purpose=accommodations&budget=Mid-range&interest=Wildlife&duration=1-2'
        )->assertRedirect()->headers->get('Location'));

        $this->assertStringContainsString('price_tier=Mid-range', $location);
        $this->assertStringNotContainsString('interest=', $location);
        $this->assertStringNotContainsString('duration=', $location);
    }

    public function test_duration_narrows_packages_to_trips_of_that_length(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        foreach ([['day-tour', 1], ['summit-trek', 3], ['grand-tour', 7]] as [$slug, $days]) {
            Package::create([
                'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)),
                'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature & Adventure', 'is_accredited' => true,
                'rating' => 4.5, 'review_count' => 5, 'price_tier' => 'Mid-range',
                'duration_days' => $days,
            ]);
        }

        $html = $this->get('/packages?duration=1-2')->assertOk()->getContent();
        $this->assertStringContainsString('Day Tour', $html);
        $this->assertStringNotContainsString('Summit Trek', $html);
        $this->assertStringNotContainsString('Grand Tour', $html);

        $open = $this->get('/packages?duration=5-plus')->assertOk()->getContent();
        $this->assertStringContainsString('Grand Tour', $open);
        $this->assertStringNotContainsString('Day Tour', $open);
    }
}
