<?php

namespace App\Support;

use App\Models\Itinerary;

/**
 * "Would a traveller actually follow this itinerary?" as numbers, read from a SAVED itinerary (its rows and its stay_plan
 * notices). It is a reporting tool, like ItineraryAudit, and nothing that generates, ranks or schedules a trip uses it.
 *
 * Bands such as "a long driving day" come from config('itinerary.notes') and are only ever counted here. They are not rules:
 * nothing in this class changes a result, fails a run or moves a stop.
 */
class ItineraryFollowability
{
    /** A day's driving above this is counted as a day with a longer drive (the page's existing tip starts here). */
    public const LONGER_DRIVE_MINUTES = 240;

    /** The end of the dinner window: a stay reached after it is a late check-in. */
    public const LATE_CHECKIN_FROM = 19 * 60 + 30;

    /** The usual lunch hours end here (the builder starts no lunch after 13:00). */
    public const LUNCH_WINDOW_CLOSES = 13 * 60;

    /** Free time of at least this many minutes in a day is counted as a long idle block. */
    public const IDLE_BLOCK_MINUTES = 240;

    /**
     * @return array<string, mixed>  one trip's figures
     */
    public function analyse(Itinerary $trip, string $band): array
    {
        $items = $trip->items()->with('accommodation')->orderBy('day_number')->orderBy('sort_order')->get();
        $byDay = $items->groupBy('day_number');
        $totalDays = max(1, (int) $trip->total_days);
        $notices = collect(data_get($trip->stay_plan, 'notices', []));
        $unplaced = $notices->where('type', 'unplaced_destination')->values();
        $notes = ItineraryDayNotes::forItinerary($byDay, $totalDays);
        $long = ItineraryDayNotes::longDrivingMinutes();

        $placedIds = $items->where('kind', 'activity')->whereNotNull('destination_id')->pluck('destination_id')->unique();
        $driving = [];
        $lunch = ['days' => 0, 'with' => 0, 'arrival_after_window' => 0, 'not_arranged' => 0, 'other' => 0];
        $dinnerMissing = 0;
        $late = 0;
        $minutesLate = 0;
        $afterMidnight = 0;
        $idle = [];
        $stays = [];

        foreach (range(1, $totalDays) as $day) {
            $rows = $byDay->get($day, collect());
            $driving[$day] = ItineraryDayNotes::drivingMinutes($rows);
            $acts = $rows->where('kind', 'activity')->whereNotNull('destination_id');
            $night = $rows->firstWhere('kind', 'overnight');
            $isLate = $night && str_contains((string) $night->title, 'Late check-in');

            if ($night && $night->accommodation_id) {
                $stays[$day] = (int) $night->accommodation_id;
            }
            if ($isLate) {
                $late++;
                $at = $this->minutes($night->starts_at);
                $minutesLate += $at !== null ? max(0, $at - self::LATE_CHECKIN_FROM) : 0;
                $afterMidnight += $at === null ? 1 : 0;
            }
            if ($day < $totalDays && $night && ! $isLate && $rows->where('kind', 'meal')->filter(fn ($r) => str_starts_with((string) $r->title, 'Dinner'))->isEmpty()) {
                $dinnerMissing++;
            }
            foreach ($rows->where('kind', 'free') as $free) {
                $from = $this->minutes($free->starts_at);
                $to = $this->minutes($free->ends_at);
                if ($from !== null && $to !== null && $to - $from >= self::IDLE_BLOCK_MINUTES) {
                    $idle[] = $to - $from;
                }
            }
            if ($acts->isNotEmpty()) {
                $lunch['days']++;
                if ($rows->where('kind', 'meal')->contains(fn ($r) => str_starts_with((string) $r->title, 'Lunch'))) {
                    $lunch['with']++;
                } elseif (($this->minutes($acts->first()->starts_at) ?? 0) >= self::LUNCH_WINDOW_CLOSES) {
                    $lunch['arrival_after_window']++;
                } elseif (collect($notes[$day] ?? [])->contains('type', 'lunch_not_arranged')) {
                    $lunch['not_arranged']++;
                } else {
                    $lunch['other']++;
                }
            }
        }

        $nights = $totalDays - 1;
        $changes = 0;
        for ($n = 2; $n <= $nights; $n++) {
            if (isset($stays[$n], $stays[$n - 1]) && $stays[$n] !== $stays[$n - 1]) {
                $changes++;
            }
        }

        return [
            'band' => $band,
            'days' => $totalDays,
            'coverage' => [
                'placed' => $placedIds->count(),
                'omitted' => $unplaced->count(),
                'omitted_neutral' => $unplaced->where('reason', 'not_recorded')->count(),
                'causes' => $unplaced->countBy('reason')->all(),
            ],
            'driving' => [
                'per_day' => $driving,
                'longest' => max($driving),
                'days_over_longer_drive' => count(array_filter($driving, fn ($m) => $m > self::LONGER_DRIVE_MINUTES)),
                'days_over_long' => count(array_filter($driving, fn ($m) => $m > $long)),
            ],
            'continuity' => [
                'nights' => $nights,
                'nights_without_stay' => max(0, $nights - count(array_filter(array_keys($stays), fn ($d) => $d <= $nights))),
                'distinct_stays' => count(array_unique($stays)),
                'changes' => $changes,
            ],
            'late_checkins' => ['count' => $late, 'minutes_late' => $minutesLate, 'after_midnight' => $afterMidnight],
            'meals' => ['lunch' => $lunch, 'dinner_missing' => $dinnerMissing],
            'arrival_transfer_days' => collect($notes)->filter(fn ($n) => collect($n)->contains('type', 'arrival_transfer'))->count(),
            'idle' => ['blocks' => count($idle), 'longest' => $idle === [] ? 0 : max($idle)],
        ];
    }

