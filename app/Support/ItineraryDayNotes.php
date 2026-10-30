<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plain-language notes about the days of a SAVED itinerary, read from its rows. It only reads: it never writes a row,
 * and nothing that generates, ranks or schedules a trip uses it.
 *
 * Every sentence states facts that are in the rows (times, journeys, the stay's name, the next day with sightseeing).
 * None says WHY a day is the way it is, because the rows do not record that.
 *
 *  - arrival_transfer  day 1 of a trip of two or more days with no sightseeing and a journey to the stay
 *  - late_checkin      a night that ends in a "Late check-in" (after the dinner window)
 *  - lunch_not_arranged  a "Free time until dinner" that covers the usual lunch hours on a day with sightseeing and no lunch
 */
class ItineraryDayNotes
{
    /** The usual lunch hours, as the builder plans them (it may start a lunch from 11:30 to 13:00). */
    private const LUNCH_FROM = 11 * 60 + 30;

    private const LUNCH_TO = 13 * 60;

    /** Driving over this many minutes in a day is called a long driving day. Message-only, see config/itinerary.php. */
    public static function longDrivingMinutes(): int
    {
        return (int) config('itinerary.notes.long_driving_day_minutes', 480);
    }

    /** The day's driving, on the slow-end estimates the schedule is built with. */
    public static function drivingMinutes(Collection $rows): int
    {
        return (int) $rows->where('kind', 'travel')->sum('travel_max_minutes');
    }

    /**
     * @param  Collection<int, Collection>  $itemsByDay  day number => that day's rows, in order (accommodation loaded if the name should show)
     * @return array<int, list<array{type: string, text: string}>>  day number => notes (days with none are left out)
     */
    public static function forItinerary(Collection $itemsByDay, int $totalDays): array
    {
        $notes = [];
        $firstSightseeingDay = $itemsByDay->filter(fn ($rows) => $rows->where('kind', 'activity')->isNotEmpty())->keys()->min();

        foreach ($itemsByDay as $day => $rows) {
            $day = (int) $day;
            $rows = $rows->values();
            $found = array_values(array_filter([
                self::arrivalTransfer($rows, $day, $totalDays, $firstSightseeingDay),
                self::lateCheckin($rows),
                self::lunchNotArranged($rows),
            ]));
            if ($found !== []) {
                $notes[$day] = $found;
            }
        }

        return $notes;
    }

    private static function arrivalTransfer(Collection $rows, int $day, int $totalDays, ?int $firstSightseeingDay): ?array
    {
        if ($day !== 1 || $totalDays < 2 || $rows->where('kind', 'activity')->isNotEmpty()) {
            return null;
        }
        $stay = $rows->firstWhere('kind', 'overnight');
        $driving = self::drivingMinutes($rows);
        if ($stay === null || $driving === 0) {
            return null;
        }

        $arrived = $rows->firstWhere('kind', 'baseline');
        $text = 'Day 1 is mainly the journey to your stay.';
        if ($arrived && self::minutes($arrived->starts_at) !== null) {
            $text .= ' You arrive at '.self::clock($arrived->starts_at).' and travel up to '.self::duration($driving);
        } else {
            $text .= ' The journey takes up to '.self::duration($driving);
        }
        $name = $stay->accommodation?->name;
        $text .= $name ? ' to '.$name.'.' : '.';
        if ($firstSightseeingDay !== null) {
            $text .= ' Your first sightseeing stop is on day '.$firstSightseeingDay.'.';
        }

        return ['type' => 'arrival_transfer', 'text' => $text];
    }

    private static function lateCheckin(Collection $rows): ?array
    {
        $stay = $rows->first(fn ($r) => $r->kind === 'overnight' && str_contains((string) $r->title, 'Late check-in'));
        if ($stay === null) {
            return null;
        }
        $lastJourney = $rows->where('kind', 'travel')->last();
        $name = $stay->accommodation?->name ?: 'your stay';

        if (self::minutes($stay->starts_at) !== null) {
            $text = 'You reach '.$name.' at '.self::clock($stay->starts_at).', after the usual dinner hours';
        } elseif (str_contains((string) $stay->note, 'after midnight')) {
            // The schedule shows no time for a row past midnight, and says so in the row's own note.
            $text = 'You reach '.$name.' after midnight, so no set time is shown';
        } else {
            return null;
        }
        $text .= $lastJourney && $lastJourney->travel_max_minutes ? '; the last journey is up to '.self::duration((int) $lastJourney->travel_max_minutes).'.' : '.';

        return ['type' => 'late_checkin', 'text' => $text];
    }

    private static function lunchNotArranged(Collection $rows): ?array
    {
        if ($rows->where('kind', 'activity')->isEmpty()
            || $rows->contains(fn ($r) => $r->kind === 'meal' && str_starts_with((string) $r->title, 'Lunch'))) {
            return null;
        }
        $free = $rows->first(function ($r) {
            $from = self::minutes($r->starts_at);
            $to = self::minutes($r->ends_at);

            return $r->kind === 'free' && str_starts_with((string) $r->title, 'Free time until dinner')
                && $from !== null && $to !== null && $from < self::LUNCH_TO && $to > self::LUNCH_FROM;
        });
        if ($free === null) {
            return null;
        }

        return ['type' => 'lunch_not_arranged', 'text' => 'Lunch is not planned on this day. The free time from '.self::clock($free->starts_at).' covers the usual lunch hours (11:30 AM to 1:00 PM), so lunch is yours to arrange.'];
    }

    private static function minutes(mixed $time): ?int
    {
        if ($time instanceof DateTimeInterface) {
            return (int) $time->format('G') * 60 + (int) $time->format('i');
        }
        if (is_string($time) && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return null;
    }

    private static function clock(mixed $time): string
    {
        $m = self::minutes($time) ?? 0;

        return Carbon::createFromTime(intdiv($m, 60) % 24, $m % 60)->format('g:i A');
    }

    private static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }
}
