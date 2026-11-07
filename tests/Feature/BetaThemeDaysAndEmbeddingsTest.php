<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\DestinationTag;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Chatbot\GroqChatbotClient;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Embeddings\EmbeddingDiagnostics;
use App\Services\Recommendation\AiSkeletonEvaluationLog;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The embedding-based theme-based days are the planner's own, deterministic step. These tests pin down that they run
 * whether or not the optional Groq stage is on, that a missing or broken vector is reported rather than silently
 * accepted, that the stored vectors are checked by a read-only diagnostic, and that Edit Preferences / Regenerate treat
 * the variation counter the way the page says they do. Every Groq client is a scripted stand-in.
 */
class BetaThemeDaysAndEmbeddingsTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = ['lat' => 7.10, 'lng' => 125.50];

    private const TWO_STOPS_A_DAY = [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']];

    private Region $region;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->region = Region::create(['name' => 'Davao City']);
        $this->logPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-skeleton-r2-'.bin2hex(random_bytes(4)).'.log';
        config(['logging.channels.'.AiSkeletonEvaluationLog::CHANNEL => ['driver' => 'single', 'path' => $this->logPath, 'level' => 'info']]);
        Log::forgetChannel(AiSkeletonEvaluationLog::CHANNEL);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    private function place(string $name, ?array $vector, float $lat = 7.10, float $lng = 125.50, ?string $tag = null, array $extra = []): Destination
    {
        $destination = Destination::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => $this->region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
        ]);

        if ($tag) {
            DestinationTag::create(['destination_id' => $destination->id, 'kind' => 'category', 'value' => $tag]);
        }
        if ($vector !== null) {
            $this->embed($destination, $vector);
        }

        return $destination;
    }

    private function embed(Destination $destination, array $vector, bool $normalise = true, array $row = []): DestinationEmbedding
    {
        return DestinationEmbedding::create($row + [
            'destination_id' => $destination->id, 'model' => config('services.embeddings.model', 'nomic-embed-text'),
            'dimensions' => count($vector), 'text_hash' => sha1('x'.$destination->id),
            'vector' => $normalise ? DestinationEmbeddingService::normalise($vector) : $vector,
        ]);
    }

    private function sequence(array $places): array
    {
        return array_map(fn (Destination $d) => ['row' => ['destination' => $d->load('tags'), 'drs' => 4.0], 'distance_km' => 1.0], $places);
    }

    /** Six places in three themes, with vectors, spread over a few kilometres. */
    private function themedCatalogue(): void
    {
        $spec = [
            ['Zoo', [1, 0, 0], 'wildlife'], ['Farm', [0, 1, 0], 'farm'], ['Aviary', [0.95, 0.05, 0], 'wildlife'],
            ['Orchard', [0.05, 0.95, 0], 'farm'], ['Beach', [0, 0, 1], 'beach'], ['Cove', [0.02, 0.02, 0.96], 'beach'],
            ['Park', [0.5, 0.5, 0.2], 'park'], ['Garden', [0.45, 0.55, 0.1], 'park'],
        ];
        foreach ($spec as $i => [$name, $vector, $tag]) {
            $this->place($name, $vector, 7.05 + $i * 0.01, 125.55 + ($i % 3) * 0.01, $tag, ['rating' => 4.9 - $i * 0.05]);
        }
    }

    private function preference(int $days = 3): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => $days, 'travel_type' => 'Solo', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'origin_lat' => 7.0489, 'origin_lng' => 125.5736, 'origin_label' => 'Davao City',
        ]);
        foreach (['Wildlife', 'Nature & Adventure'] as $activity) {
            PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => $activity]);
        }

        return $preference->load('activities', 'amenities');
    }

    /** Counts calls to group() and otherwise behaves exactly like the real grouper. */
    private function spyOnGrouper(): object
    {
        $spy = new class extends ThemeDayGrouper {
            public int $calls = 0;

            public ?array $lastResult = null;

            public function group(array $sequence, array $dayCapacities, array $origin): ?array
            {
                $this->calls++;

                return $this->lastResult = parent::group($sequence, $dayCapacities, $origin);
            }
        };
        $this->app->instance(ThemeDayGrouper::class, $spy);

        return $spy;
    }

    private function fakeGroq(callable $answer): object
    {
        $fake = new class($answer) extends GroqChatbotClient {
            public int $calls = 0;

            public function __construct(private $answer) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(string $systemPrompt, string $userMessage, int $timeoutSeconds = 8): string
            {
                $this->calls++;

                return ($this->answer)(json_decode($userMessage, true));
            }
        };
        $this->app->instance(GroqChatbotClient::class, $fake);

        return $fake;
    }

    private function reversing(): callable
    {
        return function (array $request): string {
            $ids = array_reverse(array_column($request['candidates'], 'id'));
            $days = [];
            $offset = 0;
            foreach ($request['trip']['stops_per_day'] as $i => $size) {
                $days[] = ['day' => $i + 1, 'theme' => 'Theme '.($i + 1), 'destination_ids' => array_slice($ids, $offset, $size)];
                $offset += $size;
            }

            return json_encode(['days' => $days]);
        };
    }

    private function loggedEvents(): array
    {
        if (! is_file($this->logPath)) {
            return [];
        }
        $events = [];
        foreach (file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/ai_skeleton\.evaluation (\{.*\})\s*(?:\[\])?\s*$/', $line, $m)) {
                $events[] = json_decode($m[1], true);
            }
        }

        return $events;
    }

    /** What the visitor sees of a trip, for comparing two generations. */
    private function signature(Itinerary $itinerary): array
    {
        $itinerary = $itinerary->fresh();

        return [
            'rows' => $itinerary->items()->orderBy('day_number')->orderBy('sort_order')->get()
                ->map(fn ($i) => [$i->day_number, $i->sort_order, $i->kind, $i->destination_id, $i->accommodation_id, $i->title, $i->start_time ?? null, $i->end_time ?? null, $i->distance_km ?? null])->all(),
            'themes' => $itinerary->day_themes,
            'stay_plan' => $itinerary->stay_plan,
        ];
    }

    // ---- ThemeDayGrouper stays active, whatever Groq does ------------------------------------------------------

    public function test_theme_day_grouper_runs_and_records_its_labels_when_the_ai_stage_is_off(): void
    {
        $this->themedCatalogue();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $groq = $this->fakeGroq($this->reversing());
        $spy = $this->spyOnGrouper();

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(0, $groq->calls, 'Groq is off by default');
        $this->assertGreaterThanOrEqual(1, $spy->calls, 'turning Groq off does not turn the embedding grouping off');
        $this->assertNotNull($spy->lastResult, 'every stop had a stored vector, so the grouping ran');
        $themes = $itinerary->fresh()->day_themes;
        $this->assertNotNull($themes);
        $this->assertNotEmpty($themes['days']);
        $this->assertArrayHasKey('summary', $themes);
        $labels = array_filter(array_column($themes['days'], 'label'));
        $this->assertNotEmpty($labels, 'the existing category-based day labels are still produced');
        $this->assertNull($itinerary->fresh()->ml_skeleton_applied);
    }

    public function test_enabling_the_ai_stage_leaves_the_grouper_running_and_the_log_says_so(): void
    {
        $this->themedCatalogue();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true', 'services.groq.model' => 'test-model-x']);
        $groq = $this->fakeGroq($this->reversing());
        $spy = $this->spyOnGrouper();

        app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(1, $groq->calls);
        $this->assertGreaterThanOrEqual(1, $spy->calls);
        $event = $this->loggedEvents()[0];
        $this->assertSame('test-model-x', $event['model']);
        $this->assertIsNumeric($event['call_seconds']);
        $this->assertSame('theme_day_grouper', $event['deterministic_grouping']['engine']);
        $this->assertTrue($event['deterministic_grouping']['ran']);
        $this->assertNull($event['deterministic_grouping']['skipped_for']);
        $this->assertArrayHasKey('reverted_by_guard', $event['deterministic_grouping']);
        $this->assertGreaterThan(0, $event['comparison']['stops_chosen']);
        $this->assertArrayHasKey('travel_km', $event['comparison']);
        $this->assertArrayHasKey('stops_placed', $event['comparison']);
        $this->assertArrayHasKey('feasibility_cost', $event['comparison']);
    }

    public function test_the_log_names_the_embedding_reason_when_the_grouping_could_not_run_for_a_trip_the_ai_was_asked_about(): void
    {
        $this->themedCatalogue();
        DestinationEmbedding::query()->delete(); // no stop has a vector, so the grouping cannot run for any trip
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fakeGroq($this->reversing());
        $this->spyOnGrouper();

        app(ItineraryGenerationService::class)->generate($this->preference());

        $event = $this->loggedEvents()[0] ?? null;
        $this->assertNotNull($event);
        $this->assertNotEmpty($event['deterministic_grouping']['skipped_for']['destination_ids']);
        $this->assertFalse($event['deterministic_grouping']['ran']);
        $this->assertSame('route_order', $event['deterministic_grouping']['engine']);
        $this->assertSame('missing_embeddings', $event['deterministic_grouping']['skipped_for']['reason']);
    }

    // ---- embedding availability / validity -----------------------------------------------------------------------

    public function test_a_stop_without_a_vector_leaves_the_plan_alone_and_says_why(): void
    {
        $a = $this->place('A', [1, 0]);
        $b = $this->place('B', [0, 1]);
        $c = $this->place('C', [0.9, 0.1]);
        $d = $this->place('D', null);
        Log::spy();
        $grouper = new ThemeDayGrouper();

        $this->assertNull($grouper->group($this->sequence([$a, $b, $c, $d]), self::TWO_STOPS_A_DAY, self::ORIGIN));

        $this->assertSame(['reason' => 'missing_embeddings', 'destination_ids' => [$d->id]], $grouper->skipReason());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_unusable_vector_is_treated_like_a_missing_one_not_silently_compared(): void
    {
        $cases = [
            'empty vector' => [],
            'zero vector' => [0, 0],
            'wrong number of dimensions' => [1, 0, 0],
            'not numbers' => ['a', 'b'],
        ];
        foreach ($cases as $label => $bad) {
            DestinationEmbedding::query()->delete();
            Destination::query()->delete();
            $a = $this->place('A', [1, 0]);
            $b = $this->place('B', [0, 1]);
            $c = $this->place('C', [0.9, 0.1]);
            $d = $this->place('D', null);
            $this->embed($d, $bad, normalise: false, row: ['dimensions' => max(1, count($bad))]);
            Log::spy();
            $grouper = new ThemeDayGrouper();

            $this->assertNull($grouper->group($this->sequence([$a, $b, $c, $d]), self::TWO_STOPS_A_DAY, self::ORIGIN), $label);
            $this->assertSame('invalid_embeddings', $grouper->skipReason()['reason'] ?? null, $label);
            $this->assertSame([$d->id], $grouper->skipReason()['destination_ids'], $label);
        }
    }

    public function test_a_clearly_not_unit_length_vector_is_brought_back_to_unit_length_but_rounding_noise_is_left_alone(): void
    {
        $a = $this->place('A', [1, 0]);
        $b = $this->place('B', [0, 1]);
        $c = $this->place('C', [0.9, 0.1]);
        $d = $this->place('D', [50, 50]); // stored un-normalised by mistake
        DestinationEmbedding::where('destination_id', $d->id)->update(['vector' => [50, 50]]);

        $result = (new ThemeDayGrouper())->group($this->sequence([$a, $b, $c, $d]), self::TWO_STOPS_A_DAY, self::ORIGIN);

        $this->assertNotNull($result, 'a vector of the wrong length is usable once normalised');
        foreach ($result['days'] as $day) {
            $this->assertLessThanOrEqual(100, $day['similarity'], 'a longer vector cannot outweigh the others');
        }
    }

    public function test_the_diagnostic_reports_full_coverage_for_plannable_destinations_only(): void
    {
        $this->place('Plain One', [1, 0]);
        $this->place('Plain Two', [0, 1]);
        // Not plannable, so their missing vectors are not a gap: not accredited, archived, role excluded.
        $this->place('Not Accredited', null, extra: ['is_accredited' => false]);
        $this->place('Archived', null, extra: ['archived_at' => now()]);
        $this->place('Excluded Role', null, extra: ['itinerary_role' => 'excluded']);

        $report = app(EmbeddingDiagnostics::class)->run();

        $this->assertSame(2, $report['plannable']['count']);
        $this->assertSame(2, $report['plannable']['with_usable_vector']);
        $this->assertSame(100.0, (float) $report['plannable']['coverage_percent']);
        $this->assertSame([], $report['plannable']['excluded_from_theme_days']);
        $this->assertSame(2, $report['not_plannable']['not_accredited_or_archived']);
        $this->assertSame(1, $report['not_plannable']['itinerary_role_excluded']);
        $this->assertTrue($report['healthy']);
    }

    public function test_the_diagnostic_names_every_kind_of_embedding_problem_and_the_destinations_it_leaves_out(): void
    {
        $this->place('Fine', [1, 0]);
        $missing = $this->place('Missing', null);
        $empty = $this->place('Empty', null);
        $this->embed($empty, [], normalise: false, row: ['dimensions' => 1]);
        $zero = $this->place('Zero', null);
        $this->embed($zero, [0, 0], normalise: false);
        $dims = $this->place('Dims', null);
        $this->embed($dims, [1, 0, 0]);
        $word = $this->place('Word', null);
        $this->embed($word, ['a', 'b'], normalise: false);
        $otherModel = $this->place('Other Model', [0, 1]);
        DestinationEmbedding::where('destination_id', $otherModel->id)->update(['model' => 'some-other-model']);
        $lying = $this->place('Lying', [0.6, 0.8]);
        DestinationEmbedding::where('destination_id', $lying->id)->update(['dimensions' => 99]);

        $report = app(EmbeddingDiagnostics::class)->run();

        $slugsFor = fn (string $code) => array_column($report['problems'][$code] ?? [], 'slug');
        $this->assertContains('missing', $slugsFor('missing'));
        $this->assertContains('empty', $slugsFor('empty'));
        $this->assertContains('zero', $slugsFor('zero_length'));
        $this->assertContains('dims', $slugsFor('wrong_dimensions'));
        $this->assertContains('word', $slugsFor('not_numeric'));
        $this->assertContains('other-model', $slugsFor('other_model'));
        $this->assertContains('lying', $slugsFor('dimensions_field_disagrees_with_vector'));
        $this->assertEqualsCanonicalizing(
            ['missing', 'empty', 'zero', 'dims', 'word'],
            array_column($report['plannable']['excluded_from_theme_days'], 'slug'),
            'exactly the destinations that cannot take part in theme-based days are listed',
        );
        $this->assertLessThan(100, $report['plannable']['coverage_percent']);
        $this->assertFalse($report['healthy']);
    }

    public function test_two_places_with_the_same_vector_are_reported_as_near_identical(): void
    {
        $this->place('Twin A', [1, 0]);
        $this->place('Twin B', [1, 0]);
        $this->place('Other', [0, 1]);

        $report = app(EmbeddingDiagnostics::class)->run();

        $this->assertCount(1, $report['near_identical_pairs']);
        $this->assertSame(['twin-a', 'twin-b'], [$report['near_identical_pairs'][0]['a']['slug'], $report['near_identical_pairs'][0]['b']['slug']]);
    }

    public function test_the_diagnose_command_is_read_only_and_its_exit_code_follows_coverage(): void
    {
        $this->place('Fine', [1, 0]);
        $broken = $this->place('Broken', null);
        $before = DestinationEmbedding::count();

        $code = Artisan::call('embeddings:diagnose', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(1, $code, 'a plannable destination without a vector is a failure');
        $this->assertSame(50.0, (float) $json['plannable']['coverage_percent']);
        $this->assertSame($broken->id, $json['plannable']['excluded_from_theme_days'][0]['id']);
        $this->assertSame($before, DestinationEmbedding::count(), 'nothing was written');

        $this->embed($broken, [0, 1]);
        $this->assertSame(0, Artisan::call('embeddings:diagnose'));
    }

    public function test_vector_problem_codes(): void
    {
        $this->assertNull(DestinationEmbeddingService::vectorProblem([0.6, 0.8], 2));
        $this->assertSame('empty', DestinationEmbeddingService::vectorProblem([]));
        $this->assertSame('empty', DestinationEmbeddingService::vectorProblem(null));
        $this->assertSame('empty', DestinationEmbeddingService::vectorProblem('0.1,0.2'));
        $this->assertSame('not_numeric', DestinationEmbeddingService::vectorProblem([0.1, 'x']));
        $this->assertSame('not_numeric', DestinationEmbeddingService::vectorProblem([0.1, NAN]));
        $this->assertSame('zero_length', DestinationEmbeddingService::vectorProblem([0, 0, 0]));
        $this->assertSame('wrong_dimensions', DestinationEmbeddingService::vectorProblem([1, 0], 3));
    }

    // ---- deterministic grouping, geographic limit, hard constraints ---------------------------------------------

    public function test_grouping_is_repeatable_stays_within_the_distance_limit_and_keeps_every_stop_across_many_random_trips(): void
    {
        mt_srand(77);
        for ($trip = 0; $trip < 15; $trip++) {
            DestinationEmbedding::query()->delete();
            Destination::query()->delete();
            $places = [];
            $count = [4, 5, 6, 7][$trip % 4];
            for ($i = 0; $i < $count; $i++) {
                $vector = [mt_rand(0, 100) / 100, mt_rand(0, 100) / 100, mt_rand(0, 100) / 100, 0.1];
                $places[] = $this->place("Trip {$trip} Place {$i}", $vector, 7.0 + mt_rand(0, 300) / 1000, 125.4 + mt_rand(0, 300) / 1000);
            }
            $capacities = $count > 6 ? [1 => ['A', 'B'], 2 => ['A', 'B'], 3 => ['A', 'B'], 4 => ['A', 'B']] : [1 => ['A', 'B'], 2 => ['A', 'B'], 3 => ['A', 'B']];
            $grouper = new ThemeDayGrouper();

            $first = $grouper->group($this->sequence($places), $capacities, self::ORIGIN);
            $second = (new ThemeDayGrouper())->group($this->sequence($places), $capacities, self::ORIGIN);

            $this->assertNotNull($first);
            $this->assertSame(
                array_map(fn ($e) => $e['row']['destination']->id, $first['sequence']),
                array_map(fn ($e) => $e['row']['destination']->id, $second['sequence']),
                "trip {$trip} grouped differently the second time",
            );
            $this->assertEqualsCanonicalizing(
                array_map(fn (Destination $d) => $d->id, $places),
                array_map(fn ($e) => $e['row']['destination']->id, $first['sequence']),
                'no stop added or dropped',
            );
            $this->assertLessThanOrEqual(
                $first['summary']['distance_before_km'] * (1 + ThemeDayGrouper::MAX_EXTRA_DISTANCE) + ThemeDayGrouper::DISTANCE_ALLOWANCE_KM + 0.05,
                $first['summary']['distance_after_km'],
                "trip {$trip} travels further than the limit allows",
            );
            $this->assertGreaterThanOrEqual($first['summary']['mean_similarity_before'], $first['summary']['mean_similarity_after'], 'a swap is only kept when the days become more alike');
        }
    }

    public function test_the_same_answers_keep_the_same_stays_and_travel_every_time(): void
    {
        $this->themedCatalogue();
        foreach ([['Ma-a Inn', 0.0, 0.01], ['Midway Lodge', 0.05, 0.02]] as [$name, $dLat, $dLng]) {
            $stay = Accommodation::create(['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $this->region->id, 'type' => 'Hotel',
                'is_accredited' => true, 'rating' => 4, 'review_count' => 10, 'price_tier' => 'Mid-range', 'latitude' => 7.0489 + $dLat, 'longitude' => 125.5736 + $dLng]);
            AccreditationRecord::create(['listing_kind' => 'accommodation', 'listing_id' => $stay->id, 'accreditation_number' => 'DOT-T-'.$stay->id,
                'issue_date' => '2024-01-01', 'expiration_date' => '2028-12-31', 'status' => 'Active']);
        }
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $preference = $this->preference();

        $first = $this->signature(app(ItineraryGenerationService::class)->generate($preference));
        for ($run = 2; $run <= 5; $run++) {
            $this->assertSame($first, $this->signature(app(ItineraryGenerationService::class)->generate($preference->fresh()->load('activities', 'amenities'))), "run {$run}");
        }
        $this->assertNotEmpty(array_filter(array_column($first['rows'], 4)), 'the fixture really does include a stay, so consistency is being tested');
    }

    // ---- Edit Preferences / Regenerate and the variation counter -------------------------------------------------

    private function payload(array $override = []): array
    {
        return $override + [
            'travel_days' => 3, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'transport_pref' => 'public', 'public_kind' => 'taxi', 'activities' => ['Wildlife', 'Nature & Adventure'],
        ];
    }

    private function variation(): int
    {
        return (int) TouristPreference::findOrFail(session(TripPlannerController::PREFERENCE_KEY))->variation;
    }

    public function test_regenerate_asks_for_another_variation_and_saving_unchanged_answers_keeps_the_plan_on_screen(): void
    {
        $this->themedCatalogue();
        config(['ai-skeleton.enabled' => null]);

        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
        $this->assertSame(0, $this->variation());

        $this->post('/plan/itinerary/regenerate')->assertRedirect(route('plan.itinerary'));
        $this->post('/plan/itinerary/regenerate')->assertRedirect(route('plan.itinerary'));
        $this->assertSame(2, $this->variation(), 'each Regenerate asks for the next variation');
        $onScreen = $this->signature(Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY)));

        // Edit Preferences, change nothing, Generate: the variation is kept, so the plan on screen is the plan returned.
        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
        $this->assertSame(2, $this->variation());
        $this->assertSame($onScreen, $this->signature(Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY))));
        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
        $this->assertSame($onScreen, $this->signature(Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY))));
    }

    public function test_changing_an_answer_starts_the_new_plan_from_the_standard_result_not_from_an_old_variation(): void
    {
        $this->themedCatalogue();
        config(['ai-skeleton.enabled' => null]);
        $changes = [
            'duration' => ['travel_days' => 2],
            'budget' => ['budget' => 'Premium'],
            'travel type' => ['travel_type' => 'Couple'],
            'interests' => ['activities' => ['Wildlife']],
            'transport' => ['public_kind' => 'jeep'],
        ];

        foreach ($changes as $what => $change) {
            $this->flushSession();
            $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
            $this->post('/plan/itinerary/regenerate');
            $this->post('/plan/itinerary/regenerate');
            $this->assertSame(2, $this->variation());

            $this->post('/plan', $this->payload($change))->assertRedirect(route('plan.itinerary'));

            $this->assertSame(0, $this->variation(), "changing the {$what} drops the old variation");
        }
    }

    public function test_an_edit_after_regenerate_matches_what_a_brand_new_visitor_with_the_same_answers_gets(): void
    {
        $this->themedCatalogue();
        config(['ai-skeleton.enabled' => null]);
        $change = ['budget' => 'Premium', 'travel_type' => 'Couple'];

        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
        $this->post('/plan/itinerary/regenerate');
        $this->post('/plan/itinerary/regenerate');
        $this->post('/plan', $this->payload($change))->assertRedirect(route('plan.itinerary'));
        $edited = $this->signature(Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY)));

        $this->flushSession();
        $this->post('/plan', $this->payload($change))->assertRedirect(route('plan.itinerary'));
        $fresh = $this->signature(Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY)));

        $this->assertSame($fresh, $edited);
    }

    public function test_saving_the_form_never_modifies_a_preference_the_visitor_did_not_change(): void
    {
        $this->themedCatalogue();
        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));
        $id = session(TripPlannerController::PREFERENCE_KEY);
        $before = TouristPreference::findOrFail($id);
        $attributes = $before->getAttributes();
        $activities = $before->activities()->pluck('activity')->sort()->values()->all();

        $this->post('/plan/itinerary/regenerate');
        $this->post('/plan', $this->payload())->assertRedirect(route('plan.itinerary'));

        $after = TouristPreference::findOrFail($id);
        $afterAttributes = $after->getAttributes();
        unset($attributes['variation'], $afterAttributes['variation'], $attributes['updated_at'], $afterAttributes['updated_at']);
        $this->assertSame($attributes, $afterAttributes);
        $this->assertSame($activities, $after->activities()->pluck('activity')->sort()->values()->all());
    }
}
