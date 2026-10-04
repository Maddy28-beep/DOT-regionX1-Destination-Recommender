<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Events page lists published events (as server-rendered upcoming cards
 * plus the JSON the calendar script reads), hides archived and past ones from
 * the upcoming list, and the homepage teaser shows only the next three.
 */
class EventsCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $title, string $start, ?string $end = null, array $extra = []): Event
    {
        return Event::create(array_merge([
            'title' => $title, 'slug' => str($title)->slug(), 'category' => 'festival',
            'starts_on' => $start, 'ends_on' => $end, 'location' => 'Davao City',
        ], $extra));
    }

    public function test_events_page_lists_upcoming_and_embeds_calendar_json(): void
    {
        $this->event('Future Fest', Carbon::today()->addDays(5)->toDateString());
        $this->event('Old Fair', Carbon::today()->subDays(30)->toDateString());

        $response = $this->get(route('events.index'))->assertOk()->assertSee('Future Fest');

        $this->assertSame(1, $response->viewData('upcoming')->count());
        $this->assertCount(2, $response->viewData('calendarEvents'));
        $response->assertSee('id="eventsData"', false);
    }

    public function test_archived_events_are_hidden(): void
    {
        $this->event('Hidden Fest', Carbon::today()->addDays(2)->toDateString(), null, ['archived_at' => now()]);

        $this->get(route('events.index'))->assertOk()->assertDontSee('Hidden Fest');
    }

    public function test_multi_day_event_in_progress_counts_as_upcoming(): void
    {
        $this->event('Running Fest', Carbon::today()->subDay()->toDateString(), Carbon::today()->addDay()->toDateString());

        $this->assertSame(1, Event::published()->upcoming()->count());
    }

    public function test_date_range_label(): void
    {
        $this->assertSame('Aug 14 – 23, 2026', $this->event('A', '2026-08-14', '2026-08-23')->dateRangeLabel());
        $this->assertSame('Aug 28 – Sep 2, 2026', $this->event('B', '2026-08-28', '2026-09-02')->dateRangeLabel());
        $this->assertSame('Oct 17, 2026', $this->event('C', '2026-10-17')->dateRangeLabel());
    }

    public function test_homepage_teaser_shows_next_three_only(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $this->event("Teaser Event {$n}", Carbon::today()->addDays($n)->toDateString());
        }

        $this->get('/')->assertOk()
            ->assertSee('Happening soon')
            ->assertSee('Teaser Event 3')
            ->assertDontSee('Teaser Event 4');
    }

    public function test_homepage_omits_teaser_without_events(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Happening soon');
    }

    public function test_nav_and_footer_link_to_events(): void
    {
        $this->get('/')->assertSee(route('events.index'), false);
    }
}
