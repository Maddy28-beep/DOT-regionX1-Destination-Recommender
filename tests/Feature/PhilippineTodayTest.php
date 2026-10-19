<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\Event;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The server runs on UTC, so until 8 AM in Davao its "today" is still yesterday. "Today" must be the Davao date. */
class PhilippineTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_trip_with_no_start_date_begins_on_the_davao_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 21:00:00', 'UTC'));   // 5:00 AM Monday 19 Oct in Davao

        [$from] = (new TouristPreference(['travel_days' => 2]))->travelWindow();

        $this->assertSame('2026-10-19', $from->toDateString());
        $this->assertSame(1, $from->dayOfWeekIso, 'a place closed on Sundays must not be treated as closed on this Monday');
    }

    public function test_an_advisory_that_starts_today_in_davao_is_active_in_the_early_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 21:00:00', 'UTC'));

        Advisory::create(['title' => 'Road works', 'message' => 'm', 'severity' => 'warning', 'starts_at' => '2026-10-19', 'ends_at' => '2026-10-19']);

        $this->assertSame(1, Advisory::active()->count());
    }

    public function test_an_event_on_the_davao_date_is_happening_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 21:00:00', 'UTC'));

        $event = new Event(['title' => 'Festival', 'starts_on' => '2026-10-19']);

        $this->assertSame('Today', $event->countdownLabel());
    }
}
