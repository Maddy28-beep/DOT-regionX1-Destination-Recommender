<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Beta-testing arrangement: sample (demonstration) destinations take part in the recommender, Apriori, itinerary generation
 * and geographic sequencing exactly like official ones. The placeholder flag only says where a record came from.
 *
 * The strongest check is the flip: the same catalogue is planned three times with only the provenance changed (all official,
 * all sample, no records at all) and every result must be identical.
 */
class SampleParticipationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['dot.accreditation_test_grace_until' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private int $n = 0;

    private function place(string $name, float $km, float $rating = 4.0, string $type = 'Nature & Leisure', array $over = []): Destination
    {
        return Destination::create($over + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => $type, 'is_accredited' => true, 'rating' => $rating, 'review_count' => 20, 'price_tier' => 'Mid-range',
            'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61, 'distance_km' => $km,
        ]);
    }

    private function record(Destination $d, string $as): void
    {
        AccreditationRecord::where('listing_kind', 'destination')->where('listing_id', $d->id)->delete();
        if ($as !== 'none') {
            AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $d->id, 'status' => 'Active', 'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01',
                'accreditation_number' => $as === 'sample' ? 'SAMPLE-'.str_pad((string) (++$this->n), 4, '0', STR_PAD_LEFT) : 'DOT-R11-TST-'.str_pad((string) (++$this->n), 5, '0', STR_PAD_LEFT).'-2026',
                'is_placeholder' => $as === 'sample']);
        }
        app(SampleData::class)->flush();
    }

    private function stay(string $name, float $km): Accommodation
    {
        $s = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 4, 'review_count' => 10, 'latitude' => 7.07 + $km / 111.2, 'longitude' => 125.61]);
        AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $s->id, 'accreditation_number' => 'DOT-R11-HTL-'.$s->id.'-2026', 'status' => 'Active',
            'issue_date' => '2026-01-01', 'expiration_date' => '2028-01-01', 'is_placeholder' => false]);

        return $s;
    }

    private function preference(string $range = 'moderate', int $days = 3): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Family', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => $range, 'travel_purpose' => 'Leisure',
            'visitor_type' => 'First-time Visitor', 'place_of_origin' => 'Davao City', 'start_date' => '2026-11-13', 'arrival_time' => '08:00',
            'origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Start',
        ]);
    }

    /** @return array{matches: array, stops: array, stays: array, trip: Itinerary} */
    private function plan(string $range = 'moderate', int $days = 3): array
    {
        $trip = app(ItineraryGenerationService::class)->generate($this->preference($range, $days)->load('activities', 'amenities'), 7.07, 125.61);

        return [
            'trip' => $trip,
            'matches' => $trip->matches()->orderBy('rank')->get(['destination_id', 'rank', 'match_score', 'pm', 'rs', 'ps', 'ds', 'as'])->map->only(['destination_id', 'rank', 'match_score', 'pm', 'rs', 'ps', 'ds', 'as'])->all(),
            'stops' => $trip->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('day_number')->orderBy('sort_order')->get()
                ->groupBy('day_number')->map(fn ($rows) => $rows->pluck('destination_id')->all())->all(),
            'stays' => $trip->items()->where('kind', 'overnight')->orderBy('day_number')->pluck('accommodation_id', 'day_number')->all(),
        ];
    }

    private function catalogue(): array
    {
        $places = [];
        foreach ([[3, 4.9], [5, 4.7], [8, 4.5], [11, 4.3], [14, 4.1], [18, 3.9], [22, 3.7], [26, 3.5]] as $i => [$km, $rating]) {
            $places[] = $this->place('Place '.($i + 1), $km, $rating, $i % 2 ? 'Wildlife' : 'Nature & Leisure');
        }
        $this->stay('Home Stay', 10);
        $this->stay('Other Stay', 24);

        return $places;
    }

    // ---- the flip: provenance changes, nothing else may ---------------------------------------------------------

    public function test_the_ranking_the_chosen_stops_their_order_and_the_stays_are_identical_whatever_the_provenance(): void
    {
        $places = $this->catalogue();

        foreach ($places as $d) {
            $this->record($d, 'official');
        }
        $official = $this->plan();
        $this->assertNotEmpty($official['stops'], 'the baseline plan places stops');

        foreach ($places as $d) {
            $this->record($d, 'sample');
        }
        $sample = $this->plan();

        foreach ($places as $d) {
            $this->record($d, 'none');
        }
        $unrecorded = $this->plan();

        // Alternate: half official, half sample (the mixed catalogue the beta really has).
        foreach ($places as $i => $d) {
            $this->record($d, $i % 2 ? 'sample' : 'official');
        }
        $mixed = $this->plan();

        foreach (['sample' => $sample, 'unrecorded' => $unrecorded, 'mixed' => $mixed] as $label => $other) {
            $this->assertSame($official['matches'], $other['matches'], "$label: the Content-Based ranking, factor by factor, is the same");
            $this->assertSame($official['stops'], $other['stops'], "$label: the same stops on the same days, in the same order");
            $this->assertSame($official['stays'], $other['stays'], "$label: the same stays");
        }
    }

    public function test_the_comparison_can_tell_a_real_difference_from_a_provenance_difference(): void
    {
        // The control for the test above: change a fact that SHOULD matter (a rating) and the result must change.
        $places = $this->catalogue();
        foreach ($places as $d) {
            $this->record($d, 'official');
        }
        $before = $this->plan();

        $places[0]->update(['rating' => 1.0, 'review_count' => 500]);
        $places[7]->update(['rating' => 5.0, 'review_count' => 500]);

        $after = $this->plan();

        $this->assertNotSame($before['matches'], $after['matches'], 'a change of rating changes the ranking, so identical rankings above are not a vacuous result');
    }

    public function test_a_sample_destination_scores_exactly_like_an_official_one_with_the_same_facts(): void
    {
        $official = $this->place('Alpha Cascade', 9, 4.4);
        $sample = $this->place('Beta Cascade [SAMPLE]', 9, 4.4);
        $this->record($official, 'official');
        $this->record($sample, 'sample');

        $ranked = app(ContentBasedRecommendationService::class)->rank($this->preference()->load('activities', 'amenities'))->keyBy(fn ($r) => $r['destination']->id);

        $this->assertTrue($ranked->has($official->id) && $ranked->has($sample->id), 'both are ranked');
        foreach (['drs', 'pm', 'rs', 'ps', 'ds', 'as'] as $factor) {
            $this->assertSame($ranked[$official->id][$factor], $ranked[$sample->id][$factor], "same $factor");
        }
    }

    public function test_a_catalogue_made_only_of_sample_destinations_still_plans_a_trip(): void
    {
        foreach ($this->catalogue() as $d) {
            $this->record($d, 'sample');
        }

        $plan = $this->plan();

        $this->assertNotEmpty($plan['stops']);
        $this->assertCount(2, $plan['stays'], 'and sleeps somewhere on each night');
        $this->assertGreaterThan(0, count($plan['matches']));
    }

    public function test_the_stop_the_planner_chooses_first_is_still_chosen_and_shown_when_it_is_a_sample(): void
    {
        $places = $this->catalogue();
        foreach ($places as $d) {
            $this->record($d, 'official');
        }
        $firstStopId = $this->plan()['stops'][1][0];
        $chosen = Destination::findOrFail($firstStopId);

        // The planner's own first choice becomes a sample record: it must still be the first choice, and be on the page.
        $this->record($chosen, 'sample');
        $plan = $this->plan();

        $this->assertSame($firstStopId, $plan['stops'][1][0], 'a sample record is chosen exactly as an official one was');
        $html = $this->withSession([TripPlannerController::ITINERARY_KEY => $plan['trip']->id, TripPlannerController::PREFERENCE_KEY => $plan['trip']->preference_id])
            ->get(route('plan.itinerary'))->assertOk()->getContent();
        $this->assertStringContainsString($chosen->name, $html, 'and it is on the itinerary page');
        $this->assertTrue($plan['trip']->matches()->where('destination_id', $chosen->id)->exists(), 'and in the ranking behind it');
        $this->assertGreaterThan(0, count($plan['matches']));
    }

    public function test_every_sample_destination_that_fits_the_traveller_is_ranked_not_just_the_chosen_ones(): void
    {
        $places = $this->catalogue();
        foreach ($places as $i => $d) {
            $this->record($d, $i < 4 ? 'sample' : 'official');
        }

        $rankedIds = collect($this->plan()['matches'])->pluck('destination_id')->all();

        foreach ($places as $d) {
            $this->assertContains($d->id, $rankedIds, $d->name.' is ranked whatever its provenance');
        }
    }

    public function test_sample_and_unrecorded_destinations_stay_eligible_for_the_catalogue_and_the_planner(): void
    {
        $sample = $this->place('Demo Falls [SAMPLE]', 6);
        $this->record($sample, 'sample');
        $unrecorded = $this->place('Unrecorded Falls', 7);
        $this->record($unrecorded, 'none');

        $this->assertTrue(Destination::publiclyVisible()->whereKey($sample->id)->exists());
        $this->assertTrue(Destination::publiclyVisible()->whereKey($unrecorded->id)->exists());
        $this->get('/destinations')->assertOk()->assertSee('Demo Falls [SAMPLE]')->assertSee('Unrecorded Falls');
    }

    // ---- Apriori ----------------------------------------------------------------------------------------------

    private function pair(Destination $d, Accommodation $stay, int $surveys = 3): void
    {
        foreach (range(1, $surveys) as $i) {
            $survey = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'demo', 'submitted_at' => now()]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'destination', 'listing_id' => $d->id]);
            ExitSurveyVisit::create(['exit_survey_id' => $survey->id, 'listing_kind' => 'accommodation', 'listing_id' => $stay->id]);
        }
    }

    public function test_apriori_treats_a_sample_destination_exactly_like_an_official_one(): void
    {
        $official = $this->place('Official Falls', 6);
        $sample = $this->place('Demo Falls [SAMPLE]', 7);
        $this->record($official, 'official');
        $this->record($sample, 'sample');
        $stayA = $this->stay('Stay A', 8);
        $stayB = $this->stay('Stay B', 9);
        $this->pair($official, $stayA);
        $this->pair($sample, $stayB);

        $apriori = app(AprioriService::class);
        $forOfficial = $apriori->suggestionsFor('destination', $official->id, 3, 'accommodation');
        $forSample = $apriori->suggestionsFor('destination', $sample->id, 3, 'accommodation');

        $this->assertSame($stayA->id, $forOfficial->first()['listing_id']);
        $this->assertSame($stayB->id, $forSample->first()['listing_id'], 'the sample gets its rule');
        foreach (['confidence', 'support', 'co_count'] as $measure) {
            $this->assertSame($forOfficial->first()[$measure], $forSample->first()[$measure], "same $measure");
        }
        $this->assertGreaterThanOrEqual(2, $apriori->topRules(50, 2, 0.1)->count(), 'and both appear among the mined rules');
    }

    // ---- nothing in the engines can tell the difference ----------------------------------------------------------

    public function test_the_recommendation_code_never_reads_provenance(): void
    {
        foreach (array_merge(glob(app_path('Services/Recommendation/*.php')), glob(app_path('Services/Embeddings/*.php'))) as $file) {
            $this->assertDoesNotMatchRegularExpression('/SampleData|is_placeholder|isSample|\[SAMPLE\]/', file_get_contents($file),
                basename($file).' must not look at whether a record is sample data: provenance is only a label');
        }
        $this->assertStringNotContainsString('is_placeholder', file_get_contents(app_path('Models/Concerns/HasArchiving.php')), 'the shared visibility scope depends only on is_accredited');
    }
}
