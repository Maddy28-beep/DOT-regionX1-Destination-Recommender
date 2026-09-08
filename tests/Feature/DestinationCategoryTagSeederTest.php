<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Database\Seeders\DestinationCategoryTagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 17 destinations imported from the real DOT accreditation list had no
 * category tags at all, which mattered beyond completeness: without a
 * category tag, ContentBasedRecommendationService::interestSimilarity()
 * falls back to a coarse type guess, and "Food Tourism" specifically has no
 * destination type that implies it -- so it could never be a real match for
 * anything before this seeder ran.
 */
class DestinationCategoryTagSeederTest extends TestCase
{
    use RefreshDatabase;

    private function untaggedDestination(string $slug, string $type = 'Farm Tourism'): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => $slug, 'name' => $slug, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => $type, 'is_accredited' => true,
            'rating' => 4.0, 'review_count' => 5, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_it_tags_every_destination_it_targets(): void
    {
        $this->untaggedDestination('belviz-farm');
        $this->untaggedDestination('jkm-mini-zoo', 'Wildlife');

        (new DestinationCategoryTagSeeder())->run();

        $farm = Destination::where('slug', 'belviz-farm')->first();
        $zoo = Destination::where('slug', 'jkm-mini-zoo')->first();

        $this->assertTrue($farm->tags->where('kind', 'category')->pluck('value')->contains('Food Tasting'),
            'A Farm Tourism listing must get a tag containing "food" so Food Tourism can ever match it.');
        $this->assertTrue($zoo->tags->where('kind', 'category')->pluck('value')->contains('Wildlife'));
    }

    public function test_it_never_touches_a_destination_that_already_has_a_category_tag(): void
    {
        $destination = $this->untaggedDestination('belviz-farm');
        $destination->tags()->create(['kind' => 'category', 'value' => 'Hand-Curated Tag']);

        (new DestinationCategoryTagSeeder())->run();

        $tags = $destination->fresh()->tags->where('kind', 'category')->pluck('value');
        $this->assertSame(['Hand-Curated Tag'], $tags->all(),
            'A destination that was already tagged must not be overwritten or added to.');
    }

    public function test_it_is_idempotent(): void
    {
        $this->untaggedDestination('belviz-farm');

        (new DestinationCategoryTagSeeder())->run();
        (new DestinationCategoryTagSeeder())->run();

        $destination = Destination::where('slug', 'belviz-farm')->first();
        $this->assertCount(3, $destination->tags,
            'Running the seeder twice must not duplicate tags.');
    }

    /**
     * The real-world motivation: before this seeder, no destination TYPE
     * mapped to "Food Tourism" at all (see TYPE_TO_INTEREST), so a tourist
     * who picked only "Food Tourism" as their interest could never get a
     * genuine match -- every destination fell back to the same neutral 0.5.
     */
    public function test_food_tourism_can_now_actually_match_something(): void
    {
        $this->untaggedDestination('belviz-farm');
        (new DestinationCategoryTagSeeder())->run();

        $preference = \App\Models\TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        $preference->activities()->create(['activity' => 'Food Tourism']);
        $preference->load('activities', 'amenities');

        $service = new \App\Services\Recommendation\ContentBasedRecommendationService();
        $ranked = $service->rank($preference);
        $top = $ranked->first();

        $this->assertSame('belviz-farm', $top['destination']->slug,
            'The one Food-Tourism-tagged destination should now rank first for that interest.');
    }
}
