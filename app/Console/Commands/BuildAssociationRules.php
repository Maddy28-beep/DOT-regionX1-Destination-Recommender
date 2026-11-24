<?php

namespace App\Console\Commands;

use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\AssociationRuleBuilder;
use Illuminate\Console\Command;

/**
 * Rebuilds the precomputed "people who visit A also visit B" table. Safe to run any time and as often as wanted: it only
 * reads the recorded trips and replaces the rules table, and nothing reads that table until the switch is on
 * (php artisan features:set association_rules_table on). It runs nightly; run it by hand after importing surveys.
 */
class BuildAssociationRules extends Command
{
    protected $signature = 'association-rules:build {--only=* : build only these sets (all, real, itinerary, demo, visitcounts)}';

    protected $description = 'Rebuild the precomputed association-rule table from the recorded trips';

    public function handle(AssociationRuleBuilder $builder): int
    {
        $only = $this->option('only') ?: null;

        if ($only !== null) {
            $unknown = array_diff($only, [...array_keys(AprioriService::SOURCE_SETS), AprioriService::VISIT_COUNTS_KEY]);
            if ($unknown !== []) {
                $this->error('Unknown set: '.implode(', ', $unknown).'. Choose from: '.implode(', ', [...array_keys(AprioriService::SOURCE_SETS), AprioriService::VISIT_COUNTS_KEY]).'.');

                return self::FAILURE;
            }
        }

        $started = microtime(true);
        $done = $builder->build($only);

        foreach ($done as $key => $info) {
            $this->line($key === AprioriService::VISIT_COUNTS_KEY
                ? sprintf('%-10s %s listings with visit counts', $key, number_format($info['rules']))
                : sprintf('%-10s %s trips -> %s rules', $key, number_format($info['trips']), number_format($info['rules'])));
        }
        $this->info(sprintf('Built in %.1f s.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
