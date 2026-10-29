<?php

namespace App\Services\Establishment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How travellers are finding and visiting one listing, from what the site already records: QR check-ins
 * (tourist_visits) and the trip plans that included the place (itinerary_items).
 *
 * Every number is a plain count over a window of days, set beside the window of the same length before it.
 */
class ListingInsights
{
    public const RANGES = [7, 30, 90];

    /** The itinerary_items column that points at each kind of listing; kinds without one never appear in a trip plan. */
    private const ITEM_COLUMN = [
        'destination' => 'destination_id',
        'accommodation' => 'accommodation_id',
        'restaurant' => 'restaurant_id',
        'souvenir_center' => 'souvenir_center_id',
    ];

    public static function hasTripPlans(string $kind): bool
    {
        return isset(self::ITEM_COLUMN[$kind]);
    }

    /** Check-ins in the last $days days (today included), $before the same number of days before those. */
    public function checkIns(string $kind, int $id, int $days): array
    {
        [$from, $to] = $this->window($days);

        return [
            'total' => $this->visitCount($kind, $id, $from, $to),
            'before' => $this->visitCount($kind, $id, $from->copy()->subDays($days), $from->copy()->subDay()),
        ];
    }

    /**
     * Check-ins for the chart: one bar a day for 7 and 30 days, one a week for 90.
     *
     * @return array<int, array{label: string, title: string, value: int}>
     */
    public function series(string $kind, int $id, int $days): array
    {
        [$from, $to] = $this->window($days);

        // 90 days is shown as 13 whole weeks ending today, so the first bar starts a day before the window does
        $step = $days === 90 ? 7 : 1;
        $cursor = $days === 90 ? $to->copy()->subDays(7 * 13 - 1) : $from->copy();
        $first = $cursor->copy();

        $perDay = DB::table('tourist_visits')
            ->where('listing_kind', $kind)->where('listing_id', $id)
            ->whereDate('visit_date', '>=', $first->toDateString())->whereDate('visit_date', '<=', $to->toDateString())
            ->selectRaw('visit_date as day, count(*) as n')->groupBy('visit_date')
            ->pluck('n', 'day');

        $bars = [];

        while ($cursor <= $to) {
            $value = 0;
            for ($i = 0; $i < $step; $i++) {
                $value += (int) ($perDay[$cursor->copy()->addDays($i)->toDateString()] ?? 0);
            }

            $bars[] = [
                'label' => match ($days) {
                    7 => $cursor->format('D'),
                    30 => ((int) $cursor->diffInDays($first) % 5 === 0) ? $cursor->format('j') : '',
                    default => (count($bars) % 3 === 0) ? $cursor->format('M j') : '',
                },
                'title' => ($days === 90 ? 'Week of ' : '').$cursor->format('M j'),
                'value' => $value,
            ];

            $cursor->addDays($step);
        }

        return $bars;
    }

    /**
     * Trip plans that included the listing, and on which day of the trip.
     *
     * @return array{total: int, before: int, byDay: array{1: int, 2: int, 3: int}}|null  null for kinds a plan never contains
     */
    public function tripPlans(string $kind, int $id, int $days): ?array
    {
        $column = self::ITEM_COLUMN[$kind] ?? null;
        if ($column === null) {
            return null;
        }

        [$from, $to] = $this->window($days);
        $count = fn (Carbon $a, Carbon $b) => (int) DB::table('itinerary_items')
            ->join('itineraries', 'itineraries.id', '=', 'itinerary_items.itinerary_id')
            ->where("itinerary_items.{$column}", $id)
            ->whereBetween('itineraries.generated_at', [$a->copy()->startOfDay(), $b->copy()->endOfDay()])
            ->distinct()->count('itinerary_items.itinerary_id');

        $byDay = DB::table('itinerary_items')
            ->join('itineraries', 'itineraries.id', '=', 'itinerary_items.itinerary_id')
            ->where("itinerary_items.{$column}", $id)
            ->whereBetween('itineraries.generated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('itinerary_items.itinerary_id as trip, min(itinerary_items.day_number) as day')
            ->groupBy('itinerary_items.itinerary_id')
            ->pluck('day');

        return [
            'total' => $count($from, $to),
            'before' => $count($from->copy()->subDays($days), $from->copy()->subDay()),
            'byDay' => [
                1 => $byDay->filter(fn ($d) => (int) $d === 1)->count(),
                2 => $byDay->filter(fn ($d) => (int) $d === 2)->count(),
                3 => $byDay->filter(fn ($d) => (int) $d >= 3)->count(),
            ],
        ];
    }

    /** "+12%" / "-5%" / null when there is nothing before to compare with. */
    public static function change(int $now, int $before): ?int
    {
        return $before === 0 ? null : (int) round(($now - $before) / $before * 100);
    }

    /** @return array{0: Carbon, 1: Carbon} the first and last day of the window, Philippine time */
    private function window(int $days): array
    {
        $to = Carbon::now('Asia/Manila')->startOfDay();

        return [$to->copy()->subDays($days - 1), $to];
    }

    private function visitCount(string $kind, int $id, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('tourist_visits')
            ->where('listing_kind', $kind)->where('listing_id', $id)
            ->whereDate('visit_date', '>=', $from->toDateString())->whereDate('visit_date', '<=', $to->toDateString())
            ->count();
    }
}
