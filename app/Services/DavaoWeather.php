<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The weather in Davao City for the top bar's "Davao today" chip, from Open-Meteo (free, no key; nothing about the visitor is sent,
 * only Davao City's coordinates). Kept for 30 minutes so a page view never waits on it, and any failure just means no chip.
 */
class DavaoWeather
{
    private const LAT = 7.0731;

    private const LON = 125.6128;

    /** WMO weather code => short words. */
    private const WORDS = [
        0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Cloudy', 45 => 'Foggy', 48 => 'Foggy',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Drizzle', 61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
        80 => 'Rain showers', 81 => 'Rain showers', 82 => 'Heavy showers', 95 => 'Thunderstorm', 96 => 'Thunderstorm', 99 => 'Thunderstorm',
    ];

    /** @return array{temp: int, words: string}|null */
    public function now(): ?array
    {
        // Off while the tests run, so they never reach the network (a test that wants it switches it on and fakes Http).
        if (! config('services.open_meteo.enabled', ! app()->runningUnitTests())) {
            return null;
        }

        return Cache::remember('davao-weather-now', now()->addMinutes(30), function () {
            try {
                $response = Http::timeout(3)->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => self::LAT, 'longitude' => self::LON, 'current' => 'temperature_2m,weather_code', 'timezone' => 'Asia/Manila',
                ]);
                $current = $response->successful() ? $response->json('current') : null;
                if (! is_array($current) || ! is_numeric($current['temperature_2m'] ?? null)) {
                    return null;
                }

                return ['temp' => (int) round($current['temperature_2m']), 'words' => self::WORDS[(int) ($current['weather_code'] ?? -1)] ?? 'Fair'];
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
