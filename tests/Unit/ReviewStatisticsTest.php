<?php

namespace Tests\Unit;

use App\Services\Feedback\ReviewStatistics;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Every expected number below was worked out by hand, not by running the code.
 */
class ReviewStatisticsTest extends TestCase
{
    private function stats(): ReviewStatistics
    {
        return new ReviewStatistics();
    }

    private function now(): Carbon
    {
        return Carbon::parse('2026-11-05 12:00:00', 'UTC');
    }

    /**
     * Five reviews. Columns: overall / service / amenities.
     *   #1 5 / 4 / 3    #2 4 / - / 3    #3 4 / 3 / -    #4 2 / - / -    #5 5 / 5 / -
     */
    private function fiveReviews(): array
    {
        $at = '2026-11-01 00:00:00';

        return [
            ['rating' => 5, 'service_rating' => 4, 'amenities_rating' => 3, 'created_at' => $at],
            ['rating' => 4, 'service_rating' => null, 'amenities_rating' => 3, 'created_at' => $at],
            ['rating' => 4, 'service_rating' => 3, 'amenities_rating' => null, 'created_at' => $at],
            ['rating' => 2, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => $at],
            ['rating' => 5, 'service_rating' => 5, 'amenities_rating' => null, 'created_at' => $at],
        ];
    }

