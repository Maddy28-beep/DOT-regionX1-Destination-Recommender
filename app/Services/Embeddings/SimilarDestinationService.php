<?php

namespace App\Services\Embeddings;

use App\Models\Advisory;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\Itinerary;
use Illuminate\Support\Collection;

/**
 * "Similar-place swaps": for a stop in a generated itinerary, finds the places
 * that mean most nearly the same thing, ranked by cosine similarity of their
 * pretrained-embedding vectors (DestinationEmbeddingService).
 *
 * The model only supplies the meaning. Everything that decides whether a place
 * is a fair substitute is ordinary, checkable code:
 *   - it must be a public, accredited, non-archived sightseeing destination;
 *   - it must not already be in the trip;
 *   - it must not be under an active danger advisory (a closure or safety notice);
 *   - when both places have coordinates it must be within MAX_DISTANCE_KM of
 *     the stop it replaces, so a swap never sends the traveller across the region.
 */
class SimilarDestinationService
{
    /** Furthest a substitute may be from the stop it replaces. */
    public const MAX_DISTANCE_KM = 60.0;

    /** Advisory severity that counts as "do not offer this place". */
    public const CLOSING_SEVERITY = 'danger';

    /** True once at least one destination has a stored vector. */
    public function isAvailable(): bool
    {
        return DestinationEmbedding::query()->exists();
    }

    /**
     * Destination ids that are under an active danger advisory right now.
     *
     * @return array<int, int>
     */
    public function closedDestinationIds(): array
    {
        return Advisory::active()
            ->where('listing_kind', 'destination')
            ->where('severity', self::CLOSING_SEVERITY)
            ->pluck('listing_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** The first active danger advisory on one destination, for display. */
    public function closingAdvisory(int $destinationId): ?Advisory
    {
        return Advisory::active()
            ->where('listing_kind', 'destination')
            ->where('listing_id', $destinationId)
            ->where('severity', self::CLOSING_SEVERITY)
            ->urgentFirst()
            ->first();
    }

    /**
     * The best substitutes for $original within this itinerary.
     *
     * @return Collection<int, array{destination: Destination, similarity: float, distance_km: ?float}>
     */
    public function alternatives(Destination $original, Itinerary $itinerary, int $limit = 3): Collection
    {
        $originalVector = DestinationEmbedding::where('destination_id', $original->id)->value('vector');

        if (! is_array($originalVector)) {
            return collect();
        }

        $inTrip = $itinerary->items()->whereNotNull('destination_id')->pluck('destination_id')
            ->map(fn ($id) => (int) $id)->all();
        $excluded = array_merge($inTrip, [$original->id], $this->closedDestinationIds());

        $candidates = Destination::publiclyVisible()
            ->where(fn ($q) => $q->whereNull('itinerary_role')->orWhere('itinerary_role', 'sightseeing'))
            ->whereNotIn('id', $excluded)
            ->get()
            ->keyBy('id');

        if ($candidates->isEmpty()) {
            return collect();
        }

        $vectors = DestinationEmbedding::whereIn('destination_id', $candidates->keys())->get()->keyBy('destination_id');

        return $candidates
            ->map(function (Destination $candidate) use ($original, $originalVector, $vectors) {
                $embedding = $vectors->get($candidate->id);
                if (! $embedding) {
                    return null;
                }

                $distance = $this->distanceKm($original, $candidate);
                if ($distance !== null && $distance > self::MAX_DISTANCE_KM) {
                    return null;
                }

                return [
                    'destination' => $candidate,
                    'similarity' => round(DestinationEmbeddingService::similarity($originalVector, $embedding->vector), 4),
                    'distance_km' => $distance !== null ? round($distance, 1) : null,
                ];
            })
            ->filter()
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }

    private function distanceKm(Destination $a, Destination $b): ?float
    {
        if ($a->latitude === null || $a->longitude === null || $b->latitude === null || $b->longitude === null) {
            return null;
        }

        $lat1 = deg2rad((float) $a->latitude);
        $lat2 = deg2rad((float) $b->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $b->longitude - (float) $a->longitude);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1.0, sqrt($h)));
    }
}
