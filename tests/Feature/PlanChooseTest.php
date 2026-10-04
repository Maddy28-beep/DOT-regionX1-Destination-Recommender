<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "How would you like to plan your trip?" page.
 */
class PlanChooseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_offers_both_paths_with_what_each_gives(): void
    {
        $this->get(route('plan.choose'))
            ->assertOk()
            ->assertSee('Personalized Itinerary')
            ->assertSee('Tour Packages')
            ->assertSee('About 2 minutes')
            ->assertSee('3 short steps')
            ->assertSee('Best if')
            ->assertSee(route('plan.edit'), false)
            ->assertSee(route('packages.index'), false);
    }

    public function test_it_marks_one_path_as_recommended_and_helps_the_undecided(): void
    {
        $this->get(route('plan.choose'))
            ->assertOk()
            ->assertSee('Recommended')
            ->assertSee('Not sure which to pick?')
            ->assertSee('I\'m just exploring', false);
    }

    public function test_it_works_without_an_account(): void
    {
        $this->get(route('plan.choose'))->assertOk()->assertSee('No account needed');
    }
}
