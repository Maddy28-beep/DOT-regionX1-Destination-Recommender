<?php

namespace App\Services\Feedback;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Descriptive and inferential statistics for traveller feedback
 * (thesis objective 1.3.2.6: statistical tools for feedback analysis and
 * service quality evaluation).
 *
 * Pure functions: reviews in, numbers out. Nothing here touches the database,
 * the request, or the clock (except through the optional $now argument).
 *
 * Honesty rules: an aspect nobody rated has n = 0 and every statistic null,
 * never 0. A statistic that needs two or more observations (standard
 * deviation, confidence interval) is null below that, and the service quality
 * index refuses to produce a score from fewer than MIN_FOR_INDEX reviews.
 *
 * Each review may be an array or an object with the keys: rating,
 * service_rating, amenities_rating (1-5 or null) and created_at.
 */
class ReviewStatistics
{
    public const ASPECTS = [
        'overall' => 'rating',
        'service' => 'service_rating',
        'amenities' => 'amenities_rating',
    ];

    public const LABELS = ['overall' => 'Overall', 'service' => 'Service', 'amenities' => 'Amenities'];

    public const MIN_FOR_INDEX = 5;
    public const MIN_FOR_TREND = 3;
    public const TREND_DAYS = 30;

    /** Two-sided 95% critical values of Student's t, by degrees of freedom 1..30. Beyond 30: 1.96. */
    private const T_95 = [
        1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571,
        6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262, 10 => 2.228,
        11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131,
        16 => 2.120, 17 => 2.110, 18 => 2.101, 19 => 2.093, 20 => 2.086,
        21 => 2.080, 22 => 2.074, 23 => 2.069, 24 => 2.064, 25 => 2.060,
        26 => 2.056, 27 => 2.052, 28 => 2.048, 29 => 2.045, 30 => 2.042,
    ];

    /**
     * Everything for one set of reviews.
     *
     * @param  iterable<array|object>  $reviews
     * @return array{review_count:int, aspects:array<string,array>, index:array}
     */
    public function analyse(iterable $reviews, ?CarbonInterface $now = null): array
    {
        $reviews = is_array($reviews) ? $reviews : iterator_to_array($reviews, false);
        $now = $now ? Carbon::instance($now) : Carbon::now();

        $aspects = [];
        foreach (self::ASPECTS as $key => $field) {
            $aspects[$key] = $this->aspect($reviews, $field, $now);
        }

        return [
            'review_count' => count($reviews),
            'aspects' => $aspects,
            'index' => $this->qualityIndex($aspects),
        ];
    }

    /** Statistics for one rating column. */
    public function aspect(array $reviews, string $field, CarbonInterface $now): array
    {
        $stats = $this->summarise($this->values($reviews, $field));
        $stats['trend'] = $this->trend($reviews, $field, $now);

        return $stats;
    }

