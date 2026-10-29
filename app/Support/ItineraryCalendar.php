<?php

namespace App\Support;

use App\Models\Itinerary;
use Illuminate\Support\Carbon;

/**
 * An itinerary as an iCalendar (.ics) file, so a traveller can put the plan in their own calendar.
 *
 * One event for each stop, meal and night's stay, on the date the day falls on. The plan's times are Manila
 * time and are written as UTC, which every calendar app reads the same way. Journeys, the starting row,
 * free time and the departure are left out: they are not things to attend.
 *
 * Needs the trip's arrival date; without one there is no day to put an event on, and null is returned.
 */
class ItineraryCalendar
{
    private const TIMEZONE = 'Asia/Manila';

    /** An event with no end time of its own (a meal, the night's check-in) is shown as an hour long. */
    private const DEFAULT_MINUTES = 60;

    public function build(Itinerary $itinerary, mixed $startDate): ?string
    {
        if (blank($startDate)) {
            return null;
        }

        $first = Carbon::parse($startDate, self::TIMEZONE)->startOfDay();
        $stamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ExploreDVO//Itinerary//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape('My Davao itinerary'),
        ];

        foreach ($itinerary->items->sortBy([['day_number', 'asc'], ['sort_order', 'asc']]) as $item) {
            if (! in_array($item->kind, ['activity', 'meal', 'overnight'], true) || blank($item->starts_at)) {
                continue;
            }

            $day = $first->copy()->addDays(max(0, (int) $item->day_number - 1));
            $start = Carbon::parse($day->format('Y-m-d').' '.$item->starts_at, self::TIMEZONE);
            $end = filled($item->ends_at)
                ? Carbon::parse($day->format('Y-m-d').' '.$item->ends_at, self::TIMEZONE)
                : $start->copy()->addMinutes(self::DEFAULT_MINUTES);
            if ($end->lessThanOrEqualTo($start)) {
                $end = $start->copy()->addMinutes(self::DEFAULT_MINUTES);
            }

            $listing = $item->listing();
            $summary = match ($item->kind) {
                'overnight' => 'Check in: '.($listing->name ?? 'your stay'),
                'activity' => $listing->name ?? $item->title,
                default => $item->title,
            };

            $description = trim($item->title !== $summary ? $item->title : '');
            $description .= ($description !== '' ? "\n" : '').'Planned by ExploreDVO. Times are estimates, so check opening hours before you go.';

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$itinerary->getKey().'-'.$item->getKey().'@exploredavao';
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$end->copy()->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$this->escape($summary);
            if ($listing && filled($listing->location ?? null)) {
                $lines[] = 'LOCATION:'.$this->escape($listing->location);
            }
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines))."\r\n";
    }

    /** Text values escape backslash, semicolon, comma and line breaks (RFC 5545 section 3.3.11). */
    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }

    /** Lines longer than 75 bytes continue on the next line, which starts with one space (RFC 5545 section 3.1). */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === '' ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $out."\r\n ".$current;
    }
}
