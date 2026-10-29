<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking the plan with you: the calendar file and the print / PDF button.
 */
class ItineraryShareTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $extra = []): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        foreach ([['a', 7.07, 125.61], ['b', 7.18, 125.45], ['c', 7.02, 125.41]] as [$slug, $lat, $lng]) {
            Destination::firstOrCreate(['slug' => $slug], [
                'name' => 'Place '.$slug.', the first', 'location' => 'Davao City; Davao del Sur', 'region_id' => $region->id,
                'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10,
                'price_tier' => 'Mid-range', 'latitude' => $lat, 'longitude' => $lng,
            ]);
        }

        $this->post('/plan', $extra + [
            'travel_days' => 1, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
            'activities' => ['Nature'], 'amenities' => ['Parking Area'], 'place_of_origin' => 'Cebu City',
            'arrival_time' => '08:00',
        ])->assertRedirect();
    }

    public function test_the_calendar_has_an_event_for_each_stop_on_the_day_it_falls(): void
    {
        $this->plan(['start_date' => '2026-11-04']);

        $response = $this->get(route('plan.itinerary.calendar'))->assertOk();
        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="exploredavao-itinerary.ics"', $response->headers->get('Content-Disposition'));

        $ics = $response->getContent();
        $itinerary = Itinerary::latest('id')->first();
        $stops = $itinerary->items()->where('kind', 'activity')->count();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertGreaterThanOrEqual(1, $stops);
        $this->assertSame($stops + $itinerary->items()->whereIn('kind', ['meal', 'overnight'])->count(), substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(substr_count($ics, 'BEGIN:VEVENT'), substr_count($ics, 'END:VEVENT'));
        $this->assertStringNotContainsString('Travel to', $ics, 'Journeys are not events.');
    }

    public function test_times_are_manila_time_written_as_utc(): void
    {
        $this->plan(['start_date' => '2026-11-04']);

        $first = Itinerary::latest('id')->first()->items()->where('kind', 'activity')->orderBy('sort_order')->first();
        $expected = \Illuminate\Support\Carbon::parse('2026-11-04 '.$first->starts_at, 'Asia/Manila')->utc()->format('Ymd\THis\Z');

        $this->assertStringContainsString('DTSTART:'.$expected, $this->get(route('plan.itinerary.calendar'))->getContent());
    }

    public function test_text_is_escaped_and_no_line_is_longer_than_75_bytes(): void
    {
        $this->plan(['start_date' => '2026-11-04']);

        $ics = $this->get(route('plan.itinerary.calendar'))->getContent();

        $this->assertMatchesRegularExpression('/Place [abc]\\\\, the first/', $ics, 'A comma in a name is escaped.');
        $this->assertStringContainsString('Davao City\\; Davao del Sur', $ics, 'A semicolon is escaped.');
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }
    }

    public function test_without_an_arrival_date_the_traveller_is_sent_to_add_one(): void
    {
        $this->plan();

        $this->get(route('plan.itinerary.calendar'))->assertRedirect(route('plan.edit'));
    }

    public function test_with_no_plan_there_is_nothing_to_download(): void
    {
        $this->get(route('plan.itinerary.calendar'))->assertRedirect(route('plan.edit'));
    }

    public function test_the_page_offers_print_and_the_calendar(): void
    {
        $this->plan(['start_date' => '2026-11-04']);

        $html = $this->get(route('plan.itinerary'))->assertOk()->getContent();

        $this->assertStringContainsString('data-print', $html);
        $this->assertStringContainsString('Print / PDF', $html);
        $this->assertStringContainsString(route('plan.itinerary.calendar'), $html);
        $this->assertStringNotContainsString('Add an arrival date in your preferences first', $html);
    }

    public function test_the_calendar_button_says_why_when_there_is_no_arrival_date(): void
    {
        $this->plan();

        $this->assertStringContainsString('Add an arrival date in your preferences first', $this->get(route('plan.itinerary'))->getContent());
        $this->assertSame(1, TouristPreference::count());
    }
}
