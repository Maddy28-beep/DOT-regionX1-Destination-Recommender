<?php

namespace App\Services\Recommendation;

/**
 * Turns a straight-line distance between two points into the road distance and
 * journey time a traveller can actually plan around.
 *
 * Haversine gives the distance a bird flies. Nobody drives that, so every
 * figure here is derived from it rather than reported as it:
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
 * These are planning estimates, not routing. They exist so a schedule can say
 * "leave at 1:15" instead of leaving the traveller to guess.
 */
class TravelTimeEstimator
{
    /** Road distance vs straight line. 1.3 is the usual planning figure for mixed terrain. */
    private const DETOUR_FACTOR = 1.3;

    /** Fastest and slowest sustained speeds worth planning around, km/h. */
    private const FAST_KMH = 40.0;

    private const SLOW_KMH = 25.0;

    /** Getting out of one place and into the next: parking, walking, waiting. */
    private const OVERHEAD_MINUTES = 10;

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
     * @return array{distance_km: float, min_minutes: int, max_minutes: int, ferry: bool}
     */
    public function estimate(float $straightLineKm, ?string $fromRegion = null, ?string $toRegion = null): array
    {
        $roadKm = $straightLineKm * self::DETOUR_FACTOR;

        // A ferry leg is one that starts or ends on the island, not one that
        // stays on it -- travelling between two Samal beaches involves no boat.
        $ferry = ($fromRegion === self::FERRY_REGION) !== ($toRegion === self::FERRY_REGION);
        $ferryMinutes = $ferry ? self::FERRY_MINUTES : 0;

        $fast = ($roadKm / self::FAST_KMH) * 60 + self::OVERHEAD_MINUTES + $ferryMinutes;
        $slow = ($roadKm / self::SLOW_KMH) * 60 + self::OVERHEAD_MINUTES + $ferryMinutes;

        return [
            'distance_km' => round($roadKm, 1),
            'min_minutes' => $this->toQuarterHour($fast),
            'max_minutes' => $this->toQuarterHour($slow),
            'ferry' => $ferry,
        ];
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
