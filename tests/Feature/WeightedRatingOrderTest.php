<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ordering on raw `rating` read an empty review record as a nought-star
 * verdict. In this catalogue that buried most of the platform: 17 of 25
 * accredited destinations came off the DOT list unreviewed and sat below
 * every hand-written entry permanently, so no genuinely accredited
 * establishment could reach the landing page or the top of a rating sort.
 */
class WeightedRatingOrderTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug, float $rating, int $reviews): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => $slug, 'name' => $slug, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => $rating, 'review_count' => $reviews,
        ]);
    }

    /** @return array<int, string> */
    private function order(): array
    {
        return Destination::publiclyVisible()->orderByWeightedRating()->pluck('slug')->all();
    }

    public function test_an_unreviewed_listing_sorts_mid_pack_rather_than_last(): void
    {
        $this->destination('excellent', 5.0, 40);
        $this->destination('mediocre', 4.0, 40);
        $this->destination('never-reviewed', 0.0, 0);

        $this->assertSame(['excellent', 'never-reviewed', 'mediocre'], $this->order(),
            'An unreviewed listing should rest at the catalogue average, between the two rated ones.');
    }

    /**
     * Evidence, not its absence, is what moves the score.
     *
     * The catalogue needs a realistic spread for this to hold: with only the
     * two listings below on file, the mean is computed from them alone and is
     * dragged up to 4.8 by the very five-star review being weighed, so the
     * prior stops pulling it back and the single review wins. That is a real
     * property of shrinking towards a small sample, not a quirk of the sort --
     * it is also why this only bites on a near-empty catalogue.
     */
    public function test_many_good_reviews_outrank_a_single_glowing_one(): void
    {
        foreach (['ordinary-a', 'ordinary-b', 'ordinary-c'] as $slug) {
            $this->destination($slug, 4.0, 20);
        }

        $this->destination('fifty-good', 4.6, 50);
        $this->destination('one-perfect', 5.0, 1);

        $order = $this->order();

        $this->assertLessThan(array_search('one-perfect', $order, true), array_search('fifty-good', $order, true),
            'A single five-star review is shrunk towards the mean; fifty good ones are not.');
    }

    /**
     * A listing resting on the prior must not displace one that earned the
     * same score, so the tie breaks towards whoever has real reviews.
     */
    public function test_a_tie_at_the_mean_breaks_towards_the_reviewed_listing(): void
    {
        $this->destination('high', 5.0, 30);
        $this->destination('low', 4.0, 30);
        $this->destination('exactly-average', 4.5, 30);
        $this->destination('never-reviewed', 0.0, 0);

        $order = $this->order();

        $this->assertSame('high', $order[0]);
        $this->assertSame('exactly-average', $order[1],
            'Sitting exactly on the mean with 30 reviews should beat sitting there on no evidence.');
        $this->assertSame('never-reviewed', $order[2]);
        $this->assertSame('low', $order[3]);
    }

    /** The rating sort on the catalogue is what most visitors actually meet. */
    public function test_the_catalogue_rating_sort_surfaces_unreviewed_listings(): void
    {
        $this->destination('below-average-but-popular', 4.0, 200);
        $this->destination('well-loved', 5.0, 200);
        $this->destination('newly-accredited', 0.0, 0);

        $html = $this->get('/destinations?sort=rating')->assertOk()->getContent();

        $positions = [];
        foreach (['well-loved', 'newly-accredited', 'below-average-but-popular'] as $slug) {
            $positions[$slug] = strpos($html, $slug);
            $this->assertNotFalse($positions[$slug], "{$slug} should be listed.");
        }

        $this->assertLessThan($positions['newly-accredited'], $positions['well-loved']);
        $this->assertLessThan($positions['below-average-but-popular'], $positions['newly-accredited'],
            'An unreviewed listing must no longer be dumped below everything with any rating at all.');
    }
}
