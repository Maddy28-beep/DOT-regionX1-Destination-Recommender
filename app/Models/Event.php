<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Event extends Model
{
    /** Category key => label shown on the calendar's filter chips. */
    public const CATEGORIES = [
        'festival' => 'Festival',
        'cultural' => 'Cultural',
        'food' => 'Food & Drink',
        'sports' => 'Sports',
        'nature' => 'Nature',
    ];

    protected $fillable = [
        'title', 'slug', 'category', 'starts_on', 'ends_on',
        'location', 'time_label', 'description', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /** Everything the public pages may show. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Still to come, or running today: the end date (or the start, for a one-day event) has not passed. */
    public function scopeUpcoming(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where(function (Builder $q) use ($today) {
            $q->where('ends_on', '>=', $today)
                ->orWhere(fn (Builder $single) => $single->whereNull('ends_on')->where('starts_on', '>=', $today));
        });
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('starts_on')->orderBy('title');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    /** "Aug 14 – 23, 2026", "Oct 17, 2026", or "Aug 28 – Sep 2, 2026". */
    public function dateRangeLabel(): string
    {
        $start = $this->starts_on;
        $end = $this->ends_on;

        if (! $end || $end->isSameDay($start)) {
            return $start->format('M j, Y');
        }

        if ($start->isSameMonth($end)) {
            return $start->format('M j').' – '.$end->format('j, Y');
        }

        return $start->format('M j').' – '.$end->format('M j, Y');
    }

    /** Inner SVG markup (24x24, stroked) for each category's icon. */
    private const ICONS = [
        'festival' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>',
        'cultural' => '<path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/>',
        'food' => '<path d="M5 2v7a2 2 0 0 0 4 0V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6a2 2 0 0 0 2 2h3zm0 0v7"/>',
        'sports' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2z"/>',
        'nature' => '<path d="M7 20h10M10 20c5.5-2.5.8-6.4 3-10M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8zM14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/>',
    ];

    public function categoryIcon(): string
    {
        $paths = self::ICONS[$this->category] ?? self::ICONS['festival'];

        return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    }

    /** The last day the event runs; a one-day event ends the day it starts. */
    public function lastDay(): Carbon
    {
        return $this->ends_on ?? $this->starts_on;
    }

    /** "Happening now", "Today", "Tomorrow", "In 6 days", or "Ended". */
    public function countdownLabel(): string
    {
        $today = Carbon::today();

        if ($this->lastDay()->lt($today)) {
            return 'Ended';
        }
        if ($this->starts_on->lte($today)) {
            return $this->starts_on->isSameDay($today) && $this->lastDay()->isSameDay($today) ? 'Today' : 'Happening now';
        }

        $days = (int) $today->diffInDays($this->starts_on);

        return $days === 1 ? 'Tomorrow' : "In {$days} days";
    }

    /** What the ticket stub shows under the day number: "to 11" for a run, otherwise the weekday. */
    public function stubFoot(): string
    {
        if (! $this->ends_on || $this->ends_on->isSameDay($this->starts_on)) {
            return $this->starts_on->format('D');
        }

        return 'to '.($this->starts_on->isSameMonth($this->ends_on) ? $this->ends_on->format('j') : $this->ends_on->format('M j'));
    }

    /** The shape the calendar script and homepage cards both read. */
    public function toCalendarArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'categoryLabel' => $this->categoryLabel(),
            'icon' => $this->categoryIcon(),
            'start' => $this->starts_on->toDateString(),
            'end' => $this->lastDay()->toDateString(),
            'month' => strtoupper($this->starts_on->format('M')),
            'day' => $this->starts_on->format('j'),
            'stubFoot' => $this->stubFoot(),
            'dateLabel' => $this->dateRangeLabel(),
            'location' => $this->location,
            'time' => $this->time_label,
            'description' => $this->description,
        ];
    }
}
