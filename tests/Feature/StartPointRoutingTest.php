<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Thesis objective 1.3.2.3: the route from the traveller's chosen start point to the first planned stop.
 * Day 1's OSRM request and Google Maps link begin at the start point when one was given; nothing else changes.
 */
class StartPointRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function stops(): array
    {
        return [
            ['label' => 'Zoo', 'address' => 'Davao City', 'lat' => 7.12, 'lng' => 125.64],
            ['label' => 'Museum', 'address' => null, 'lat' => 7.07, 'lng' => 125.61],
        ];
    }

    public function test_with_start_point_prepends_the_origin_and_feeds_the_google_maps_link(): void
    {
        $with = Itinerary::withStartPoint($this->stops(), ['lat' => 6.5, 'lng' => 125.1], 'Tagum');

        $this->assertCount(3, $with);
        $this->assertSame('Start: Tagum', $with[0]['label']);

        parse_str((string) parse_url(Itinerary::googleMapsUrl($with), PHP_URL_QUERY), $q);
        $this->assertSame('6.5,125.1', $q['origin']);
        $this->assertSame('7.07,125.61', $q['destination']);
        $this->assertSame('7.12,125.64', $q['waypoints']);
    }

    public function test_without_an_origin_the_stops_and_link_are_unchanged(): void
    {
        $this->assertSame($this->stops(), Itinerary::withStartPoint($this->stops(), null, 'Tagum'));

        parse_str((string) parse_url(Itinerary::googleMapsUrl($this->stops()), PHP_URL_QUERY), $q);
        $this->assertSame('7.12,125.64', $q['origin']);
        $this->assertArrayNotHasKey('waypoints', $q);
    }

    public function test_a_single_stop_day_becomes_a_real_route_from_the_start_point(): void
    {
        $one = [$this->stops()[0]];
        parse_str((string) parse_url(Itinerary::googleMapsUrl(Itinerary::withStartPoint($one, ['lat' => 6.5, 'lng' => 125.1])), PHP_URL_QUERY), $q);
        $this->assertSame('6.5,125.1', $q['origin']);
        $this->assertSame('7.12,125.64', $q['destination']);
    }

    private function renderItinerary(array $originAttrs): string
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach ([['Zoo', 'Wildlife', 7.12, 125.64], ['Museum', 'Cultural Heritage', 7.07, 125.61], ['Beach One', 'Beach & Leisure', 7.15, 125.71]] as $i => [$name, $type, $lat, $lng]) {
            Destination::create([
                'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 5 - $i * 0.1, 'review_count' => 10, 'price_tier' => 'Mid-range',
                'latitude' => $lat, 'longitude' => $lng,
            ]);
        }
        Accommodation::create([
            'slug' => 'city-inn', 'name' => 'City Inn', 'location' => 'Davao', 'region_id' => $region->id, 'type' => 'Hotel',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'latitude' => 7.07, 'longitude' => 125.61,
        ]);
        $preference = TouristPreference::create(array_merge([
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => '08:00',
        ], $originAttrs));
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));

        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();

        Carbon::setTestNow();

        // Js::from() embeds the stop list as JSON.parse('...') with quotes written as "; read it back as plain JSON.
        return str_replace('\u0022', '"', $html);
    }

    /** @return array<int, string> the origin= value of every Google Maps route link, in order of appearance */
    private function googleOrigins(string $html): array
    {
        preg_match_all('~https://www\.google\.com/maps/dir/\?[^"\s]+~', html_entity_decode($html), $m);

        return array_map(function ($url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return $q['origin'] ?? '';
        }, $m[0]);
    }

    public function test_rendered_day_one_route_and_link_start_from_the_travellers_current_location(): void
    {
        $html = $this->renderItinerary(['origin_lat' => 6.4, 'origin_lng' => 125.2, 'origin_label' => 'Current location']);

        $origins = $this->googleOrigins($html);
        $this->assertNotEmpty($origins);
        $this->assertSame('6.4,125.2', $origins[0]);
        // The stop list handed to the OSRM route panel starts with the start point.
        $this->assertStringContainsString('"label":"Start: Current location"', $html);
        $this->assertStringContainsString('"lat":6.4,"lng":125.2', $html);
        // Day 1 only: the start point is not added to any other day's route panel.
        $this->assertSame(1, substr_count($html, '"label":"Start: Current location"'));
        $this->assertStringContainsString('router.project-osrm.org/route/v1/driving/', $html);
        // The 12-second OSRM timeout is intact.
        $this->assertStringContainsString('12000', $html);
    }

    public function test_rendered_route_uses_a_typed_address_start_point(): void
    {
        $html = $this->renderItinerary(['origin_lat' => 6.4, 'origin_lng' => 125.2, 'origin_label' => 'Tagum City Hall']);

        $this->assertStringContainsString('"label":"Start: Tagum City Hall"', $html);
        $this->assertSame('6.4,125.2', $this->googleOrigins($html)[0]);
    }

    public function test_rendered_route_is_unchanged_without_a_start_point(): void
    {
        $html = $this->renderItinerary([]);

        $this->assertStringNotContainsString('"label":"Start:', $html);
        $origins = $this->googleOrigins($html);
        $this->assertNotEmpty($origins);
        foreach ($origins as $origin) {
            $this->assertNotSame('6.4,125.2', $origin);
        }
    }
}
