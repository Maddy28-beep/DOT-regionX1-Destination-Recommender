<?php

namespace App\Support;

use App\Models\Itinerary;
use App\Models\TouristPreference;
use Illuminate\Support\Carbon;

/**
 * "Every itinerary has a start and an end, each with a date and a time", in Philippine time, and the line the itinerary
 * header shows: "From <origin> · Pick-up at <location> · <start> → <end> (<duration>)".
 *
 * The start and end are the traveller's own moments (TouristPreference::tripStart / tripEnd). A trip saved before they
 * existed gets them mapped from its arrival date and time (TripWindow::fromLegacy). Dates and times carry no zone of their
 * own: they are wall-clock time in the Philippines, and are labelled "PHT".
 */
final class TripSpan
{
    /**
     * @return array{start_label: string, end_label: string, duration: ?string, duration_days: ?int, from: ?string, pickup: string, summary: string, known: bool}
     */
    public static function for(TouristPreference $preference, ?Itinerary $itinerary = null): array
    {
        $start = $preference->tripStart();
        $end = $preference->tripEnd();

        $from = trim((string) $preference->place_of_origin);
        $from = ($from === '' || $from === 'Prefer not to say') ? null : $from;
        $pickup = $preference->startLabel();

        $label = fn (?Carbon $moment) => $moment ? $moment->format('D, j M Y, g:i A').' PHT' : 'not set';
        $duration = $start && $end ? TripWindow::text($start, $end) : null;

        $parts = array_filter([
            $from ? 'From '.$from : null,
            'Pick-up at '.$pickup,
            $start && $end ? $label($start).' → '.$label($end).' ('.$duration.')' : null,
        ]);

        return [
            'start_label' => $label($start),
            'end_label' => $label($end),
            'duration' => $duration,
            'duration_days' => $preference->tripDurationDays(),
            'from' => $from,
            'pickup' => $pickup,
            'summary' => implode(' · ', $parts),
            'known' => $start !== null && $end !== null,
        ];
    }
}