    public function test_overall_statistics_for_five_reviews(): void
    {
        $o = $this->stats()->analyse($this->fiveReviews(), $this->now())['aspects']['overall'];

        // values 5,4,4,2,5 -> sum 20, n 5, mean 20/5 = 4
        $this->assertSame(5, $o['n']);
        $this->assertEqualsWithDelta(4.0, $o['mean'], 0.00001);
        // sorted 2,4,4,5,5 -> middle (3rd) value = 4
        $this->assertEquals(4, $o['median']);
        // deviations 1,0,0,-2,1 -> squares 1+0+0+4+1 = 6; sample variance 6/(5-1) = 1.5; sd = sqrt(1.5) = 1.224745
        $this->assertEqualsWithDelta(1.224745, $o["sd"], 0.0001);
        // df = 4 -> t = 2.776; margin = 2.776 * 1.224745 / sqrt(5) = 2.776 * 0.547723 = 1.52048
        $this->assertEqualsWithDelta(1.52048, $o['ci']['margin'], 0.0005);
        $this->assertEqualsWithDelta(2.47952, $o['ci']['low'], 0.0005);
        $this->assertEqualsWithDelta(5.52048, $o['ci']['high'], 0.0005);
        // distribution: one 2, two 4s, two 5s -> 20% / 40% / 40%
        $counts = array_map(fn ($r) => $r['count'], $o['distribution']);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 2, 5 => 2], $counts);
        $this->assertSame(20.0, $o['distribution'][2]['percent']);
        $this->assertSame(40.0, $o['distribution'][4]['percent']);
        $this->assertSame(0.0, $o['distribution'][1]['percent']);
    }

    public function test_service_statistics_ignore_reviews_that_skipped_the_aspect(): void
    {
        $s = $this->stats()->analyse($this->fiveReviews(), $this->now())['aspects']['service'];

        // values 4,3,5 (two reviews left it blank) -> n 3, mean 12/3 = 4, median of 3,4,5 = 4
        $this->assertSame(3, $s['n']);
        $this->assertEqualsWithDelta(4.0, $s['mean'], 0.00001);
        $this->assertEquals(4, $s['median']);
        // deviations 0,-1,1 -> squares sum 2; variance 2/2 = 1; sd = 1
        $this->assertEqualsWithDelta(1.0, $s['sd'], 0.00001);
        // df = 2 -> t = 4.303; margin = 4.303 * 1 / sqrt(3) = 4.303 / 1.7320508 = 2.48435
        $this->assertEqualsWithDelta(2.48435, $s['ci']['margin'], 0.0005);
        $this->assertEqualsWithDelta(1.51565, $s['ci']['low'], 0.0005);
        $this->assertEqualsWithDelta(6.48435, $s['ci']['high'], 0.0005);
    }

    public function test_two_equal_ratings_give_zero_spread_and_a_point_interval(): void
    {
        $a = $this->stats()->analyse($this->fiveReviews(), $this->now())['aspects']['amenities'];

        // values 3,3 -> n 2, mean 3, median (3+3)/2 = 3, deviations 0,0 -> sd 0, margin 12.706 * 0 = 0
        $this->assertSame(2, $a['n']);
        $this->assertEqualsWithDelta(3.0, $a['mean'], 0.00001);
        $this->assertEquals(3, $a['median']);
        $this->assertEqualsWithDelta(0.0, $a['sd'], 0.00001);
        $this->assertEqualsWithDelta(3.0, $a['ci']['low'], 0.00001);
        $this->assertEqualsWithDelta(3.0, $a['ci']['high'], 0.00001);
    }

    public function test_even_count_median_is_the_mean_of_the_two_middle_values(): void
    {
        // sorted 1,2,4,5 -> (2 + 4) / 2 = 3
        $this->assertEquals(3, $this->stats()->summarise([5, 1, 4, 2])['median']);
    }

    public function test_an_aspect_nobody_rated_has_no_numbers_not_zeros(): void
    {
        $reviews = [['rating' => 4, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => '2026-11-01']];
        $a = $this->stats()->analyse($reviews, $this->now())['aspects']['service'];

        $this->assertSame(0, $a['n']);
        $this->assertNull($a['mean']);
        $this->assertNull($a['median']);
        $this->assertNull($a['sd']);
        $this->assertNull($a['ci']);
        $this->assertNull($a['trend']);
        $this->assertNull($a['distribution'][3]['percent']);
    }

    public function test_one_rating_has_a_mean_but_no_sd_or_interval(): void
    {
        $s = $this->stats()->summarise([4]);

        $this->assertSame(1, $s['n']);
        $this->assertEqualsWithDelta(4.0, $s['mean'], 0.00001);
        $this->assertNull($s['sd']);
        $this->assertNull($s['ci']);
        $this->assertSame(100.0, $s['distribution'][4]['percent']);
    }

    public function test_t_table_values_and_the_normal_fallback(): void
    {
        $t = $this->stats();
        $this->assertSame(12.706, $t->tCritical(1));
        $this->assertSame(2.776, $t->tCritical(4));
        $this->assertSame(2.042, $t->tCritical(30));
        $this->assertSame(1.96, $t->tCritical(31));
        $this->assertSame(1.96, $t->tCritical(500));
    }

    public function test_interval_uses_the_normal_value_beyond_thirty_degrees(): void
    {
        // 32 ratings: sixteen 5s and sixteen 3s. mean 4; every deviation is +-1 -> sum of squares 32;
        // variance 32/31; sd = 1.016001; df = 31 -> 1.96; margin = 1.96 * 1.016001 / sqrt(32) = 1.96 * 0.179605 = 0.352026
        $values = array_merge(array_fill(0, 16, 5), array_fill(0, 16, 3));
        $s = $this->stats()->summarise($values);

        $this->assertEqualsWithDelta(1.016001, $s["sd"], 0.0001);
        $this->assertEqualsWithDelta(0.352026, $s['ci']['margin'], 0.0001);
        $this->assertEqualsWithDelta(3.647974, $s['ci']['low'], 0.0001);
        $this->assertEqualsWithDelta(4.352026, $s['ci']['high'], 0.0001);
    }

    public function test_quality_index_averages_the_available_aspect_means(): void
    {
        $r = $this->stats()->analyse($this->fiveReviews(), $this->now());

        // means: overall 4, service 4, amenities 3 -> (4+4+3)/3 = 3.666667 -> (3.666667-1)/4*100 = 66.67 -> 66.7
        $this->assertSame(66.7, $r['index']['score']);
        $this->assertSame(['overall', 'service', 'amenities'], $r['index']['aspects_used']);
        $this->assertNull($r['index']['message']);
    }

    public function test_quality_index_skips_aspects_with_no_data(): void
    {
        $reviews = [];
        foreach ([5, 4, 4, 2, 5] as $v) {
            $reviews[] = ['rating' => $v, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => '2026-11-01'];
        }
        $r = $this->stats()->analyse($reviews, $this->now());

        // only overall (mean 4) -> (4-1)/4*100 = 75
        $this->assertSame(75.0, $r['index']['score']);
        $this->assertSame(['overall'], $r['index']['aspects_used']);
    }

    public function test_quality_index_scale_ends(): void
    {
        $five = array_fill(0, 5, ['rating' => 5, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => '2026-11-01']);
        $one = array_fill(0, 5, ['rating' => 1, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => '2026-11-01']);

        $this->assertSame(100.0, $this->stats()->analyse($five, $this->now())['index']['score']); // (5-1)/4*100
        $this->assertSame(0.0, $this->stats()->analyse($one, $this->now())['index']['score']);    // (1-1)/4*100
    }

    public function test_quality_index_is_withheld_below_five_reviews(): void
    {
        $r = $this->stats()->analyse(array_slice($this->fiveReviews(), 0, 4), $this->now());

        $this->assertNull($r['index']['score']);
        $this->assertStringContainsString('Not enough reviews (fewer than 5)', $r['index']['message']);
    }

    public function test_no_reviews_at_all(): void
    {
        $r = $this->stats()->analyse([], $this->now());

        $this->assertSame(0, $r['review_count']);
        $this->assertNull($r['aspects']['overall']['mean']);
        $this->assertNull($r['index']['score']);
    }

    private function review(int $rating, string $at): array
    {
        return ['rating' => $rating, 'service_rating' => null, 'amenities_rating' => null, 'created_at' => $at];
    }

    public function test_trend_compares_the_last_thirty_days_with_the_thirty_before(): void
    {
        // now = 2026-11-05 12:00. Recent window (Oct 6 12:00 .. Nov 5 12:00], previous (Sep 6 12:00 .. Oct 6 12:00].
        $reviews = [
            $this->review(5, '2026-11-01 08:00:00'), $this->review(4, '2026-10-20 08:00:00'), $this->review(5, '2026-10-10 08:00:00'),
            $this->review(3, '2026-10-01 08:00:00'), $this->review(4, '2026-09-20 08:00:00'), $this->review(2, '2026-09-10 08:00:00'),
            $this->review(1, '2026-08-01 08:00:00'), // older than 60 days: in neither window
        ];
        $t = $this->stats()->analyse($reviews, $this->now())['aspects']['overall']['trend'];

        // recent 5,4,5 -> 14/3 = 4.6667; previous 3,4,2 -> 9/3 = 3; difference 4.6667 - 3 = 1.6667
        $this->assertSame(3, $t['recent_n']);
        $this->assertSame(3, $t['previous_n']);
        $this->assertEqualsWithDelta(4.6667, $t['recent_mean'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $t['previous_mean'], 0.0001);
        $this->assertEqualsWithDelta(1.6667, $t['difference'], 0.0001);
    }

    public function test_trend_is_null_when_either_side_has_fewer_than_three(): void
    {
        $twoRecent = [
            $this->review(5, '2026-11-01 08:00:00'), $this->review(4, '2026-10-20 08:00:00'),
            $this->review(3, '2026-10-01 08:00:00'), $this->review(4, '2026-09-20 08:00:00'), $this->review(2, '2026-09-10 08:00:00'),
        ];
        $this->assertNull($this->stats()->analyse($twoRecent, $this->now())['aspects']['overall']['trend']);

        $twoPrevious = [
            $this->review(5, '2026-11-01 08:00:00'), $this->review(4, '2026-10-20 08:00:00'), $this->review(5, '2026-10-10 08:00:00'),
            $this->review(3, '2026-10-01 08:00:00'), $this->review(4, '2026-09-20 08:00:00'),
        ];
        $this->assertNull($this->stats()->analyse($twoPrevious, $this->now())['aspects']['overall']['trend']);
    }

    public function test_a_review_exactly_thirty_days_old_belongs_to_the_previous_period(): void
    {
        // Oct 6 12:00 is exactly now - 30 days: recent window is exclusive at that end, so it counts as previous.
        $reviews = [
            $this->review(5, '2026-11-01 08:00:00'), $this->review(5, '2026-10-20 08:00:00'), $this->review(5, '2026-10-10 08:00:00'),
            $this->review(1, '2026-10-06 12:00:00'), $this->review(1, '2026-10-01 08:00:00'), $this->review(1, '2026-09-20 08:00:00'),
        ];
        $t = $this->stats()->analyse($reviews, $this->now())['aspects']['overall']['trend'];

        // recent 5,5,5 = 5; previous 1,1,1 = 1 (would be only two previous reviews if the boundary one were counted recent)
        $this->assertSame(3, $t['recent_n']);
        $this->assertSame(3, $t['previous_n']);
        $this->assertEqualsWithDelta(4.0, $t['difference'], 0.0001);
    }

    public function test_out_of_range_values_are_ignored(): void
    {
        // 0 and 6 are not ratings -> only 3 and 5 counted: n 2, mean 4
        $s = $this->stats()->analyse([
            $this->review(3, '2026-11-01'), $this->review(5, '2026-11-01'), $this->review(0, '2026-11-01'), $this->review(6, '2026-11-01'),
        ], $this->now())['aspects']['overall'];

        $this->assertSame(2, $s['n']);
        $this->assertEqualsWithDelta(4.0, $s['mean'], 0.00001);
    }
}
