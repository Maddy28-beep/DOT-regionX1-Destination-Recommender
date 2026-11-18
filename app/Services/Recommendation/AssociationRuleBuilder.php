<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\DB;

/**
 * Counts the "people who visit A also visit B" pairs once and keeps them in association_rules, so the planner reads a
 * few rows instead of re-counting every recorded trip for every stop it plans.
 *
 * It uses the very same counting as AprioriService::liveRows(), so the table and the live count cannot disagree on the
 * method. Each mix of sources is replaced inside one transaction: a build that fails part-way leaves the previous
 * rules exactly as they were.
 */
class AssociationRuleBuilder
{
    public function __construct(private readonly AprioriService $apriori) {}

    /**
     * @param  array<int, string>|null  $only  limit to some of the AprioriService::SOURCE_SETS keys, or 'visitcounts'; null builds all
     * @return array<string, array{trips: int, rules: int}>
     */
    public function build(?array $only = null): array
    {
        $done = [];

        foreach (AprioriService::SOURCE_SETS as $key => $sources) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $service = $this->apriori->onlySources($sources);
            $trips = $service->transactionCount();

            // Every pair seen in 2+ trips and at least 10% confident: the lowest bar any caller uses. A caller that
            // asks for less than that is answered from the trips themselves (see AprioriService::TABLE_MIN_*).
            $rows = $trips === 0
                ? collect()
                : $service->liveRows(PHP_INT_MAX, AprioriService::TABLE_MIN_SUPPORT, AprioriService::TABLE_MIN_CONFIDENCE)
                    ->filter(fn ($r) => round($r->co_count / $r->ant_count, 4) >= AprioriService::TABLE_MIN_CONFIDENCE)
                    ->values();

            DB::transaction(function () use ($key, $trips, $rows) {
                DB::table('association_rules')->where('source_set', $key)->delete();

                foreach ($rows->chunk(500) as $chunk) {
                    DB::table('association_rules')->insert($chunk->map(fn ($r) => [
                        'source_set' => $key,
                        'a_kind' => $r->a_kind,
                        'a_id' => (int) $r->a_id,
                        'b_kind' => $r->b_kind,
                        'b_id' => (int) $r->b_id,
                        'co_count' => (int) $r->co_count,
                        'ant_count' => (int) $r->ant_count,
                    ])->all());
                }

                DB::table('association_rule_builds')->upsert(
                    [['source_set' => $key, 'total_transactions' => $trips, 'rule_count' => $rows->count(), 'built_at' => now()]],
                    ['source_set'],
                    ['total_transactions', 'rule_count', 'built_at']
                );
            });

            $done[$key] = ['trips' => $trips, 'rules' => $rows->count()];
        }

        if ($only === null || in_array(AprioriService::VISIT_COUNTS_KEY, $only, true)) {
            $done[AprioriService::VISIT_COUNTS_KEY] = ['trips' => 0, 'rules' => $this->buildVisitCounts()];
        }

        return $done;
    }

    /**
     * Visits per listing, counted exactly as ContentBasedRecommendationService::visitShares() counts them live: every
     * visit row of a survey that counts (trial surveys are left out by the models' own scopes).
     */
    private function buildVisitCounts(): int
    {
        $rows = \App\Models\ExitSurveyVisit::query()
            ->whereIn('exit_survey_id', \App\Models\ExitSurvey::query()->select('id'))
            ->selectRaw('listing_kind, listing_id, count(*) as visits')
            ->groupBy('listing_kind', 'listing_id')
            ->get();

        DB::transaction(function () use ($rows) {
            DB::table('association_visit_counts')->delete();

            foreach ($rows->chunk(500) as $chunk) {
                DB::table('association_visit_counts')->insert($chunk->map(fn ($r) => [
                    'listing_kind' => $r->listing_kind,
                    'listing_id' => (int) $r->listing_id,
                    'visits' => (int) $r->visits,
                ])->all());
            }

            DB::table('association_rule_builds')->upsert(
                [['source_set' => AprioriService::VISIT_COUNTS_KEY, 'total_transactions' => 0, 'rule_count' => $rows->count(), 'built_at' => now()]],
                ['source_set'],
                ['rule_count', 'built_at']
            );
        });

        return $rows->count();
    }
}
