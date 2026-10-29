<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use Database\Seeders\DemoExitSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The team's own test entries were submitted as real surveys. They move to demo under a batch name, reversibly,
 * and nothing else is touched.
 */
class RelabelResearcherSurveysTest extends TestCase
{
    use RefreshDatabase;

    private function survey(string $source = 'real', int $rating = 1, bool $withPlace = false): ExitSurvey
    {
        $s = ExitSurvey::create(['overall_rating' => $rating, 'would_recommend' => 'No', 'data_source' => $source, 'submitted_at' => now()]);

        if ($withPlace) {
            $d = Destination::create(['slug' => 'real-place', 'name' => 'Real Place', 'location' => 'Davao', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
                'type' => 'Nature', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range']);
            ExitSurveyVisit::create(['exit_survey_id' => $s->id, 'listing_kind' => 'destination', 'listing_id' => $d->id]);
        }

        return $s;
    }

    private function fiveTestEntries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->survey();
        }
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->fiveTestEntries();

        $this->artisan('surveys:relabel-researcher-tests')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(5, ExitSurvey::where('data_source', 'real')->count());
        $this->assertSame(0, ExitSurvey::whereNotNull('batch')->count());
    }

    public function test_apply_relabels_exactly_the_placeless_real_surveys_and_nothing_else(): void
    {
        $this->fiveTestEntries();
        $tourist = $this->survey('real', 5, true);
        $existingDemo = $this->survey('demo', 4);

        $this->artisan('surveys:relabel-researcher-tests', ['--apply' => true])->assertSuccessful();

        $this->assertSame(5, ExitSurvey::where('data_source', 'demo')->where('batch', 'researcher-test-2026-10')->count());
        $this->assertSame('real', $tourist->fresh()->data_source, 'A real survey that names a place is never touched.');
        $this->assertNull($tourist->fresh()->batch);
        $this->assertNull($existingDemo->fresh()->batch);
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->where('data_source', 'test')->count(), 'Never labelled test: test rows are deleted after 24 hours.');
    }

    public function test_it_stops_when_the_number_of_matches_is_not_what_was_expected(): void
    {
        $this->fiveTestEntries();
        $this->survey(); // a sixth placeless real survey: could be a genuine tourist

        $this->artisan('surveys:relabel-researcher-tests', ['--apply' => true])->assertFailed();

        $this->assertSame(6, ExitSurvey::where('data_source', 'real')->count());
    }

    public function test_revert_moves_the_batch_back_to_real(): void
    {
        $this->fiveTestEntries();
        $this->artisan('surveys:relabel-researcher-tests', ['--apply' => true])->assertSuccessful();

        $this->artisan('surveys:relabel-researcher-tests', ['--revert' => true, '--apply' => true])->assertSuccessful();

        $this->assertSame(5, ExitSurvey::where('data_source', 'real')->whereNull('batch')->count());
        $this->assertSame(0, ExitSurvey::where('data_source', 'demo')->count());
    }

    public function test_rerunning_the_demo_seeder_leaves_a_batch_alone(): void
    {
        $this->fiveTestEntries();
        $this->artisan('surveys:relabel-researcher-tests', ['--apply' => true])->assertSuccessful();
        foreach (['Eden Nature Park', 'Philippine Eagle Center'] as $name) {
            Destination::create(['slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
                'type' => 'Nature', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range']);
        }

        $this->seed(DemoExitSurveySeeder::class);

        $this->assertSame(5, ExitSurvey::where('batch', 'researcher-test-2026-10')->count(), 'The seeder replaces only its own rows.');
        $this->assertSame(300, ExitSurvey::where('data_source', 'demo')->whereNull('batch')->count());
    }
}
