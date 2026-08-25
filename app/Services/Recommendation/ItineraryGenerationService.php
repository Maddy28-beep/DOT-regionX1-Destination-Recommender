<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Models\Itinerary;
use App\Models\Tourist;
use App\Models\TouristPreference;
use Illuminate\Support\Facades\DB;

/**
 * AI-Driven Itinerary Generation module (manuscript Sec. 2.3.4, "AI-Driven Itinerary
 * Generation"). Combines the Content-Based Recommendation ranking, Apriori-derived
 * complementary establishments, and a heuristic rule-based sequencing step
 * (Haversine distance + Nearest Neighbor) into a day-by-day travel plan.
 *
 * Per the manuscript: "the heuristic approach does not replace the Content-Based
 * Recommendation and Apriori Algorithm... it serves as a supporting rule-based
 * algorithm that arranges the recommended destinations into a logical and
 * convenient travel sequence."
 */
class ItineraryGenerationService
{
    /** Fallback baseline when no live location is provided: Davao City center (matches "tourist's selected starting location"). */
    private const DEFAULT_ORIGIN_LAT = 7.0731;

    private const DEFAULT_ORIGIN_LNG = 125.6128;

    private const DESTINATIONS_PER_DAY = 2;

    public function __construct(
        private readonly ContentBasedRecommendationService $contentBased,
        private readonly AprioriService $apriori,
    ) {}

    public function generate(Tourist $tourist, TouristPreference $preference, ?float $originLat = null, ?float $originLng = null): Itinerary
    {
        $originLat ??= self::DEFAULT_ORIGIN_LAT;
        $originLng ??= self::DEFAULT_ORIGIN_LNG;

        $ranked = $this->contentBased->rank($tourist, $preference);

        if ($ranked->isEmpty()) {
            throw new \RuntimeException('No accredited destinations are available to build an itinerary.');
        }

        $totalDays = max(1, (int) $preference->travel_days);
        $maxStops = min($ranked->count(), $totalDays * self::DESTINATIONS_PER_DAY);
        $topRanked = $ranked->take($maxStops);

        $sequence = $this->sequenceByNearestNeighbor($topRanked, $originLat, $originLng);

        return DB::transaction(function () use ($tourist, $preference, $totalDays, $ranked, $sequence) {
            $itinerary = Itinerary::create([
                'tourist_id' => $tourist->id,
                'preference_id' => $preference->id,
                'total_days' => $totalDays,
                'est_party_size' => null,
                'generated_at' => now(),
            ]);

            // Table 8: full computed Destination Recommendation ranking, not just the stops used.
            foreach ($ranked->values() as $index => $row) {
                $itinerary->matches()->create([
                    'destination_id' => $row['destination']->id,
                    'rank' => $index + 1,
                    'match_score' => $row['drs'],
                ]);
            }

            $this->buildDayByDayItems($itinerary, $sequence, $preference, $totalDays);

            return $itinerary->load(['matches.destination', 'items.destination', 'items.accommodation']);
        });
    }

