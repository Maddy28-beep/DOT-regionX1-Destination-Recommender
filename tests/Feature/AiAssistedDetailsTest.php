<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\AiSkeletonAdvisor;
use App\Support\StopReasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Why this pick?" on every stop, and a day's one-line AI summary.
 *
 * The planner chooses every place and time. An AI service may only write the day's name and summary, so the summary is
 * shown only when it did; a plan without AI text is plain and complete.
 */
class AiAssistedDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, array $overrides = []): Destination
    {
        return Destination::create($overrides + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Malagos, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Wildlife',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 10, 'price_tier' => 'Budget-Friendly',
            'latitude' => 7.15, 'longitude' => 125.40, 'hours' => '8:00 AM–5:00 PM',
        ]);
    }

    private function row(Itinerary $trip, int $order, int $day, string $kind, array $extra = []): ItineraryItem
    {
        return ItineraryItem::create($extra + [
            'itinerary_id' => $trip->id, 'day_number' => $day, 'sort_order' => $order, 'slot' => 'Morning',
            'kind' => $kind, 'title' => ucfirst($kind),
        ]);
    }

    /** @return array{0: Itinerary, 1: TouristPreference, 2: Destination, 3: Destination} */
    private function plan(): array
    {
        $eagle = $this->place('Philippine Eagle Center');
        $zoo = $this->place('JKM Mini Zoo', ['type' => 'Nature & Leisure', 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Premium']);

        $pref = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'budget' => 'Budget-Friendly', 'accommodation_pref' => 'Any', 'distance_pref' => 'far',
        ]);
        PreferenceActivity::create(['preference_id' => $pref->id, 'activity' => 'Wildlife']);

        $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 2, 'generated_at' => now()]);
        $this->row($trip, 1, 1, 'baseline', ['title' => 'Arrival — Davao City centre', 'starts_at' => '08:00:00']);
        $this->row($trip, 2, 1, 'activity', ['title' => 'Wildlife exploration — Philippine Eagle Center', 'starts_at' => '10:00:00', 'ends_at' => '12:30:00', 'destination_id' => $eagle->id]);
        $this->row($trip, 3, 2, 'activity', ['title' => 'Visit JKM Mini Zoo', 'starts_at' => '09:00:00', 'ends_at' => '10:30:00', 'destination_id' => $zoo->id]);
        $this->row($trip, 4, 2, 'departure', ['title' => 'Departure', 'starts_at' => '15:00:00']);

        return [$trip, $pref, $eagle, $zoo];
    }

    private function page(Itinerary $trip, TouristPreference $pref)
    {
        return $this->withSession([TripPlannerController::ITINERARY_KEY => $trip->id, TripPlannerController::PREFERENCE_KEY => $pref->id])
            ->get(route('plan.itinerary'));
    }

    public function test_every_sightseeing_stop_has_a_why_this_pick_panel_built_from_planner_facts(): void
    {
        [$trip, $pref] = $this->plan();

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="pairing-why__btn"'), 'one button for each of the two stops');
        $this->assertStringContainsString('Chosen because it matches your interest in Wildlife.', $html);
        $this->assertStringContainsString('Matches your interest: Wildlife', $html);
        $this->assertStringContainsString('Fits your budget band', $html);
        // The budget tag is no longer a line on the card itself; it is a chip inside the panel.
        $this->assertStringNotContainsString('<p class="tl-card__tags"><span class="tl-tag tl-tag--match"><x-icon', $html);
        $this->assertStringNotContainsString('tl-tag--match', $html);
        $this->assertStringContainsString('Rated 4.5 from 10 reviews', $html);
        // No AI wrote any of it.
        $this->assertStringNotContainsString('AI-assisted', $html);
    }

    public function test_the_plain_plan_has_no_summary_line(): void
    {
        [$trip, $pref] = $this->plan();

        $this->page($trip, $pref)->assertOk()->assertDontSee('itin-day__ai', false);
    }

    public function test_an_ai_named_day_shows_its_summary_line(): void
    {
        [$trip, $pref] = $this->plan();
        $trip->update(['day_themes' => [
            'days' => [1 => ['label' => 'Wild side', 'similarity' => null, 'stops' => []], 2 => ['label' => 'Town', 'similarity' => null, 'stops' => []]],
            'summary' => null,
            'ai_skeleton' => ['applied' => true, 'days' => [1 => 'Wild side', 2 => 'Town'], 'summaries' => [1 => 'Animals and nature close to the city.']],
        ]]);

        $html = $this->page($trip, $pref)->assertOk()->getContent();

        $this->assertStringContainsString('<em>Animals and nature close to the city.</em>', $html);
        $this->assertSame(1, substr_count($html, 'class="itin-day__ai"'), 'only the day the AI wrote a summary for');
        $this->assertStringNotContainsString('data-ai-marker', $html);
    }

    public function test_a_stop_with_no_known_facts_still_gets_an_honest_reason(): void
    {
        $zoo = $this->place('Plain Place', ['rating' => 0, 'review_count' => 0, 'price_tier' => null, 'type' => 'Other']);

        $reason = StopReasons::for($zoo, null);

        $this->assertSame('Chosen as one of the best-scoring places for your answers.', $reason['sentence']);
        $this->assertSame(['One of the best matches for your answers'], $reason['facts']);
    }

    public function test_the_validator_keeps_a_clean_summary_and_drops_an_unusable_one_without_rejecting_the_plan(): void
    {
        $advisor = app(AiSkeletonAdvisor::class);
        $sizes = [1 => 2, 2 => 1];
        $day = fn (string $summaryJson) => '{"days":[{"day":1,"theme":"Wild side",'.$summaryJson.'"destination_ids":[3,1]},{"day":2,"theme":"Town","destination_ids":[2]}]}';

        $kept = $advisor->validate($day('"summary":"Animals and green space together.",'), [1, 2, 3], $sizes);
        $this->assertSame('Animals and green space together.', $kept[1]['summary']);
        $this->assertArrayNotHasKey('summary', $kept[2]);

        // Digits (a time, a price, a distance) are the planner's to state, so such a summary is dropped, not repaired.
        $digits = $advisor->validate($day('"summary":"Only 12 km from the city.",'), [1, 2, 3], $sizes);
        $this->assertNotNull($digits);
        $this->assertArrayNotHasKey('summary', $digits[1]);

        $long = $advisor->validate($day('"summary":"'.str_repeat('a', AiSkeletonAdvisor::MAX_SUMMARY_LENGTH + 1).'",'), [1, 2, 3], $sizes);
        $this->assertNotNull($long);
        $this->assertArrayNotHasKey('summary', $long[1]);

        $notText = $advisor->validate($day('"summary":["x"],'), [1, 2, 3], $sizes);
        $this->assertNotNull($notText);
        $this->assertArrayNotHasKey('summary', $notText[1]);
    }

    public function test_the_phone_gets_a_44px_why_this_pick_button(): void
    {
        $css = file_get_contents(public_path('css/itinerary-redesign.css'));

        $this->assertMatchesRegularExpression('/@media \(max-width: 560px\) \{\s*\.pairing-why__btn \{ min-height: 44px; \}/', $css);
    }
}
