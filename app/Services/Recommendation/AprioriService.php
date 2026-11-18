<?php

namespace App\Services\Recommendation;

use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use App\Support\Features;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Association Rule Mining through the Apriori Algorithm (manuscript Sec. 2.3.4).
 *
 * Each completed exit survey is treated as one transaction, and the listings
 * recorded in its ExitSurveyVisit rows are the itemset for that transaction
 * (mirroring Table 10's "Sample Tourist Visitation Dataset"). This class mines
 * 2-item association rules ("tourists who visited A also visited B") using:
 *
 *   Support(A->B)    = |transactions containing both A and B| / |total transactions|     -- Equation 8
 *   Confidence(A->B)  = |transactions containing both A and B| / |transactions containing A| -- Equation 9
 */
class AprioriService
{
    /**
     * Which transaction sources feed the mining: any of 'real' (tourist
     * surveys), 'itinerary' (baskets coded from published itineraries) and
     * 'demo' (simulated). Null means every source except trial ('test') rows,
     * which no query ever sees. Set through onlySources() so a caller can ask
     * "what do real surveys alone say?" without changing anyone else's result.
     *
     * @var array<int, string>|null
     */
    private ?array $sources = null;

    /** A copy of this service that mines only the given sources. */
    public function onlySources(?array $sources): static
    {
        $copy = clone $this;
        $copy->sources = $sources ?: null;

        return $copy;
    }

    /**
     * What the precomputed table (association_rules) holds: pairs seen in at least this many trips, at least this
     * confident. A caller that asks for less than that is answered from the trips themselves.
     */
    public const TABLE_MIN_SUPPORT = 2;

    public const TABLE_MIN_CONFIDENCE = 0.10;

    /** The four mixes of sources that the table is built for. */
    public const SOURCE_SETS = ['all' => null, 'real' => ['real'], 'itinerary' => ['itinerary'], 'demo' => ['demo']];

    /** 'all', 'real', 'itinerary' or 'demo' when this copy mines exactly one of those; null for any other mix. */
    public function sourceKey(): ?string
    {
        if ($this->sources === null) {
            return 'all';
        }

        return count($this->sources) === 1 && in_array($this->sources[0], ['real', 'itinerary', 'demo'], true) ? $this->sources[0] : null;
    }

    /**
     * The precomputed build to read from, or null to count from the trips themselves: the switch is off, no build has
     * been made for this mix of sources yet, or anything at all goes wrong reading it.
     */
    private function build(): ?object
    {
        $key = $this->sourceKey();
        if ($key === null || ! Features::on(Features::ASSOCIATION_RULES_TABLE)) {
            return null;
        }

        try {
            return DB::table('association_rule_builds')->where('source_set', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** The pseudo source set under which the visit counts are recorded in association_rule_builds. */
    public const VISIT_COUNTS_KEY = 'visitcounts';

    /**
     * Recorded visits per listing id of one kind, read from the precomputed counts; null means "count them live" (the
     * switch is off, or the counts have not been built yet). Counts every source except trial ones, like the live count.
     *
     * @param  iterable<int>  $listingIds
     * @return Collection<int, int>|null
     */
    public function precomputedVisitCounts(string $listingKind, iterable $listingIds): ?Collection
    {
        if (! Features::on(Features::ASSOCIATION_RULES_TABLE)) {
            return null;
        }

        try {
            if (! DB::table('association_rule_builds')->where('source_set', self::VISIT_COUNTS_KEY)->exists()) {
                return null;
            }

            return DB::table('association_visit_counts')
                ->where('listing_kind', $listingKind)
                ->whereIn('listing_id', collect($listingIds)->values()->all())
                ->pluck('visits', 'listing_id')
                ->map(fn ($n) => (int) $n);
        } catch (Throwable) {
            return null;
        }
    }

    /** When the table the rules are read from was built (UTC), or null while they are counted live. */
    public function rulesBuiltAt(): ?string
    {
        return $this->build()?->built_at;
    }

    private function surveys(): \Illuminate\Database\Eloquent\Builder
    {
        return ExitSurvey::query()->when($this->sources, fn ($q) => $q->whereIn('data_source', $this->sources));
    }

    private function visits(): \Illuminate\Database\Eloquent\Builder
    {
        return ExitSurveyVisit::query()->when($this->sources, fn ($q) => $q->whereIn(
            'exit_survey_id',
            ExitSurvey::query()->select('id')->whereIn('data_source', $this->sources)
        ));
    }

    /**
     * Complementary listings frequently co-visited with a given listing, ranked by confidence.
     *
     * @return Collection<int, array{listing_kind: string, listing_id: int, co_count: int, support: float, confidence: float}>
     */
    public function getAssociatedListings(
        string $listingKind,
        int $listingId,
        int $limit = 5,
        float $minConfidence = 0.1,
        int $minSupportCount = 2,
        ?string $onlyKind = null
    ): Collection {
        if ($minSupportCount >= self::TABLE_MIN_SUPPORT && $minConfidence >= self::TABLE_MIN_CONFIDENCE && ($build = $this->build())) {
            return $this->associatedFromTable($build, $listingKind, $listingId, $limit, $minConfidence, $minSupportCount, $onlyKind);
        }

        // The trips that contain A stay inside the database as a subquery. Pulling their ids into PHP and sending them
        // back as one huge IN (...) list failed outright once a place was in more than 65,535 trips (the Postgres
        // limit on bound parameters) and made every lookup slower as the survey data grew.
        $countA = $this->visits()->where('listing_kind', $listingKind)
            ->where('listing_id', $listingId)
            ->distinct()
            ->count('exit_survey_id');
        $totalTransactions = $this->surveys()->count();

        if ($countA === 0 || $totalTransactions === 0) {
            return collect();
        }

        $surveysWithA = $this->visits()->where('listing_kind', $listingKind)
            ->where('listing_id', $listingId)
            ->select('exit_survey_id');

        $coOccurrences = $this->visits()->whereIn('exit_survey_id', $surveysWithA)
            // The kind the caller wants is chosen here, before the limit below: otherwise the strongest rules of
            // other kinds use up the limit and a restaurant or stay that is a good match is never seen.
            ->when($onlyKind !== null, fn ($q) => $q->where('listing_kind', $onlyKind))
            ->where(function ($q) use ($listingKind, $listingId) {
                $q->where('listing_kind', '!=', $listingKind)
                    ->orWhere('listing_id', '!=', $listingId);
            })
            ->selectRaw('listing_kind, listing_id, count(distinct exit_survey_id) as co_count')
            ->groupBy('listing_kind', 'listing_id')
            ->havingRaw('count(distinct exit_survey_id) >= ?', [$minSupportCount])
            ->get();

        return $coOccurrences
            ->map(fn ($row) => [
                'listing_kind' => $row->listing_kind,
                'listing_id' => (int) $row->listing_id,
                'co_count' => (int) $row->co_count,
                'support' => round($row->co_count / $totalTransactions, 4),
                'confidence' => round($row->co_count / $countA, 4),
            ])
            ->filter(fn ($rule) => $rule['confidence'] >= $minConfidence)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();
    }

    /** The same answer as the live query below, read from the precomputed rows for this one listing. */
    private function associatedFromTable(object $build, string $listingKind, int $listingId, int $limit, float $minConfidence, int $minSupportCount, ?string $onlyKind): Collection
    {
        $total = (int) $build->total_transactions;
        if ($total === 0) {
            return collect();
        }

        return DB::table('association_rules')
            ->where('source_set', $build->source_set)
            ->where('a_kind', $listingKind)
            ->where('a_id', $listingId)
            ->where('co_count', '>=', $minSupportCount)
            ->when($onlyKind !== null, fn ($q) => $q->where('b_kind', $onlyKind))
            ->orderByRaw('(co_count * 1.0 / ant_count) desc')
            ->orderByDesc('co_count')->orderBy('b_kind')->orderBy('b_id')
            ->get()
            ->map(fn ($row) => [
                'listing_kind' => $row->b_kind,
                'listing_id' => (int) $row->b_id,
                'co_count' => (int) $row->co_count,
                'support' => round($row->co_count / $total, 4),
                'confidence' => round($row->co_count / $row->ant_count, 4),
            ])
            ->filter(fn ($rule) => $rule['confidence'] >= $minConfidence)
            ->take($limit)
            ->values();
    }

    /**
     * Attach the resolved Eloquent model to each rule row (via the morph map),
     * dropping any that no longer resolve.
     *
     * Scoped through publiclyVisible() where the model supports it (every
     * real tourism listing kind does): a plain find() previously resolved
     * whatever the id pointed at regardless of accreditation or archiving,
     * so an establishment that later closed down -- or lost accreditation --
     * kept being suggested to travellers forever, since nothing about the
     * historical exit-survey data it was mined from ever changes.
     */
    public function resolveListings(Collection $rules): Collection
    {
        $morphMap = Relation::morphMap();

        return $rules
            ->map(function (array $rule) use ($morphMap) {
                $modelClass = $morphMap[$rule['listing_kind']] ?? null;
                $query = $modelClass ? $modelClass::query() : null;

                if ($query && method_exists($modelClass, 'scopePubliclyVisible')) {
                    $query->publiclyVisible();
                    \App\Support\OfficialOnlyMode::apply($query);
                }

                // While a trip is being planned, a suggested restaurant, stay or
                // souvenir stop that is closed on the traveller's dates is dropped.
                if ($query && method_exists($modelClass, 'scopeAvailableForTrip')) {
                    $query->availableForTrip();
                }

                $rule['listing'] = $query?->find($rule['listing_id']);

                return $rule;
            })
            ->filter(fn (array $rule) => $rule['listing'] !== null)
            ->values();
    }

    /**
     * Convenience: top associated listings for a destination, already resolved to models,
     * used by the itinerary generator to suggest complementary accommodations/restaurants/etc.
     */
    public function suggestionsFor(string $listingKind, int $listingId, int $limit = 3, ?string $onlyKind = null): Collection
    {
        // Over-fetch, then trim: places that are hidden, closed or not yet visible drop out when the rules are
        // resolved, and cutting to the limit first could leave fewer suggestions than there were good rules.
        $rules = $this->getAssociatedListings($listingKind, $listingId, $limit * 4, 0.1, 2, $onlyKind);

        return $this->resolveListings($rules)->take($limit)->values();
    }

    /**
     * Every directional association rule (A -> B) across the whole exit-survey dataset, ranked by
     * confidence — used by the admin "Association Rules & Co-Visitation Patterns" overview page,
     * as opposed to getAssociatedListings() which scopes to a single starting listing.
     *
     * @return Collection<int, array{a_kind: string, a_id: int, b_kind: string, b_id: int, co_count: int, support: float, confidence: float}>
     */
    public function topRules(int $limit = 15, int $minSupportCount = 2, float $minConfidence = 0.15): Collection
    {
        $build = $minSupportCount >= self::TABLE_MIN_SUPPORT && $minConfidence >= self::TABLE_MIN_CONFIDENCE ? $this->build() : null;

        $totalTransactions = $build ? (int) $build->total_transactions : $this->surveys()->count();
        if ($totalTransactions === 0) {
            return collect();
        }

        $rows = $build
            ? $this->tableRows($build, $limit, $minSupportCount, $minConfidence)
            : $this->liveRows($limit, $minSupportCount, $minConfidence);

        $rules = $rows
            ->map(fn ($row) => [
                'a_kind' => $row->a_kind,
                'a_id' => (int) $row->a_id,
                'b_kind' => $row->b_kind,
                'b_id' => (int) $row->b_id,
                'co_count' => (int) $row->co_count,
                'support' => round($row->co_count / $totalTransactions, 4),
                'confidence' => round($row->co_count / $row->ant_count, 4),
            ])
            ->filter(fn ($rule) => $rule['co_count'] >= $minSupportCount && $rule['confidence'] >= $minConfidence)
            ->values();

        // Look every listing up once per kind, instead of one query per rule side.
        $morphMap = Relation::morphMap();
        $found = [];
        $wanted = [];
        foreach ($rules as $rule) {
            $wanted[$rule['a_kind']][$rule['a_id']] = true;
            $wanted[$rule['b_kind']][$rule['b_id']] = true;
        }
        foreach ($wanted as $kind => $idSet) {
            $modelClass = $morphMap[$kind] ?? null;
            if (! $modelClass) {
                continue;
            }
            foreach (array_chunk(array_keys($idSet), 1000) as $chunk) {
                foreach ($modelClass::query()->whereIn((new $modelClass)->getQualifiedKeyName(), $chunk)->get() as $model) {
                    $found[$kind][(int) $model->getKey()] = $model;
                }
            }
        }

        return $rules->map(function (array $rule) use ($found) {
            $rule['a_listing'] = $found[$rule['a_kind']][$rule['a_id']] ?? null;
            $rule['b_listing'] = $found[$rule['b_kind']][$rule['b_id']] ?? null;

            return $rule;
        })->filter(fn ($rule) => $rule['a_listing'] && $rule['b_listing'])->values();
    }

    /** The rows of the precomputed table that clear the thresholds, strongest first. */
    private function tableRows(object $build, int $limit, int $minSupportCount, float $minConfidence): Collection
    {
        $query = DB::table('association_rules')
            ->where('source_set', $build->source_set)
            ->where('co_count', '>=', $minSupportCount)
            ->whereRaw('(co_count * 1.0 / ant_count) >= CAST(? AS DECIMAL(10, 6))', [round($minConfidence - 0.0001, 6)])
            ->select('a_kind', 'a_id', 'b_kind', 'b_id', 'co_count', 'ant_count')
            ->orderByRaw('(co_count * 1.0 / ant_count) desc')
            ->orderByDesc('co_count')
            ->orderBy('a_kind')->orderBy('a_id')->orderBy('b_kind')->orderBy('b_id');

        if ($limit < PHP_INT_MAX) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Every pair counted by the database from the trips themselves, strongest first. This is also what the nightly
     * build stores (see AssociationRuleBuilder), so the table and the live count can never disagree on the method.
     *
     * Equation 9 divides by every transaction containing A, and that has to include the ones where A was the only
     * place visited: a tourist who went to A and nowhere else is evidence AGAINST "people who visit A also visit B",
     * so leaving them out only ever inflates confidence. The antecedent count below is every trip that has A, pair or
     * not; it agrees with getAssociatedListings(), which has always counted the full set, and with topRules()'s own
     * support figure, which divides by every transaction. (Samal Island appears in 32 transactions but was once
     * counted as 26, reporting Samal -> BlueJaz at 73.1% where the data gives 59.4%.)
     *
     * The pairs are counted by the database, not by looping over every visit in PHP: that loop held all visits in
     * memory at once (about 345 MB at 100,000 trips). PHP only receives the pairs that already clear the thresholds.
     */
    public function liveRows(int $limit, int $minSupportCount, float $minConfidence): Collection
    {
        $items = fn () => $this->visits()->select('exit_survey_id', 'listing_kind', 'listing_id')->distinct()->toBase();

        $antecedents = DB::query()->fromSub($items(), 'i')
            ->selectRaw('i.listing_kind as ant_kind, i.listing_id as ant_id, count(*) as ant_count')
            ->groupBy('i.listing_kind', 'i.listing_id');

        $pairs = DB::query()->fromSub($items(), 'a')
            ->joinSub($items(), 'b', fn ($join) => $join->on('a.exit_survey_id', '=', 'b.exit_survey_id'))
            ->where(fn ($q) => $q->whereColumn('a.listing_kind', '!=', 'b.listing_kind')->orWhereColumn('a.listing_id', '!=', 'b.listing_id'))
            ->selectRaw('a.listing_kind as a_kind, a.listing_id as a_id, b.listing_kind as b_kind, b.listing_id as b_id, count(*) as co_count')
            ->groupBy('a.listing_kind', 'a.listing_id', 'b.listing_kind', 'b.listing_id')
            ->havingRaw('count(*) >= ?', [$minSupportCount]);

        $mined = DB::query()->fromSub($pairs, 'p')
            ->joinSub($antecedents, 'n', fn ($join) => $join->on('p.a_kind', '=', 'n.ant_kind')->on('p.a_id', '=', 'n.ant_id'))
            ->selectRaw('p.a_kind, p.a_id, p.b_kind, p.b_id, p.co_count, n.ant_count')
            // A little under the threshold: the exact test is on the confidence rounded to 4 places, in the caller.
            // Cast: a bare float is bound as text, and SQLite compares a number with text as always smaller.
            ->whereRaw('(p.co_count * 1.0 / n.ant_count) >= CAST(? AS DECIMAL(10, 6))', [round($minConfidence - 0.0001, 6)])
            ->orderByRaw('(p.co_count * 1.0 / n.ant_count) desc')
            ->orderByDesc('p.co_count')
            ->orderBy('p.a_kind')->orderBy('p.a_id')->orderBy('p.b_kind')->orderBy('p.b_id');

        // The rows already come in confidence order, so the first $limit that pass are the top $limit.
        if ($limit < PHP_INT_MAX) {
            $mined->limit($limit);
        }

        return $mined->get();
    }

    /** How many trips this mix of sources counts (the total that support is measured against). */
    public function transactionCount(): int
    {
        return $this->surveys()->count();
    }
}
