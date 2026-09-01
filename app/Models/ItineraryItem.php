<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'itinerary_id', 'day_number', 'sort_order', 'slot', 'kind', 'title',
        'starts_at', 'ends_at', 'distance_km', 'travel_min_minutes', 'travel_max_minutes',
        'destination_id', 'accommodation_id', 'restaurant_id', 'souvenir_center_id', 'note',
        'rule_basis', 'rule_support', 'rule_confidence', 'rule_co_count',
    ];

    /**
     * Why this row's establishment was chosen, when an association rule chose
     * it (Apriori, Sec. 2.3.4, Equations 8-9).
     *
     * Returns null for rows picked by proximity or preference, so the page can
     * credit the algorithm only where it actually did the work.
     */
    public function ruleExplanation(): ?string
    {
        if (blank($this->rule_basis) || $this->rule_confidence === null) {
            return null;
        }

        $confidence = round((float) $this->rule_confidence * 100);
        $support = round((float) $this->rule_support * 100, 1);

        return sprintf(
            'Frequently visited with %s — %d%% confidence, %s%% support (%d trips)',
            $this->rule_basis,
            $confidence,
            $support,
            $this->rule_co_count,
        );
    }

    /**
     * What the schedule's right-hand column says for this row.
     *
     * Only a journey has a distance. Everything else is somewhere the traveller
     * already is, and saying so is more use than leaving the column blank.
     */
    public function travelSummary(): string
    {
        return match ($this->kind) {
            'travel' => $this->describeTravel(),
            'baseline' => 'Baseline Location',
            'overnight' => 'Within Accommodation',
            'meal' => $this->accommodation_id ? 'Within Accommodation' : 'Within Destination',
            'departure' => 'End of Itinerary',
            default => 'On-site Activity',
        };
    }

    private function describeTravel(): string
    {
        if ($this->distance_km === null) {
            return 'Travel time varies';
        }

        $km = rtrim(rtrim(number_format((float) $this->distance_km, 1), '0'), '.');

        $minutes = $this->travel_min_minutes === $this->travel_max_minutes
            ? $this->travel_min_minutes.' mins'
            : $this->travel_min_minutes.'–'.$this->travel_max_minutes.' mins';

        return "Approx. {$km} km, {$minutes}";
    }

    /** "9:30 AM – 12:00 PM", or just the start when the row is a moment. */
    public function timeLabel(): string
    {
        if (blank($this->starts_at)) {
            return '';
        }

        $start = \Illuminate\Support\Carbon::parse($this->starts_at)->format('g:i A');

        if (blank($this->ends_at)) {
            return $start;
        }

        return $start.' – '.\Illuminate\Support\Carbon::parse($this->ends_at)->format('g:i A');
    }

    /** The listing this row points at, whatever kind it is. */
    public function listing()
    {
        return $this->destination ?: ($this->accommodation ?: ($this->restaurant ?: $this->souvenirCenter));
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function souvenirCenter(): BelongsTo
    {
        return $this->belongsTo(SouvenirCenter::class);
    }
}
