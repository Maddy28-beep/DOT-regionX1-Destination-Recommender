<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    /**
     * @param  ?int  $simulatedTransactions  Demo (simulated) transactions in the mined dataset, when the caller knows it.
     * @param  ?int  $totalTransactions  All transactions the rules were mined from, when the caller knows it.
     */
    public function ruleExplanation(?int $simulatedTransactions = null, ?int $totalTransactions = null): ?string
    {
        if (blank($this->rule_basis) || $this->rule_confidence === null) {
            return null;
        }

        $confidence = round((float) $this->rule_confidence * 100);
        $support = round((float) $this->rule_support * 100, 1);

        // The mined dataset is the same for every rule in an itinerary, so its demo/genuine
        // composition (the provenance panel's counts) decides the wording: counts that are
        // entirely simulated must say so, or "(N trips)" reads as genuine tourist visits.
        // A mixed dataset is labelled as including simulated trips without claiming a
        // per-rule split the stored row does not carry.
        $trips = match (true) {
            $simulatedTransactions !== null && $totalTransactions !== null
                && $totalTransactions > 0 && $simulatedTransactions >= $totalTransactions => sprintf('%d simulated trips', $this->rule_co_count),
            $simulatedTransactions !== null && $totalTransactions !== null
                && $totalTransactions > 0 && $simulatedTransactions > 0 => sprintf('%d trips, including simulated demonstration trips', $this->rule_co_count),
            default => sprintf('%d trips', $this->rule_co_count),
        };

        return sprintf(
            'Frequently visited with %s — %d%% confidence, %s%% support (%s)',
            $this->rule_basis,
            $confidence,
            $support,
            $trips,
        );
    }

    /**
     * The same rule as ruleExplanation(), as separate pieces for a small card: the paired place, the confidence and
     * support as the whole-number and one-decimal percentages the sentence uses, how many trips it rests on, and whether
     * those trips are simulated. Nothing is added here that the stored rule row does not carry.
     *
     * @return array{basis: string, confidence: int, support: string, trips: int, simulated: 'none'|'some'|'all'}|null
     */
    public function ruleFacts(?int $simulatedTransactions = null, ?int $totalTransactions = null): ?array
    {
        if (blank($this->rule_basis) || $this->rule_confidence === null) {
            return null;
        }

        $known = $simulatedTransactions !== null && $totalTransactions !== null && $totalTransactions > 0;

        return [
            'basis' => (string) $this->rule_basis,
            'confidence' => (int) round((float) $this->rule_confidence * 100),
            'support' => (string) round((float) $this->rule_support * 100, 1),
            'trips' => (int) $this->rule_co_count,
            'simulated' => match (true) {
                $known && $simulatedTransactions >= $totalTransactions => 'all',
                $known && $simulatedTransactions > 0 => 'some',
                default => 'none',
            },
        ];
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
            'free' => 'Location of your choice',
            default => 'On-site Activity',
        };
    }

    /** A journey in a few words, for the line between two cards: "15 min · 1.4 km", "30–45 min · 9.8 km". */
    public function travelBrief(): string
    {
        if ($this->distance_km === null) {
            return 'Travel time varies';
        }

        $km = rtrim(rtrim(number_format((float) $this->distance_km, 1), '0'), '.');
        $minutes = $this->travel_min_minutes === $this->travel_max_minutes
            ? $this->travel_min_minutes.' min'
            : $this->travel_min_minutes.'–'.$this->travel_max_minutes.' min';

        return "{$minutes} · {$km} km";
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

        $start = Carbon::parse($this->starts_at)->format('g:i A');

        if (blank($this->ends_at)) {
            return $start;
        }

        return $start.' – '.Carbon::parse($this->ends_at)->format('g:i A');
    }

    /**
     * How long the row lasts in words ("2 hours", "1 hour 30 min", "45 min"), or null for a row that is a
     * moment (no end time) or has no times at all.
     */
    public function durationLabel(): ?string
    {
        if (blank($this->starts_at) || blank($this->ends_at)) {
            return null;
        }

        $start = Carbon::parse($this->starts_at);
        $minutes = (int) $start->diffInMinutes(Carbon::parse($this->ends_at));

        if ($minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => $rest.' min',
            $rest === 0 => $hours.' '.($hours === 1 ? 'hour' : 'hours'),
            default => $hours.' '.($hours === 1 ? 'hour' : 'hours').' '.$rest.' min',
        };
    }

    /** The same length in the short form a card uses: "2 h 30 min", "1 h", "45 min". */
    public function durationShort(): ?string
    {
        $label = $this->durationLabel();

        return $label === null ? null : str_replace([' hours', ' hour'], ' h', $label);
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
