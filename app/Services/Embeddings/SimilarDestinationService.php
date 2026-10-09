<?php

namespace App\Services\Embeddings;

use App\Models\Advisory;
use App\Models\Destination;
use App\Models\DestinationEmbedding;
use App\Models\Itinerary;
use App\Services\Recommendation\ContentBasedRecommendationService;
use Carbon\Carbon;
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
 *   - it must be open on the traveller's dates: not closed by its own operating
 *     status and not under an active danger advisory (HasOperatingStatus);
 *   - when both places have coordinates it must be within MAX_DISTANCE_KM of
 *     the stop it replaces, so a swap never sends the traveller across the region.
 *
 * Within what is allowed, the order is a blend, not similarity alone. Two places can
 * mean nearly the same thing and still be a poor swap -- the most similar substitute
 * for a convention centre was a golf club -- so the traveller's own interests and the
 * distance count too: 50% similarity of meaning, 30% how well the place fits the
 * interests the traveller picked, 20% how close it is to the stop it replaces.
 */
class SimilarDestinationService
{
    /** Furthest a substitute may be from the stop it replaces. */
    public const MAX_DISTANCE_KM = 60.0;

    /** How the order is made up; they add to 1. */
    private const WEIGHT_SIMILARITY = 0.5;

    private const WEIGHT_INTEREST = 0.3;

    private const WEIGHT_PROXIMITY = 0.2;

    /** True once at least one destination has a stored vector. */
    public function isAvailable(): bool
    {
        return DestinationEmbedding::query()->exists();
    }

    /**
     * The traveller's dates for this itinerary: its preference's travel window,
     * or just today when the preference is gone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(Itinerary $itinerary): array
    {
        return $itinerary->preference?->travelWindow() ?? [now()->startOfDay(), now()->startOfDay()];
    }

    /**
     * Destination ids that cannot be visited on these dates.
     *
     * @param  array{0: Carbon, 1: Carbon}  $window
     * @return array<int, int>
     */
    public function unavailableDestinationIds(array $window): array
    {
        $open = Destination::query()->availableDuring(...$window)->pluck('id');

        return Destination::query()->whereNotIn('id', $open)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Why this place cannot be visited on these dates, or null when it can.
     *
     * @param  array{0: Carbon, 1: Carbon}  $window
     * @return array{reason: string, source: string}|null  source is 'advisory' or 'status'
     */
    public function closure(Destination $destination, array $window): ?array
    {
        $advisory = Advisory::active()
            ->where('listing_kind', 'destination')
            ->where('listing_id', $destination->id)
            ->where('severity', Destination::CLOSING_ADVISORY_SEVERITY)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $window[1]->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $window[0]->toDateString()))
            ->urgentFirst()
            ->first();

        if ($advisory) {
            return ['reason' => $advisory->title, 'source' => 'advisory'];
        }

        if ($destination->isClosedByStatus($window[0])) {
            return ['reason' => $destination->operatingNotice() ?? 'Closed', 'source' => 'status'];
        }

        return null;
    }

    /**
     * The best substitutes for $original within this itinerary.
     *
     * @return Collection<int, array{destination: Destination, similarity: float, distance_km: ?float, score: float, interest_fit: ?float}>
     *                                                                                                                                       interest_fit is null when the traveller picked no interests
     */
    public function alternatives(Destination $original, Itinerary $itinerary, int $limit = 3): Collection
    {
        $originalVector = DestinationEmbedding::where('destination_id', $original->id)->value('vector');

        if (! is_array($originalVector)) {
            return collect();
        }

        $inTrip = $itinerary->items()->whereNotNull('destination_id')->pluck('destination_id')
            ->map(fn ($id) => (int) $id)->all();

        $candidates = Destination::publiclyVisible()
            ->availableDuring(...$this->windowFor($itinerary))
            ->where(fn ($q) => $q->whereNull('itinerary_role')->orWhere('itinerary_role', 'sightseeing'))
            ->whereNotIn('id', array_merge($inTrip, [$original->id]))
            ->with('tags')
            ->get()
            ->keyBy('id');

        if ($candidates->isEmpty()) {
            return collect();
        }

        $vectors = DestinationEmbedding::whereIn('destination_id', $candidates->keys())->get()->keyBy('destination_id');
        $interestFit = $this->interestFit($itinerary, $candidates);

        return $candidates
            ->map(function (Destination $candidate) use ($original, $originalVector, $vectors, $interestFit) {
                $embedding = $vectors->get($candidate->id);
                if (! $embedding) {
                    return null;
                }

                $distance = $this->distanceKm($original, $candidate);
                if ($distance !== null && $distance > self::MAX_DISTANCE_KM) {
                    return null;
                }

                $similarity = round(DestinationEmbeddingService::similarity($originalVector, $embedding->vector), 4);
                $fit = $interestFit?->get($candidate->id) ?? ($interestFit === null ? null : 0.0);

                // No coordinates: neither near nor far, so the middle of the scale rather than a guess.
                $proximity = $distance === null ? 0.5 : 1 - min($distance, self::MAX_DISTANCE_KM) / self::MAX_DISTANCE_KM;

                return [
                    'destination' => $candidate,
                    'similarity' => $similarity,
                    'distance_km' => $distance !== null ? round($distance, 1) : null,
                    'interest_fit' => $fit,
                    'score' => round(
                        self::WEIGHT_SIMILARITY * $similarity
                        + self::WEIGHT_INTEREST * ($fit ?? 1.0)
                        + self::WEIGHT_PROXIMITY * $proximity,
                        4
                    ),
                ];
            })
            ->filter()
            ->sort(fn (array $a, array $b) => [$b['score'], $b['similarity']] <=> [$a['score'], $a['similarity']])
            ->take($limit)
            ->values();
    }

    /**
     * Whether each candidate fits at least one of the interests the traveller picked: 1.0 if it does, 0.0 if
     * it does not, from the same content-based scoring the itinerary was built with. The scoring itself
     * grades by how many of the picked interests a place covers (a farm covering one of three scores a third),
     * but for choosing a substitute the question is whether it fits the traveller's interests at all, and a
     * tagged place would otherwise always lose to an untagged one that happens to score 1.
     * Null when the traveller picked no interests (then it ranks nothing). A candidate the scoring leaves out
     * (outside the traveller's distance range) counts as 0.
     *
     * @param  Collection<int, Destination>  $candidates
     * @return Collection<int, float>|null  keyed by destination id
     */
    private function interestFit(Itinerary $itinerary, Collection $candidates): ?Collection
    {
        $preference = $itinerary->preference;

        if (! $preference) {
            return null;
        }

        $preference->loadMissing('activities', 'amenities');

        if ($preference->activities->isEmpty()) {
            return null;
        }

        return app(ContentBasedRecommendationService::class)
            ->rank($preference, $candidates)
            ->mapWithKeys(fn (array $row) => [$row['destination']->id => $row['interest_fit'] > 0 ? 1.0 : 0.0]);
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