    /**
     * Nearest Neighbor heuristic: starting from the baseline, repeatedly visit
     * whichever remaining ranked destination is geographically closest.
     */
    private function sequenceByNearestNeighbor($topRanked, float $originLat, float $originLng): array
    {
        $remaining = $topRanked->values()->all();
        $sequence = [];
        $currentLat = $originLat;
        $currentLng = $originLng;

        while (! empty($remaining)) {
            $nearestIndex = null;
            $nearestDistance = null;

            foreach ($remaining as $index => $row) {
                $destination = $row['destination'];
                if ($destination->latitude === null || $destination->longitude === null) {
                    continue;
                }
                $distance = $this->haversineKm($currentLat, $currentLng, (float) $destination->latitude, (float) $destination->longitude);
                if ($nearestDistance === null || $distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearestIndex = $index;
                }
            }

            // destinations missing coordinates just get appended in ranked order
            if ($nearestIndex === null) {
                $row = array_shift($remaining);
                $sequence[] = ['row' => $row, 'distance_km' => null];

                continue;
            }

            $row = $remaining[$nearestIndex];
            unset($remaining[$nearestIndex]);
            $remaining = array_values($remaining);

            $sequence[] = ['row' => $row, 'distance_km' => round($nearestDistance, 1)];
            $currentLat = (float) $row['destination']->latitude;
            $currentLng = (float) $row['destination']->longitude;
        }

        return $sequence;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function buildDayByDayItems(Itinerary $itinerary, array $sequence, TouristPreference $preference, int $totalDays): void
    {
        $slots = ['Morning', 'Afternoon', 'Evening'];
        $stopsPerDay = self::DESTINATIONS_PER_DAY;
        $chunks = array_chunk($sequence, $stopsPerDay);

        $accommodationPlaced = false;

        foreach ($chunks as $dayIndex => $dayStops) {
            $dayNumber = $dayIndex + 1;
            if ($dayNumber > $totalDays) {
                break;
            }

            if (! $accommodationPlaced) {
                $accommodation = $this->pickAccommodation($dayStops, $preference);
                if ($accommodation) {
                    $itinerary->items()->create([
                        'day_number' => $dayNumber,
                        'slot' => 'Check-in',
                        'destination_id' => null,
                        'accommodation_id' => $accommodation->id,
                        'note' => 'Suggested accommodation for the trip, based on tourist visitation patterns near your recommended destinations.',
                    ]);
                    $accommodationPlaced = true;
                }
            }

            foreach ($dayStops as $stopIndex => $stop) {
                $destination = $stop['row']['destination'];
                $slot = $slots[$stopIndex] ?? 'Afternoon';
                $note = $this->buildComplementaryNote($destination, $stop['distance_km']);

                $itinerary->items()->create([
                    'day_number' => $dayNumber,
                    'slot' => $slot,
                    'destination_id' => $destination->id,
                    'accommodation_id' => null,
                    'note' => $note,
                ]);
            }
        }
    }

    /** Suggests a nearby accommodation using Apriori co-visitation, falling back to the tourist's stated accommodation preference. */
    private function pickAccommodation(array $dayStops, TouristPreference $preference): ?Accommodation
    {
        foreach ($dayStops as $stop) {
            $destination = $stop['row']['destination'];
            $suggestions = $this->apriori->suggestionsFor('destination', $destination->id, 3)
                ->filter(fn ($rule) => $rule['listing_kind'] === 'accommodation');

            if ($suggestions->isNotEmpty()) {
                return $suggestions->first()['listing'];
            }
        }

        $query = Accommodation::where('is_accredited', true)->whereNull('archived_at');
        if ($preference->accommodation_pref && $preference->accommodation_pref !== 'Any') {
            $query->where('type', $preference->accommodation_pref);
        }

        return $query->orderByDesc('rating')->first()
            ?? Accommodation::where('is_accredited', true)->whereNull('archived_at')->orderByDesc('rating')->first();
    }

    /** Builds a short note listing Apriori-derived complementary restaurants/souvenir shops/packages for this stop. */
    private function buildComplementaryNote($destination, ?float $distanceKm): string
    {
        $suggestions = $this->apriori->suggestionsFor('destination', $destination->id, 4)
            ->filter(fn ($rule) => in_array($rule['listing_kind'], ['restaurant', 'souvenir_center', 'package', 'tour_operator']));

        $parts = [];
        if ($distanceKm !== null) {
            $parts[] = sprintf('~%.1f km from the previous stop', $distanceKm);
        }
        if ($suggestions->isNotEmpty()) {
            $labels = $suggestions->take(2)->map(fn ($rule) => $rule['listing']->name.' ('.str_replace('_', ' ', $rule['listing_kind']).')');
            $parts[] = 'Frequently visited together: '.$labels->implode(', ');
        }

        return $parts ? implode('. ', $parts).'.' : '';
    }
}
