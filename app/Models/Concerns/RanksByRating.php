<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ordering by rating without treating "not reviewed yet" as "rated zero".
 *
 * A plain orderByDesc('rating') reads an empty review record as a nought-star
 * verdict, which buries every listing nobody has got round to reviewing --
 * and in this catalogue that is most of it: 17 of 25 accredited destinations
 * came off the DOT list with no ratings at all, so they sat below all eight
 * hand-written entries permanently, whatever they actually are.
 *
 * This applies the shrunk average the recommender already uses for the same
 * problem (ContentBasedRecommendationService::ratingsScore): a listing starts
 * at the catalogue average and moves towards its own rating as real reviews
 * arrive, so evidence moves the score rather than the absence of it. An
 * unreviewed listing therefore sorts mid-pack, a place with fifty good
 * reviews still outranks one with a single glowing one, and nothing has to be
 * invented to fill the gap.
 */
trait RanksByRating
{
    /**
     * How much catalogue average a listing is weighed against, in reviews.
     * Matches the recommender's REVIEW_PRIOR: the two solve the same problem
     * and should not drift apart on how quickly evidence takes over.
     */
    private static float $ratingPrior = 5.0;

    /**
     * Best-rated first, counting an unreviewed listing as average rather than
     * awful. Ties break towards the listing with real reviews behind it --
     * the same score backed by evidence beats one resting on the prior.
     */
    public function scopeOrderByWeightedRating(Builder $query): Builder
    {
        $mean = static::query()->where('review_count', '>', 0)->avg('rating');

        /*
         * The prior and the mean are both bound parameters, and multiplying
         * two untyped placeholders together is something Postgres refuses to
         * resolve a type for ("operator is not unique: unknown * unknown") --
         * SQLite tolerates it, which is why the test suite (sqlite :memory:)
         * never caught this against the project's real Postgres database.
         * Casting each one is portable across all three drivers.
         */
        return $query
            ->orderByRaw(
                '(review_count * rating + CAST(? AS float) * CAST(? AS float)) / (review_count + CAST(? AS float)) desc',
                [self::$ratingPrior, (float) ($mean ?? 0), self::$ratingPrior]
            )
            ->orderByDesc('review_count');
    }
}
