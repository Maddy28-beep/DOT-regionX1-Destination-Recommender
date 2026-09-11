<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A manually-posted, temporary notice from a DOT Admin -- "Mt. Apo is closed
 * this season" or a general platform-wide notice -- kept separate from
 * is_accredited/archived_at because it is about current conditions, not
 * accreditation status.
 */
class Advisory extends Model
{
    protected $fillable = [
        'title', 'message', 'severity', 'listing_kind', 'listing_id', 'starts_at', 'ends_at', 'admin_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    /** Null on both columns means a general, platform-wide advisory rather than one tied to a listing. */
    public function listing(): MorphTo
    {
        return $this->morphTo('listing', 'listing_kind', 'listing_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /**
     * Live right now: no start date yet counts as already started (posted
     * immediately, no scheduling required), and no end date means it stays
     * up until an admin removes it rather than expiring on its own.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }

    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('listing_kind')->whereNull('listing_id');
    }

    public function scopeForListing(Builder $query, string $listingKind, int $listingId): Builder
    {
        return $query->where('listing_kind', $listingKind)->where('listing_id', $listingId);
    }
}
