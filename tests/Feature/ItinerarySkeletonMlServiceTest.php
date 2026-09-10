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
 * ItinerarySkeletonMlService is the one place the closed-candidate-list
 * guarantee ("the model must not invent a destination") and the
 * always-safe-to-fall-back guarantee are actually enforced. Every test here
 * uses Http::fake() -- no real Ollama server is required to run this suite,
 * and phpunit.xml already forces PHI4MINI_URL="" globally so nothing here
 * can accidentally reach a live model.
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
        $coords = [[7.0731, 125.6128], [7.0800, 125.6200], [7.0650, 125.6050], [7.0900, 125.6300]];

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

    // ---- proposeSkeleton() / validate() -----------------------------------

    public function test_a_valid_response_is_accepted_and_grouped_by_day(): void
    {
        $this->configure();
        [$a, $b, $c, $d] = $this->makeDestinations(4);
        $sequence = $this->sequenceFor([$a, $b, $c, $d]);

        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [
                    ['destination_id' => $a->id, 'slot' => 'Morning'],
                    ['destination_id' => $d->id, 'slot' => 'Afternoon'],
                ]],
                ['day_number' => 2, 'stops' => [
                    ['destination_id' => $b->id, 'slot' => 'Morning'],
                    ['destination_id' => $c->id, 'slot' => 'Afternoon'],
                ]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNotNull($skeleton, 'A fully valid, complete response must be accepted.');
        $this->assertSame([$a->id, $d->id], array_column($skeleton[1], 'destination_id'));
        $this->assertSame([$b->id, $c->id], array_column($skeleton[2], 'destination_id'));
    }

    public function test_a_response_missing_a_candidate_is_rejected(): void
    {
        $this->configure();
        [$a, $b, $c] = $this->makeDestinations(3);
        $sequence = $this->sequenceFor([$a, $b, $c]);

        // $c never appears anywhere in the response.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [['destination_id' => $a->id, 'slot' => 'Morning']]],
                ['day_number' => 2, 'stops' => [['destination_id' => $b->id, 'slot' => 'Morning']]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNull($skeleton, 'Dropping a candidate must discard the whole response, not just that stop.');
    }

    public function test_a_response_with_a_duplicate_candidate_is_rejected(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        // $a is assigned to both days; $b never appears.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [['destination_id' => $a->id, 'slot' => 'Morning']]],
                ['day_number' => 2, 'stops' => [['destination_id' => $a->id, 'slot' => 'Morning']]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNull($skeleton, 'A repeated destination_id must discard the whole response.');
    }

    public function test_a_response_inventing_a_destination_is_rejected(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [
                    ['destination_id' => $a->id, 'slot' => 'Morning'],
                    // An id that was never in the candidate list at all.
                    ['destination_id' => 999999, 'slot' => 'Afternoon'],
                ]],
                ['day_number' => 2, 'stops' => [['destination_id' => $b->id, 'slot' => 'Morning']]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNull($skeleton, 'An id outside the candidate list must never be accepted — this is what stops the model inventing a destination.');
    }

    public function test_a_response_with_an_out_of_range_day_number_is_rejected(): void
    {
        $this->configure();
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        // Only one day was requested; the response invents day 2.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 2, 'stops' => [['destination_id' => $a->id, 'slot' => 'Morning']]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNull($skeleton, 'A day_number beyond total_days must be rejected.');
    }

    public function test_a_response_exceeding_day_capacity_is_rejected(): void
    {
        $this->configure();
        [$a, $b, $c] = $this->makeDestinations(3);
        $sequence = $this->sequenceFor([$a, $b, $c]);

        // Day 1 only has one slot, but the response packs three stops into it.
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [
                    ['destination_id' => $a->id, 'slot' => 'Morning'],
                    ['destination_id' => $b->id, 'slot' => 'Morning'],
                    ['destination_id' => $c->id, 'slot' => 'Morning'],
                ]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning']], $this->preference(), null
        );

        $this->assertNull($skeleton, 'A day must never receive more stops than it has slots for.');
    }

    /**
     * Some JSON encoders write a schema-valid "integer" as e.g. 1.0 rather
     * than 1. A strict is_int() check used to reject this outright, discarding
     * an otherwise perfectly valid, complete response.
     */
    public function test_whole_number_floats_are_accepted_for_ids_and_day_numbers(): void
    {
        $this->configure();
        [$a, $b] = $this->makeDestinations(2);
        $sequence = $this->sequenceFor([$a, $b]);

        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1.0, 'stops' => [['destination_id' => (float) $a->id, 'slot' => 'Morning']]],
                ['day_number' => 2.0, 'stops' => [['destination_id' => (float) $b->id, 'slot' => 'Morning']]],
            ],
        ])])]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning', 'Afternoon'], 2 => ['Morning', 'Afternoon']], $this->preference(), null
        );

        $this->assertNotNull($skeleton, 'A whole-number float must be accepted the same as a genuine integer.');
        $this->assertSame($a->id, $skeleton[1][0]['destination_id']);
        $this->assertIsInt($skeleton[1][0]['destination_id'], 'The stored id must be a genuine int, not a float, for strict downstream comparisons.');
    }

    public function test_an_http_failure_returns_null_rather_than_throwing(): void
    {
        $this->configure();
        [$a] = $this->makeDestinations(1);
        $sequence = $this->sequenceFor([$a]);

        Http::fake(['localhost:11434/*' => Http::response(['error' => 'model not found'], 500)]);

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning']], $this->preference(), null
        );

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

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning']], $this->preference(), null
        );

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

        $skeleton = (new ItinerarySkeletonMlService())->proposeSkeleton(
            $sequence, [1 => ['Morning']], $this->preference(), null
        );

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
        Http::fake(['localhost:11434/*' => Http::response(['response' => json_encode([
            'days' => [
                ['day_number' => 1, 'stops' => [
                    ['destination_id' => $a->id, 'slot' => 'Morning'],
                    ['destination_id' => $d->id, 'slot' => 'Afternoon'],
                ]],
                ['day_number' => 2, 'stops' => [
                    ['destination_id' => $b->id, 'slot' => 'Morning'],
                    ['destination_id' => $c->id, 'slot' => 'Afternoon'],
                ]],
            ],
        ])])]);

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
