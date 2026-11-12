<?php

namespace Tests\Feature;

use App\Services\DavaoWeather;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The "Davao today" chip above the top bar: what the weather service reports, nothing when it fails, and nothing about the visitor sent. */
class DavaoWeatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.open_meteo.enabled' => true]);
        Cache::forget('davao-weather-now');
    }

    public function test_the_chip_shows_the_temperature_and_words_the_service_reports(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 31.4, 'weather_code' => 2]])]);

        $this->assertSame(['temp' => 31, 'words' => 'Partly cloudy'], app(DavaoWeather::class)->now());
        $this->get('/')->assertOk()->assertSee('Davao today')->assertSee('31&deg;C', false)->assertSee('Partly cloudy');
    }

    public function test_a_failing_service_means_no_chip_and_a_working_page(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response('down', 500)]);

        $this->assertNull(app(DavaoWeather::class)->now());
        $this->get('/')->assertOk()->assertDontSee('topstrip', false);
    }

    public function test_a_broken_reply_or_a_network_error_is_ignored(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 'hot']])]);
        $this->assertNull(app(DavaoWeather::class)->now());

        Cache::forget('davao-weather-now');
        Http::fake(['api.open-meteo.com/*' => fn () => throw new \RuntimeException('timeout')]);
        $this->assertNull(app(DavaoWeather::class)->now());
    }

    public function test_the_reply_is_kept_for_a_while_so_pages_do_not_wait_on_it(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 28, 'weather_code' => 0]])]);

        app(DavaoWeather::class)->now();
        app(DavaoWeather::class)->now();
        $this->get('/')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_only_davaos_coordinates_are_sent(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 28, 'weather_code' => 0]])]);

        app(DavaoWeather::class)->now();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latitude=7.0731') && str_contains($request->url(), 'longitude=125.6128') && ! str_contains($request->url(), 'q='));
    }

    public function test_the_tests_never_reach_the_network_unless_a_test_switches_it_on(): void
    {
        config(['services.open_meteo.enabled' => null]);
        Http::fake();

        $this->assertNull(app(DavaoWeather::class)->now());
        Http::assertNothingSent();
    }
}
