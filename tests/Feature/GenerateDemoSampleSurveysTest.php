<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\Restaurant;
use App\Services\Recommendation\AprioriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * surveys:generate-demo-samples simulates demo exit surveys that include the sample places, under their own batch.
 * It must write nothing without --apply, be repeatable from a seed, and never touch other survey rows.
 */
class GenerateDemoSampleSurveysTest extends TestCase
{
    use RefreshDatabase;

    private function places(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        foreach ([['Beach One', 'Beach & Leisure', 7.10], ['Falls Two', 'Nature & Adventure', 7.20], ['Museum Three', 'Cultural Heritage', 7.30], ['Zoo Four', 'Wildlife', 7.40], ['Peak Five', 'Adventure & Hiking', 7.50], ['Park Six', 'Nature & Leisure', 7.60]] as $i => [$name, $type, $lat]) {
            $d = Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name.($i >= 3 ? ' [SAMPLE]' : ''), 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => $lat, 'longitude' => 125.5,
            ]);
            AccreditationRecord::create([
                'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => ($i >= 3 ? 'SAMPLE-' : 'DOT-R11-').$d->id,
                'status' => 'Active', 'issue_date' => now()->subYear(), 'expiration_date' => now()->addYear(), 'is_placeholder' => $i >= 3,
            ]);
        }
    }

    private function generate(array $options = []): string
    {
        Artisan::call('surveys:generate-demo-samples', $options);

        return Artisan::output();
    }

    public function test_a_dry_run_writes_nothing_and_says_so(): void
    {
        $this->places();

        $out = $this->generate(['--count' => 40]);

        $this->assertStringContainsString('Dry run: nothing written', $out);
        $this->assertStringContainsString('40 simulated surveys', $out);
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->count());
        $this->assertSame(0, ExitSurveyVisit::count());
    }

    public function test_the_same_seed_gives_the_same_report(): void
    {
        $this->places();

        $this->assertSame($this->generate(['--count' => 60, '--seed' => 7]), $this->generate(['--count' => 60, '--seed' => 7]));
    }

    public function test_apply_writes_demo_rows_in_their_own_batch_and_includes_the_sample_places(): void
    {
        $this->places();
        $bare = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'submitted_at' => now()]);
        $real = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);

        $this->generate(['--count' => 50, '--apply' => true, '--batch' => 'demo-samples-test']);

        $batch = ExitSurvey::withoutGlobalScopes()->where('batch', 'demo-samples-test')->get();
        $this->assertSame(50, $batch->count());
        $this->assertSame(['demo'], $batch->pluck('data_source')->unique()->values()->all());

        $sampleIds = Destination::where('name', 'like', '%[SAMPLE]%')->pluck('id');
        $visited = ExitSurveyVisit::whereIn('exit_survey_id', $batch->pluck('id'))->where('listing_kind', 'destination')->pluck('listing_id');
        foreach ($sampleIds as $id) {
            $this->assertContains($id, $visited->all(), 'every sample place appears in some survey');
        }

        // The other rows are exactly as they were.
        $this->assertSame('demo', $bare->fresh()->data_source);
        $this->assertNull($bare->fresh()->batch);
        $this->assertSame('real', $real->fresh()->data_source);
    }

    public function test_an_existing_batch_is_not_added_to_and_revert_removes_only_that_batch(): void
    {
        $this->places();
        $bare = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'submitted_at' => now()]);
        $other = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'batch' => 'researcher-test-2026-10', 'submitted_at' => now()]);

        $this->generate(['--count' => 20, '--apply' => true, '--batch' => 'b1']);
        $this->assertStringContainsString("Batch 'b1' already exists", $this->generate(['--count' => 20, '--apply' => true, '--batch' => 'b1']));
        $this->assertSame(20, ExitSurvey::withoutGlobalScopes()->where('batch', 'b1')->count());

        $this->assertStringContainsString('Dry run: nothing deleted', $this->generate(['--revert' => true, '--batch' => 'b1']));
        $this->assertSame(20, ExitSurvey::withoutGlobalScopes()->where('batch', 'b1')->count());

        $this->generate(['--revert' => true, '--apply' => true, '--batch' => 'b1']);

        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->where('batch', 'b1')->count());
        $this->assertSame(0, ExitSurveyVisit::count(), 'the batch took its visits with it');
        $this->assertNotNull($bare->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_coverage_guarantee_puts_every_sample_place_in_two_baskets_and_never_touches_genuine_rows(): void
    {
        $this->places();
        $real = ExitSurvey::create(['overall_rating' => 5, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => now()]);

        // Far too few random surveys to cover six places: the guarantee must do the work.
        $this->generate(['--count' => 5, '--seed' => 11, '--ensure-sample-coverage' => true, '--apply' => true, '--batch' => 'coverage-test']);

        $batch = ExitSurvey::withoutGlobalScopes()->where('batch', 'coverage-test')->get();
        $this->assertSame(['demo'], $batch->pluck('data_source')->unique()->values()->all(), 'every generated row is simulated');

        $visits = ExitSurveyVisit::whereIn('exit_survey_id', $batch->pluck('id'))
            ->where('listing_kind', 'destination')->get()->countBy('listing_id');
        foreach (Destination::where('name', 'like', '%[SAMPLE]%')->pluck('id') as $id) {
            $this->assertGreaterThanOrEqual(2, $visits[$id] ?? 0, 'sample place '.$id.' appears in at least two baskets');
        }

        foreach (ExitSurveyVisit::whereIn('exit_survey_id', $batch->pluck('id'))->get() as $visit) {
            $this->assertNotNull(Destination::find($visit->listing_id), 'every visit points at a real listing');
        }

        $this->assertSame('real', $real->fresh()->data_source);
        $this->assertNull($real->fresh()->batch);
    }

    public function test_coverage_is_deterministic_for_a_seed_and_writes_nothing_on_a_dry_run(): void
    {
        $this->places();

        $first = $this->generate(['--count' => 5, '--seed' => 11, '--ensure-sample-coverage' => true, '--batch' => 'coverage-dry']);
        $second = $this->generate(['--count' => 5, '--seed' => 11, '--ensure-sample-coverage' => true, '--batch' => 'coverage-dry']);

        $this->assertSame($first, $second);
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->count());
    }

    public function test_after_coverage_apriori_can_start_a_rule_from_a_sample_place(): void
    {
        $this->places();
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        Restaurant::create(['slug' => 'coverage-cafe', 'name' => 'Coverage Cafe', 'location' => 'Davao City', 'region_id' => $region->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => 7.42, 'longitude' => 125.5]);
        Accommodation::create(['slug' => 'coverage-inn', 'name' => 'Coverage Inn', 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'latitude' => 7.42, 'longitude' => 125.5]);
        $this->generate(['--count' => 5, '--seed' => 11, '--ensure-sample-coverage' => true, '--apply' => true, '--batch' => 'coverage-rules']);

        $sample = Destination::where('name', 'like', '%[SAMPLE]%')->firstOrFail();
        $apriori = app(AprioriService::class);
        $associated = $apriori->getAssociatedListings('destination', $sample->id, 10, 0.1, 2, 'destination');

        // The two guaranteed baskets pair the sample place with the same companions, clearing the
        // unchanged minimum support count of 2; a single co-visit must not form a rule.
        $this->assertNotEmpty($associated);
        $this->assertGreaterThanOrEqual(2, $associated[0]['co_count']);

        // The builder credits rules on meal and overnight rows, so the coverage baskets also
        // pair each sample place with its nearest meal and stay: those rules must qualify too.
        $this->assertNotEmpty($apriori->suggestionsFor('destination', $sample->id, 5, 'restaurant'));
        $this->assertNotEmpty($apriori->suggestionsFor('destination', $sample->id, 5, 'accommodation'));
    }

    public function test_it_refuses_without_enough_places(): void
    {
        $this->assertStringContainsString('at least two visible destinations', $this->generate(['--count' => 10]));
    }
}
