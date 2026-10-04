<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyActivity;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Services\Recommendation\AprioriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /exit-survey?test=1 lets the survey be tried end to end without the answers
 * ever counting: a test survey is kept, but hidden from every count, report
 * and Apriori transaction.
 */
class ExitSurveyTestModeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, Destination> */
    private function places(): array
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return array_map(fn (string $name) => Destination::create([
            'slug' => str($name)->slug(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]), ['Eden Nature Park', 'Philippine Eagle Center']);
    }

    private function submit(array $places, bool $test): void
    {
        $payload = [
            'overall_rating' => 5, 'would_recommend' => 'Yes',
            'places_visited' => array_map(fn ($d) => 'destination:'.$d->id, $places),
            'activities' => ['Wildlife'],
        ];
        if ($test) {
            $payload['test'] = 1;
        }

        $this->post(route('exit-survey.store'), $payload)->assertRedirect(route('exit-survey.recap'));
    }

    public function test_a_normal_submission_is_real(): void
    {
        $this->submit($this->places(), test: false);

        $this->assertSame('real', ExitSurvey::firstOrFail()->data_source);
    }

    public function test_a_test_submission_is_saved_but_invisible_to_counts_and_children(): void
    {
        $this->submit($this->places(), test: true);

        $this->assertSame(0, ExitSurvey::count());
        $this->assertSame(0, ExitSurveyVisit::count());
        $this->assertSame(0, ExitSurveyActivity::count());

        $kept = ExitSurvey::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('test', $kept->data_source);
        $this->assertSame(2, ExitSurveyVisit::withoutGlobalScopes()->count());
    }

    public function test_test_surveys_never_reach_apriori(): void
    {
        $places = $this->places();
        // two real surveys with the pair, and three test ones
        $this->submit($places, test: false);
        $this->submit($places, test: false);
        $this->submit($places, test: true);
        $this->submit($places, test: true);
        $this->submit($places, test: true);

        $rule = app(AprioriService::class)->getAssociatedListings('destination', $places[0]->id, 5, 0.1, 2)->first();

        $this->assertSame(2, $rule['co_count']);
        $this->assertEquals(1.0, $rule['confidence']);
        $this->assertSame(2, ExitSurvey::count());
    }

    public function test_the_visitor_still_sees_their_recap_after_a_test_submission(): void
    {
        $this->submit($this->places(), test: true);

        $this->get(route('exit-survey.recap'))
            ->assertOk()
            ->assertSee('Eden Nature Park');
    }

    public function test_the_form_announces_test_mode_only_when_asked(): void
    {
        $this->get(route('exit-survey.create', ['test' => 1]))
            ->assertOk()
            ->assertSee('Test mode.', false)
            ->assertSee('name="test" value="1"', false);

        $this->get(route('exit-survey.create'))
            ->assertOk()
            ->assertDontSee('Test mode.', false);
    }

    public function test_the_purge_command_removes_only_test_surveys(): void
    {
        $places = $this->places();
        $this->submit($places, test: false);
        $this->submit($places, test: true);

        $this->artisan('exit-survey:purge-test')->expectsOutput('Removed 1 test survey(s).')->assertSuccessful();

        $this->assertSame(1, ExitSurvey::withoutGlobalScopes()->count());
        $this->assertSame(0, ExitSurvey::withoutGlobalScopes()->where('data_source', 'test')->count());
    }
}
