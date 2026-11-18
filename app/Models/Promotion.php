<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A discount code or promo an establishment posts on its own listing page --
 * self-service, unlike Advisory, which is DOT-authored and can also apply
 * platform-wide.
 */
class Promotion extends Model
{
    protected $fillable = [
        'review_status', 'listing_kind', 'listing_id', 'title', 'code', 'description', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    /** An offer an establishment has added waits hidden until DOT approves it (see ListingPhoto::booted). */
    protected static function booted(): void
    {
        static::addGlobalScope('approved', fn (Builder $query) => $query->whereNull($query->getModel()->getTable().'.review_status'));
    }

    /** The establishment's routes name an offer that may still be waiting; ownership is checked there. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withoutGlobalScopes()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    /**
     * Live right now: no start date yet counts as already started (posted
     * immediately), and no end date means it stays up until the establishment
     * removes it rather than expiring on its own.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now('Asia/Manila')))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now('Asia/Manila')));
    }

    public function scopeForListing(Builder $query, string $listingKind, int $listingId): Builder
    {
        return $query->where('listing_kind', $listingKind)->where('listing_id', $listingId);
    }

    /** Where it stands today, from its dates: only "showing" is ever public. */
    public function status(): string
    {
        $today = now('Asia/Manila')->startOfDay();

        if ($this->starts_at && $this->starts_at->copy()->startOfDay()->gt($today)) {
            return 'scheduled';
        }
        if ($this->ends_at && $this->ends_at->copy()->startOfDay()->lt($today)) {
            return 'ended';
        }

        return 'showing';
    }

    /** The dates in plain words, whichever of Starts and Ends the partner filled in. */
    public function validityLine(): string
    {
        $from = $this->starts_at;
        $to = $this->ends_at;

        return match (true) {
            $from && $to => $from->format('j M Y').' – '.$to->format('j M Y'),
            (bool) $to => 'Until '.$to->format('j M Y'),
            (bool) $from => 'From '.$from->format('j M Y'),
            default => 'No end date — shown until removed',
        };
    }

    /**
     * The title of the live promo of each listing of a kind (the newest when there are several), keyed by listing id. Read once
     * per request, so a grid of cards costs one query however many it shows.
     *
     * @return array<int, string>
     */
    public static function activeTitles(string $kind): array
    {
        $bag = request()->attributes;
        $key = 'active_promo_titles.'.$kind;

        if (! $bag->has($key)) {
            $bag->set($key, static::active()->where('listing_kind', $kind)->latest('id')->get(['listing_id', 'title'])
                ->unique('listing_id')->pluck('title', 'listing_id')->all());
        }

        return $bag->get($key);
    }

    /** Listings of a kind that have a live promo, as a query on their ids. */
    public static function listingIdsWithDeal(string $kind): Builder
    {
        return static::active()->where('listing_kind', $kind)->select('listing_id');
    }
}
