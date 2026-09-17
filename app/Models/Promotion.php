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
        'listing_kind', 'listing_id', 'title', 'code', 'description', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
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
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }

    public function scopeForListing(Builder $query, string $listingKind, int $listingId): Builder
    {
        return $query->where('listing_kind', $listingKind)->where('listing_id', $listingId);
    }
}
