<?php

namespace Tests\Feature;

use App\Services\Recommendation\StayPlanEvaluator;
use Tests\TestCase;

/**
 * When is a rebuilt itinerary good enough to replace the first one? Hard constraints reject it whatever else it gains;
 * everything that can trade off is counted in minutes and added up, and the rebuild has to beat the first build by the
 * tolerance (15 minutes, the step the travel estimates are rounded to).
 */
class StayPlanEvaluatorTest extends TestCase
{
    private function evaluator(): StayPlanEvaluator
    {
        config(['itinerary.stays.transfer_penalty_minutes.far' => 30, 'itinerary.stays.acceptance_tolerance_minutes' => 15]);

        return app(StayPlanEvaluator::class);
    }

    /** @return array<string, mixed> the metrics of an itinerary that places stops 1-4, with nothing wrong, 400 minutes of stay journeys */
    private function m(array $over = []): array
    {
        return $over + [
            'placed' => [1, 2, 3, 4], 'empty' => 0, 'drive' => [1 => 200, 2 => 200], 'excess' => 0, 'lateCount' => 0, 'lateMinutes' => 0,
            'transfers' => 0, 'stays' => [1 => 10], 'journey' => 400, 'violations' => 0,
        ];
    }

    // ---- hard constraints: any one rejects the rebuild -------------------------------------------------

    public function test_a_rule_broken_by_the_rebuild_rejects_it_even_if_it_is_much_shorter(): void
    {
        [$ok, $why] = $this->evaluator()->accepts($this->m(), $this->m(['violations' => 1, 'journey' => 100]), 'far');

        $this->assertFalse($ok);
        $this->assertStringStartsWith('H1', $why);
    }

    public function test_a_lost_destination_rejects_the_rebuild(): void
    {
        [$ok, $why] = $this->evaluator()->accepts($this->m(), $this->m(['placed' => [1, 2, 3], 'journey' => 100]), 'far');

        $this->assertFalse($ok);
        $this->assertStringStartsWith('H2', $why);
    }

    public function test_a_gained_destination_is_not_a_loss(): void
    {
        [$ok] = $this->evaluator()->accepts($this->m(), $this->m(['placed' => [1, 2, 3, 4, 5], 'journey' => 300]), 'far');

        $this->assertTrue($ok);
    }

    public function test_an_extra_empty_day_rejects_the_rebuild(): void
    {
        [$ok, $why] = $this->evaluator()->accepts($this->m(), $this->m(['empty' => 1, 'journey' => 100]), 'far');

        $this->assertFalse($ok);
        $this->assertStringStartsWith('H3', $why);
    }

    public function test_a_strict_preference_never_accepts_more_changes_of_stay(): void
    {
        $e = $this->evaluator();

        [$ok, $why] = $e->accepts($this->m(), $this->m(['transfers' => 1, 'journey' => 100]), 'near');
        $this->assertFalse($ok);
        $this->assertStringStartsWith('H4', $why);

        [$ok] = $e->accepts($this->m(), $this->m(['transfers' => 1, 'journey' => 100]), 'far');
        $this->assertTrue($ok, 'Remote may change stay when it pays');
    }

    // ---- the one optimisation criterion ------------------------------------------------------------------

    public function test_a_tie_keeps_the_plan_that_already_exists(): void
    {
        [$ok, $why] = $this->evaluator()->accepts($this->m(), $this->m(), 'far');

        $this->assertFalse($ok);
        $this->assertStringStartsWith('H5', $why);
    }

    public function test_the_rebuild_must_be_better_by_the_tolerance(): void
    {
        $e = $this->evaluator();

        [$almost] = $e->accepts($this->m(), $this->m(['journey' => 400 - 14]), 'far');
        [$enough] = $e->accepts($this->m(), $this->m(['journey' => 400 - 15]), 'far');

        $this->assertFalse($almost, '14 minutes is rounding noise');
        $this->assertTrue($enough);
    }

    public function test_a_small_cost_does_not_sink_a_clearly_better_plan(): void
    {
        // 200 minutes less travelling to the stays against 60 more minutes of driving over the limit and one late check-in of 45 minutes.
        [$ok] = $this->evaluator()->accepts($this->m(), $this->m(['journey' => 200, 'excess' => 60, 'lateCount' => 1, 'lateMinutes' => 45]), 'far');

        $this->assertTrue($ok);
    }

    public function test_the_same_stops_with_worse_driving_or_lateness_is_not_accepted(): void
    {
        $e = $this->evaluator();

        [$driving] = $e->accepts($this->m(), $this->m(['excess' => 120]), 'far');
        [$late] = $e->accepts($this->m(), $this->m(['lateCount' => 1, 'lateMinutes' => 90]), 'far');
        [$stays] = $e->accepts($this->m(['transfers' => 0]), $this->m(['transfers' => 1, 'journey' => 390]), 'far');

        $this->assertFalse($driving);
        $this->assertFalse($late);
        $this->assertFalse($stays, 'a change of stay costs the penalty: 10 minutes saved is not worth 30');
    }

    public function test_the_cost_adds_journeys_changes_driving_over_the_limit_and_lateness_in_minutes(): void
    {
        $e = $this->evaluator();

        $this->assertSame(400 + 2 * 30 + 45 + 30, $e->cost($this->m(['transfers' => 2, 'excess' => 45, 'lateMinutes' => 30])));

        config(['itinerary.stays.transfer_penalty_minutes.far' => 90]);
        $this->assertSame(400 + 2 * 90, $e->cost($this->m(['transfers' => 2])), 'the penalty is read from configuration');
    }

    // ---- Commutable: when is keeping one stay impractical? ----------------------------------------------

    public function test_one_stay_is_kept_when_the_flexible_plan_only_saves_travelling(): void
    {
        // Flexible drives 300 minutes less to the stays but places the same stops, with no extra lateness or driving.
        [$impractical] = $this->evaluator()->oneStayIsImpractical($this->m(), $this->m(['journey' => 100, 'transfers' => 1]));

        $this->assertFalse($impractical);
    }

    public function test_one_stay_is_impractical_when_it_misses_a_destination_the_flexible_plan_places(): void
    {
        [$impractical, $why] = $this->evaluator()->oneStayIsImpractical($this->m(['placed' => [1, 2, 3]]), $this->m());

        $this->assertTrue($impractical);
        $this->assertStringContainsString('misses a destination', $why);
    }

    public function test_one_stay_is_impractical_when_it_leaves_an_extra_empty_day_or_adds_a_late_check_in(): void
    {
        $e = $this->evaluator();

        $this->assertTrue($e->oneStayIsImpractical($this->m(['empty' => 1]), $this->m())[0]);
        $this->assertTrue($e->oneStayIsImpractical($this->m(['lateCount' => 1]), $this->m())[0]);
        $this->assertTrue($e->oneStayIsImpractical($this->m(['violations' => 1]), $this->m())[0]);
    }

    public function test_one_stay_is_impractical_only_when_driving_over_the_limit_is_worse_by_more_than_the_tolerance(): void
    {
        $e = $this->evaluator();

        $this->assertFalse($e->oneStayIsImpractical($this->m(['excess' => 15]), $this->m())[0], 'exactly the tolerance is not worse');
        $this->assertTrue($e->oneStayIsImpractical($this->m(['excess' => 16]), $this->m())[0]);
    }
}
