<?php

namespace Tests\Unit;

use App\Services\Recommendation\StayContinuityPlanner;
use PHPUnit\Framework\TestCase;

class StayContinuityPlannerTest extends TestCase
{
    private function candidate(int $id, int $cost): array
    {
        return ['listing' => (object) ['id' => $id, 'rating' => 4], 'cost' => $cost, 'rule' => null];
    }

    public function test_looks_ahead_instead_of_choosing_the_cheapest_first_night(): void
    {
        $plan = (new StayContinuityPlanner)->plan([
            1 => [1 => $this->candidate(1, 30), 2 => $this->candidate(2, 45)],
            2 => [1 => $this->candidate(1, 150), 2 => $this->candidate(2, 30)],
            3 => [1 => $this->candidate(1, 150), 2 => $this->candidate(2, 30)],
        ], 30);
        $this->assertSame([1 => 2, 2 => 2, 3 => 2], array_map(fn ($r) => $r['listing']->id, $plan));
    }

    public function test_strict_continuity_keeps_one_eligible_stay_and_remote_changes_when_worthwhile(): void
    {
        $nights = [1 => [1 => $this->candidate(1, 30), 2 => $this->candidate(2, 150)],
            2 => [1 => $this->candidate(1, 150), 2 => $this->candidate(2, 30)]];
        $planner = new StayContinuityPlanner;
        $strict = $planner->plan($nights, 1000000);
        $this->assertSame($strict[1]['listing']->id, $strict[2]['listing']->id);
        $remote = $planner->plan($nights, 30);
        $this->assertSame([1 => 1, 2 => 2], array_map(fn ($r) => $r['listing']->id, $remote));
    }

    public function test_a_night_without_eligible_hotels_remains_missing(): void
    {
        $plan = (new StayContinuityPlanner)->plan([1 => [1 => $this->candidate(1, 30)], 2 => [], 3 => [2 => $this->candidate(2, 30)]], 30);
        $this->assertNull($plan[2]);
        $this->assertSame(2, $plan[3]['listing']->id);
    }
}
