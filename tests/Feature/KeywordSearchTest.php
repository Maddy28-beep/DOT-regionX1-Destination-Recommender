<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\Restaurant;
use App\Support\KeywordSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, string $location, string $type, ?float $feeMin = null, ?float $feeMax = null, string $description = ''): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'name' => $name, 'slug' => str($name)->slug()->toString(), 'location' => $location, 'region_id' => $region->id,
            'type' => $type, 'description' => $description, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'entry_fee_min' => $feeMin, 'entry_fee_max' => $feeMax,
        ]);
    }

    private function names(string $q, array $extra = []): array
    {
        $html = $this->get(route('destinations.index', ['q' => $q] + $extra))->assertOk()->getContent();
        $found = [];
        foreach (['Hagimit Falls', 'Kaputian Beach Park', 'Mati Surf Beach', 'San Pedro Cathedral', 'Eden Nature Park'] as $name) {
            if (str_contains($html, $name)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->destination('Hagimit Falls', 'Island Garden City of Samal', 'Nature & Adventure', 50, 50, 'A series of small waterfalls and pools.');
        $this->destination('Kaputian Beach Park', 'Kaputian, Island Garden City of Samal', 'Beach & Leisure', 0, 0, 'A public beach.');
        $this->destination('Mati Surf Beach', 'Dahican, City of Mati, Davao Oriental', 'Beach & Surfing', 200, 200, 'Surfing and skimboarding.');
        $this->destination('San Pedro Cathedral', 'San Pedro Street, Davao City', 'Cultural Heritage', 0, 0, 'The oldest church in the city.');
        $this->destination('Eden Nature Park', 'Toril, Davao City', 'Nature & Adventure', 1200, 1500, 'A mountain resort and park.');
    }

    public function test_every_word_must_match_but_in_any_column(): void
    {
        // "beach samal": no name carries both words; the location carries "Samal".
        $this->assertSame(['Kaputian Beach Park'], $this->names('beach samal'));
        // "resort in mati": "in" is ignored; Mati is in the location.
        $this->assertSame(['Mati Surf Beach'], $this->names('surf in mati'));
    }

    public function test_equivalent_words_find_each_other(): void
    {
        $this->assertSame(['Hagimit Falls'], $this->names('waterfall'));
        $this->assertSame(['San Pedro Cathedral'], $this->names('church'));
        $this->assertSame(['San Pedro Cathedral'], $this->names('simbahan'));
    }

    public function test_budget_words_become_the_budget_filter(): void
    {
        $this->assertSame(['Kaputian Beach Park', 'Mati Surf Beach'], $this->names('beach'));
        $this->assertSame(['Kaputian Beach Park'], $this->names('free beach'));
        $this->assertSame(['Kaputian Beach Park'], $this->names('libre beach'));
        $this->assertSame(['Kaputian Beach Park'], $this->names('beach under 100'));
        $this->assertSame(['Kaputian Beach Park', 'Mati Surf Beach'], $this->names('beach 300 pababa'));
        $this->assertSame(['Eden Nature Park'], $this->names('luxury'));
        $this->assertSame(['Hagimit Falls', 'Kaputian Beach Park', 'San Pedro Cathedral'], $this->names('mura'));
    }

    public function test_a_budget_the_visitor_already_chose_is_not_overridden(): void
    {
        $this->assertSame(['Eden Nature Park'], $this->names('free park', ['price_min' => 1000]));
    }

    public function test_the_box_keeps_only_the_words_that_were_not_budget_words(): void
    {
        $html = $this->get(route('destinations.index', ['q' => 'free beach in samal']))->getContent();

        $this->assertStringContainsString('value="beach samal"', $html);
    }

    public function test_dining_maps_price_words_to_its_levels_and_kape_finds_cafes(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach ([['Kanto Cafe', 'Cafe', 'Budget-Friendly'], ['Harbor Grill', 'Seafood', 'Premium']] as [$name, $cuisine, $tier]) {
            Restaurant::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'location' => 'Davao City', 'region_id' => $region->id,
                'cuisine_type' => $cuisine, 'price_tier' => $tier, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);
        }

        $kape = $this->get(route('restaurants.index', ['q' => 'kape']))->getContent();
        $this->assertStringContainsString('Kanto Cafe', $kape);
        $this->assertStringNotContainsString('Harbor Grill', $kape);

        $premium = $this->get(route('restaurants.index', ['q' => 'mahal']))->getContent();
        $this->assertStringContainsString('Harbor Grill', $premium);
        $this->assertStringNotContainsString('Kanto Cafe', $premium);

        $fish = $this->get(route('restaurants.index', ['q' => 'isda']))->getContent();
        $this->assertStringContainsString('Harbor Grill', $fish);
    }

    public function test_the_parser(): void
    {
        $this->assertSame(['words' => ['beach'], 'budget' => ['price_min' => 300.0, 'price_max' => 800.0, 'price_tier' => null]],
            KeywordSearch::parse('beach ₱300-800', 'destinations'));
        $this->assertSame(['price_min' => 1500.0, 'price_max' => null, 'price_tier' => null],
            KeywordSearch::parse('hotel 1,500 pataas', 'accommodations')['budget']);
        $this->assertSame(['price_min' => null, 'price_max' => 1500.0, 'price_tier' => null],
            KeywordSearch::parse('cheap hotel', 'accommodations')['budget']);
        // "free" is a budget word only for destinations; for a stay it can mean "free breakfast".
        $this->assertSame(['free', 'breakfast'], KeywordSearch::parse('free breakfast', 'accommodations')['words']);
        // Characters with a meaning in LIKE are ordinary.
        $this->assertSame([], $this->names('100%'));
    }
}
