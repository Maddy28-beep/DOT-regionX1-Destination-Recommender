<?php

namespace App\Models\Concerns;

use App\Support\TravelWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Operating status for listings an itinerary can contain.
 *
 * Two independent things can make a place unavailable for a trip:
 *   1. its own status (temporarily closed with a reopen date, or closed for good), and
 *   2. an active DANGER advisory that overlaps the trip dates.
 * A warning or info advisory only warns; the place can still be recommended.
 */
trait HasOperatingStatus
{
    public const STATUS_OPEN = 'open';

    public const STATUS_TEMPORARILY_CLOSED = 'temporarily_closed';

    public const STATUS_CLOSED = 'closed';

    public const OPERATING_STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_TEMPORARILY_CLOSED => 'Temporarily closed',
        self::STATUS_CLOSED => 'Closed for good',
    ];

    /** Advisory severity that keeps a place out of new itineraries. */
    public const CLOSING_ADVISORY_SEVERITY = 'danger';

    public function initializeHasOperatingStatus(): void
    {
        $this->fillable = array_merge($this->fillable, ['operating_status', 'closure_reason', 'reopens_on']);
        $this->casts['reopens_on'] = 'date';
    }

    /** Not closed by the place's own status during [$from, $to]. */
    public function scopeOpenDuring(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where(function (Builder $q) use ($from) {
            $q->whereNull('operating_status')
                ->orWhere('operating_status', self::STATUS_OPEN)
                // closed for a while, but open again by the first day of the trip
                ->orWhere(fn (Builder $c) => $c->where('operating_status', self::STATUS_TEMPORARILY_CLOSED)
                    ->whereNotNull('reopens_on')
                    ->whereDate('reopens_on', '<=', $from->toDateString()));
        });
    }

    /** No active danger advisory on this listing that overlaps [$from, $to]. */
    public function scopeWithoutClosingAdvisoryDuring(Builder $query, Carbon $from, Carbon $to): Builder
    {
        $kind = $this->advisoryKind();

        return $query->whereNotExists(function ($sub) use ($kind, $from, $to) {
            $sub->select(DB::raw(1))
                ->from('advisories')
                ->where('advisories.listing_kind', $kind)
                ->whereColumn('advisories.listing_id', $this->getTable().'.'.$this->getKeyName())
                ->where('advisories.severity', self::CLOSING_ADVISORY_SEVERITY)
                ->where(fn ($d) => $d->whereNull('advisories.starts_at')->orWhereDate('advisories.starts_at', '<=', $to->toDateString()))
                ->where(fn ($d) => $d->whereNull('advisories.ends_at')->orWhereDate('advisories.ends_at', '>=', $from->toDateString()));
        });
    }

    /** Open by its own status AND not under a closing advisory, for the given dates. */
    public function scopeAvailableDuring(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->openDuring($from, $to)->withoutClosingAdvisoryDuring($from, $to);
    }

    /**
     * Same, using the trip currently being planned (TravelWindow). Does nothing
     * when no trip is being planned, so public pages still list closed places.
     */
    public function scopeAvailableForTrip(Builder $query): Builder
    {
        $window = TravelWindow::current();

        return $window ? $query->availableDuring($window[0], $window[1]) : $query;
    }

    public function isClosedByStatus(Carbon $from): bool
    {
        return match ($this->operating_status ?? self::STATUS_OPEN) {
            self::STATUS_CLOSED => true,
            self::STATUS_TEMPORARILY_CLOSED => $this->reopens_on === null || $this->reopens_on->copy()->startOfDay()->gt($from->copy()->startOfDay()),
            default => false,
        };
    }

    public function isAvailableDuring(Carbon $from, Carbon $to): bool
    {
        return static::query()->whereKey($this->getKey())->availableDuring($from, $to)->exists();
    }

    /**
     * A short sentence for the public page: null while the place is open.
     * "Temporarily closed until October 20: repairs", "Closed for good: ...".
     */
    public function operatingNotice(): ?string
    {
        $status = $this->operating_status ?? self::STATUS_OPEN;
        if ($status === self::STATUS_OPEN) {
            return null;
        }

        $reason = $this->closure_reason ? ': '.rtrim($this->closure_reason, '.') : '';

        if ($status === self::STATUS_CLOSED) {
            return 'Closed for good'.$reason;
        }

        if ($this->reopens_on && $this->reopens_on->isPast()) {
            return null; // the reopening date has passed: treat as open again
        }

        return 'Temporarily closed'
            .($this->reopens_on ? ' until '.$this->reopens_on->format('F j') : ' (no reopening date yet)')
            .$reason;
    }

    /** The key Advisory rows use for this model (listing_kind), from the morph map. */
    public function advisoryKind(): string
    {
        return array_search(static::class, Relation::morphMap(), true) ?: $this->getTable();
    }
}
