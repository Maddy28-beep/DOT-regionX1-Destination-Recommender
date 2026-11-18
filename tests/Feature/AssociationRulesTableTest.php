<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\Restaurant;
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\AssociationRuleBuilder;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The precomputed association-rule table (association_rules_table switch): it must give the same answers as counting
 * the trips live, stay out of the way while the switch is off or no build exists, and never be left half-built.
 */
class AssociationRulesTableTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Destination|Restaurant> */
    private array $places = [];

    private function dest(string $name): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return $this->places[$name] = Destination::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
    }

    private function cafe(): Restaurant
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Restaurant::create(['slug' => 'cafe', 'name' => 'Cafe', 'location' => 'Davao', 'region_id' => $region->id, 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
    }

    private function survey(string $source, array $places): void
    {
        $s = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => $source, 'submitted_at' => now()]);
        foreach ($places as [$kind, $id]) {
            ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => $kind, 'listing_id' => $id]);
        }
    }

    /** A small, uneven set of trips over three sources, with a restaurant, a repeat and a lone visit. */
    private function seedTrips(): array
    {
        $a = $this->dest('Eden');
        $b = $this->dest('Falls');
        $c = $this->dest('Museum');
        $cafe = $this->cafe();

        foreach (['real', 'real', 'real', 'itinerary', 'itinerary', 'demo', 'demo', 'demo'] as $i => $src) {
            $trip = [['destination', $a->id], ['destination', $b->id]];
            if ($i % 2 === 0) {
                $trip[] = ['destination', $c->id];
            }
            if ($i % 3 !== 0) {
                $trip[] = ['restaurant', $cafe->id];
            }
            if ($i === 4) {
                $trip[] = ['destination', $a->id];   // listed twice in one trip
            }
            $this->survey($src, $trip);
        }
        $this->survey('real', [['destination', $a->id]]);   // a lone visit still counts for A

        return [$a, $b, $c, $cafe];
    }

    private function flag(bool $on): void
    {
        DB::table('feature_flags')->updateOrInsert(['name' => Features::ASSOCIATION_RULES_TABLE], ['enabled' => $on]);
    }

    private function normalizeTop($rules): array
    {
        return $rules->map(fn ($r) => [$r['a_kind'], $r['a_id'], $r['b_kind'], $r['b_id'], $r['co_count'], $r['support'], $r['confidence']])->all();
    }

    private function normalizeFor($rules): array
    {
        return $rules->map(fn ($r) => [$r['listing_kind'], $r['listing_id'], $r['co_count'], $r['support'], $r['confidence']])
            ->sortBy(fn ($r) => $r[0].'-'.str_pad((string) $r[1], 8, '0', STR_PAD_LEFT))->values()->all();
    }

    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse(Features::on(Features::ASSOCIATION_RULES_TABLE));
    }

    public function test_the_table_gives_the_same_answers_as_counting_live_for_every_source(): void
    {
        [$a, $b, $c, $cafe] = $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();

        foreach (AprioriService::SOURCE_SETS as $key => $sources) {
            $this->flag(false);
            $live = app(AprioriService::class)->onlySources($sources);
            $liveTop = $this->normalizeTop($live->topRules(PHP_INT_MAX, 2, 0.15));
            $liveTop15 = $this->normalizeTop($live->topRules(15, 2, 0.15));
            $liveFor = [];
            foreach ([$a, $b, $c] as $p) {
                foreach ([null, 'restaurant', 'destination'] as $kind) {
                    $liveFor[$p->id.'|'.$kind] = $this->normalizeFor($live->getAssociatedListings('destination', $p->id, 10, 0.1, 2, $kind));
                }
            }

            $this->flag(true);
            $table = app(AprioriService::class)->onlySources($sources);
            $this->assertNotNull($table->rulesBuiltAt(), "{$key}: the table should be in use");
            $this->assertSame($liveTop, $this->normalizeTop($table->topRules(PHP_INT_MAX, 2, 0.15)), "{$key}: all rules");
            $this->assertSame($liveTop15, $this->normalizeTop($table->topRules(15, 2, 0.15)), "{$key}: top 15");
            foreach ([$a, $b, $c] as $p) {
                foreach ([null, 'restaurant', 'destination'] as $kind) {
                    $this->assertSame($liveFor[$p->id.'|'.$kind], $this->normalizeFor($table->getAssociatedListings('destination', $p->id, 10, 0.1, 2, $kind)), "{$key}: {$p->name} {$kind}");
                }
            }
        }
    }

    public function test_the_table_is_ignored_while_the_switch_is_off(): void
    {
        [$a] = $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();
        DB::table('association_rules')->update(['co_count' => 999]);   // a table that would be obviously wrong if it were read

        $this->flag(false);
        $rule = app(AprioriService::class)->getAssociatedListings('destination', $a->id, 5, 0.1, 2)->first();

        $this->assertNotSame(999, $rule['co_count']);
        $this->assertNull(app(AprioriService::class)->rulesBuiltAt());
    }

    public function test_with_no_build_yet_it_counts_live_even_when_the_switch_is_on(): void
    {
        [$a] = $this->seedTrips();
        $this->flag(true);

        $service = app(AprioriService::class);

        $this->assertNull($service->rulesBuiltAt());
        $this->assertNotEmpty($service->getAssociatedListings('destination', $a->id, 5, 0.1, 2));
        $this->assertNotEmpty($service->topRules());
    }

    public function test_a_mix_of_sources_the_table_does_not_cover_counts_live(): void
    {
        [$a] = $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();
        $this->flag(true);

        $mix = app(AprioriService::class)->onlySources(['real', 'demo']);

        $this->assertNull($mix->sourceKey());
        $this->assertNull($mix->rulesBuiltAt());
        $this->assertNotEmpty($mix->getAssociatedListings('destination', $a->id, 5, 0.1, 2));
    }

    public function test_asking_for_less_than_the_table_holds_is_answered_live(): void
    {
        $a = $this->dest('A');
        $b = $this->dest('B');
        $this->survey('real', [['destination', $a->id], ['destination', $b->id]]);   // seen together just once
        app(AssociationRuleBuilder::class)->build();
        $this->flag(true);

        $service = app(AprioriService::class);

        $this->assertCount(0, $service->getAssociatedListings('destination', $a->id, 5, 0.1, 2), 'two trips are needed');
        $this->assertCount(1, $service->getAssociatedListings('destination', $a->id, 5, 0.1, 1), 'one trip, asked for explicitly, comes from the trips themselves');
    }

    public function test_new_trips_show_up_after_the_next_build_and_not_before(): void
    {
        $a = $this->dest('A');
        $b = $this->dest('B');
        $this->survey('real', [['destination', $a->id], ['destination', $b->id]]);
        $this->survey('real', [['destination', $a->id], ['destination', $b->id]]);
        app(AssociationRuleBuilder::class)->build();
        $this->flag(true);

        $service = fn () => app(AprioriService::class)->getAssociatedListings('destination', $a->id, 5, 0.1, 2)->first();
        $this->assertSame(2, $service()['co_count']);

        $this->survey('real', [['destination', $a->id], ['destination', $b->id]]);
        $this->assertSame(2, $service()['co_count'], 'the table is a nightly count, so a new trip waits for the next build');

        app(AssociationRuleBuilder::class)->build();
        $this->assertSame(3, $service()['co_count']);
    }

    public function test_a_build_that_fails_leaves_the_previous_rules_in_place(): void
    {
        $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();
        $before = DB::table('association_rules')->orderBy('id')->get()->all();
        $builtAt = DB::table('association_rule_builds')->where('source_set', 'all')->value('built_at');

        $broken = new class extends AprioriService
        {
            public function liveRows(int $limit, int $minSupportCount, float $minConfidence): \Illuminate\Support\Collection
            {
                throw new \RuntimeException('the count failed');
            }
        };

        try {
            (new AssociationRuleBuilder($broken))->build(['all']);
            $this->fail('the build should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('the count failed', $e->getMessage());
        }

        $this->assertEquals($before, DB::table('association_rules')->orderBy('id')->get()->all());
        $this->assertSame($builtAt, DB::table('association_rule_builds')->where('source_set', 'all')->value('built_at'));
    }

    public function test_a_rebuild_replaces_the_rules_for_that_source_only(): void
    {
        $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();
        $demoBefore = DB::table('association_rules')->where('source_set', 'demo')->count();
        DB::table('association_rules')->where('source_set', 'real')->delete();

        app(AssociationRuleBuilder::class)->build(['real']);

        $this->assertGreaterThan(0, DB::table('association_rules')->where('source_set', 'real')->count());
        $this->assertSame($demoBefore, DB::table('association_rules')->where('source_set', 'demo')->count());
        $this->assertSame(1, DB::table('association_rule_builds')->where('source_set', 'real')->count());
    }

    public function test_the_build_command_reports_each_set_and_rejects_an_unknown_one(): void
    {
        $this->seedTrips();

        $this->artisan('association-rules:build')->expectsOutputToContain('trips ->')->assertSuccessful();
        $this->assertSame(5, DB::table('association_rule_builds')->count(), 'the four source sets and the visit counts');

        $this->artisan('association-rules:build', ['--only' => ['nonsense']])->expectsOutputToContain('Unknown set')->assertFailed();
    }

    public function test_the_admin_page_says_when_the_rules_were_built_only_while_the_table_is_in_use(): void
    {
        $this->seedTrips();
        app(AssociationRuleBuilder::class)->build();
        $admin = AdminUser::create(['email' => 'tbl-admin@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);

        $this->flag(false);
        $this->assertStringNotContainsString('last built', $this->actingAs($admin, 'admin')->get(route('admin.association-rules'))->getContent());

        $this->flag(true);
        $this->assertStringContainsString('last built', $this->actingAs($admin, 'admin')->get(route('admin.association-rules'))->getContent());
    }

    /** What the planner uses to weigh a destination by how often it was visited. */
    private function visitShares(iterable $destinations): array
    {
        $service = app(ContentBasedRecommendationService::class);
        $method = new \ReflectionMethod($service, 'visitShares');

        return $method->invoke($service, collect($destinations));
    }

    public function test_the_planners_visit_shares_are_the_same_from_the_table_as_counted_live(): void
    {
        [$a, $b, $c] = $this->seedTrips();
        $this->survey('real', [['destination', $c->id], ['destination', $c->id]]);   // the same place twice in one trip counts twice, as it always did
        ExitSurvey::create(['overall_rating' => 1, 'would_recommend' => 'No', 'data_source' => 'test', 'submitted_at' => now()]);   // a trial survey is left out
        $trial = ExitSurvey::withoutGlobalScopes()->where('data_source', 'test')->first();
        ExitSurveyVisit::withoutGlobalScopes()->create(['exit_survey_id' => $trial->id, 'listing_kind' => 'destination', 'listing_id' => $b->id]);
        app(AssociationRuleBuilder::class)->build();

        $this->flag(false);
        $live = $this->visitShares([$a, $b, $c]);
        $this->flag(true);
        $table = $this->visitShares([$a, $b, $c]);

        $this->assertNotEmpty($live);
        $this->assertEquals($live, $table);
        $this->assertNotNull(app(AprioriService::class)->precomputedVisitCounts('destination', [$a->id]));
    }

    public function test_visit_shares_are_counted_live_while_the_switch_is_off_or_nothing_is_built(): void
    {
        [$a, $b, $c] = $this->seedTrips();
        $apriori = app(AprioriService::class);

        $this->flag(true);
        $this->assertNull($apriori->precomputedVisitCounts('destination', [$a->id]), 'switch on but nothing built yet');
        $this->assertNotEmpty($this->visitShares([$a, $b, $c]));

        $this->flag(false);
        $counted = $this->visitShares([$a, $b, $c]);

        app(AssociationRuleBuilder::class)->build();
        DB::table('association_visit_counts')->where('listing_id', $a->id)->update(['visits' => 999]);   // an obviously wrong table
        $this->assertNull($apriori->precomputedVisitCounts('destination', [$a->id]), 'switch off');
        $this->assertEquals($counted, $this->visitShares([$a, $b, $c]), 'the wrong table is not read while the switch is off');
    }

    public function test_new_visits_reach_the_shares_after_the_next_build(): void
    {
        $a = $this->dest('A');
        $b = $this->dest('B');
        $this->survey('real', [['destination', $a->id]]);
        $this->survey('real', [['destination', $a->id]]);
        $this->survey('real', [['destination', $b->id]]);
        app(AssociationRuleBuilder::class)->build();
        $this->flag(true);

        $this->assertEquals([$a->id => 1.0, $b->id => 0.5], $this->visitShares([$a, $b]));

        $this->survey('real', [['destination', $b->id]]);
        $this->assertEquals([$a->id => 1.0, $b->id => 0.5], $this->visitShares([$a, $b]), 'waits for the next build');

        app(AssociationRuleBuilder::class)->build(['visitcounts']);
        $this->assertEquals([$a->id => 1.0, $b->id => 1.0], $this->visitShares([$a, $b]));
    }
}
