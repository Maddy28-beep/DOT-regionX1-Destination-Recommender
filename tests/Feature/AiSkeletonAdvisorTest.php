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
use App\Services\Recommendation\AprioriService;
use App\Services\Recommendation\ContentBasedRecommendationService;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use App\Services\Recommendation\ThemeDayGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The AI-assisted skeleton stage is advice only. Every test here uses a fake Groq client; nothing leaves the machine.
 */
class AiSkeletonAdvisorTest extends TestCase
{
    use RefreshDatabase;

    /** Scripted stand-in for the Groq client. $answer receives the decoded request and returns the model's text. */
    private function fake(callable $answer, bool $configured = true): object
    {
        $fake = new class($answer, $configured) extends GroqChatbotClient {
            public int $calls = 0;

            public array $requests = [];

            public ?int $timeout = null;

            public function __construct(private $answer, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function complete(string $systemPrompt, string $userMessage, int $timeoutSeconds = 8): string
            {
                $this->calls++;
                $this->timeout = $timeoutSeconds;
                $this->requests[] = $userMessage;

                return ($this->answer)(json_decode($userMessage, true));
            }
        };
        $this->app->instance(GroqChatbotClient::class, $fake);

        return $fake;
    }

    /** A well-formed answer that puts the candidates into the day sizes in reverse order. */
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

    private function signature(Itinerary $itinerary): array
    {
        return $itinerary->items()->orderBy('day_number')->orderBy('sort_order')->get()
            ->map(fn ($i) => [$i->day_number, $i->sort_order, $i->kind, $i->destination_id, $i->title])->all();
    }

    /** What the planner makes with the stage switched off. */
    private function baseline(TouristPreference $preference): array
    {
        config(['ai-skeleton.enabled' => 'false']);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference);
        $signature = $this->signature($itinerary);
        $itinerary->delete();
        config(['ai-skeleton.enabled' => null]);

        return $signature;
    }

