<?php

namespace App\Services\Recommendation;

use App\Models\TourOperator;
use Illuminate\Support\Collection;

/**
 * Which tour operators to suggest to a traveller who wants to be picked up at their starting point.
 *
 * The catalogue holds an operator's name, base, specialization and contact number. It holds nothing about
 * where they collect travellers, when, in what vehicle or for what price, so this does not pretend to match a
 * traveller to one. It only answers "who is close to where you start", and the itinerary tells the traveller
 * to confirm the pick-up with the operator.
 *
 * Operators with coordinates come first, nearest first. Most operators have none, so when those do not fill
 * the list the rest come from the region of the nearest located one (the traveller's own area), best reviewed
 * first. An operator whose position is unknown never gets a distance made up for it.
 */
class TourOperatorSuggester
{
    public const LIMIT = 3;

    /** The starting point when the traveller shared none: the Davao City centre, as the itinerary itself uses. */
    private const DEFAULT_REGION = 'Davao City';

    /**
     * @return Collection<int, array{operator: TourOperator, km: float|null}>
     */
    public function near(?float $lat, ?float $lng, int $limit = self::LIMIT, ?string $transport = null): Collection
    {
        $lat ??= ContentBasedRecommendationService::DEFAULT_ORIGIN_LAT;
        $lng ??= ContentBasedRecommendationService::DEFAULT_ORIGIN_LNG;

        $operators = TourOperator::publiclyVisible()->where('is_accredited', true)->with('region')->get();

        if (TransportMode::isRentalCar($transport)) {
            $rentalPool = $operators->filter(fn (TourOperator $o) => $this->isRentalOrTransportOperator($o))->values();
            $picked = $this->pickFromPool($rentalPool, $lat, $lng, $limit);

            if ($picked->count() < $limit) {
                $taken = $picked->pluck('operator.id');
                $remainingPool = $operators->reject(fn (TourOperator $o) => $taken->contains($o->id))->values();
                $picked = $picked->concat($this->pickFromPool($remainingPool, $lat, $lng, $limit - $picked->count()));
            }

            return $picked->values();
        }

        return $this->pickFromPool($operators, $lat, $lng, $limit);
    }

    /**
     * @param  Collection<int, TourOperator>  $operators
     * @return Collection<int, array{operator: TourOperator, km: float|null}>
     */
    private function pickFromPool(Collection $operators, float $lat, float $lng, int $limit): Collection
    {
        if ($limit <= 0 || $operators->isEmpty()) {
            return collect();
        }

        $located = $operators
            ->filter(fn (TourOperator $o) => $o->latitude !== null && $o->longitude !== null)
            ->map(fn (TourOperator $o) => [
                'operator' => $o,
                'km' => round($this->haversineKm($lat, $lng, (float) $o->latitude, (float) $o->longitude), 1),
            ])
            ->sortBy('km')
            ->values();

        $picked = $located->take($limit);

        if ($picked->count() < $limit) {
            $region = $located->first()['operator']->region?->name ?? self::DEFAULT_REGION;
            $taken = $picked->pluck('operator.id');

            $fill = $operators
                ->reject(fn (TourOperator $o) => $taken->contains($o->id) || ($o->latitude !== null && $o->longitude !== null))
                ->filter(fn (TourOperator $o) => $o->region?->name === $region)
                ->sortBy([['rating', 'desc'], ['review_count', 'desc'], ['name', 'asc']])
                ->take($limit - $picked->count())
                ->map(fn (TourOperator $o) => ['operator' => $o, 'km' => null]);

            $picked = $picked->concat($fill);
        }

        return $picked->values();
    }

    private function isRentalOrTransportOperator(TourOperator $operator): bool
    {
        if (strcasecmp((string) $operator->specialization, 'Tourist Transport') === 0) {
            return true;
        }

        if (strcasecmp((string) $operator->specialization, 'Island Cruises') === 0) {
            return false;
        }

        $haystack = $operator->name.' '.($operator->specialization ?? '').' '.($operator->description ?? '');

        return preg_match('/\b(rent[\s-]*a[\s-]*car|car\s+rentals?|vehicle\s+rentals?|tourist\s+transport)\b/i', $haystack) === 1;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
