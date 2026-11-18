<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bundle of an establishment's changes to its listing: a draft while it is being put together, pending while
 * it waits for DOT, then approved or returned with a note.
 */
class ListingSubmission extends Model
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const RETURNED = 'returned';
    public const APPROVED = 'approved';

    /** Waiting longer than this many days is marked overdue. */
    public const OVERDUE_DAYS = 3;

    protected $fillable = ['establishment_account_id', 'listing_kind', 'listing_id', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'note'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ListingChange::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EstablishmentAccount::class, 'establishment_account_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::PENDING && $this->submitted_at && $this->submitted_at->diffInDays(now()) > self::OVERDUE_DAYS;
    }

    /** The listing this is about, or null if it has since been removed. */
    public function listing(): ?Model
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->listing_kind);

        return $class ? $class::find($this->listing_id) : null;
    }
}
