<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Chatbot\GroqChatbotClient;
use App\Services\Recommendation\AiSkeletonAdvisor;
use App\Services\Recommendation\AiSkeletonEvaluationLog;
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Beta stability: the deterministic planner alone decides the days, the same answers give the same trip, every AI
 * evaluation (when the stage is deliberately switched on) leaves a structured record, and Regenerate says what it does.
 * Every Groq client here is a scripted stand-in; nothing leaves the machine.
 */
class BetaDeterministicItineraryTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-skeleton-test-'.bin2hex(random_bytes(4)).'.log';
        $this->routeLogTo($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    private function routeLogTo(string $path): void
    {
        config(['logging.channels.'.AiSkeletonEvaluationLog::CHANNEL => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
        Log::forgetChannel(AiSkeletonEvaluationLog::CHANNEL);
    }

    /** @return array<int, array<string, mixed>> the JSON context of each evaluation line written so far */
    private function logged(): array
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

    /** A Groq stand-in that counts calls. $answer receives the decoded request and returns the model's text. */
    private function fake(callable $answer, bool $configured = true): object
    {
        $fake = new class($answer, $configured) extends GroqChatbotClient {
            public int $calls = 0;

            public function __construct(private $answer, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
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

    /** A well-formed answer that shuffles the candidates differently on every call, like a model at temperature 0.4. */
    private function shuffling(): callable
    {
        return function (array $request): string {
            $ids = array_column($request['candidates'], 'id');
            shuffle($ids);
            $days = [];
            $offset = 0;
            foreach ($request['trip']['stops_per_day'] as $i => $size) {
                $days[] = ['day' => $i + 1, 'theme' => 'Random theme '.random_int(1, 9999), 'destination_ids' => array_slice($ids, $offset, $size)];
                $offset += $size;
            }

            return json_encode(['days' => $days]);
        };
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

    private function places(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach ([
            ['Zoo', 'Wildlife', 7.12, 125.64], ['Eagle Centre', 'Wildlife', 7.15, 125.41],
            ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Old Church', 'Cultural Heritage', 7.06, 125.60],
            ['Beach One', 'Beach & Leisure', 7.15, 125.71], ['Eden Park', 'Nature & Adventure', 7.03, 125.37],
        ] as $i => [$name, $type, $lat, $lng]) {
            Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 4.5 - $i * 0.1, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }
    }

    private function preference(): TouristPreference
    {
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-12-01', 'arrival_time' => '08:00',
            'origin_lat' => 7.0489, 'origin_lng' => 125.5736, 'origin_label' => 'Secret Street 99',
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        return $preference->load('activities', 'amenities');
    }

    /** What a visitor would see of the trip: every row's day, order, kind, place and wording, plus the day names and stay. */
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

    // ---- Phase 1: AI grouping is off by default --------------------------------------------------------------

    public function test_with_a_key_but_no_flag_the_outside_service_is_never_asked_and_nothing_is_recorded(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $fake = $this->fake($this->shuffling());

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(0, $fake->calls, 'beta default: Groq is not called for day grouping');
        $fresh = $itinerary->fresh();
        $this->assertNull($fresh->ml_skeleton_applied);
        $this->assertNull(data_get($fresh->day_themes, 'ai_skeleton'));
        $this->assertSame([], $this->logged(), 'nothing was evaluated, so nothing is logged');
    }

    public function test_an_empty_or_false_flag_is_also_off_and_only_an_explicit_true_turns_it_on(): void
    {
        $this->fake($this->reversing());
        config(['services.groq.key' => 'test-key']);
        foreach ([null, '', 'false', '0', 'no', 'garbage'] as $value) {
            config(['ai-skeleton.enabled' => $value]);
            $this->assertFalse(app(AiSkeletonAdvisor::class)->enabled(), 'flag '.var_export($value, true));
        }
        foreach (['true', '1', true] as $value) {
            config(['ai-skeleton.enabled' => $value]);
            $this->assertTrue(app(AiSkeletonAdvisor::class)->enabled(), 'flag '.var_export($value, true));
        }
    }

    public function test_the_flag_can_bring_the_ai_grouping_back_without_any_code_change(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $fake = $this->fake($this->reversing());

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(1, $fake->calls);
        $this->assertNotNull($itinerary->fresh()->ml_skeleton_seconds);
        $this->assertCount(1, $this->logged(), 'one evaluation line per generation that asked the model');
    }

    public function test_the_same_answers_give_the_identical_trip_seven_times_even_with_a_key_and_a_shuffling_model_available(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $fake = $this->fake($this->shuffling());
        $preference = $this->preference();

        $first = null;
        for ($run = 1; $run <= 7; $run++) {
            $signature = $this->signature(app(ItineraryGenerationService::class)->generate($preference->fresh()->load('activities', 'amenities')));
            $first ??= $signature;
            $this->assertSame($first, $signature, "run {$run} differs from run 1");
        }
        $this->assertSame(0, $fake->calls);
    }

    public function test_generating_does_not_change_the_saved_preferences(): void
    {
        $this->places();
        $preference = $this->preference();
        $before = $preference->fresh()->getAttributes();
        $activities = $preference->activities()->pluck('activity')->all();

        app(ItineraryGenerationService::class)->generate($preference);
        app(ItineraryGenerationService::class)->generate($preference->fresh()->load('activities', 'amenities'));

        $this->assertSame($before, $preference->fresh()->getAttributes());
        $this->assertSame($activities, $preference->activities()->pluck('activity')->all());
    }

    // ---- the user's flow: generate, reopen the form, change nothing, generate again --------------------------

    private function surveyPayload(): array
    {
        return [
            'travel_days' => 3, 'travel_type' => 'Solo', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'transport_pref' => 'public', 'public_kind' => 'taxi', 'activities' => ['Wildlife', 'Cultural Heritage', 'Beach & Island'],
        ];
    }

    /** The values a browser would submit from the rendered edit form without the visitor touching anything. */
    private function submittedByUntouchedForm(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xp = new DOMXPath($doc);
        $form = $xp->query('//form[contains(@action, "/plan") and not(contains(@action, "itinerary"))]')->item(0);
        $this->assertNotNull($form, 'the preferences form is on the page');

        $out = [];
        foreach ($xp->query('.//input | .//select | .//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $name === '_token') {
                continue;
            }
            $type = strtolower($el->getAttribute('type'));
            if ($el->nodeName === 'select') {
                $selected = $xp->query('.//option[@selected]', $el)->item(0);
                $value = $selected ? $selected->getAttribute('value') : null;
            } elseif ($el->nodeName === 'textarea') {
                $value = $el->textContent;
            } elseif (in_array($type, ['radio', 'checkbox'], true)) {
                if (! $el->hasAttribute('checked')) {
                    continue;
                }
                $value = $el->getAttribute('value');
            } else {
                $value = $el->getAttribute('value');
            }
            if ($value === null) {
                continue;
            }
            if (str_ends_with($name, '[]')) {
                $out[substr($name, 0, -2)][] = $value;
            } else {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public function test_reopening_the_form_and_submitting_it_untouched_gives_the_same_preferences_and_the_same_trip(): void
    {
        $this->places();
        // Worst case for the old behaviour: key present, model free to answer differently every time.
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $this->fake($this->shuffling());

        $this->post('/plan', $this->surveyPayload())->assertRedirect(route('plan.itinerary'));
        $preferenceId = session(TripPlannerController::PREFERENCE_KEY);
        $firstItinerary = Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY));
        $first = $this->signature($firstItinerary);
        $saved = TouristPreference::findOrFail($preferenceId);
        $savedAttributes = $saved->getAttributes();
        $savedActivities = $saved->activities()->pluck('activity')->sort()->values()->all();

        for ($run = 2; $run <= 7; $run++) {
            $form = $this->get('/plan')->assertOk()->getContent();
            $submitted = $this->submittedByUntouchedForm($form);

            // Every value the visitor entered comes back prefilled.
            $this->assertSame('3', (string) $submitted['travel_days']);
            $this->assertSame('Solo', $submitted['travel_type']);
            $this->assertSame('Mid-range', $submitted['budget']);
            $this->assertSame('Any', $submitted['accommodation_pref']);
            $this->assertSame('moderate', $submitted['distance_pref']);
            $this->assertSame('Leisure', $submitted['travel_purpose']);
            $this->assertSame('First-time Visitor', $submitted['visitor_type']);
            $this->assertSame('Cebu City', $submitted['place_of_origin']);
            $this->assertSame('public', $submitted['transport_pref'], 'a taxi trip reopens on the Public Transport card');
            $this->assertSame('taxi', $submitted['public_kind'], 'and on the Taxi choice, not the default Jeepney');
            $this->assertEqualsCanonicalizing($savedActivities, $submitted['activities']);

            $this->post('/plan', $submitted)->assertRedirect(route('plan.itinerary'));
            $itinerary = Itinerary::findOrFail(session(TripPlannerController::ITINERARY_KEY));
            $this->assertSame($first, $this->signature($itinerary), "run {$run} differs from run 1");
        }

        $after = TouristPreference::findOrFail($preferenceId);
        $this->assertSame($savedAttributes, $after->getAttributes(), 're-submitting the untouched form changes no stored answer');
        $this->assertSame($savedActivities, $after->activities()->pluck('activity')->sort()->values()->all());
    }

    // ---- Phase 2: evaluation logging --------------------------------------------------------------------------

    public function test_a_rejected_answer_is_logged_with_its_reason_and_the_fallback_flag_and_no_personal_data(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fake(fn () => 'Sure! Here is a plan for your trip.');

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $events = $this->logged();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('rejected', $event['outcome']);
        $this->assertSame('advisor', $event['decided_by']);
        $this->assertSame(['invalid_answer'], $event['reasons']);
        $this->assertTrue($event['fallback_used']);
        $this->assertSame('not_the_expected_json', $event['validation']['validation_failure']);
        $this->assertFalse($event['validation']['answer_valid']);
        $this->assertSame($itinerary->id, $event['itinerary_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $event['correlation_id']);
        $this->assertNotEmpty($event['evaluated_at']);
        $this->assertNotNull($event['call_seconds']);

        $raw = file_get_contents($this->logPath);
        foreach (['Cebu City', 'Secret Street', '7.0489', '125.5736', '2026-12-01', '08:00', 'Sure! Here is a plan'] as $private) {
            $this->assertStringNotContainsString($private, $raw, "{$private} must not be written to the evaluation log");
        }
    }

    public function test_each_validation_rule_has_its_own_reason_code(): void
    {
        $advisor = app(AiSkeletonAdvisor::class);
        $sizes = [1 => 2, 2 => 1];
        $reason = fn (string $raw, array $ids = [1, 2, 3]) => $advisor->inspect($raw, $ids, $sizes)[1];

        $this->assertSame('not_the_expected_json', $reason('nope'));
        $this->assertSame('wrong_number_of_days', $reason('{"days":[]}'));
        $this->assertSame('day_entry_missing_fields', $reason('{"days":[{"day":1},{"day":2}]}'));
        $this->assertSame('day_entry_wrong_type_or_number', $reason('{"days":[{"day":2,"theme":"a","destination_ids":[1,2]},{"day":1,"theme":"b","destination_ids":[3]}]}'));
        $this->assertSame('wrong_stops_per_day', $reason('{"days":[{"day":1,"theme":"a","destination_ids":[1]},{"day":2,"theme":"b","destination_ids":[2,3]}]}'));
        $this->assertSame('unknown_destination_id', $reason('{"days":[{"day":1,"theme":"a","destination_ids":[1,9]},{"day":2,"theme":"b","destination_ids":[3]}]}'));
        $this->assertSame('repeated_destination_id', $reason('{"days":[{"day":1,"theme":"a","destination_ids":[1,2]},{"day":2,"theme":"b","destination_ids":[1]}]}'));
        $this->assertSame('unusable_day_theme', $reason('{"days":[{"day":1,"theme":"","destination_ids":[1,2]},{"day":2,"theme":"b","destination_ids":[3]}]}'));
        $this->assertSame('candidates_missing', $reason('{"days":[{"day":1,"theme":"a","destination_ids":[1,2]},{"day":2,"theme":"b","destination_ids":[3]}]}', [1, 2, 3, 4]));
        $this->assertNull($reason('{"days":[{"day":1,"theme":"a","destination_ids":[1,2]},{"day":2,"theme":"b","destination_ids":[3]}]}'), 'a valid answer has no failure');
    }

    public function test_an_accepted_suggestion_is_logged_as_accepted_with_the_comparison(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fake($this->reversing());

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $event = $this->logged()[0];
        $applied = (bool) $itinerary->fresh()->ml_skeleton_applied;
        $this->assertSame($applied ? 'accepted' : 'rejected', $event['outcome'], 'the log says exactly what the itinerary column says');
        $this->assertSame(! $applied, $event['fallback_used']);
        $this->assertSame($event['reasons'] === [], $applied);
        $this->assertTrue($event['validation']['answer_valid']);
        $this->assertArrayHasKey('stops_placed', $event['validation']['built_plan'] ?? ['stops_placed' => null]);
    }

    public function test_a_suggestion_the_built_plan_cannot_keep_is_logged_with_the_guards_numbers(): void
    {
        $this->places();
        $preference = $this->preference();
        $preference->update(['distance_pref' => 'far']);
        $preference = $preference->fresh()->load('activities', 'amenities');
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fake($this->reversing());

        // A builder that, like the real one, loses a stop when asked to build a different order than the planner's own.
        $dropping = new class extends ItineraryScheduleBuilder {
            private ?array $first = null;

            public function __construct() {}

            public function build($itinerary, array $sequence, $preference, array $dayCapacities, array $origin, ?array $stay): void
            {
                $ids = array_map(fn ($e) => $e['row']['destination']->id, $sequence);
                $this->first ??= $ids;
                foreach ($sequence as $i => $entry) {
                    if ($ids !== $this->first && $i === count($sequence) - 1) {
                        continue;
                    }
                    ItineraryItem::create([
                        'itinerary_id' => $itinerary->id, 'day_number' => intdiv($i, 2) + 1, 'sort_order' => $i, 'slot' => 'Morning',
                        'kind' => 'activity', 'title' => 'Visit '.$entry['row']['destination']->name,
                        'destination_id' => $entry['row']['destination']->id,
                    ]);
                }
            }
        };
        $service = new ItineraryGenerationService(
            app(ContentBasedRecommendationService::class), app(AprioriService::class), $dropping, new ThemeDayGrouper(), app(AiSkeletonAdvisor::class),
        );

        $itinerary = $service->generate($preference);

        $this->assertFalse($itinerary->fresh()->ml_skeleton_applied);
        $event = $this->logged()[0];
        $this->assertSame('rejected', $event['outcome']);
        $this->assertSame('planner_guard', $event['decided_by']);
        $this->assertContains('fewer_stops_placed', $event['reasons']);
        $this->assertTrue($event['fallback_used']);
        $this->assertLessThan($event['validation']['built_plan']['stops_placed']['planner'], $event['validation']['built_plan']['stops_placed']['ai']);
    }

    public function test_a_log_that_cannot_be_written_never_costs_the_traveller_their_itinerary(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fake(fn () => 'not json');
        // A "folder" that is really a file: the line cannot be written.
        $blocker = tempnam(sys_get_temp_dir(), 'blocker');
        $this->routeLogTo($blocker.DIRECTORY_SEPARATOR.'ai.log');

        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertGreaterThan(0, $itinerary->items()->count());
        @unlink($blocker);
    }

    // ---- Phase 3: Regenerate -----------------------------------------------------------------------------------

    public function test_the_itinerary_page_explains_what_regenerate_does(): void
    {
        $this->places();
        config(['ai-skeleton.enabled' => null]);
        $this->post('/plan', $this->surveyPayload())->assertRedirect(route('plan.itinerary'));

        $html = $this->get('/plan/itinerary')->assertOk()->getContent();
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));

        $this->assertStringContainsString('Regenerate creates a new itinerary variation using the same preferences. Your existing preferences remain unchanged.', $text);
        $this->assertStringContainsString('aria-describedby="regenerate-note"', $html);
        $this->assertStringContainsString('id="regenerate-note"', $html);
    }

    public function test_regenerate_leaves_every_chosen_setting_alone_and_only_moves_the_variation_counter(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => null]);
        $fake = $this->fake($this->shuffling());
        $this->post('/plan', $this->surveyPayload())->assertRedirect(route('plan.itinerary'));
        $preferenceId = session(TripPlannerController::PREFERENCE_KEY);
        $before = TouristPreference::findOrFail($preferenceId);
        $beforeAttributes = $before->getAttributes();
        $beforeActivities = $before->activities()->pluck('activity')->sort()->values()->all();

        $this->post('/plan/itinerary/regenerate')->assertRedirect(route('plan.itinerary'));

        $after = TouristPreference::findOrFail($preferenceId);
        $afterAttributes = $after->getAttributes();
        $this->assertSame((int) $beforeAttributes['variation'] + 1, (int) $afterAttributes['variation']);
        unset($beforeAttributes['variation'], $afterAttributes['variation']);
        $this->assertSame($beforeAttributes, $afterAttributes, 'no interest, transport, budget, duration or other answer changed');
        $this->assertSame($beforeActivities, $after->activities()->pluck('activity')->sort()->values()->all());
        $this->assertSame(0, $fake->calls);
    }
}
