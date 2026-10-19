<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\Restaurant;
use App\Services\Recommendation\AprioriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The kind a caller wants (a restaurant, a stay, a souvenir shop) is chosen before the limit, not after it. */
class AprioriKindFilterTest extends TestCase
{
    use RefreshDatabase;

    private function dest(string $name): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
    }

    private function survey(array $places): void
    {
        $s = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);

        foreach ($places as [$kind, $id]) {
            ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => $kind, 'listing_id' => $id]);
        }
    }

    public function test_a_restaurant_is_found_even_when_stronger_rules_belong_to_other_kinds(): void
    {
        $eden = $this->dest('Eden');
        $others = [$this->dest('Falls'), $this->dest('Museum'), $this->dest('Beach')];
        $region = Region::first();
        $cafe = Restaurant::create(['slug' => 'cafe', 'name' => 'Cafe', 'location' => 'Davao', 'region_id' => $region->id, 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);

        // Four trips: every other destination appears in all four (confidence 1.0), the cafe in two (0.5).
        for ($i = 0; $i < 4; $i++) {
            $this->survey(array_merge([['destination', $eden->id]], array_map(fn ($d) => ['destination', $d->id], $others), $i < 2 ? [['restaurant', $cafe->id]] : []));
        }

        $apriori = app(AprioriService::class);

        // Before the fix the three destinations used up the limit of 3 and the cafe never appeared.
        $found = $apriori->suggestionsFor('destination', $eden->id, 3, 'restaurant');

        $this->assertSame(['Cafe'], $found->pluck('listing.name')->all());
        $this->assertSame(0.5, $found->first()['confidence']);
    }

    public function test_a_place_listed_twice_in_one_survey_counts_once(): void
    {
        $a = $this->dest('A');
        $b = $this->dest('B');

        $this->survey([['destination', $a->id], ['destination', $a->id], ['destination', $b->id]]);
        $this->survey([['destination', $a->id], ['destination', $b->id]]);

        $rule = app(AprioriService::class)->getAssociatedListings('destination', $a->id, 5, 0.1, 2)->first();

        $this->assertSame(1.0, $rule['confidence'], 'Both trips that included A also included B.');
    }
}