    /**
     * n, mean, median, sample SD, distribution and 95% CI of a list of 1-5 ratings.
     *
     * @param  list<int>  $values
     */
    public function summarise(array $values): array
    {
        $n = count($values);
        $distribution = array_fill_keys([1, 2, 3, 4, 5], ['count' => 0, 'percent' => null]);

        foreach ($values as $v) {
            $distribution[$v]['count']++;
        }
        foreach ($distribution as $star => $row) {
            $distribution[$star]['percent'] = $n > 0 ? round($row['count'] / $n * 100, 1) : null;
        }

        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'median' => null, 'sd' => null, 'distribution' => $distribution, 'ci' => null];
        }

        $mean = array_sum($values) / $n;
        $sd = $this->sampleStandardDeviation($values);

        return [
            'n' => $n,
            'mean' => round($mean, 4),
            'median' => $this->median($values),
            'sd' => $sd === null ? null : round($sd, 4),
            'distribution' => $distribution,
            'ci' => $this->confidenceInterval($mean, $sd, $n),
        ];
    }

    /** @param list<int|float> $values */
    public function median(array $values): int|float|null
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Sample standard deviation (divides by n - 1). Null below two observations. */
    public function sampleStandardDeviation(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($values) / $n;
        $ss = 0.0;
        foreach ($values as $v) {
            $ss += ($v - $mean) ** 2;
        }

        return sqrt($ss / ($n - 1));
    }

    /** t critical value (two-sided 95%) for $df degrees of freedom. */
    public function tCritical(int $df): float
    {
        return self::T_95[$df] ?? 1.96;
    }

    /** mean +/- t * sd / sqrt(n); null below two observations. Not clamped to 1-5. */
    public function confidenceInterval(float $mean, ?float $sd, int $n): ?array
    {
        if ($n < 2 || $sd === null) {
            return null;
        }
        $margin = $this->tCritical($n - 1) * $sd / sqrt($n);

        return ['low' => round($mean - $margin, 4), 'high' => round($mean + $margin, 4), 'margin' => round($margin, 4)];
    }

    /**
     * Mean of the last 30 days against the 30 days before that. The windows are
     * (now-30d, now] and (now-60d, now-30d]. Null unless both have at least 3 ratings.
     */
    public function trend(array $reviews, string $field, CarbonInterface $now): ?array
    {
        $now = Carbon::instance($now);
        $recentFrom = $now->copy()->subDays(self::TREND_DAYS);
        $previousFrom = $now->copy()->subDays(self::TREND_DAYS * 2);

        $recent = [];
        $previous = [];
        foreach ($reviews as $review) {
            $v = $this->rating($review, $field);
            $at = $this->createdAt($review);
            if ($v === null || $at === null) {
                continue;
            }
            if ($at->gt($recentFrom) && $at->lte($now)) {
                $recent[] = $v;
            } elseif ($at->gt($previousFrom) && $at->lte($recentFrom)) {
                $previous[] = $v;
            }
        }

        if (count($recent) < self::MIN_FOR_TREND || count($previous) < self::MIN_FOR_TREND) {
            return null;
        }

        $recentMean = array_sum($recent) / count($recent);
        $previousMean = array_sum($previous) / count($previous);

        return [
            'recent_mean' => round($recentMean, 4), 'recent_n' => count($recent),
            'previous_mean' => round($previousMean, 4), 'previous_n' => count($previous),
            'difference' => round($recentMean - $previousMean, 4),
        ];
    }

    /**
     * Service quality index: the mean of the aspect means that have data,
     * scaled so 1 star = 0 and 5 stars = 100: (mean - 1) / 4 * 100.
     * Withheld (score null) with fewer than MIN_FOR_INDEX reviews.
     *
     * @param  array<string,array>  $aspects  output of aspect()
     */
    public function qualityIndex(array $aspects): array
    {
        $n = $aspects['overall']['n'] ?? 0;
        $means = [];
        foreach ($aspects as $key => $a) {
            if (($a['n'] ?? 0) > 0) {
                $means[$key] = $a['mean'];
            }
        }

        if ($n < self::MIN_FOR_INDEX || $means === []) {
            return [
                'score' => null,
                'aspects_used' => array_keys($means),
                'message' => 'Not enough reviews (fewer than '.self::MIN_FOR_INDEX.') for a service quality index.',
            ];
        }

        $mean = array_sum($means) / count($means);

        return [
            'score' => round(($mean - 1) / 4 * 100, 1),
            'aspects_used' => array_keys($means),
            'message' => null,
        ];
    }

    /** @return list<int> the valid 1-5 ratings in a column, nulls dropped */
    private function values(array $reviews, string $field): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $v = $this->rating($review, $field);
            if ($v !== null) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function rating(array|object $review, string $field): ?int
    {
        $v = data_get($review, $field);
        if ($v === null || $v === '') {
            return null;
        }
        $v = (int) $v;

        return $v >= 1 && $v <= 5 ? $v : null;
    }

    private function createdAt(array|object $review): ?Carbon
    {
        $at = data_get($review, 'created_at');

        return $at === null ? null : Carbon::parse($at);
    }
}
