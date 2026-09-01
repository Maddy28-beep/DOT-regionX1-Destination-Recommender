<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Address type-ahead for the trip planner's starting-point field.
 *
 * Geoapify when a key is configured, OpenStreetMap's Nominatim when not.
 *
 * The split matters. Geoapify has an endpoint built for autocomplete -- it
 * expects partial input and ranks accordingly -- while Nominatim is a full
 * geocoder whose usage policy discourages a request per keystroke. Geoapify is
 * therefore the real implementation and Nominatim the safety net, so a missing,
 * expired or rate-limited key degrades the feature instead of removing it.
 * Neither needs a billing account; Google Places would.
 *
 * Called from the server rather than the browser for three reasons, all
 * load-bearing:
 *
 *  1. The API key stays server-side. Putting it in page JavaScript publishes
 *     it to everyone who views source.
 *  2. Results are cached here, so a hundred people typing "Davao" produce one
 *     upstream request rather than a hundred, which is what keeps a free tier
 *     free.
 *  3. A visitor typing where they are does not need that keystroke stream
 *     going straight to a third party along with their IP and referrer. A
 *     small thing, but this is a system built to hold as little about people
 *     as possible.
 *
 * Failure is never fatal: an empty list means the visitor types their address
 * out in full, which the planner accepts anyway.
 */
class AddressSuggestionService
{
    private const GEOAPIFY_ENDPOINT = 'https://api.geoapify.com/v1/geocode/autocomplete';

    private const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /** Nominatim's policy asks for a real identifier; an anonymous agent gets blocked. */
    private const USER_AGENT = 'ExploreDVO/1.0 (DOT Region XI tourism platform)';

    /** Long enough to be a place, short enough not to hammer the geocoder. */
    private const MIN_QUERY_LENGTH = 3;

    private const MAX_RESULTS = 6;

    /** Suggestions for one query change rarely; a day is plenty. */
    private const CACHE_HOURS = 24;

    /** Davao City centre — results near it rank higher, without excluding the rest. */
    private const BIAS_LAT = 7.0731;

    private const BIAS_LNG = 125.6128;

    /** Bounding box for the Davao Region (west, north, east, south). */
    private const DAVAO_VIEWBOX = '125.0,7.9,126.7,5.6';

    /**
     * @return Collection<int, array{label: string, lat: float, lng: float}>
     */
    public function suggest(string $query): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return collect();
        }

        // The provider is part of the key: switching providers should not serve
        // yesterday's results from the other one.
        $key = 'geocode:'.($this->hasKey() ? 'geoapify' : 'osm').':'.md5(mb_strtolower($query));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return collect($cached);
        }

        $result = $this->hasKey() ? $this->fromGeoapify($query) : $this->fromNominatim($query);

        /*
         * Only a genuine answer from the provider is cached -- including a
         * real "nothing matches this text", which is worth remembering for a
         * day. A failed request (network error, bad key, provider outage) is
         * returned as an empty list but never written to the cache: caching a
         * transient failure for CACHE_HOURS is what turned a missing CA
         * bundle into every traveller being told an address does not exist
         * for a full day. The route is already throttled, so an outage can
         * retry on the next request instead of being locked in.
         */
        if ($result !== null) {
            Cache::put($key, $result->all(), now()->addHours(self::CACHE_HOURS));

            return $result;
        }

        return collect();
    }

    /**
     * The single best match for an address typed out in full.
     *
     * Used when someone writes their address rather than picking a suggestion,
     * so the plan can still be sequenced from where they actually are.
     *
     * @return array{label: string, lat: float, lng: float}|null
     */
    public function resolve(string $query): ?array
    {
        return $this->suggest($query)->first();
    }

    private function hasKey(): bool
    {
        return filled(config('services.geoapify.key'));
    }

    /**
     * @return Collection<int, array{label: string, lat: float, lng: float}>|null
     *   null means the request itself failed and nothing should be cached;
     *   an empty Collection means the provider genuinely found no matches.
     */
    private function fromGeoapify(string $query): ?Collection
    {
        return $this->attempt(function () use ($query) {
            $response = Http::timeout(6)->get(self::GEOAPIFY_ENDPOINT, [
                'text' => $query,
                'format' => 'json',
                'limit' => self::MAX_RESULTS,
                'filter' => 'countrycode:ph',
                // Bias, not a filter: a visitor flying in from Manila still
                // needs to be able to name where they are starting.
                'bias' => 'proximity:'.self::BIAS_LNG.','.self::BIAS_LAT,
                'apiKey' => config('services.geoapify.key'),
            ]);

            if ($response->failed()) {
                // Worth a line in the log: a 401 here means the key is wrong
                // and the feature has quietly dropped to the fallback.
                Log::warning('Geoapify autocomplete returned '.$response->status());

                return null;
            }

            return $this->normalise($response->json('results') ?? [], 'formatted', 'lat', 'lon');
        });
    }

    /**
     * @return Collection<int, array{label: string, lat: float, lng: float}>|null
     */
    private function fromNominatim(string $query): ?Collection
    {
        return $this->attempt(function () use ($query) {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(6)
                ->get(self::NOMINATIM_ENDPOINT, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => self::MAX_RESULTS,
                    'countrycodes' => 'ph',
                    'viewbox' => self::DAVAO_VIEWBOX,
                    'bounded' => 0,
                ]);

            if ($response->failed()) {
                return null;
            }

            return $this->normalise($response->json() ?? [], 'display_name', 'lat', 'lon');
        });
    }

    /**
     * Both providers return a label and a coordinate pair under different
     * names; everything downstream sees the same shape.
     *
     * @param  array<int, array<string, mixed>>  $places
     * @return Collection<int, array{label: string, lat: float, lng: float}>
     */
    private function normalise(array $places, string $labelKey, string $latKey, string $lngKey): Collection
    {
        return collect($places)
            ->map(fn (array $place) => [
                'label' => (string) ($place[$labelKey] ?? ''),
                'lat' => (float) ($place[$latKey] ?? 0),
                'lng' => (float) ($place[$lngKey] ?? 0),
            ])
            ->filter(fn (array $place) => $place['label'] !== '' && $place['lat'] !== 0.0)
            ->values();
    }

    /**
     * Runs a lookup, turning any exception into null rather than letting it
     * propagate.
     *
     * A geocoder being down, slow, or misconfigured must never stop somebody
     * planning a trip -- they can always type the address out instead. Null
     * is distinct from an empty Collection so the caller can skip caching a
     * failure (see suggest()) rather than caching "no results" for a day
     * because of a temporary outage.
     *
     * @param  callable(): (Collection<int, array{label: string, lat: float, lng: float}>|null)  $lookup
     * @return Collection<int, array{label: string, lat: float, lng: float}>|null
     */
    private function attempt(callable $lookup): ?Collection
    {
        try {
            return $lookup();
        } catch (Throwable $e) {
            /*
             * Redact the key before logging. Guzzle puts the full request URL
             * in its exception message, and ours carries apiKey= -- so an
             * ordinary connection failure was writing the secret into
             * laravel.log in plain text, where it outlives the request and
             * gets copied around with the log file.
             */
            $message = preg_replace('/(apiKey=)[^&\s]+/i', '$1[redacted]', $e->getMessage());

            Log::warning('Address lookup failed: '.$message);

            return collect();
        }
    }
}
