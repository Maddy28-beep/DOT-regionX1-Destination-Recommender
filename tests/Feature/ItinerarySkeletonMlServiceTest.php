<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use App\Services\Recommendation\ItinerarySkeletonMlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pretrained ML inference step (manuscript Sec. 2.3.4, "Pretrained ML Model").
 *
 * ItinerarySkeletonMlService is where three guarantees are enforced: the
 * closed-candidate-list guarantee ("the model must not invent, drop, or
 * repeat a destination"), the deterministic-repair guarantee (a duplicate or
 * missing id is fixed rather than discarded, whenever it can be), and the
 * always-safe-to-fall-back guarantee. Every test here uses Http::fake() — no
 * real Ollama server is required to run this suite, and phpunit.xml already
 * forces PHI4MINI_URL="" globally so nothing here can accidentally reach a
 * live model. Real-model accuracy is measured separately by
 * `php artisan recommendation:diagnose-ml`, which never runs under phpunit.
 */
class ItinerarySkeletonMlServiceTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config(['services.phi4mini.url' => 'http://localhost:11434', 'services.phi4mini.model' => 'phi4-mini']);
    }

    /** @return array<int, Destination> */
    private function makeDestinations(int $count): array
    {
        $region = Region::create(['name' => 'Davao City']);
        $destinations = [];

        // Tightly clustered on purpose: every test here cares about which
        // day/order a stop lands in, not whether it survives fitsInDay()'s
        // travel-time cutoff, so keeping every stop a few km apart removes
        // that as a variable.
        $coords = [[7.0731, 125.6128], [7.0800, 125.6200], [7.0650, 125.6050], [7.0900, 125.6300], [7.0600, 125.6400]];

        for ($i = 0; $i < $count; $i++) {
            $destinations[] = Destination::create([
                'slug' => "stop-{$i}", 'name' => "Stop {$i}", 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Nature & Leisure',
                'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $coords[$i][0], 'longitude' => $coords[$i][1],
            ]);
        }

        return $destinations;
    }

    /** @return array<int, array{row: array{destination: Destination, drs: float}, distance_km: float|null}> */
    private function sequenceFor(array $destinations): array
    {
        $sequence = [];
        foreach ($destinations as $i => $destination) {
            $sequence[] = ['row' => ['destination' => $destination, 'drs' => 4.0], 'distance_km' => $i === 0 ? 0.0 : 5.0];
        }

        return $sequence;
    }

    private function preference(): TouristPreference
    {
        return TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ]);
    }

    private function fakeOllama(array $decodedResponse): void
    {
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode($decodedResponse)])]);
    }

    // ---- proposeSkeleton(): a fully valid raw response ---------------------

    public function test_a_valid_response_is_accepted_and_grouped_by_day(): void
    {
        $this->configure();
        [$a, $b, $c, $d] = $this->makeDestinations(4);
        $sequence = $this->sequenceFor([$a, $b, $c, $d]);

        $this->fakeOllama([
            'day_1' => ['morning' => $a->id, 'afternoon' => $d->id],
            'day_2' => ['morning' => $b->id, 'afternoon' => $c->id],
        ]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNotNull($skeleton, 'A fully valid, complete response must be accepted.');
        $this->assertSame([$a->id, $d->id], array_column($skeleton[1], 'destination_id'));
        $this->assertSame([$b->id, $c->id], array_column($skeleton[2], 'destination_id'));
    }

    // ---- deterministic repair -----------------------------------------------

    public function test_a_duplicated_id_is_repaired_by_filling_in_the_missing_candidate(): void
    {
        $this->configure();
        [$a, $b, $c, $d] = $this->makeDestinations(4);
        $sequence = $this->sequenceFor([$a, $b, $c, $d]);

        // $a is used twice; $d never appears -- the exact real-world failure
        // mode this feature was built to fix.
        $this->fakeOllama([
            'day_1' => ['morning' => $a->id, 'afternoon' => $b->id],
            'day_2' => ['morning' => $c->id, 'afternoon' => $a->id],
        ]);

        $service = new ItinerarySkeletonMlService();
        $skeleton = $service->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNotNull($skeleton, 'A duplicate/missing pair must be repaired, not discarded.');
        $ids = array_merge(array_column($skeleton[1], 'destination_id'), array_column($skeleton[2], 'destination_id'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], $ids, 'Every candidate must appear exactly once after repair.');
        $this->assertSame($d->id, $skeleton[2][1]['destination_id'], 'The repaired id should take over the exact slot the duplicate occupied.');

        $this->assertNotNull($service->lastDiagnostics);
        $this->assertFalse($service->lastDiagnostics['raw_valid']);
        $this->assertTrue($service->lastDiagnostics['repaired']);
        $this->assertSame([$a->id], $service->lastDiagnostics['duplicate_ids']);
        $this->assertSame([$d->id], $service->lastDiagnostics['missing_ids']);
    }

    public function test_an_unknown_id_is_dropped_and_repaired_with_the_missing_candidate(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        $this->fakeOllama([
            'day_1' => ['morning' => $a->id, 'afternoon' => 999999],
        ]);

        $service = new ItinerarySkeletonMlService();
        $skeleton = $service->proposeSkeleton($sequence, [1 => ['Morning', 'Afternoon']], $this->preference(), null);

        $this->assertNotNull($skeleton, 'An unknown id must be dropped and repaired, not cause a full rejection.');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($skeleton[1], 'destination_id'));
        $this->assertSame([999999], $service->lastDiagnostics['unknown_values']);
        $this->assertTrue($service->lastDiagnostics['repaired']);
    }

    public function test_a_missing_property_is_repaired_with_the_missing_candidate(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        // The model omits "afternoon" entirely.
        $this->fakeOllama(['day_1' => ['morning' => $a->id]]);

        $service = new ItinerarySkeletonMlService();
        $skeleton = $service->proposeSkeleton($sequence, [1 => ['Morning', 'Afternoon']], $this->preference(), null);

        $this->assertNotNull($skeleton);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($skeleton[1], 'destination_id'));
        $this->assertSame([$b->id], $service->lastDiagnostics['missing_ids']);
    }

    public function test_completely_unrelated_json_is_fully_repaired_into_nearest_neighbor_order(): void
    {
        $this->configure();
        [$a, $b, $c] = $this->makeDestinations(3);
        $sequence = $this->sequenceFor([$a, $b, $c]);

        // Nothing in here matches the expected day_N/slot shape at all.
        $this->fakeOllama(['unexpected' => 'shape']);

        $service = new ItinerarySkeletonMlService();
        $skeleton = $service->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning']], $this->preference(), null
        );

        $this->assertNotNull($skeleton, 'Every slot becoming a gap is still repairable -- it degrades to nearest-neighbor order, not a fallback.');
        $ids = array_merge(array_column($skeleton[1], 'destination_id'), array_column($skeleton[2], 'destination_id'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $ids);
        $this->assertSame([$a->id, $b->id, $c->id], $ids, 'With nothing usable from the model, repair should fall back to the original nearest-neighbor order.');
    }

    // ---- day-capacity trimming (buildDaySlotPlan) --------------------------

    public function test_a_short_candidate_list_never_forces_a_fill_beyond_the_candidate_count(): void
    {
        $this->configure();
        // 3 candidates, but day capacities offer room for 4 -- day 2's
        // "afternoon" slot must never be sent to the model or required.
        [$a, $b, $c] = $this->makeDestinations(3);
        $sequence = $this->sequenceFor([$a, $b, $c]);

        $capturedSchema = null;
        Http::fake(function ($request) use (&$capturedSchema, $a, $b, $c) {
            $capturedSchema = $request->data()['format'];

            return Http::response(['response' => json_encode([
                'day_1' => ['morning' => $a->id, 'afternoon' => $b->id],
                'day_2' => ['morning' => $c->id],
            ])]);
        });

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNotNull($skeleton);
        $this->assertArrayNotHasKey('afternoon', $capturedSchema['properties']['day_2']['properties'], 'The schema must not declare a slot beyond the candidate count.');
        $this->assertCount(1, $skeleton[2], 'Day 2 must only receive as many stops as there are leftover candidates.');
    }

    // ---- structured-output schema -------------------------------------------

    public function test_the_schema_constrains_destination_ids_to_the_closed_candidate_set(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        $capturedSchema = null;
        Http::fake(function ($request) use (&$capturedSchema, $a, $b) {
            $capturedSchema = $request->data()['format'];

            return Http::response(['response' => json_encode(['day_1' => ['morning' => $a->id, 'afternoon' => $b->id]])]);
        });

        (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning', 'Afternoon']], $this->preference(), null);

        $this->assertNotNull($capturedSchema);
        $enum = $capturedSchema['properties']['day_1']['properties']['morning']['enum'];
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $enum);
        $this->assertSame(['morning', 'afternoon'], $capturedSchema['properties']['day_1']['required']);
        $this->assertFalse($capturedSchema['properties']['day_1']['additionalProperties']);
    }

    public function test_temperature_is_low_and_configurable(): void
    {
        $this->configure();
        config(['services.phi4mini.temperature' => 0.1]);
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        $capturedOptions = null;
        Http::fake(function ($request) use (&$capturedOptions, $a) {
            $capturedOptions = $request->data()['options'];

            return Http::response(['response' => json_encode(['day_1' => ['morning' => $a->id]])]);
        });

        (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning']], $this->preference(), null);

        $this->assertSame(0.1, $capturedOptions['temperature']);
    }

    // ---- whole-number floats -------------------------------------------------

    /**
     * Some JSON encoders write a schema-valid "integer" as e.g. 1.0 rather
     * than 1. A strict is_int() check used to reject this outright, discarding
     * an otherwise perfectly valid, complete response.
     */
    public function test_whole_number_floats_are_accepted_for_ids(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        $this->fakeOllama(['day_1' => ['morning' => (float) $a->id, 'afternoon' => (float) $b->id]]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning', 'Afternoon']], $this->preference(), null);

        $this->assertNotNull($skeleton, 'A whole-number float must be accepted the same as a genuine integer.');
        $this->assertSame($a->id, $skeleton[1][0]['destination_id']);
        $this->assertIsInt($skeleton[1][0]['destination_id'], 'The stored id must be a genuine int, not a float, for strict downstream comparisons.');
    }

    // ---- transport/parsing failures still fall back safely -------------------

    public function test_an_http_failure_returns_null_rather_than_throwing(): void
    {
        $this->configure();
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        Http::fake(['localhost:11434/*' => Http::response(['error' => 'model not found'], 500)]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning']], $this->preference(), null);

        $this->assertNull($skeleton, 'A non-2xx response must fall back quietly, not surface an error.');
    }

    /** Simulates Ollama not running at all, or the request timing out. */
    public function test_a_connection_failure_returns_null_rather_than_throwing(): void
    {
        $this->configure();
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning']], $this->preference(), null);

        $this->assertNull($skeleton, 'Ollama being unreachable must never bubble up as an exception to the caller.');
    }

    public function test_unparseable_json_returns_null(): void
    {
        $this->configure();
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        // Ollama's own envelope is valid JSON, but the "response" field inside
        // it — the model's actual completion — is not.
        Http::fake(['localhost:11434/*' => Http::response(['response' => 'not valid json {{{'])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton($sequence, [1 => ['Morning']], $this->preference(), null);

        $this->assertNull($skeleton, 'A response field that is not valid JSON must fall back quietly.');
    }

    // ---- End-to-end, through ItineraryGenerationService --------------------

    /**
     * Proves the wiring, not just the service in isolation: a validated
     * skeleton actually changes which day a stop is persisted under, via
     * ItineraryGenerationService's constructor injection and
     * ItineraryScheduleBuilder::reorderBySkeleton().
     */
    public function test_ml_enabled_generation_uses_the_proposed_skeleton(): void
    {
        $this->configure();
        [$a, $b, $c, $d] = $this->makeDestinations(4);

        // Nearest-neighbor order from the default origin is Stop 0..3 in
        // index order (each successively a little further out), which the
        // existing greedy day-filler would naturally chunk as day1=[a,b],
        // day2=[c,d]. The faked skeleton deliberately proposes a different
        // pairing (day1=[a,d], day2=[b,c]) so the assertion can tell whether
        // the model's grouping actually took effect or was ignored.
        $this->fakeOllama([
            'day_1' => ['morning' => $a->id, 'afternoon' => $d->id],
            'day_2' => ['morning' => $b->id, 'afternoon' => $c->id],
        ]);

        $preference = $this->preference()->load('activities', 'amenities');
        $itinerary = app(ItineraryGenerationService::class)->generate($preference);

        $byDay = $itinerary->items()->where('kind', 'activity')->orderBy('day_number')->orderBy('sort_order')->get()
            ->groupBy('day_number')->map(fn ($items) => $items->pluck('destination_id')->all());

        $this->assertEqualsCanonicalizing([$a->id, $d->id], $byDay[1] ?? [],
            'Day 1 must contain exactly the stops the ML skeleton assigned to it.');
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $byDay[2] ?? [],
            'Day 2 must contain exactly the stops the ML skeleton assigned to it.');
    }

    /**
     * The backward-compatibility guarantee: with the ML step unconfigured —
     * the state every test outside this file already runs in, via
     * phpunit.xml's global PHI4MINI_URL="" — itinerary generation must behave
     * exactly as it did before this feature existed, and must never attempt
     * a network call at all.
     */
    public function test_generation_falls_back_to_nearest_neighbor_order_when_ml_is_unconfigured(): void
    {
        config(['services.phi4mini.url' => '']);
        Http::fake();

        [$a, $b] = $this->makeDestinations(2);
        $preference = TouristPreference::create([
            'travel_days' => 1, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
        ])->load('activities', 'amenities');

        $itinerary = app(ItineraryGenerationService::class)->generate($preference);

        $this->assertGreaterThan(0, $itinerary->items()->where('kind', 'activity')->count(),
            'The itinerary must still generate successfully with the ML step unconfigured.');
        Http::assertNothingSent();
    }
}
