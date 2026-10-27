<?php

namespace App\Services\Recommendation;

use App\Models\Accommodation;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\SouvenirCenter;
use App\Models\TouristPreference;

/**
 * Optional ideas for the Day 1 "Free time until dinner" row: up to three souvenir centers near the accommodation.
 *
 * This reads an itinerary that already exists and returns data for the page to show. It never writes, never
 * changes a row, and is not part of generating, ranking or scheduling a trip: the ideas are not stops, have no
 * times, and nothing is booked. Where the data to suggest something sensibly is missing (no free-time row, no
 * accommodation, no coordinates) it returns nothing rather than guessing.
 *
 * The same day's free time, the accommodation the dinner row names, the trip's first date and the travel estimates
 * the timeline already uses are all it needs.
 */
class FreeTimeIdeas
{
    /** The most ideas shown under one free-time row. */
    public const MAX_IDEAS = 3;

    /** How long a visit to a shop is assumed to take, for judging whether it fits before dinner. */
    public const VISIT_MINUTES = 60;

    public function __construct(private readonly TravelTimeEstimator $travel) {}

    /**
     * @return array<int, list<array{shop: SouvenirCenter, km: float}>> free-time item id => its ideas (empty when there are none)
     */
    public function forItinerary(Itinerary $itinerary, TouristPreference $preference): array
    {
        if ($itinerary->package_id) {
            return [];
        }

        $free = $this->dayOneFreeTime($itinerary);

        if ($free === null) {
            return [];
        }

        [$freeRow, $accommodation] = $free;

        $window = $this->windowMinutes($freeRow);

        if ($window === null
            || $accommodation->latitude === null || $accommodation->longitude === null
        ) {
            return [];
        }

        $originLat = (float) $accommodation->latitude;
        $originLng = (float) $accommodation->longitude;
        $dayOne = $preference->travelWindow()[0];
        $scheduled = $itinerary->items->pluck('souvenir_center_id')->filter()->map(fn ($id) => (int) $id)->all();

        $candidates = [];

        $shops = SouvenirCenter::query()
            ->publiclyVisible()
            ->availableDuring($dayOne->copy(), $dayOne->copy())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($scheduled !== [], fn ($q) => $q->whereNotIn('id', $scheduled))
            ->with('region')
            ->get();

        foreach ($shops as $shop) {
            $straightKm = $this->haversineKm($originLat, $originLng, (float) $shop->latitude, (float) $shop->longitude);
            $leg = $this->travel->estimate($straightKm, $accommodation->region?->name, $shop->region?->name, $preference->transport_pref);

            // Both ways on the slow estimate, plus the visit, has to fit before dinner.
            if (2 * $leg['max_minutes'] + self::VISIT_MINUTES > $window) {
                continue;
            }

            $candidates[] = ['shop' => $shop, 'km' => $leg['distance_km'], 'straight' => $straightKm];
        }

        usort($candidates, fn (array $a, array $b) => [$a['straight'], -((float) ($a['shop']->rating ?? 0)), $a['shop']->id]
            <=> [$b['straight'], -((float) ($b['shop']->rating ?? 0)), $b['shop']->id]);

        $ideas = [];
        $names = [];

        foreach ($candidates as $candidate) {
            $name = mb_strtolower(trim((string) $candidate['shop']->name));

            // Two listings with the same name are one idea.
            if (isset($names[$name])) {
                continue;
            }

            $names[$name] = true;
            $ideas[] = ['shop' => $candidate['shop'], 'km' => $candidate['km']];

            if (count($ideas) === self::MAX_IDEAS) {
                break;
            }
        }

        return $ideas === [] ? [] : [$freeRow->id => $ideas];
    }

    /**
     * Day 1's "Free time until dinner" row, and the accommodation its dinner row names.
     *
     * Found by structure, not by title: a free row on Day 1 that is directly followed by the dinner row of an
     * accommodation. The early-arrival free time is followed by a journey, so it is never matched.
     *
     * @return array{0: ItineraryItem, 1: Accommodation}|null
     */
    private function dayOneFreeTime(Itinerary $itinerary): ?array
    {
        $rows = $itinerary->items->where('day_number', 1)->sortBy('sort_order')->values();

        foreach ($rows as $i => $row) {
            $next = $rows[$i + 1] ?? null;

            if ($row->kind !== 'free'
                || $next === null
                || $next->kind !== 'meal'
                || ! str_starts_with((string) $next->title, 'Dinner')
                || ! $next->accommodation_id
            ) {
                continue;
            }

            $accommodation = $next->accommodation;

            return $accommodation ? [$row, $accommodation] : null;
        }

        return null;
    }

    /** The free time in minutes, or null when the row has no usable start and end. */
    private function windowMinutes(ItineraryItem $row): ?int
    {
        if (blank($row->starts_at) || blank($row->ends_at)) {
            return null;
        }

        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $window = $minutes((string) $row->ends_at) - $minutes((string) $row->starts_at);

        return $window > 0 ? $window : null;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
