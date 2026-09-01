<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The trip planner's starting-point field: type an address, pick from
 * suggestions, or write it out in full.
 *
 * The geocoder is a third party that can be slow, rate-limited or down, so the
 * governing rule in all of these is that none of that may stop somebody
 * planning a trip.
 */
class AddressSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests describe the configured provider explicitly, so neither a
        // developer's real key nor its absence can change what they assert.
        config(['services.geoapify.key' => 'test-key']);
        Cache::flush();
    }

    private function geocoderReturns(array $places): void
    {
        Http::fake([
            'api.geoapify.com/*' => Http::response(['results' => $places]),
        ]);
    }

    /** @return array<string, mixed> */
    private function place(string $name, float $lat, float $lng): array
    {
        return ['formatted' => $name, 'lat' => $lat, 'lon' => $lng];
    }

    public function test_typing_returns_matching_addresses(): void
    {
        $this->geocoderReturns([
            $this->place('Francisco Bangoy International Airport, Davao City', 7.1255, 125.6456),
            $this->place('Bangoy Street, Davao City', 7.0700, 125.6100),
        ]);

        $response = $this->getJson('/plan/address-suggest?q=bangoy')->assertOk();

        $response->assertJsonCount(2, 'results');
        $response->assertJsonPath('results.0.label', 'Francisco Bangoy International Airport, Davao City');
        $response->assertJsonPath('results.0.lat', 7.1255);
    }

    /** Two keystrokes is not a search; it is a way to hammer a geocoder. */
    public function test_a_very_short_query_asks_the_geocoder_nothing(): void
    {
        Http::fake();

        $this->getJson('/plan/address-suggest?q=da')->assertOk()->assertJsonPath('results', []);

        Http::assertNothingSent();
    }

    /** Repeated lookups of the same text must not repeat the upstream request. */
    public function test_results_are_cached(): void
    {
        $this->geocoderReturns([$this->place('Davao City', 7.07, 125.61)]);

        $this->getJson('/plan/address-suggest?q=davao city');
        $this->getJson('/plan/address-suggest?q=DAVAO CITY');

        Http::assertSentCount(1);
    }

    /** A geocoder that is down returns nothing, not an error page. */
    public function test_an_upstream_failure_degrades_quietly(): void
    {
        Http::fake(['api.geoapify.com/*' => Http::response('', 503)]);

        $this->getJson('/plan/address-suggest?q=somewhere')
            ->assertOk()
            ->assertJsonPath('results', []);
    }

    /**
     * A failed lookup must not be cached, or a transient outage (a missing
     * CA bundle, a brief provider blip) tells every visitor for the next 24
     * hours that a real address does not exist.
     *
     * Uses a response sequence rather than two Http::fake() calls: Laravel
     * resolves the FIRST registered stub that matches a URL, so a second
     * Http::fake() for the same wildcard never overrides the first -- the
     * sequence is what actually returns a different response per request.
     */
    public function test_a_failed_lookup_is_retried_rather_than_cached(): void
    {
        Http::fakeSequence('api.geoapify.com/*')
            ->push('', 503)
            ->push(['results' => [$this->place('Somewhere, Davao City', 7.07, 125.61)]]);

        $this->getJson('/plan/address-suggest?q=somewhere')->assertJsonPath('results', []);

        // The outage clears; the very next request for the same text must
        // reach the provider again rather than replaying the cached failure.
        $this->getJson('/plan/address-suggest?q=somewhere')
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Somewhere, Davao City');

        Http::assertSentCount(2);
    }

    /** The key is what selects the provider. */
    public function test_a_configured_key_uses_geoapify(): void
    {
        $this->geocoderReturns([$this->place('Davao City', 7.07, 125.61)]);

        $this->getJson('/plan/address-suggest?q=davao')->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.geoapify.com')
                && str_contains($request->url(), 'apiKey=test-key');
        });
    }

    /** With no key the lookup falls back rather than going dark. */
    public function test_no_key_falls_back_to_the_keyless_geocoder(): void
    {
        config(['services.geoapify.key' => null]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Davao City, Philippines', 'lat' => '7.07', 'lon' => '125.61'],
            ]),
        ]);

        $this->getJson('/plan/address-suggest?q=davao')
            ->assertOk()
            ->assertJsonPath('results.0.label', 'Davao City, Philippines');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'nominatim.openstreetmap.org'));
    }

    /**
     * A failed lookup must not write the key into the log.
     *
     * Guzzle puts the full request URL in its exception message, and ours
     * carries apiKey=, so an ordinary connection failure was persisting the
     * secret to laravel.log in plain text.
     */
    public function test_a_failure_does_not_log_the_api_key(): void
    {
        config(['services.geoapify.key' => 'super-secret-key']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 60: SSL certificate problem for '
                .'https://api.geoapify.com/v1/geocode/autocomplete?text=davao&apiKey=super-secret-key'
            );
        });

        Log::shouldReceive('warning')->once()->withArgs(function (string $message) {
            $this->assertStringNotContainsString('super-secret-key', $message,
                'The API key must be redacted before anything is written to the log.');
            $this->assertStringContainsString('[redacted]', $message);

            return true;
        });

        $this->getJson('/plan/address-suggest?q=davao')->assertOk()->assertJsonPath('results', []);
    }

    /** The key must never reach the browser. */
    public function test_the_api_key_is_not_exposed_to_the_page(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertStringNotContainsString('test-key', $html,
            'The geocoding key is server-side only; putting it in page JavaScript publishes it.');
        $this->assertStringNotContainsString('geoapify', strtolower($html),
            'The page talks to our own endpoint, never the geocoder directly.');
    }

    public function test_the_planner_form_offers_the_address_field(): void
    {
        $html = $this->get('/plan')->assertOk()->getContent();

        $this->assertStringContainsString('id="origin_label"', $html);
        $this->assertStringContainsString('role="combobox"', $html,
            'The field owns a suggestion list, so it has to announce itself as one.');
        $this->assertStringContainsString('origin-suggestions', $html);
        $this->assertStringContainsString('Use my current location', $html,
            'Sharing the device position stays available alongside typing.');
    }

    /**
     * An address typed out in full, without picking a suggestion, must still
     * position the trip. Ignoring it would leave the plan sequenced from the
     * city centre while the page claimed otherwise.
     */
    public function test_a_typed_address_is_geocoded_on_submit(): void
    {
        $this->seedDestination();
        $this->geocoderReturns([$this->place('Toril, Davao City', 7.0206, 125.4103)]);

        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_label' => 'Toril, Davao City',
        ])->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::sole();

        $this->assertSame('Toril, Davao City', $preference->origin_label);
        $this->assertSame(7.021, (float) $preference->origin_lat, 'Coordinates are coarsened before storage.');
        $this->assertSame(125.41, (float) $preference->origin_lng);
    }

    /** Picking a suggestion sends its coordinates; no second lookup is needed. */
    public function test_a_chosen_suggestion_is_stored_without_re_geocoding(): void
    {
        $this->seedDestination();
        Http::fake();

        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_label' => 'Francisco Bangoy International Airport',
            'origin_lat' => 7.1255, 'origin_lng' => 125.6456,
        ])->assertRedirect(route('plan.itinerary'));

        Http::assertNothingSent();

        $preference = TouristPreference::sole();
        $this->assertSame('Francisco Bangoy International Airport', $preference->origin_label);
        $this->assertSame(7.126, (float) $preference->origin_lat);
    }

    /** An address the geocoder cannot place is kept, and the plan still builds. */
    public function test_an_unresolvable_address_still_produces_a_plan(): void
    {
        $this->seedDestination();
        $this->geocoderReturns([]);

        $this->post('/plan', [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
            'origin_label' => 'somewhere that does not exist',
        ])->assertRedirect(route('plan.itinerary'));

        $preference = TouristPreference::sole();

        $this->assertSame('somewhere that does not exist', $preference->origin_label);
        $this->assertNull($preference->origin_lat, 'An unplaceable address must not invent coordinates.');

        $this->get('/plan/itinerary')->assertOk();
    }

    /** Leaving the field blank clears any previous starting point. */
    public function test_clearing_the_field_removes_the_starting_point(): void
    {
        $this->seedDestination();
        Http::fake();

        $payload = [
            'travel_days' => 2, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
            'accommodation_pref' => 'Any', 'distance_pref' => 'near',
        ];

        $this->post('/plan', $payload + ['origin_lat' => 7.07, 'origin_lng' => 125.61, 'origin_label' => 'Somewhere']);
        $this->assertNotNull(TouristPreference::sole()->origin_lat);

        $this->post('/plan', $payload);

        $preference = TouristPreference::sole()->refresh();
        $this->assertNull($preference->origin_lat);
        $this->assertNull($preference->origin_label);
    }

    private function seedDestination(): void
    {
        $region = Region::create(['name' => 'Davao City']);

        Destination::create([
            'slug' => 'a-place', 'name' => 'A Place', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 4.5, 'review_count' => 5, 'price_tier' => 'Mid-range',
            'latitude' => 7.05, 'longitude' => 125.60, 'distance_km' => 8,
        ]);

        Cache::flush();
    }
}