    public function test_it_is_off_unless_the_flag_is_true_and_there_is_a_key(): void
    {
        $fake = $this->fake($this->reversing());
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'false']);
        $this->assertFalse(app(AiSkeletonAdvisor::class)->enabled());
        config(['ai-skeleton.enabled' => null]);
        $this->assertFalse(app(AiSkeletonAdvisor::class)->enabled(), 'beta default: unset flag + key = off');
        config(['ai-skeleton.enabled' => 'true']);
        $this->assertTrue(app(AiSkeletonAdvisor::class)->enabled(), 'explicitly true + key = on');

        $this->fake($this->reversing(), configured: false);
        config(['ai-skeleton.enabled' => 'true']);
        $this->assertFalse(app(AiSkeletonAdvisor::class)->enabled(), 'never on without a key');

        $this->places();
        $fake = $this->fake($this->reversing());
        config(['ai-skeleton.enabled' => 'false']);
        $itinerary = app(ItineraryGenerationService::class)->generate($this->preference());
        $this->assertSame(0, $fake->calls);
        $this->assertNull($itinerary->fresh()->ml_skeleton_applied);
        $this->assertNull(data_get($itinerary->fresh()->day_themes, 'ai_skeleton'));
    }

    public function test_the_request_carries_only_non_personal_trip_facts(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true', 'ai-skeleton.timeout' => 5]);
        $fake = $this->fake($this->reversing());

        app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(1, $fake->calls);
        $this->assertSame(5, $fake->timeout, 'the configured timeout is passed to the client');
        $request = $fake->requests[0];
        foreach (['Cebu City', 'Secret Street', '7.0489', '125.5736', '2026-12-01', '08:00', 'Couple'] as $private) {
            $this->assertStringNotContainsString($private, $request);
        }
        $decoded = json_decode($request, true);
        $this->assertSame(['trip', 'candidates'], array_keys($decoded));
        $this->assertSame(['id', 'name', 'type', 'price_tier', 'tags'], array_keys($decoded['candidates'][0]));
        $this->assertSame(['Wildlife'], $decoded['trip']['interests']);
    }

    public function test_a_valid_answer_can_only_rearrange_the_planners_own_stops_and_the_checks_still_hold(): void
    {
        $this->places();
        $preference = $this->preference();
        $baseline = $this->baseline($preference);
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $fake = $this->fake($this->reversing());

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);

        $this->assertSame(1, $fake->calls);
        $baselineIds = collect($baseline)->pluck(3)->filter()->unique();
        $aiIds = $itinerary->items()->whereNotNull('destination_id')->pluck('destination_id')->unique();
        $this->assertSame([], $aiIds->diff($baselineIds)->all(), 'no destination the planner had not already placed');
        $this->assertGreaterThanOrEqual($baselineIds->count(), $aiIds->count());

        $fresh = $itinerary->fresh();
        $applied = data_get($fresh->day_themes, 'ai_skeleton.applied') === true;
        $this->assertSame($applied, $fresh->ml_skeleton_applied, 'the column says exactly what the page will say');
        $this->assertNotNull($fresh->ml_skeleton_seconds);
        if (! $applied) {
            $this->assertSame($baseline, $this->signature($itinerary), 'an advisory that was not kept leaves the plan identical');
        }
    }

    public function test_a_fitting_answer_is_kept_labelled_and_shown_on_the_page(): void
    {
        $this->places();
        $preference = $this->preference();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);

        // Reverse the planner's grouping: different days, same stops, still within the travelling limit in this fixture.
        $this->fake(function (array $request): string {
            $ids = array_column($request['candidates'], 'id');
            $sizes = $request['trip']['stops_per_day'];
            $order = array_reverse($ids);
            $days = [];
            $offset = 0;
            foreach ($sizes as $i => $size) {
                $days[] = ['day' => $i + 1, 'theme' => '<b>Day theme '.($i + 1).'</b>', 'destination_ids' => array_slice($order, $offset, $size)];
                $offset += $size;
            }

            return "```json\n".json_encode(['days' => $days])."\n```";
        });

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);
        $fresh = $itinerary->fresh();

        $this->assertTrue(data_get($fresh->day_themes, 'ai_skeleton.applied'), 'this fixture is built so the suggestion fits and is kept');

        $this->assertTrue($fresh->ml_skeleton_applied);
        $this->assertSame('Day theme 1', $fresh->day_themes['days'][1]['label'], 'markup stripped from the theme');
        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();
        $this->assertStringContainsString('Day themes suggested with AI assistance', $html);
        $this->assertStringContainsString('Day theme 1', $html);
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function badAnswers(): array
    {
        $with = fn (callable $mutate) => function (array $request) use ($mutate): string {
            $ids = array_column($request['candidates'], 'id');
            $days = [];
            $offset = 0;
            foreach ($request['trip']['stops_per_day'] as $i => $size) {
                $days[] = ['day' => $i + 1, 'theme' => 'Theme', 'destination_ids' => array_slice(array_reverse($ids), $offset, $size)];
                $offset += $size;
            }

            return $mutate($days);
        };

        return [
            'not json' => [fn () => 'Sure! Here is a plan for your trip.'],
            'empty days' => [fn () => '{"days":[]}'],
            'unknown id' => [$with(function (array $days) {
                $days[0]['destination_ids'][0] = 999999;

                return json_encode(['days' => $days]);
            })],
            'duplicate id' => [$with(function (array $days) {
                $days[1]['destination_ids'][0] = $days[0]['destination_ids'][0];

                return json_encode(['days' => $days]);
            })],
            'missing day' => [$with(fn (array $days) => json_encode(['days' => array_slice($days, 0, 2)]))],
            'wrong day size' => [$with(function (array $days) {
                $moved = array_pop($days[1]['destination_ids']);
                $days[0]['destination_ids'][] = $moved;

                return json_encode(['days' => $days]);
            })],
            'ids as strings' => [$with(function (array $days) {
                $days[0]['destination_ids'] = array_map('strval', $days[0]['destination_ids']);

                return json_encode(['days' => $days]);
            })],
            'theme too long' => [$with(function (array $days) {
                $days[0]['theme'] = str_repeat('x', 200);

                return json_encode(['days' => $days]);
            })],
            'extra top level text' => [$with(fn (array $days) => 'Here you go: '.json_encode(['days' => $days]))],
        ];
    }

    /** @dataProvider badAnswers */
    public function test_an_invalid_answer_gives_exactly_the_planners_own_plan(callable $answer): void
    {
        $this->places();
        $preference = $this->preference();
        $baseline = $this->baseline($preference);
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $fake = $this->fake($answer);

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);

        $this->assertSame(1, $fake->calls);
        $this->assertSame($baseline, $this->signature($itinerary));
        $fresh = $itinerary->fresh();
        $this->assertFalse($fresh->ml_skeleton_applied);
        $this->assertNull(data_get($fresh->day_themes, 'ai_skeleton'));
    }

    public function test_a_timeout_or_failure_gives_exactly_the_planners_own_plan_and_logs_no_prompt(): void
    {
        $this->places();
        $preference = $this->preference();
        $baseline = $this->baseline($preference);
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);

        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $this->fake(function () {
            throw new RuntimeException('cURL error 28: Operation timed out ... Wildlife Zoo Eagle Centre');
        });

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);

        $this->assertSame($baseline, $this->signature($itinerary));
        $this->assertFalse($itinerary->fresh()->ml_skeleton_applied);
        $this->assertNull(data_get($itinerary->fresh()->day_themes, 'ai_skeleton'));
        $line = implode("\n", $logged);
        $this->assertStringContainsString('AI skeleton call failed', $line);
        $this->assertStringNotContainsString('Eagle Centre', $line);
        $this->assertStringNotContainsString('Zoo', $line);
    }

    public function test_a_trip_with_too_many_stops_is_not_sent(): void
    {
        $this->places();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true', 'ai-skeleton.max_candidates' => 2]);
        $fake = $this->fake($this->reversing());

        app(ItineraryGenerationService::class)->generate($this->preference());

        $this->assertSame(0, $fake->calls);
    }

    public function test_the_validator_accepts_fenced_json_and_rejects_everything_else_partial(): void
    {
        $advisor = app(AiSkeletonAdvisor::class);
        $sizes = [1 => 2, 2 => 1];

        $ok = $advisor->validate("```json\n{\"days\":[{\"day\":1,\"theme\":\"Wild  side\",\"destination_ids\":[3,1]},{\"day\":2,\"theme\":\"Town\",\"destination_ids\":[2]}]}\n```", [1, 2, 3], $sizes);
        $this->assertSame([1 => ['theme' => 'Wild side', 'stops' => [3, 1]], 2 => ['theme' => 'Town', 'stops' => [2]]], $ok);

        $this->assertNull($advisor->validate('{"days":[{"day":1,"theme":"a","destination_ids":[3,1]},{"day":2,"theme":"b","destination_ids":[2]}], "x": 1}extra', [1, 2, 3], $sizes));
        $this->assertNull($advisor->validate('{"days":[{"day":2,"theme":"a","destination_ids":[3,1]},{"day":1,"theme":"b","destination_ids":[2]}]}', [1, 2, 3], $sizes), 'days out of order');
        $this->assertNull($advisor->validate('{"days":[{"day":1,"theme":"a","destination_ids":[3,1]},{"day":2,"theme":"","destination_ids":[2]}]}', [1, 2, 3], $sizes), 'empty theme');
        $this->assertNull($advisor->validate('{"days":[{"day":1,"theme":"a","destination_ids":[3,1]},{"day":2,"theme":"b","destination_ids":[2]}]}', [1, 2, 3, 4], $sizes), 'a candidate left out');
    }

    /** A stand-in schedule builder that, like the real one, drops a stop when it cannot fit a day. */
    private function droppingBuilder(): ItineraryScheduleBuilder
    {
        return new class extends ItineraryScheduleBuilder {
            private ?array $first = null;

            public function __construct() {}

            public function build($itinerary, array $sequence, $preference, array $dayCapacities, array $origin, ?array $stay): void
            {
                // The first order built is the planner's own; any different order loses its last stop, like a day that cannot finish.
                $ids = array_map(fn ($e) => $e['row']['destination']->id, $sequence);
                $this->first ??= $ids;
                $reversedish = $ids !== $this->first;
                foreach ($sequence as $i => $entry) {
                    if ($reversedish && $i === count($sequence) - 1) {
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
    }

    public function test_a_suggestion_whose_built_plan_loses_a_stop_is_undone_row_for_row(): void
    {
        $this->places();
        $preference = $this->preference();
        $preference->update(['distance_pref' => 'far']);
        $preference = $preference->fresh()->load('activities', 'amenities');
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'false']);

        $make = fn () => new ItineraryGenerationService(
            app(ContentBasedRecommendationService::class), app(AprioriService::class), $this->droppingBuilder(), new ThemeDayGrouper(),
            app(AiSkeletonAdvisor::class),
        );
        $baseline = $this->signature($make()->generate($preference));

        config(['ai-skeleton.enabled' => 'true']);
        $fake = $this->fake($this->reversing());
        $itinerary = $make()->generate($preference);

        $this->assertSame(1, $fake->calls);
        // Same rows as the planner's own plan (ids differ, content does not).
        $this->assertSame($baseline, $this->signature($itinerary));
        $this->assertFalse($itinerary->fresh()->ml_skeleton_applied);
        $this->assertNull(data_get($itinerary->fresh()->day_themes, 'ai_skeleton'));
    }

    public function test_the_page_says_nothing_about_ai_when_it_fell_back(): void
    {
        $this->places();
        $preference = $this->preference();
        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'true']);
        $this->fake(fn () => 'not json');

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);
        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();

        $this->assertStringNotContainsString('AI assistance', $html);
        $this->assertStringNotContainsString('AI-assisted days', $html);
    }

    public function test_an_old_itinerary_with_a_stored_ml_flag_does_not_get_the_label(): void
    {
        // Itineraries from the retired Phi-4-mini step may carry ml_skeleton_applied = true; the label reads only day_themes.
        $this->places();
        $preference = $this->preference();
        config(['ai-skeleton.enabled' => 'false']);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference);
        $itinerary->update(['ml_skeleton_applied' => true]);

        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();

        $this->assertStringNotContainsString('AI assistance', $html);
    }

    public function test_the_privacy_policy_discloses_groq_for_trip_parameters_only_when_the_stage_is_on(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $text = fn () => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->get(route('legal.privacy'))->assertOk()->getContent()), ENT_QUOTES)));

        config(['services.groq.key' => null, 'ai-skeleton.enabled' => null]);
        $this->assertStringNotContainsString('AI-assisted day plan', $text());

        config(['services.groq.key' => 'test-key', 'ai-skeleton.enabled' => 'false']);
        $this->assertStringNotContainsString('AI-assisted day plan', $text());

        config(['ai-skeleton.enabled' => null]);
        $this->fake(fn () => '', configured: true);
        $this->assertStringNotContainsString('AI-assisted day plan', $text(), 'unset flag = off, so no disclosure either');

        config(['ai-skeleton.enabled' => 'true']);
        $on = $text();
        $this->assertStringContainsString('AI-assisted day plan (Groq). When you generate an itinerary, ExploreDVO\'s server sends Groq, an outside AI service whose servers are outside the Philippines, a description of the trip so that it can suggest which of the places the planner has already chosen go together on each day and name each day. It receives the number of days, the distance range, budget level and interests you chose, and, for each candidate place, its name, type, price level and category tags. It does not receive your name, browser token, starting point, dates, arrival time or anything you typed.', $on);
        $this->assertStringContainsString('if Groq does not answer in time or answers badly, the itinerary is made without it.', $on);
        $this->assertStringContainsString('Last updated November 5, 2026', $on);
        Carbon::setTestNow();
    }
}