    /**
     * Adds the trips of one band together. Figures that are a "longest" take the maximum; everything else is summed.
     *
     * @param  list<array<string, mixed>>  $trips
     * @return array<string, mixed>
     */
    public function summarise(array $trips): array
    {
        $sum = fn (callable $get) => array_sum(array_map($get, $trips));
        $causes = [];
        foreach ($trips as $t) {
            foreach ($t['coverage']['causes'] as $cause => $n) {
                $causes[$cause] = ($causes[$cause] ?? 0) + $n;
            }
        }
        ksort($causes);
        $dayCount = $sum(fn ($t) => $t['days']);
        $drivingTotal = $sum(fn ($t) => array_sum($t['driving']['per_day']));

        return [
            'trips' => count($trips),
            'days' => $dayCount,
            'coverage' => [
                'placed' => $sum(fn ($t) => $t['coverage']['placed']),
                'omitted' => $sum(fn ($t) => $t['coverage']['omitted']),
                'omitted_neutral' => $sum(fn ($t) => $t['coverage']['omitted_neutral']),
                'causes' => $causes,
            ],
            'driving' => [
                'average_per_day' => $dayCount > 0 ? (int) round($drivingTotal / $dayCount) : 0,
                'longest_day' => $trips === [] ? 0 : max(array_map(fn ($t) => $t['driving']['longest'], $trips)),
                'days_over_longer_drive' => $sum(fn ($t) => $t['driving']['days_over_longer_drive']),
                'days_over_long' => $sum(fn ($t) => $t['driving']['days_over_long']),
            ],
            'continuity' => [
                'nights' => $sum(fn ($t) => $t['continuity']['nights']),
                'nights_without_stay' => $sum(fn ($t) => $t['continuity']['nights_without_stay']),
                'changes' => $sum(fn ($t) => $t['continuity']['changes']),
            ],
            'late_checkins' => ['count' => $sum(fn ($t) => $t['late_checkins']['count']), 'minutes_late' => $sum(fn ($t) => $t['late_checkins']['minutes_late']), 'after_midnight' => $sum(fn ($t) => $t['late_checkins']['after_midnight'])],
            'meals' => [
                'sightseeing_days' => $sum(fn ($t) => $t['meals']['lunch']['days']),
                'with_lunch' => $sum(fn ($t) => $t['meals']['lunch']['with']),
                'no_lunch_arrival_after_window' => $sum(fn ($t) => $t['meals']['lunch']['arrival_after_window']),
                'no_lunch_not_arranged_note' => $sum(fn ($t) => $t['meals']['lunch']['not_arranged']),
                'no_lunch_other' => $sum(fn ($t) => $t['meals']['lunch']['other']),
                'dinner_missing' => $sum(fn ($t) => $t['meals']['dinner_missing']),
            ],
            'arrival_transfer_days' => $sum(fn ($t) => $t['arrival_transfer_days']),
            'idle' => ['blocks' => $sum(fn ($t) => $t['idle']['blocks']), 'longest' => $trips === [] ? 0 : max(array_map(fn ($t) => $t['idle']['longest'], $trips))],
        ];
    }

    private function minutes(mixed $time): ?int
    {
        if ($time instanceof \DateTimeInterface) {
            return (int) $time->format('G') * 60 + (int) $time->format('i');
        }

        return is_string($time) && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }
}

