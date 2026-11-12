<?php

namespace App\Support;

use App\Models\Itinerary;
use App\Models\TouristPreference;
use App\Services\Recommendation\ItineraryScheduleBuilder;
use Illuminate\Support\Carbon;

/**
 * "Every itinerary has a start and an end, each with a date and a time", in Philippine time.
 *
 *  - Start: the arrival date and time the traveller gave. A blank time is the planner's earliest start (said to be assumed);
 *    a blank date is said to be missing, never replaced by today's date.
 *  - End: the last day of the trip (arrival date + days - 1) and the "finish by" time. With no finish-by time it is the
 *    time the last day's schedule actually ends.
 *
 * Dates and times carry no zone of their own: they are the wall-clock time in the Philippines, and are labelled "PHT".
 */
final class TripSpan
{
    /**
     * @return array{start_label: string, end_label: string, start_date: ?string, end_date: ?string, start_assumed: bool, end_source: string, weekday_unknown: bool}
     */
    public static function for(TouristPreference $preference, Itinerary $itinerary): array
    {
        $startAssumed = blank($preference->arrival_time);
        $startTime = Carbon::parse($startAssumed ? ItineraryScheduleBuilder::EARLIEST_DEPARTURE : substr((string) $preference->arrival_time, 0, 5));

        $startDate = $preference->start_date;
        $endDate = $preference->departureDate();

        if (filled($preference->end_time)) {
            $endTime = Carbon::parse(substr((string) $preference->end_time, 0, 5));
            $endSource = 'finish_by';
        } else {
            $last = $itinerary->items->where('day_number', (int) $itinerary->total_days);
            $stamps = $last->map(fn ($item) => $item->ends_at ?: $item->starts_at)->filter()->map(fn ($t) => Carbon::parse($t));
            $endTime = $stamps->isNotEmpty() ? $stamps->max() : null;
            $endSource = $endTime ? 'planned' : 'unknown';
        }

        $when = fn (?Carbon $date, ?Carbon $time, string $noDate) => trim(
            ($date ? $date->format('D, j M Y') : $noDate).($time ? ', '.$time->format('g:i A').' PHT' : '')
        );

        return [
            'start_label' => $when($startDate, $startTime, 'date not set').($startAssumed ? ' (assumed start)' : ''),
            'end_label' => $when($endDate, $endTime, 'date not set').match ($endSource) {
                'finish_by' => ' (finish by)',
                'planned' => ' (when the plan ends)',
                default => '',
            },
            'start_date' => $startDate?->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'start_assumed' => $startAssumed,
            'end_source' => $endSource,
            'weekday_unknown' => $startDate === null,
        ];
    }
}
