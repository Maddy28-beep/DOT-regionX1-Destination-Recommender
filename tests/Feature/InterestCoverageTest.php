<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Services\Recommendation\InterestCoverage;
use Tests\TestCase;

/**
 * A trip that asks for Cultural Heritage, Nature and Wildlife must not be filled by the three best-scoring nature
 * places. Pools are built by hand, best first, so the cause of every result is plain.
 */
class InterestCoverageTest extends TestCase
{
    private int $next = 1;

    private function row(string $name, string $type, array $tags = []): array
    {
        $d = new Destination(['name' => $name, 'type' => $type]);
        $d->id = $this->next++;
        $d->setRelation('tags', collect(array_map(fn ($t) => (object) ['kind' => 'category', 'value' => $t], $tags)));

        return ['destination' => $d];
    }

    private function names($rows): array
    {
        return $rows->map(fn ($r) => $r['destination']->name)->all();
    }

    public function test_an_interest_with_no_stop_takes_the_place_of_the_lowest_ranked_stop(): void
    {
        $ranked = collect([
            $this->row('Eden', 'Nature & Adventure'), $this->row('Malagos', 'Nature & Adventure'), $this->row('Falls', 'Nature & Adventure'),
            $this->row('Museum', 'Cultural Heritage'),
        ]);

        $chosen = (new InterestCoverage())->select($ranked, 3, ['Nature & Adventure', 'Cultural Heritage']);

        $this->assertSame(['Eden', 'Malagos', 'Museum'], $this->names($chosen));
    }

    public function test_a_stop_that_is_the_only_cover_of_an_interest_is_never_the_one_dropped(): void
    {
        $ranked = collect([
            $this->row('Eden', 'Nature & Adventure'), $this->row('Eagle Center', 'Wildlife'), $this->row('Falls', 'Nature & Adventure'),
            $this->row('Museum', 'Cultural Heritage'),
        ]);

        $chosen = (new InterestCoverage())->select($ranked, 3, ['Nature & Adventure', 'Wildlife', 'Cultural Heritage']);

        $this->assertContains('Eagle Center', $this->names($chosen), 'The only wildlife stop stays.');
        $this->assertContains('Museum', $this->names($chosen));
        $this->assertNotContains('Falls', $this->names($chosen), 'The duplicate nature stop makes room.');
    }

    public function test_a_tag_that_contains_the_interests_keyword_covers_it(): void
    {
        $ranked = collect([
            $this->row('Eden', 'Nature & Adventure'), $this->row('Falls', 'Nature & Adventure'),
            $this->row('Tasting Farm', 'Farm Tourism', ['Food Tasting']),
        ]);

        $chosen = (new InterestCoverage())->select($ranked, 2, ['Food Tourism']);

        $this->assertSame(['Eden', 'Tasting Farm'], $this->names($chosen));
    }

    public function test_an_interest_nothing_in_the_pool_covers_changes_nothing(): void
    {
        $ranked = collect([$this->row('Eden', 'Nature & Adventure'), $this->row('Falls', 'Nature & Adventure'), $this->row('Beach', 'Beach & Leisure')]);

        $chosen = (new InterestCoverage())->select($ranked, 2, ['Wildlife']);

        $this->assertSame(['Eden', 'Falls'], $this->names($chosen));
    }

    public function test_when_every_interest_is_already_covered_the_ranking_is_untouched(): void
    {
        $ranked = collect([$this->row('Eden', 'Nature & Adventure'), $this->row('Museum', 'Cultural Heritage'), $this->row('Falls', 'Nature & Adventure')]);

        $chosen = (new InterestCoverage())->select($ranked, 2, ['Nature & Adventure', 'Cultural Heritage']);

        $this->assertSame(['Eden', 'Museum'], $this->names($chosen));
    }

    public function test_with_no_chosen_interests_it_is_just_the_top_of_the_ranking(): void
    {
        $ranked = collect([$this->row('A', 'Wildlife'), $this->row('B', 'Wildlife'), $this->row('C', 'Cultural Heritage')]);

        $this->assertSame(['A', 'B'], $this->names((new InterestCoverage())->select($ranked, 2, [])));
    }

    public function test_it_never_returns_more_than_the_places_asked_for_or_the_same_place_twice(): void
    {
        $ranked = collect([$this->row('A', 'Wildlife'), $this->row('B', 'Wildlife'), $this->row('C', 'Cultural Heritage'), $this->row('D', 'Beach & Leisure')]);

        $chosen = (new InterestCoverage())->select($ranked, 2, ['Cultural Heritage', 'Beach & Island']);

        $this->assertCount(2, $chosen);
        $this->assertCount(2, array_unique($this->names($chosen)));
    }
}
