<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Embeddings\DestinationEmbeddingService;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A place that is closed on a weekday ("Closed Mondays", "Tue–Sun ...") is not scheduled on that day. */
class ClosedDaysPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function places(?string $zooHours): void
    {
        $region = Region::create(['name' => 'Davao City']);

        foreach ([
            ['Zoo', 'Wildlife', 7.12, 125.64, $zooHours], ['Eagle Centre', 'Wildlife', 7.15, 125.41, null],
            ['Museum', 'Cultural Heritage', 7.07, 125.61, null], ['Old Church', 'Cultural Heritage', 7.06, 125.60, null],
            ['Beach One', 'Beach & Leisure', 7.15, 125.71, null], ['Eden Park', 'Nature & Adventure', 7.03, 125.37, null],
        ] as $i => [$name, $type, $lat, $lng, $hours]) {
            $d = Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 5 - $i * 0.1, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'latitude' => $lat, 'longitude' => $lng, 'hours' => $hours,
            ]);
            DestinationEmbedding::create([
                'destination_id' => $d->id, 'model' => 'test', 'dimensions' => 2, 'text_hash' => sha1($name),
                'vector' => DestinationEmbeddingService::normalise([$i % 2, 1 - $i % 2]),
            ]);
        }
    }

    /** @return array<int, list<string>> day number => place names visited */
    private function visits(string $startDate): array
    {
        $preference = TouristPreference::create([
            'travel_days' => 3, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'far', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => $startDate,
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);

        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));

        $days = [];
        foreach ($itinerary->items()->where('kind', 'activity')->whereNotNull('destination_id')->orderBy('sort_order')->get() as $item) {
            $days[$item->day_number][] = Destination::find($item->destination_id)->name;
        }

        return $days;
    }

    public function test_a_place_closed_on_the_weekday_is_not_scheduled_that_day(): void
    {
        // Without a closed day the planner puts the Zoo on day 2 (see the control below); day 2 here is a Tuesday.
        $this->places('Closed Tuesdays');
        $days = $this->visits('2026-10-12');   // Monday start

        $this->assertNotContains('Zoo', $days[2] ?? [], 'Zoo is closed on Tuesdays');
        $this->assertContains('Zoo', collect($days)->flatten()->all(), 'it is moved to another day, not lost');
    }

    public function test_control_the_same_place_keeps_its_usual_day_when_it_is_open(): void
    {
        $this->places('Closed Tuesdays');
        $days = $this->visits('2026-10-13');   // Tuesday start: day 2 is a Wednesday

        $this->assertContains('Zoo', $days[2] ?? []);
    }
}
