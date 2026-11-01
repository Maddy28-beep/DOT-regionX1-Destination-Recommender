<?php

namespace App\Services\Recommendation;

/**
 * Turns a straight-line distance between two points into the road distance and
 * journey time a traveller can actually plan around.
 *
 * Checked coordinate pairs use dated road-network snapshots. Other pairs use
 * Haversine with a detour allowance:
 *
 *   - Road distance is the straight line times a detour factor. Real routes
 *     bend around coastline, river and mountain, and Davao Region has all
 *     three.
 *   - Time is a RANGE, not a number. Speeds through Davao City traffic and
 *     out on the provincial roads differ by more than a factor of two, and a
 *     single figure would be a promise the plan cannot keep.
 *   - A crossing to Samal adds the ferry, which no distance calculation can
 *     see. That is the one hard-coded special case, and it is named as such.
 *
 * Times remain planning estimates, without live traffic or ferry queues. They let a schedule say
 * "leave at 1:15" instead of leaving the traveller to guess.
 */
class TravelTimeEstimator
{
    private ?array $roadDistances = null;

    /** Road distance vs straight line. 1.3 is the usual planning figure for mixed terrain. */
    private const DETOUR_FACTOR = 1.3;

    /*
     * Speeds and per-leg overhead (parking, walking, waiting) come from the traveller's TransportMode. A
     * personal car -- the default when nothing was chosen -- is 40 / 25 km/h and 10 minutes, which is what
     * this class used to hard-code for everyone.
     */

    /**
     * The Island Garden City of Samal is reached by ferry from Davao City.
     * Crossing plus queueing, added whenever exactly one end of a leg is on
     * the island.
     */
    private const FERRY_MINUTES = 30;

    private const FERRY_REGION = 'Island Garden City of Samal';

    /**
     * @param  string|null  $fromRegion  region name of the origin, when known
     * @param  string|null  $toRegion    region name of the destination, when known
     * @param  string|null  $mode        a TransportMode value; null (or unknown) plans as a personal car
     * @param  array{lat: float, lng: float}|null  $from  optional mapped endpoint for a checked road distance
     * @param  array{lat: float, lng: float}|null  $to  optional mapped endpoint for a checked road distance
     * @return array{distance_km: float, min_minutes: int, max_minutes: int, ferry: bool}
     */
    public function estimate(float $straightLineKm, ?string $fromRegion = null, ?string $toRegion = null, ?string $mode = null, ?array $from = null, ?array $to = null): array
    {
        $roadKm = $this->checkedRoadKm($from, $to) ?? $straightLineKm * self::DETOUR_FACTOR;

        // A ferry leg is one that starts or ends on the island, not one that
        // stays on it -- travelling between two Samal beaches involves no boat.
        $ferry = ($fromRegion === self::FERRY_REGION) !== ($toRegion === self::FERRY_REGION);
        $ferryMinutes = $ferry ? self::FERRY_MINUTES : 0;

        $overhead = TransportMode::overheadMinutes($mode);
        $fast = ($roadKm / TransportMode::fastKmh($mode)) * 60 + $overhead + $ferryMinutes;
        $slow = ($roadKm / TransportMode::slowKmh($mode)) * 60 + $overhead + $ferryMinutes;

        return [
            'distance_km' => round($roadKm, 1),
            'min_minutes' => $this->toQuarterHour($fast),
            'max_minutes' => $this->toQuarterHour($slow),
            'ferry' => $ferry,
        ];
    }

    /** Use a checked road distance only for its directed coordinate pair; other legs remain estimates. */
    private function checkedRoadKm(?array $from, ?array $to): ?float
    {
        if (! isset($from['lat'], $from['lng'], $to['lat'], $to['lng'])) {
            return null;
        }
        $key = fn (array $point): string => sprintf('%.5F,%.5F', $point['lat'], $point['lng']);
        if ($this->roadDistances === null) {
            $this->roadDistances = [];
            $path = dirname(__DIR__, 3).'/database/data/planner-road-routes-2026-11-01.json';
            if (is_file($path)) {
                $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                foreach ($manifest['routes'] as $route) {
                    $this->roadDistances[$key($route['from']).'>'.$key($route['to'])] = (float) $route['road_km'];
                }
            }
        }

        return $this->roadDistances[$key($from).'>'.$key($to)] ?? null;
    }

    /**
     * Rounds to a quarter hour, minimum 5 minutes.
     *
     * Journey estimates are read as "about 45 minutes", so presenting them to
     * the minute would imply a precision this does not have.
     */
    private function toQuarterHour(float $minutes): int
    {
        return max(5, (int) (round($minutes / 15) * 15));
    }

    /** "Approx. 17 km (including ferry), 45-60 mins" — the schedule's travel column. */
    public function describe(array $estimate): string
    {
        $ferry = $estimate['ferry'] ? ' (including ferry)' : '';

        $minutes = $estimate['min_minutes'] === $estimate['max_minutes']
            ? $estimate['min_minutes'].' mins'
            : $estimate['min_minutes'].'–'.$estimate['max_minutes'].' mins';

        return sprintf('Approx. %s km%s, %s', $this->trim($estimate['distance_km']), $ferry, $minutes);
    }

    /** 17.0 reads as 17; 3.5 keeps its half. */
    private function trim(float $km): string
    {
        return rtrim(rtrim(number_format($km, 1), '0'), '.');
    }
}
