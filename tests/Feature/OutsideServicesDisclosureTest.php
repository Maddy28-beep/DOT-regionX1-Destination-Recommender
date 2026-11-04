<?php

namespace Tests\Feature;

use App\Http\Controllers\TripPlannerController;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\PreferenceActivity;
use App\Models\Region;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The owner-approved outside-services disclosures (Privacy Policy section 4, Terms sections 4 and 5) and the
 * route button label that keeps the Terms accurate. The approved paragraphs are pinned word for word so that
 * later edits to the legal pages cannot drift from what was approved.
 */
class OutsideServicesDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function text(string $uri): string
    {
        $html = $this->get($uri)->assertOk()->getContent();

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
    }

    public function test_privacy_policy_section_four_has_the_approved_outside_services_wording(): void
    {
        $text = $this->text(route('legal.privacy'));

        $approved = [
            'Map images (OpenStreetMap). The maps on the site are drawn from small map images loaded from OpenStreetMap\'s public tile servers. Your browser contacts those servers directly; ExploreDVO does not pass the request through its own server. Each request tells OpenStreetMap your IP address, your browser type, the website the request came from (the site\'s address, not the specific page) and which part of the map you are looking at. If your itinerary map is centred to include your starting point, that area is what is requested; the starting point itself is not sent as coordinates.',
            'Routes and directions (OSRM). When you open the directions for a day on an itinerary page, your browser asks the public routing server of the OSRM project (router.project-osrm.org) for the route. The request contains the map coordinates of that day\'s planned places, in order: the attractions, and also the meals, shops and lodging that the itinerary lists for that day. For Day 1, if you chose a starting point, the request also contains the coordinates of that starting point, as the first place on the route. It does not contain your name or anything else about you. As with any request, the routing server also sees your IP address, your browser type and the website the request came from. This is a free public server run by others; ExploreDVO does not control it and cannot guarantee that it is available.',
            'Fonts (Google Fonts). The text styles used on the site (Poppins, Alfa Slab One and Caveat) are loaded from Google\'s font servers on every page. Your browser contacts those servers directly and they receive your IP address, your browser type, the website the request came from and the font files requested.',
            'Google Maps links (only if you click). Each day of an itinerary has an "Open Day N in Google Maps" link. Nothing is sent to Google until you click it. When you do, Google Maps opens in a new tab and receives, in the link, the coordinates of that day\'s planned places (or the name and address of a place that has no coordinates) in order. For Day 1, if you chose a starting point, the link also carries the coordinates of that starting point as the first place, and nothing else about you; it does not carry your name. Google handles that under its own terms.',
            'About these outside services. OpenStreetMap, OSRM, Google Fonts and Google Maps are run by independent third parties, not by ExploreDVO or DOT Region XI. They handle the information above under their own policies; ExploreDVO does not control how long they keep it or where they process it, and this policy does not describe that. ExploreDVO does not send them your name, email or browser token.',
        ];

        $position = 0;
        foreach ($approved as $paragraph) {
            $found = strpos($text, $paragraph, $position);
            $this->assertNotFalse($found, 'approved text missing or out of order: '.substr($paragraph, 0, 50));
            $position = $found;
        }

        // The replaced bullet and its misleading phrase are gone; the other section 4 bullets are untouched.
        $this->assertStringNotContainsString('Maps and directions.', $text);
        $this->assertStringNotContainsString('turn-by-turn', $text);
        $this->assertStringContainsString('4. Outside services and where data is stored', $text);
        $this->assertStringContainsString('Address search. If you type an address as your starting point', $text);
        $this->assertStringContainsString('Your device\'s location. Only used if you press "Use my current location"', $text);
        $this->assertStringContainsString('Hosting. ExploreDVO\'s data is stored on a cloud server provided by DigitalOcean in Singapore.', $text);

        // No retention period or legal-compliance claim was added for the outside providers.
        $this->assertStringNotContainsString('comply with', $text);

        // The approved text dates the change to its publication day.
        $this->assertStringContainsString('Last updated November 5, 2026', $text);
        $this->assertStringContainsString('Last updated November 4, 2026', $this->text(route('legal.terms')));
    }

    public function test_terms_have_the_approved_section_four_correction_and_outside_services_section(): void
    {
        $text = $this->text(route('legal.terms'));

        $headings = ['1. What ExploreDVO is', '2. No bookings or payments happen here', '3. Accreditation information',
            '4. Itineraries and recommendations are suggestions', '5. Outside services', '6. Establishment partner accounts',
            '7. Changes to these terms', '8. Contact'];
        $position = 0;
        foreach ($headings as $heading) {
            $found = strpos($text, $heading, $position);
            $this->assertNotFalse($found, "heading missing or out of order: {$heading}");
            $position = $found;
        }
        $this->assertStringNotContainsString('6. Changes to these terms', $text);
        $this->assertStringNotContainsString('7. Contact', $text);

        $this->assertStringContainsString('Generated itineraries, distance estimates, and travel times are planning aids based on the information available to the system, not a guarantee of feasibility. The route line, distance, driving time and step-by-step directions shown on an itinerary map are a planning route that an outside routing service calculates between the planned places (see section 5). They are not live navigation: they do not take current traffic, road closures or weather into account, and the road they show may differ from the one you can actually take. On the day of travel, use a navigation app for directions. Always confirm opening hours, availability, and travel conditions before you go.', $text);
        $this->assertStringNotContainsString('not routed driving directions', $text);

        $this->assertStringContainsString('ExploreDVO\'s pages use services run by other organisations: OpenStreetMap for map images, the public OSRM routing server for road routes and directions, Google Fonts for text styles, and, if you click a "Google Maps" link, Google Maps. They are provided by third parties, not by ExploreDVO or DOT Region XI, under their own terms. They may be slow, unavailable or inaccurate at times, in which case maps, routes or fonts may not display correctly. Your browser contacts these services directly, and the information they receive is described in section 4 of the Privacy Policy, which also describes the other outside services that features of the site use.', $text);

        // Unrelated sections are untouched.
        $this->assertStringContainsString('A business that registers a partner account represents that it holds valid DOT accreditation', $text);
        $this->assertStringContainsString('These terms may be updated from time to time as the platform changes.', $text);
    }

    public function test_the_terms_link_points_at_the_privacy_policy_whose_section_four_exists(): void
    {
        $html = $this->get(route('legal.terms'))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="'.route('legal.privacy').'">Privacy Policy</a>', $html);
        // "see section 5" in section 4 points at the new Outside services section.
        $this->assertStringContainsString('<h2>5. Outside services</h2>', $html);
        $this->assertStringContainsString('<h2>4. Outside services and where data is stored</h2>', $this->get(route('legal.privacy'))->getContent());
    }

    public function test_the_route_button_is_labelled_a_planned_route_and_nothing_promises_navigation(): void
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
        $preference = TouristPreference::create([
            'travel_days' => 2, 'travel_type' => 'Couple', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any',
            'distance_pref' => 'moderate', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Cebu City', 'start_date' => '2026-10-28', 'arrival_time' => '08:00',
        ]);
        PreferenceActivity::create(['preference_id' => $preference->id, 'activity' => 'Wildlife']);
        $itinerary = app(ItineraryGenerationService::class)->generate($preference->load('activities', 'amenities'));

        $html = $this->withSession([
            TripPlannerController::PREFERENCE_KEY => $preference->id,
            TripPlannerController::ITINERARY_KEY => $itinerary->id,
        ])->get('/plan/itinerary')->assertOk()->getContent();

        // The same <summary> control, now with the day number in an accurate label.
        $this->assertMatchesRegularExpression('/<summary class="btn btn-outline"[^>]*>View planned route for Day 1<\/summary>/', $html);
        $this->assertStringNotContainsString('turn-by-turn', strtolower($html));
        // The route panel and the Google Maps link are still there.
        $this->assertStringContainsString('id="route-day-1"', $html);
        $this->assertStringContainsString('https://www.google.com/maps/dir/?api=1', $html);
        $this->assertStringContainsString('router.project-osrm.org/route/v1/driving/', $html);

        Carbon::setTestNow();
    }
}
