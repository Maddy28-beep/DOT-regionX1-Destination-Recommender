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
    public function test_the_first_screen_is_fitted_to_the_window(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="hero-screen"', $html);
        $this->assertStringContainsString('js/hero-fit.js', $html,
            'The first screen is scaled to the window by this script, so the stats and the scroll cue are never below the fold.');
    }

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

    public function test_a_typed_name_is_carried_to_the_chosen_catalogue(): void
    {
        foreach (['destinations', 'accommodations', 'restaurants', 'packages', 'souvenir_centers'] as $purpose) {
            $location = urldecode((string) $this->get('/search?purpose='.$purpose.'&q='.rawurlencode('  Samal   '))
                ->assertRedirect()->headers->get('Location'));

            $this->assertStringContainsString('q=Samal', $location, "A typed name should reach {$purpose}.");
        }

        $this->assertStringNotContainsString('q=', (string) $this->get('/search?purpose=destinations&q='.rawurlencode('   '))->assertRedirect()->headers->get('Location'),
            'A blank name must not be sent along.');
    }

    public function test_a_very_long_name_is_cut_to_the_length_of_the_field(): void
    {
        $location = urldecode((string) $this->get('/search?purpose=destinations&q='.str_repeat('a', 400))->assertRedirect()->headers->get('Location'));

        $this->assertStringContainsString('q='.str_repeat('a', 100), $location);
        $this->assertStringNotContainsString(str_repeat('a', 101), $location);
    }

    public function test_the_name_search_finds_the_listing_on_the_chosen_catalogue(): void
    {
        $this->destination('samal-island', 'Beach & Island');
        $this->destination('eden-nature-park', 'Nature & Adventure');

        $html = $this->get('/destinations?q=Samal')->assertOk()->getContent();

        $this->assertStringContainsString('Samal Island', $html);
        $this->assertStringNotContainsString('Eden Nature Park', $html);
    }

    public function test_the_landing_page_shows_the_five_catalogues_as_tabs_and_a_name_search(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-hero-search', $html);
        $this->assertStringNotContainsString('class="ticket-search', $html, 'The single row of selects is gone.');

        foreach (['destinations', 'accommodations', 'restaurants', 'packages', 'souvenir_centers'] as $purpose) {
            $this->assertMatchesRegularExpression('/<input type="radio" name="purpose" value="'.$purpose.'"/', $html);
        }

        $this->assertSame(1, preg_match_all('/name="purpose"[^>]*checked/', $html), 'Exactly one tab starts selected.');
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('name="duration"', $html);
        $this->assertStringContainsString('name="budget"', $html);
        $this->assertStringContainsString('name="interest"', $html);
        $this->assertStringContainsString('css/hero-search.css', $html);
        $this->assertStringContainsString('js/hero-search.js', $html);
    }

    public function test_budget_is_a_row_of_pills_with_any_budget_as_the_default(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier) {
            $this->assertMatchesRegularExpression('/<input type="radio" name="budget" value="'.$tier.'"/', $html);
        }

        $this->assertMatchesRegularExpression('/<input type="radio" name="budget" value="" checked>/', $html,
            '"Any budget" is the one selected by default.');
        $this->assertSame(1, preg_match_all('/name="budget"[^>]*checked/', $html));
    }

    public function test_an_empty_budget_from_the_default_pill_is_not_sent_along(): void
    {
        $location = urldecode((string) $this->get('/search?purpose=destinations&budget=&q=')->assertRedirect()->headers->get('Location'));

        $this->assertStringNotContainsString('price_tier=', $location);
        $this->assertStringNotContainsString('q=', $location);
    }

    /** Which tab can use which filter is stated in the markup, so the script and the server agree. */
    public function test_each_tab_declares_which_filters_it_can_use(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach ([
            'destinations' => ['region_id interest', 1],
            'accommodations' => ['region_id type', 1],
            'restaurants' => ['region_id cuisine_type', 1],
            'packages' => ['region_id interest duration', 1],
            'souvenir_centers' => ['region_id', 0],
        ] as $purpose => [$filters, $budget]) {
            $this->assertMatchesRegularExpression(
                '/value="'.$purpose.'"[^>]*data-filters="'.$filters.'"\s+data-budget="'.$budget.'"/s', $html,
                "{$purpose} should declare filters [{$filters}] and budget={$budget}."
            );
        }
    }

    public function test_each_tab_offers_the_filters_its_own_catalogue_can_apply(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        \App\Models\Accommodation::create([
            'slug' => 'beach-stay', 'name' => 'Beach Stay', 'location' => 'Samal', 'region_id' => $region->id,
            'type' => 'Beach Resort', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
        \App\Models\Restaurant::create([
            'slug' => 'sea-grill', 'name' => 'Sea Grill', 'location' => 'Davao City', 'region_id' => $region->id,
            'cuisine_type' => 'Filipino Seafood', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="Beach Resort">Beach Resort</option>', $html, 'Kinds of stay come from the stays on the site.');
        $this->assertStringContainsString('<option value="Filipino Seafood">Filipino Seafood</option>', $html, 'Cuisines come from the restaurants on the site.');
        $this->assertStringContainsString('<option value="'.$region->id.'">Davao City</option>', $html, 'Places are the provinces and cities.');
        $this->assertStringContainsString('name="region_id"', $html);
        $this->assertStringContainsString('name="type"', $html);
        $this->assertStringContainsString('name="cuisine_type"', $html);
        $this->assertStringContainsString('name="duration"', $html);
    }

    public function test_each_catalogue_is_sent_only_the_filters_its_page_reads(): void
    {
        $query = '&region_id=2&budget=Mid-range&interest=Wildlife&type=Hotel&cuisine_type=Cafe&duration=3-4';
        $go = fn (string $purpose) => urldecode((string) $this->get('/search?purpose='.$purpose.$query)->assertRedirect()->headers->get('Location'));

        $stays = $go('accommodations');
        $this->assertStringContainsString('region_id=2', $stays);
        $this->assertStringContainsString('type=Hotel', $stays);
        $this->assertStringContainsString('price_tier=Mid-range', $stays);
        foreach (['interest=', 'cuisine_type=', 'duration='] as $other) {
            $this->assertStringNotContainsString($other, $stays);
        }

        $dining = $go('restaurants');
        $this->assertStringContainsString('cuisine_type=Cafe', $dining);
        $this->assertStringContainsString('region_id=2', $dining);
        foreach (['type=Hotel', 'interest=', 'duration='] as $other) {
            $this->assertStringNotContainsString($other, $dining);
        }

        $packages = $go('packages');
        foreach (['region_id=2', 'interest=Wildlife', 'duration=3-4', 'price_tier=Mid-range'] as $kept) {
            $this->assertStringContainsString($kept, $packages);
        }
        $this->assertStringNotContainsString('cuisine_type=', $packages);

        $destinations = $go('destinations');
        foreach (['region_id=2', 'interest=Wildlife', 'price_tier=Mid-range'] as $kept) {
            $this->assertStringContainsString($kept, $destinations);
        }
        foreach (['duration=', 'cuisine_type=', 'type=Hotel'] as $other) {
            $this->assertStringNotContainsString($other, $destinations);
        }
    }

    /** Souvenir shops record no price tier, so a budget sent to them could only be ignored. */
    public function test_souvenir_shops_are_searched_by_name_and_place_only(): void
    {
        $location = urldecode((string) $this->get('/search?purpose=souvenir_centers&q=Gift&region_id=1&budget=Premium&interest=Wildlife')
            ->assertRedirect()->headers->get('Location'));

        $this->assertStringContainsString('q=Gift', $location);
        $this->assertStringContainsString('region_id=1', $location);
        $this->assertStringNotContainsString('price_tier=', $location);
        $this->assertStringNotContainsString('interest=', $location);
    }

    public function test_a_place_that_is_not_an_id_is_dropped(): void
    {
        foreach (['abc', '0', '-3', '1; DROP TABLE regions'] as $bad) {
            $location = urldecode((string) $this->get('/search?purpose=destinations&region_id='.rawurlencode($bad))->assertRedirect()->headers->get('Location'));

            $this->assertStringNotContainsString('region_id=', $location, "\"{$bad}\" should not be passed on as a place.");
        }
    }

    public function test_the_filters_that_are_sent_really_narrow_the_listing(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $samal = Region::firstOrCreate(['name' => 'Samal']);
        foreach ([['Beach Stay', 'Beach Resort', $samal], ['City Inn', 'Hotel', $region]] as [$name, $type, $where]) {
            \App\Models\Accommodation::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'x', 'region_id' => $where->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            ]);
        }

        $html = $this->get('/accommodations?type=Beach+Resort&region_id='.$samal->id)->assertOk()->getContent();

        $this->assertStringContainsString('Beach Stay', $html);
        $this->assertStringNotContainsString('City Inn', $html);
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
